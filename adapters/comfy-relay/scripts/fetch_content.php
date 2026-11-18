<?php
/**
 * GET /v1/videos/{id}/content —— 下载成片。
 *
 * 从云机 /view 拉 mp4，**以流式方式回传**，避免共享主机内存吃满。
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
if (($rec['status'] ?? '') !== 'completed' || ($rec['filename'] ?? '') === '') {
    fail(409, '任务尚未完成，无法下载');
}

$q = http_build_query([
    'filename' => $rec['filename'],
    'subfolder' => $rec['subfolder'] ?? '',
    'type' => $rec['type'] ?? 'output',
]);
$url = COMFY_BASE . '/view?' . $q;

// 先用 HEAD 探一下大小和类型
$head = http_request('HEAD', $url, null, COMFY_TOKEN !== '' ? ['Authorization' => 'Bearer ' . COMFY_TOKEN] : [], ['timeout' => 60]);
if ($head['status'] === 0) {
    fail(502, '云机不可达: ' . $head['error']);
}
if ($head['status'] !== 200) {
    fail(502, '云机取片失败（HTTP ' . $head['status'] . '）');
}

$ctype = 'video/mp4';
$clen = null;
if (function_exists('curl_getinfo')) {
    // 用 curl 真正拉，因为要透传 stream
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 900,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => COMFY_TOKEN !== '' ? ['Authorization: Bearer ' . COMFY_TOKEN] : [],
]);
if (COMFY_TOKEN !== '') {
    curl_setopt($ch, CURLOPT_USERPWD, 'x:' . COMFY_TOKEN);
}

http_response_code(200);
header('Content-Type: ' . $ctype);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Expose-Headers: *');
header('Content-Disposition: inline; filename="' . basename((string) $rec['filename']) . '"');

$out = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);
if ($out === false) {
    // 头已经发出去了，只能中断
    error_log('[comfy-relay] content stream error: ' . $err);
}
exit;
