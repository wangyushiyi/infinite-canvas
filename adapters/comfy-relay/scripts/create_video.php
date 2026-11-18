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

$wf = comfy_load_workflow(COMFY_VIDEO_WORKFLOW);
if (!$wf) {
    fail(500, '工作流模板不存在或解析失败: ' . COMFY_VIDEO_WORKFLOW);
}

// ---------------------------------------------------------------- 解析字段

$model = trim((string) ($_POST['model'] ?? ''));
$prompt = trim((string) ($_POST['prompt'] ?? ''));
$seconds = (float) ($_POST['seconds'] ?? 5);
$size = trim((string) ($_POST['size'] ?? ''));
$ratio = trim((string) ($_POST['ratio'] ?? ''));
$mode = trim((string) ($_POST['mode'] ?? 'reference'));
$generateAudio = ($_POST['generate_audio'] ?? 'true') === 'true';
$watermark = ($_POST['watermark'] ?? 'false') === 'true';

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
    ]);
}

$imageNames = array_map(static fn($r) => $r['name'], $references);
$videoName = $videoRef !== null ? $videoRef['name'] : '';

// ---------------------------------------------------------------- 编排工作流

$promptJson = h3_build_prompt($prompt, max(1, count($imageNames)), $mode);

// size 形如 "480x640"；取宽高比交给 ResolutionSelector
$aspect = $size !== '' && preg_match('/^\d+x\d+$/', $size) ? $size : $ratio;
if ($aspect === '') {
    $aspect = '16:9';
}

// 分辨率 -> megapixels 近似（画布 vquality: 480/720/1080）
$resName = trim((string) ($_POST['resolution_name'] ?? '720p'));
$resNum = (int) preg_replace('/[^0-9]/', '', $resName) ?: 720;
$megapixels = $resNum <= 480 ? 0.5 : ($resNum <= 720 ? 0.9 : 1.4);

$patched = comfy_patch_workflow(
    $wf,
    $imageNames,
    $videoName,
    $promptJson,
    $seconds,
    random_int(1, 2147483647),   // 固定 seed 会命中 ComfyUI 缓存出一样的片
    8,
    $aspect,
    $megapixels
);

// ---------------------------------------------------------------- 提交

$body = json_encode([
    'prompt' => $patched,
    'client_id' => 'comfy-relay-' . bin2hex(random_bytes(4)),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$res = comfy_request('POST', '/prompt', $body, ['Content-Type' => 'application/json'], ['timeout' => 12, 'retries' => 3]);
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
    'prompt' => $prompt,
    'seconds' => $seconds,
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
]);
