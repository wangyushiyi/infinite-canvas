<?php
/**
 * GET /v1/images/{id} —— 查询生图任务状态。
 *
 * 完成时返回 OpenAI 生图格式：{ created, data: [{ b64_json, url }] }
 */
declare(strict_types=1);

// index.php 路由已注入 $taskId；这里再兜底 CLI/直接访问场景
if (!isset($taskId) || $taskId === '') {
    $taskId = $argv[1] ?? ($_GET['id'] ?? '');
}
if (!preg_match('/^[A-Za-z0-9_\-]+$/', (string) $taskId)) {
    fail(400, '非法 task id');
}

$rec = state_get((string) $taskId);
if ($rec === null) {
    fail(404, '任务不存在或已过期: ' . $taskId);
}
if (($rec['kind'] ?? '') !== 'image') {
    fail(400, '不是生图任务');
}

$promptId = (string) ($rec['prompt_id'] ?? '');
if ($promptId === '') {
    fail(500, '任务记录损坏，缺少 prompt_id');
}

$res = comfy_request('GET', '/history/' . rawurlencode($promptId), null, [], ['timeout' => 5, 'retries' => 1]);

if ($res['error'] !== null) {
    send_json([
        'id' => $taskId,
        'object' => 'image.generation',
        'status' => $rec['status'] ?? 'queued',
        'note' => '云机暂时不可达: ' . $res['error'],
    ]);
}

$history = is_array($res['json']) ? $res['json'] : [];
$run = $history[$promptId] ?? null;
if (!is_array($run)) {
    send_json([
        'id' => $taskId,
        'object' => 'image.generation',
        'status' => $rec['status'] === 'completed' ? 'completed' : 'queued',
    ]);
}

$status = $run['status'] ?? [];
if ((string) ($status['status_str'] ?? '') === 'error') {
    $msgs = [];
    foreach (($status['messages'] ?? []) as $m) {
        $msgs[] = is_array($m) ? (json_encode($m[1] ?? $m, JSON_UNESCAPED_UNICODE) ?: '') : (string) $m;
    }
    $detail = implode(' | ', array_filter($msgs));
    state_put($taskId, array_merge($rec, ['status' => 'failed', 'error' => $detail]));
    send_json([
        'id' => $taskId,
        'object' => 'image.generation',
        'status' => 'failed',
        'error' => ['message' => '云机出图失败: ' . substr($detail, 0, 800), 'type' => 'comfy_execution_error'],
    ], 200);
}

$filename = (string) ($rec['filename'] ?? '');
$subfolder = (string) ($rec['subfolder'] ?? '');
if ($filename === '') {
    foreach (($run['outputs'] ?? []) as $out) {
        if (!empty($out['images']) && is_array($out['images'])) {
            $first = $out['images'][0] ?? null;
            if (is_array($first) && !empty($first['filename'])) {
                $filename = (string) $first['filename'];
                $subfolder = (string) ($first['subfolder'] ?? '');
                break;
            }
        }
    }
}

if ($filename === '') {
    send_json([
        'id' => $taskId,
        'object' => 'image.generation',
        'status' => 'queued',
    ]);
}

state_put($taskId, array_merge($rec, [
    'status' => 'completed',
    'filename' => $filename,
    'subfolder' => $subfolder,
]));

// 取回图片，转成 dataURL（画布接受 dataURL）
$q = http_build_query(['filename' => $filename, 'subfolder' => $subfolder, 'type' => 'output']);
$img = comfy_request('GET', '/view?' . $q, null, [], ['timeout' => 8, 'retries' => 1]);
if ($img['status'] !== 200 || $img['body'] === '') {
    send_json([
        'id' => $taskId,
        'object' => 'image.generation',
        'status' => 'queued',
        'note' => '图片产物取回失败，稍后重试',
    ]);
}

send_json([
    'id' => $taskId,
    'object' => 'image.generation',
    'status' => 'completed',
    'created' => time(),
    'data' => [[
        'b64_json' => base64_encode($img['body']),
        'url' => 'data:image/png;base64,' . base64_encode($img['body']),
        'revised_prompt' => $rec['prompt'] ?? '',
    ]],
]);
