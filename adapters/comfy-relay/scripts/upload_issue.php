<?php
/**
 * 上传令牌：解决「文件不过中继」的问题。
 *
 * 背景（实测）：
 *   - Hostinger → AutoDL 云机的 GET/JSON POST 通，但 **multipart 大文件上传会超时**
 *     （16KB 成功、190KB 超时；且出网有 ~25% 超时率，跨境链路不稳）。
 *   - 浏览器 → 云机的 multipart 上传**完全正常**（ComfyUI 带 --enable-cors-header *，
 *     预检和实际 POST 都回 Access-Control-Allow-Origin: *）。
 *
 * 所以文件走「浏览器 → 云机」直传，中继只负责编排工作流。
 * 流程：
 *   1) 画布 POST /v1/uploads/issue  ->  拿到 {upload_id, cloud_name, upload_url}
 *   2) 画布把图片直接 POST 到 upload_url（云机 /upload/image），拿到云机侧文件名
 *   3) 画布 POST /v1/videos 时带上 upload_id + cloud_name
 *   4) 中继查账本拿到 cloud_name，填进工作流
 *
 * upload_url 由 config 里的 COMFY_BASE + /upload/image 拼出，画布直连即可。
 */
declare(strict_types=1);

// 上传令牌账本（复用 tasks.json，同一个文件）
function upload_issue(string $cloudName, string $mime, int $size): array
{
    $uploadId = 'up_' . bin2hex(random_bytes(10));
    state_put($uploadId, [
        'kind' => 'upload',
        'cloud_name' => $cloudName,
        'mime' => $mime,
        'size' => $size,
        'status' => 'issued',
        'created_at' => time(),
    ]);
    return [
        'upload_id' => $uploadId,
        'cloud_name' => $cloudName,
        'upload_url' => COMFY_BASE . '/upload/image',
        'fields' => ['type' => 'input', 'overwrite' => 'true'],
        'expires_in' => 3600,
    ];
}

/**
 * 从请求里取出上传信息。
 * 画布可以传：
 *   upload_id            —— 中继签发的令牌（用于校验）
 *   cloud_name           —— 浏览器直传后云机返回的文件名
 *   image[] / images     —— 传统 multipart（文件走中继，仅小文件可行）
 */
function collect_references(): array
{
    $refs = [];

    // ---- 方式 A：浏览器直传（推荐）----
    //
    // ⚠️ 多图必须用**数组式字段名** cloud_names[] / upload_ids[]：
    //    PHP 解析 multipart 表单时，同名字段只保留**最后一个**，
    //    重复的 upload_id/cloud_name 会丢图（实测 3 图只进 1 张，
    //    其余槽拿到空值 -> ComfyUI 报 LoadImage IsADirectoryError）。
    //    带 [] 的字段名 PHP 会解析成数组，才是多图的正确载体。
    //    （画布本身传多图用的就是 image[] / images，PHP 天然支持数组。）
    $uploadIds = $_POST['upload_ids'] ?? ($_POST['upload_ids[]'] ?? null);
    $cloudNames = $_POST['cloud_names'] ?? ($_POST['cloud_names[]'] ?? null);
    if (is_array($uploadIds) || is_array($cloudNames)) {
        $uploadIds = is_array($uploadIds) ? array_values($uploadIds) : [];
        $cloudNames = is_array($cloudNames) ? array_values($cloudNames) : [];
        foreach ($cloudNames as $i => $cn) {
            $cn = trim((string) $cn);
            if ($cn === '') {
                continue;
            }
            $uid = trim((string) ($uploadIds[$i] ?? ''));
            if ($uid === '') {
                fail(400, 'cloud_names[] 与 upload_ids[] 数量必须一一对应');
            }
            $rec = state_get($uid);
            if ($rec === null || ($rec['kind'] ?? '') !== 'upload') {
                fail(400, 'upload_id 无效或已过期，请重新申请上传令牌');
            }
            if (!preg_match('/^[A-Za-z0-9._\-]+$/', $cn)) {
                fail(400, 'cloud_name 非法');
            }
            if (!preg_match('/\.(jpg|jpeg|png|webp)$/i', $cn)) {
                fail(400, 'cloud_name 扩展名不支持');
            }
            state_put($uid, array_merge($rec, ['status' => 'uploaded', 'cloud_name' => $cn]));
            $refs[] = ['name' => $cn, 'via' => 'direct'];
        }
        return $refs;
    }

    // 单数形式（只有一张图时用）
    $uploadId = trim((string) ($_POST['upload_id'] ?? ''));
    $cloudName = trim((string) ($_POST['cloud_name'] ?? ''));
    if ($uploadId !== '' && $cloudName !== '') {
        $rec = state_get($uploadId);
        if ($rec === null || ($rec['kind'] ?? '') !== 'upload') {
            fail(400, 'upload_id 无效或已过期，请重新申请上传令牌');
        }
        // 文件名必须和签发时约定的一致，防止被当成任意路径注入
        if (!preg_match('/^[A-Za-z0-9._\-]+$/', $cloudName)) {
            fail(400, 'cloud_name 非法');
        }
        if (!str_ends_with($cloudName, '.jpg') && !str_ends_with($cloudName, '.png')
            && !str_ends_with($cloudName, '.jpeg') && !str_ends_with($cloudName, '.webp')
            && !str_ends_with($cloudName, '.mp4') && !str_ends_with($cloudName, '.mp3')
            && !str_ends_with($cloudName, '.wav')) {
            fail(400, 'cloud_name 扩展名不支持');
        }
        state_put($uploadId, array_merge($rec, ['status' => 'uploaded', 'cloud_name' => $cloudName]));
        $refs[] = ['name' => $cloudName, 'via' => 'direct'];
    }

    // ---- 方式 B：文件过中继（兼容旧路径，仅适合小文件）----
    foreach (['image[]', 'images', 'image'] as $field) {
        if (!isset($_FILES[$field])) {
            continue;
        }
        $f = $_FILES[$field];
        $items = [];
        if (is_array($f['name'] ?? null)) {
            foreach ($f['name'] as $i => $n) {
                $items[] = [
                    'tmp_name' => $f['tmp_name'][$i],
                    'name' => $n,
                    'error' => $f['error'][$i] ?? UPLOAD_ERR_OK,
                    'type' => $f['type'][$i] ?? 'application/octet-stream',
                    'size' => $f['size'][$i] ?? 0,
                ];
            }
        } else {
            $items[] = [
                'tmp_name' => $f['tmp_name'],
                'name' => $f['name'],
                'error' => $f['error'] ?? UPLOAD_ERR_OK,
                'type' => $f['type'] ?? 'application/octet-stream',
                'size' => $f['size'] ?? 0,
            ];
        }
        foreach ($items as $it) {
            if (($it['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                continue;
            }
            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $it['name']);
            $ext = strtolower(pathinfo($safe, PATHINFO_EXTENSION)) ?: 'png';
            $cloudName = 'canvas_ref_' . bin2hex(random_bytes(5)) . '.' . $ext;
            $up = comfy_upload($it['tmp_name'], $cloudName, $it['type']);
            if (!$up['ok']) {
                fail(502, '参考图上传到云机失败: ' . $up['error']);
            }
            $refs[] = ['name' => $up['name'], 'via' => 'relay'];
        }
    }

    return $refs;
}

/**
 * 原视频：同理，支持浏览器直传（upload_video_id / video_cloud_name）。
 * 没有原视频时返回 null。
 */
function collect_video(): ?array
{
    // 数组式优先（与参考图同一约定）；原视频通常只有 1 个（槽 0）
    $videoIds = $_POST['upload_video_ids'] ?? ($_POST['upload_video_ids[]'] ?? null);
    $videoNames = $_POST['video_cloud_names'] ?? ($_POST['video_cloud_names[]'] ?? null);
    if (is_array($videoNames) && $videoNames) {
        $videoNames = array_values($videoNames);
        $videoIds = is_array($videoIds) ? array_values($videoIds) : [];
        $cn = trim((string) $videoNames[0]);
        $uid = trim((string) ($videoIds[0] ?? ''));
        if ($cn === '') {
            return null;
        }
        $rec = state_get($uid);
        if ($rec === null || ($rec['kind'] ?? '') !== 'upload') {
            fail(400, 'upload_video_id 无效或已过期');
        }
        if (!preg_match('/^[A-Za-z0-9._\-]+\.(mp4|mov|mkv|webm)$/i', $cn)) {
            fail(400, 'video_cloud_name 非法');
        }
        state_put($uid, array_merge($rec, ['status' => 'uploaded', 'cloud_name' => $cn]));
        return ['name' => $cn, 'via' => 'direct'];
    }

    $uploadId = trim((string) ($_POST['upload_video_id'] ?? ''));
    $cloudName = trim((string) ($_POST['video_cloud_name'] ?? ''));
    if ($uploadId !== '' && $cloudName !== '') {
        $rec = state_get($uploadId);
        if ($rec === null || ($rec['kind'] ?? '') !== 'upload') {
            fail(400, 'upload_video_id 无效或已过期');
        }
        if (!preg_match('/^[A-Za-z0-9._\-]+\.(mp4|mov|mkv|webm)$/i', $cloudName)) {
            fail(400, 'video_cloud_name 非法');
        }
        state_put($uploadId, array_merge($rec, ['status' => 'uploaded', 'cloud_name' => $cloudName]));
        return ['name' => $cloudName, 'via' => 'direct'];
    }

    foreach (['video[]', 'video', 'videos'] as $field) {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) {
            continue;
        }
        $f = $_FILES[$field];
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $f['name']);
        $ext = strtolower(pathinfo($safe, PATHINFO_EXTENSION)) ?: 'mp4';
        $cloudName = 'canvas_src_' . bin2hex(random_bytes(5)) . '.' . $ext;
        $up = comfy_upload($f['tmp_name'], $cloudName, $f['type'] ?? 'video/mp4');
        if (!$up['ok']) {
            fail(502, '原视频上传到云机失败: ' . $up['error']);
        }
        return ['name' => $up['name'], 'via' => 'relay'];
    }

    return null;
}
