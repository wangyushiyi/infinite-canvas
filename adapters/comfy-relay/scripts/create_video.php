<?php
/**
 * POST /v1/videos —— 创建视频任务。
 *
 * 画布发来的 multipart 字段（见 web/src/services/api/video.ts createOpenAIVideoTask）：
 *   model, prompt, seconds, size, ratio, resolution_name,
 *   generate_audio, watermark, mode,
 *   image[] / images (参考图，文件), video[] (原视频), audio[] (原音频)
 *
 * 流程：
 *   1) 解析参考图/原视频 → 上传到云机 input 目录
 *   2) 编排 H3 七字段 prompt
 *   3) 改写工作流（槽位真序 + 音轨直连 + 时长/分辨率/seed）
 *   4) POST /prompt 提交，返回 prompt_id
 *   5) 记入任务账本，向上面返回 OpenAI 形状的 task id
 */
declare(strict_types=1);

require __DIR__ . '/upload_issue.php';

// ---------------------------------------------------------------- 选档（模型名 -> 工作流）

$model = trim((string) ($_POST['model'] ?? ''));
$profile = comfy_video_profile($model);
$wfName = (string) ($profile['file'] ?? COMFY_VIDEO_WORKFLOW);

$wf = comfy_load_workflow($wfName);
if (!$wf) {
    fail(500, '工作流模板不存在或解析失败: ' . $wfName . '（模型 ' . $model . '）');
}

// ---------------------------------------------------------------- 解析字段

$prompt = trim((string) ($_POST['prompt'] ?? ''));
$mode = trim((string) ($_POST['mode'] ?? 'reference'));
$generateAudio = ($_POST['generate_audio'] ?? 'true') === 'true';
$watermark = ($_POST['watermark'] ?? 'false') === 'true';

// 规格优先级：**档案默认优先**，画布只在显式改了的时候才覆盖。
//   - 秒数：画布内置默认是 6（normalizeVideoSeconds 的默认值），
//           传 6 视为"未设置" -> 用档案默认；传别的值 -> 用画布的。
//   - 宽高比 / 百万像素：属于 U 系自身的规格（u22v1 就是 9:16 竖屏），
//           一律用档案默认，不接受画布覆盖，避免选错比例跑出废片。
$defaults = $profile['defaults'] ?? [];
$rawSeconds = (float) ($_POST['seconds'] ?? 0);
if ($rawSeconds > 0 && abs($rawSeconds - 6.0) > 0.01) {
    $seconds = $rawSeconds;                       // 用户在画布里显式改了时长
} else {
    $seconds = (float) ($defaults['seconds'] ?? 5.0);
}
$aspect = (string) ($defaults['aspect'] ?? '16:9');
$megapixels = (float) ($defaults['megapixels'] ?? 0.9);
$steps = (int) ($defaults['steps'] ?? 8);

// 首尾帧模式：画布用 first_frame / last_frame
$firstFrame = $_FILES['first_frame'] ?? null;
$lastFrame = $_FILES['last_frame'] ?? null;

if ($prompt === '' && !isset($_POST['upload_id'])) {
    fail(400, '需要 prompt 或至少一张参考图');
}

// ---------------------------------------------------------------- 收集参考图 / 原视频

// 优先走「浏览器直传云机」（文件不过中继，见 upload_issue.php 里的实测说明）；
// 没有直传信息时才回退到「文件过中继」。
$references = collect_references();
$videoRef = collect_video();

if (!$references && $mode !== 'frames') {
    fail(400, '至少需要 1 张参考图（直传请带 upload_id + cloud_name，或用 image[] 上传）');
}

if (DRY_RUN) {
    send_json([
        'id' => 'dryrun_' . bin2hex(random_bytes(6)),
        'object' => 'video',
        'model' => $model,
        'status' => 'queued',
        'dry_run' => true,
        'received' => [
            'prompt' => $prompt,
            'seconds' => $seconds,
            'size' => $size,
            'ratio' => $ratio,
            'mode' => $mode,
            'image_count' => count($references),
            'images' => $references,
            'video' => $videoRef,
        ],
        'profile' => [
            'model' => $model,
            'label' => (string) ($profile['label'] ?? ''),
            'workflow' => $wfName,
            'patch_mode' => (string) ($profile['mode'] ?? 'slots'),
            'spec' => [
                'seconds' => $seconds,
                'aspect' => $aspect,
                'megapixels' => $megapixels,
                'steps' => $steps,
            ],
        ],
    ]);
}

$imageNames = array_map(static fn($r) => $r['name'], $references);
$videoName = $videoRef !== null ? $videoRef['name'] : '';

// ---------------------------------------------------------------- 编排工作流

$promptJson = h3_build_prompt($prompt, max(1, count($imageNames)), $mode);

$patched = comfy_patch_workflow(
    $wf,
    $imageNames,
    $videoName,
    $promptJson,
    $seconds,
    random_int(1, 2147483647),   // 固定 seed 会命中 ComfyUI 缓存出一样的片
    $steps,
    $aspect,
    $megapixels,
    $profile
);

// ---------------------------------------------------------------- 提交

$body = json_encode([
    'prompt' => $patched,
    'client_id' => 'comfy-relay-' . bin2hex(random_bytes(4)),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// ⚠️ 超时预算必须 < Hostinger 的 PHP 执行上限（约 30s）：
//    单次 timeout × (retries+1) + 退避 sleep 必须留出余量。
//    12s × 4 = 48s 会撞上限 -> 请求被掐断成 HTTP 000（看不出原因）。
$res = comfy_request('POST', '/prompt', $body, ['Content-Type' => 'application/json'], ['timeout' => 6, 'retries' => 1]);
if ($res['error'] !== null) {
    fail(502, '提交云机工作流失败: ' . $res['error']);
}
if ($res['status'] !== 200 || !is_array($res['json']) || empty($res['json']['prompt_id'])) {
    // ComfyUI 的错误可能在 node_errors，也可能在顶层 error.message
    // （实测：'_api_config' 无 class_type 这种错误 node_errors 是空的，错误在 error.message）
    $errs = $res['json']['node_errors'] ?? null;
    if (empty($errs)) {
        $errs = $res['json']['error'] ?? null;
    }
    if (empty($errs)) {
        $errs = $res['body'];
    }
    $msg = is_string($errs) ? $errs : (json_encode($errs, JSON_UNESCAPED_UNICODE) ?: $res['body']);
    fail(502, '云机未接受工作流（HTTP ' . $res['status'] . '）: ' . substr($msg, 0, 800));
}

$promptId = (string) $res['json']['prompt_id'];
$taskId = 'cmfy_' . bin2hex(random_bytes(10));

state_put($taskId, [
    'prompt_id' => $promptId,
    'status' => 'queued',
    'filename' => '',
    'subfolder' => '',
    'type' => 'output',
    'model' => $model,
    'profile' => (string) ($profile['label'] ?? $wfName),
    'workflow' => $wfName,
    'patch_mode' => (string) ($profile['mode'] ?? 'slots'),
    'prompt' => $prompt,
    'seconds' => $seconds,
    'aspect' => $aspect,
    'megapixels' => $megapixels,
    'steps' => $steps,
    'image_count' => count($imageNames),
    'has_video' => $videoName !== '',
    'generate_audio' => $generateAudio,
    'watermark' => $watermark,
    'created_at' => time(),
]);
state_prune();

send_json([
    'id' => $taskId,
    'object' => 'video',
    'model' => $model,
    'status' => 'queued',
    'created_at' => time(),
    'prompt_id' => $promptId,
    'profile' => [
        'label' => (string) ($profile['label'] ?? ''),
        'workflow' => $wfName,
        'patch_mode' => (string) ($profile['mode'] ?? 'slots'),
        'spec' => ['seconds' => $seconds, 'aspect' => $aspect, 'megapixels' => $megapixels, 'steps' => $steps],
    ],
]);
