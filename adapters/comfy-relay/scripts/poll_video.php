<?php
/**
 * GET /v1/videos/{id} —— 查询任务状态。
 *
 * 每次查询顺带向云机拉一次 /history/{prompt_id}，
 * 拿到产物后把 filename/subfolder 记进账本，供 /content 下载。
 */
declare(strict_types=1);

$taskId = $argv[1] ?? ($_GET['id'] ?? '');
if (!preg_match('/^[A-Za-z0-9_\-]+$/', (string) $taskId)) {
    fail(400, '非法 task id');
}

$rec = state_get((string) $taskId);
if ($rec === null) {
    fail(404, '任务不存在或已过期: ' . $taskId);
}

$promptId = (string) ($rec['prompt_id'] ?? '');
if ($promptId === '') {
    fail(500, '任务记录损坏，缺少 prompt_id');
}

// ---- 向云机查历史
$res = comfy_request('GET', '/history/' . rawurlencode($promptId), null, [], ['timeout' => 60]);

if ($res['error'] !== null) {
    // 云机暂时不可达：保持 queued，别把任务判死（画布会继续轮询）
    send_json([
        'id' => $taskId,
        'object' => 'video',
        'model' => $rec['model'] ?? '',
        'status' => $rec['status'] ?? 'queued',
        'note' => '云机暂时不可达: ' . $res['error'],
    ]);
}

$history = is_array($res['json']) ? $res['json'] : [];
$run = $history[$promptId] ?? null;

if (!is_array($run)) {
    // 还没进 history（可能仍在队列）
    send_json([
        'id' => $taskId,
        'object' => 'video',
        'model' => $rec['model'] ?? '',
        'status' => $rec['status'] === 'completed' ? 'completed' : 'queued',
    ]);
}

$status = $run['status'] ?? [];
$statusStr = (string) ($status['status_str'] ?? '');

// ---- 失败
if ($statusStr === 'error') {
    $msgs = [];
    foreach (($status['messages'] ?? []) as $m) {
        $msgs[] = is_array($m) ? (json_encode($m[1] ?? $m, JSON_UNESCAPED_UNICODE) ?: '') : (string) $m;
    }
    $detail = implode(' | ', array_filter($msgs));
    state_put($taskId, array_merge($rec, ['status' => 'failed', 'error' => $detail]));
    send_json([
        'id' => $taskId,
        'object' => 'video',
        'model' => $rec['model'] ?? '',
        'status' => 'failed',
        'error' => ['message' => '云机出片失败: ' . substr($detail, 0, 800), 'type' => 'comfy_execution_error'],
    ]);
}

// ---- 成功：找产物
$filename = (string) ($rec['filename'] ?? '');
$subfolder = (string) ($rec['subfolder'] ?? '');

if ($filename === '') {
    foreach (($run['outputs'] ?? []) as $nodeId => $out) {
        foreach (['video', 'videos', 'gifs', 'images'] as $key) {
            if (!empty($out[$key]) && is_array($out[$key])) {
                $first = $out[$key][0] ?? null;
                if (is_array($first) && !empty($first['filename'])) {
                    $filename = (string) $first['filename'];
                    $subfolder = (string) ($first['subfolder'] ?? '');
                    break 2;
                }
            }
        }
    }
}

if ($filename === '') {
    // 成功但没产物（异常情况）
    state_put($taskId, array_merge($rec, ['status' => 'failed', 'error' => '云机返回成功但没有产物文件']));
    send_json([
        'id' => $taskId,
        'object' => 'video',
        'model' => $rec['model'] ?? '',
        'status' => 'failed',
        'error' => ['message' => '云机返回成功但没有产物文件', 'type' => 'comfy_no_output'],
    ]);
}

state_put($taskId, array_merge($rec, [
    'status' => 'completed',
    'filename' => $filename,
    'subfolder' => $subfolder,
    'type' => 'output',
]));

send_json([
    'id' => $taskId,
    'object' => 'video',
    'model' => $rec['model'] ?? '',
    'status' => 'completed',
    'filename' => $filename,
    'subfolder' => $subfolder,
]);
