<?php
/**
 * POST /v1/images/generations  （文生图，JSON body）
 * POST /v1/images/edits         （参考图生图，multipart）
 *
 * 画布侧协议（web/src/services/api/image.ts）：
 *   generations: POST {base}/v1/images/generations  {model,prompt,size,quality,count,n,response_format}
 *   edits:       POST {base}/v1/images/edits        multipart: image / image[]、prompt、size
 *
 * 内部转成 ComfyUI 工作流：CheckpointLoaderSimple -> CLIPTextEncode x2 ->
 * EmptyLatentImage / VAEEncode -> KSampler -> VAEDecode -> SaveImage
 */
declare(strict_types=1);

require __DIR__ . '/upload_issue.php';

$wf = comfy_load_workflow(COMFY_IMAGE_WORKFLOW);
if (!$wf) {
    fail(500, '生图工作流模板不存在或解析失败: ' . COMFY_IMAGE_WORKFLOW);
}

$isEdits = str_contains($_SERVER['REQUEST_URI'] ?? '', '/edits');

// ---------------------------------------------------------------- 解析入参

$prompt = '';
$model = '';
$size = '1024x1024';
$count = 1;
$quality = '';
$negative = '';

if ($isEdits) {
    $prompt = trim((string) ($_POST['prompt'] ?? ''));
    $model = trim((string) ($_POST['model'] ?? ''));
    $size = trim((string) ($_POST['size'] ?? '')) ?: '1024x1024';
    $count = max(1, (int) ($_POST['n'] ?? 1));
} else {
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        fail(400, '请求体不是合法 JSON');
    }
    $prompt = trim((string) ($body['prompt'] ?? ''));
    $model = trim((string) ($body['model'] ?? ''));
    $size = trim((string) ($body['size'] ?? '')) ?: '1024x1024';
    $count = max(1, (int) ($body['n'] ?? ($body['count'] ?? 1)));
    $quality = trim((string) ($body['quality'] ?? ''));
    $negative = trim((string) ($body['negative_prompt'] ?? ''));
}

if ($prompt === '') {
    fail(400, '缺少 prompt');
}

// ---------------------------------------------------------------- 尺寸

if (preg_match('/^(\d+)\s*x\s*(\d+)$/i', $size, $m)) {
    $width = max(64, min(2048, (int) $m[1]));
    $height = max(64, min(2048, (int) $m[2]));
} else {
    $width = 1024;
    $height = 1024;
}
// 必须是 8 的倍数
$width = max(64, (int) (round($width / 8) * 8));
$height = max(64, (int) (round($height / 8) * 8));

// ---------------------------------------------------------------- 参考图

$imageName = '';
if ($isEdits) {
    $files = null;
    foreach (['image[]', 'image', 'images'] as $field) {
        if (isset($_FILES[$field])) {
            $files = $_FILES[$field];
            break;
        }
    }
    if ($files === null) {
        // 也支持浏览器直传（upload_id + cloud_name）
        $uploadId = trim((string) ($_POST['upload_id'] ?? ''));
        $cloudName = trim((string) ($_POST['cloud_name'] ?? ''));
        if ($uploadId !== '' && $cloudName !== '') {
            $rec = state_get($uploadId);
            if ($rec === null || ($rec['kind'] ?? '') !== 'upload') {
                fail(400, 'upload_id 无效或已过期');
            }
            if (!preg_match('/^[A-Za-z0-9._\-]+\.(jpg|jpeg|png|webp)$/i', $cloudName)) {
                fail(400, 'cloud_name 非法');
            }
            state_put($uploadId, array_merge($rec, ['status' => 'uploaded', 'cloud_name' => $cloudName]));
            $imageName = $cloudName;
        } else {
            fail(400, '参考图生图需要上传 image（或用 upload_id + cloud_name 直传）');
        }
    } else {
        $first = is_array($files['name'] ?? null)
            ? ['tmp_name' => $files['tmp_name'][0], 'name' => $files['name'][0], 'type' => $files['type'][0] ?? 'image/png', 'error' => $files['error'][0] ?? UPLOAD_ERR_OK]
            : ['tmp_name' => $files['tmp_name'], 'name' => $files['name'], 'type' => $files['type'] ?? 'image/png', 'error' => $files['error'] ?? UPLOAD_ERR_OK];
        if ($first['error'] !== UPLOAD_ERR_OK) {
            fail(400, '参考图上传失败，错误码 ' . $first['error']);
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $first['name']);
        $ext = strtolower(pathinfo($safe, PATHINFO_EXTENSION)) ?: 'png';
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $ext = 'jpg';
        } elseif (!in_array($ext, ['png', 'webp', 'bmp'], true)) {
            $ext = 'png';
        }
        $cloudName = 'canvas_img_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $up = comfy_upload($first['tmp_name'], $cloudName, $first['type']);
        if (!$up['ok']) {
            fail(502, '参考图上传到云机失败: ' . $up['error']);
        }
        $imageName = $up['name'];
    }
}

if (DRY_RUN) {
    send_json([
        'dry_run' => true,
        'received' => [
            'prompt' => $prompt,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'count' => $count,
            'edits' => $isEdits,
            'has_reference' => $imageName !== '',
        ],
    ]);
}

// ---------------------------------------------------------------- 改写工作流

$wf['1']['inputs']['ckpt_name'] = defined('IMAGE_CKPT') ? IMAGE_CKPT : 'SDXL/sd_xl_base_1.0.safetensors';
$wf['2']['inputs']['text'] = $prompt;
$wf['3']['inputs']['text'] = $negative !== '' ? $negative : 'blurry, low quality, watermark, text, deformed, extra fingers';
$wf['4']['inputs']['width'] = $width;
$wf['4']['inputs']['height'] = $height;
$wf['4']['inputs']['batch_size'] = $count;
$wf['5']['inputs']['seed'] = random_int(1, 2147483647);
$wf['5']['inputs']['steps'] = $quality === 'low' ? 12 : ($quality === 'high' ? 36 : 28);
$wf['7']['inputs']['filename_prefix'] = 'canvas/image/relay';

if ($imageName !== '') {
    // 图生图：latent 换成 VAEEncode(参考图)，denoise 降低保留原图结构
    $wf['8']['inputs']['image'] = $imageName;
    $wf['10']['inputs']['width'] = $width;
    $wf['10']['inputs']['height'] = $height;
    $wf['5']['inputs']['latent_image'] = ['9', 0];
    $wf['5']['inputs']['denoise'] = 0.65;
} else {
    $wf['5']['inputs']['latent_image'] = ['4', 0];
    $wf['5']['inputs']['denoise'] = 1.0;
}

// ---------------------------------------------------------------- 提交

$body = json_encode([
    'prompt' => $wf,
    'client_id' => 'comfy-relay-img-' . bin2hex(random_bytes(4)),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$res = comfy_request('POST', '/prompt', $body, ['Content-Type' => 'application/json'], ['timeout' => 12, 'retries' => 3]);
if ($res['error'] !== null) {
    fail(502, '提交云机工作流失败: ' . $res['error']);
}
if ($res['status'] !== 200 || !is_array($res['json']) || empty($res['json']['prompt_id'])) {
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

// 生图也走异步：提交后立刻返回 task id，画布用 GET /v1/images/{id} 轮询。
// （Hostinger 共享主机的 PHP 执行上限约 30s，同步等出图必超时。）
$taskId = 'cmfy_img_' . bin2hex(random_bytes(10));
state_put($taskId, [
    'kind' => 'image',
    'prompt_id' => $promptId,
    'status' => 'queued',
    'filename' => '',
    'subfolder' => '',
    'type' => 'output',
    'model' => $model,
    'prompt' => $prompt,
    'width' => $width,
    'height' => $height,
    'count' => $count,
    'created_at' => time(),
]);
state_prune();

send_json([
    'id' => $taskId,
    'object' => 'image.generation',
    'model' => $model,
    'status' => 'queued',
    'created_at' => time(),
    'prompt_id' => $promptId,
]);
