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

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('#^.*?/comfy-relay#', '', $path);
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

// --- 创建视频任务
if ($path === '/v1/videos' && $method === 'POST') {
    relay_auth();
    require __DIR__ . '/scripts/create_video.php';
    exit;
}

// --- 查询视频任务
if (preg_match('#^/v1/videos/([A-Za-z0-9_\-]+)$#', $path, $m)) {
    relay_auth();
    require __DIR__ . '/scripts/poll_video.php';
    exit;
}

// --- 下载成片
if (preg_match('#^/v1/videos/([A-Za-z0-9_\-]+)/content$#', $path, $m)) {
    relay_auth();
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

fail(404, '未知路由: ' . $method . ' ' . $path);
