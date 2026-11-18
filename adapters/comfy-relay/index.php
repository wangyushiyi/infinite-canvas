<?php
/**
 * ComfyUI 云机渠道适配层 · 主入口
 *
 * 对画布暴露标准 OpenAI 视频/图片协议，内部转成 ComfyUI 的原生调用。
 *
 * 路由：
 *   GET  /comfy-relay/health                      健康检查
 *   POST /comfy-relay/v1/videos                   创建视频任务（multipart）
 *   GET  /comfy-relay/v1/videos/{id}              查询任务
 *   GET  /comfy-relay/v1/videos/{id}/content      下载成片
 *   POST /comfy-relay/v1/images/generations       文生图（JSON）
 *   POST /comfy-relay/v1/images/edits             参考图生图（multipart）
 *
 * 画布侧配置：协议 OpenAI ｜ 接口地址 = PUBLIC_BASE + '/v1' ｜ Key = RELAY_KEY
 *   → **不需要挂「调用脚本」**，画布走原生 OpenAI 路径即可。
 */
declare(strict_types=1);

// 诊断模式：显示 PHP 错误（生产请关掉）
if (getenv('COMFY_RELAY_DEBUG') === '1' || isset($_GET['__debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

require __DIR__ . '/config/config.php';
require __DIR__ . '/config/comfy_core.php';

// ---------------------------------------------------------------- 基础响应

/**
 * 统一的 CORS 响应头。**必须对 4xx/5xx 也带上**，
 * 否则浏览器跨域时会把状态码吃掉，只报 ERR_NETWORK（画布显示裸「请求失败」）。
 */
function send_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: POST, GET, DELETE, PUT, OPTIONS, PATCH');
    header('Access-Control-Expose-Headers: *');
    header('Access-Control-Max-Age: 86400');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $code, string $msg, array $extra = []): void
{
    send_json(array_merge([
        'error' => ['message' => $msg, 'type' => 'comfy_relay_error'],
        'message' => $msg,
    ], $extra), $code);
}

// CORS 预检
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: POST, GET, DELETE, PUT, OPTIONS, PATCH');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

/** 鉴权：画布带的 Bearer key 必须等于 RELAY_KEY。 */
function relay_auth(): void
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        $auth = $h['Authorization'] ?? $h['authorization'] ?? '';
    }
    if ($auth === '' && isset($_GET['key'])) {
        $auth = 'Bearer ' . $_GET['key'];
    }
    if (!preg_match('/Bearer\s+(.+)$/i', trim((string) $auth), $m)) {
        fail(401, '缺少 Authorization: Bearer <key>');
    }
    if (!hash_equals(RELAY_KEY, trim($m[1]))) {
        fail(401, 'API Key 无效');
    }
}

// ---------------------------------------------------------------- 路由

// 剥掉部署前缀（如 /comfy-relay）。用 strpos 定位第一个出现位置，
// 避免正则非贪婪/贪婪在嵌套路径下剥错。
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$marker = '/comfy-relay';
$pos = strpos($path, $marker);
if ($pos !== false) {
    $path = substr($path, $pos + strlen($marker));
}
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- 健康检查
if ($path === '/health' || $path === '/') {
    send_json([
        'ok' => true,
        'service' => 'comfy-relay',
        'upstream' => COMFY_BASE,
        'dry_run' => DRY_RUN,
        'workflow' => COMFY_VIDEO_WORKFLOW,
        'php' => PHP_VERSION,
        'time' => gmdate('c'),
    ]);
}

// --- 上游连通性诊断（用于排查 Hostinger -> 云机的出网）
if ($path === '/diag/upstream') {
    $t0 = microtime(true);
    $r = comfy_request('GET', '/system_stats', null, [], ['timeout' => 25]);
    $ms = (int) ((microtime(true) - $t0) * 1000);
    send_json([
        'ok' => $r['error'] === null && $r['status'] === 200,
        'upstream' => COMFY_BASE,
        'http_status' => $r['status'],
        'latency_ms' => $ms,
        'error' => $r['error'],
        'comfyui_version' => $r['json']['system']['comfyui_version'] ?? null,
        'server_time' => gmdate('c'),
    ], $r['error'] === null && $r['status'] === 200 ? 200 : 502);
}

// --- 裸 POST 连通性诊断（区分 GET 通但 POST 卡）
if ($path === '/diag/post') {
    $t0 = microtime(true);
    $r = comfy_request('POST', '/prompt', json_encode(['prompt' => [], 'client_id' => 'diag']), ['Content-Type' => 'application/json'], ['timeout' => 20]);
    $ms = (int) ((microtime(true) - $t0) * 1000);
    send_json(['ok' => $r['error'] === null, 'latency_ms' => $ms, 'status' => $r['status'], 'error' => $r['error'], 'body' => substr($r['body'], 0, 300)]);
}

// --- 上传耗时诊断
if ($path === '/diag/upload') {
    $t0 = microtime(true);
    $f = $_FILES['image'] ?? null;
    if (!$f) {
        fail(400, '缺 image');
    }
    $up = comfy_upload($f['tmp_name'], 'diag_' . bin2hex(random_bytes(4)) . '.jpg', $f['type']);
    $ms = (int) ((microtime(true) - $t0) * 1000);
    send_json(['ok' => !empty($up['ok']), 'latency_ms' => $ms, 'result' => $up]);
}

// --- 签发上传令牌（供浏览器直传云机，文件不过中继）
if ($path === '/v1/uploads/issue' && $method === 'POST') {
    relay_auth();
    require __DIR__ . '/scripts/upload_issue.php';
    $mime = trim((string) ($_POST['mime'] ?? 'image/png'));
    $size = (int) ($_POST['size'] ?? 0);
    $ext = strtolower((string) ($_POST['ext'] ?? ''));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'mkv', 'webm', 'mp3', 'wav'], true)) {
        $ext = 'png';
    }
    $cloudName = 'canvas_' . bin2hex(random_bytes(6)) . '.' . $ext;
    send_json(upload_issue($cloudName, $mime, $size));
}

// --- 创建视频任务
if ($path === '/v1/videos' && $method === 'POST') {
    relay_auth();
    require __DIR__ . '/scripts/create_video.php';
    exit;
}

// --- 查询视频任务
if (preg_match('#^/v1/videos/([A-Za-z0-9_\-]+)$#', $path, $m)) {
    relay_auth();
    // 脚本用 $argv[1] 取 task id；Web 下 $argv 不存在，这里补上
    $argv = [$m[1]];
    $taskId = $m[1];
    require __DIR__ . '/scripts/poll_video.php';
    exit;
}

// --- 下载成片
if (preg_match('#^/v1/videos/([A-Za-z0-9_\-]+)/content$#', $path, $m)) {
    relay_auth();
    $argv = [$m[1]];
    $taskId = $m[1];
    require __DIR__ . '/scripts/fetch_content.php';
    exit;
}

// --- 文生图
if ($path === '/v1/images/generations' && $method === 'POST') {
    relay_auth();
    require __DIR__ . '/scripts/create_image.php';
    exit;
}

// --- 参考图生图
if ($path === '/v1/images/edits' && $method === 'POST') {
    relay_auth();
    require __DIR__ . '/scripts/create_image.php';
    exit;
}

// --- 查询生图任务
if (preg_match('#^/v1/images/([A-Za-z0-9_\-]+)$#', $path, $m)) {
    relay_auth();
    $argv = [$m[1]];
    $taskId = $m[1];
    require __DIR__ . '/scripts/poll_image.php';
    exit;
}

fail(404, '未知路由: ' . $method . ' ' . $path);
