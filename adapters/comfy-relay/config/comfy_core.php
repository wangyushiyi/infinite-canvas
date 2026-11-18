<?php
/**
 * ComfyUI 云机客户端 + H3 提示词编排 + OpenAI 协议转换。
 *
 * 设计要点（全部来自实测，勿凭推断改动）：
 *
 * 1) 云机的 ComfyUI **没有** /v1/videos 这类 OpenAI 端点，且它对 4xx 响应
 *    **不带 CORS 头** → 浏览器跨域时会把状态码吃掉、只报 ERR_NETWORK。
 *    所以本服务必须自己实现 OpenAI 协议，把内部调用走服务端到服务端。
 *
 * 2) H3 的参考图真序（按节点接线，不是节点号升序）：
 *      ref_image_0<-248  1<-249  2<-247  3<-246  4..8<-279..283
 *
 * 3) 音轨一致性：把 ref_audios.* 直连原视频的 AUDIO 出口（VHS_LoadVideo 的 index 2），
 *    旁路掉工作流自带的 LoadAudio->TrimAudioDuration 链。
 *    代价：不再裁音频时长 → seconds 必须 <= 原视频时长。
 *
 * 4) 时长不是简单 seconds*24：工作流用 node 131 的表达式
 *      max(5, round(a*24)) + (5 - (max(5, round(a*24)) % 17)) % 17
 *    把 seconds 写进 node 132（PrimitiveFloat）即可，帧数由节点自己算。
 *
 * 5) ComfyUI 固定 seed 会命中缓存出一样的片 → 每次随机 seed。
 */

const H3_SLOT_IMAGES = ['248', '249', '247', '246', '279', '280', '281', '282', '283'];
const SLOT_VIDEO = '256';
const SLOT_PROMPT = '250';
const SLOT_SECONDS = '132';
const SLOT_ASPECT = '115';
const SLOT_SEED = '129';
const SLOT_STEPS = '184';
const SLOT_SAVE = '180';
const SLOT_H3 = '275';
const SLOT_LENGTH = '131';

// ---------------------------------------------------------------- HTTP 基础

/**
 * 带超时的 HTTP 请求。用 cURL（Hostinger 共享主机不一定有 allow_url_fopen）。
 *
 * @param string $method
 * @param string $url
 * @param array|null $body
 * @param array $headers
 * @param array $opts
 * @return array{status:int, body:string, json:?array, error:?string}
 */
function http_request(string $method, string $url, ?array $body = null, array $headers = [], array $opts = []): array
{
    $ch = curl_init();
    $headerLines = [];
    foreach ($headers as $k => $v) {
        $headerLines[] = $k . ': ' . $v;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => (int) ($opts['timeout'] ?? 120),
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headerLines,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'body' => '', 'json' => null, 'error' => 'curl: ' . $err];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = (string) $raw;
    $json = json_decode($text, true);
    return [
        'status' => $status,
        'body' => $text,
        'json' => is_array($json) ? $json : null,
        'error' => null,
    ];
}

/** 云机请求的统一入口：自动带鉴权头。 */
function comfy_request(string $method, string $path, ?array $body = null, array $headers = [], array $opts = []): array
{
    $headers['User-Agent'] = 'comfy-relay/1.0';
    if (COMFY_TOKEN !== '') {
        $headers['Authorization'] = 'Bearer ' . COMFY_TOKEN;
    }
    return http_request($method, COMFY_BASE . $path, $body, $headers, $opts);
}

// ---------------------------------------------------------------- 文件上传

/**
 * 上传一个本地文件到云机 input 目录，返回云机侧文件名。
 *
 * @param string $localPath 本地临时文件路径
 * @param string $name 云机侧文件名
 * @param string $mime
 */
function comfy_upload(string $localPath, string $name, string $mime = 'application/octet-stream'): array
{
    if (!is_file($localPath)) {
        return ['ok' => false, 'error' => '本地文件不存在: ' . $localPath];
    }
    $tmp = sys_get_temp_dir() . '/cfr_' . bin2hex(random_bytes(6)) . '_' . $name;
    if (!@copy($localPath, $tmp)) {
        return ['ok' => false, 'error' => '复制到临时文件失败'];
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => COMFY_BASE . '/upload/image',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_POST => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => COMFY_TOKEN !== '' ? ['Authorization: Bearer ' . COMFY_TOKEN] : [],
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'image' => new CURLFile($tmp, $mime, $name),
        'type' => 'input',
        'overwrite' => 'true',
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    if ($raw === false) {
        return ['ok' => false, 'error' => '上传失败: ' . $err];
    }
    $json = json_decode((string) $raw, true);
    if ($status !== 200 || !is_array($json) || empty($json['name'])) {
        return ['ok' => false, 'error' => '上传返回异常 HTTP ' . $status . ': ' . substr((string) $raw, 0, 300)];
    }
    return ['ok' => true, 'name' => $json['name'], 'subfolder' => $json['subfolder'] ?? '', 'type' => $json['type'] ?? 'input'];
}

// ---------------------------------------------------------------- H3 提示词

/**
 * 把一句自然语言提示词编排成 H3 的七字段 JSON prompt。
 *
 * H3 的 prompt 必须是 JSON 对象（workflow node 250 是 PrimitiveStringMultiline），
 * 字段固定为：subject_definitions / summary / retention_analysis /
 * detailed_description / overall_soundscape / non_diegetic_music
 *
 * @param string $text 用户提示词
 * @param int $imageCount 参考图数量
 * @param string $mode reference|frames
 * @return string JSON 字符串
 */
function h3_build_prompt(string $text, int $imageCount, string $mode = 'reference'): string
{
    $text = trim($text);
    if ($text === '') {
        $text = 'A short cinematic clip following the reference subject.';
    }

    // 用户已经给了 JSON → 原样使用
    if (str_starts_with($text, '{') && str_ends_with($text, '}')) {
        $probe = json_decode($text, true);
        if (is_array($probe)) {
            return json_encode($probe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    // 按句号/换行切分成多条 subject 定义；单句就一条
    // 中英文句末标点 + 换行 都作为分隔符（中文感叹号/问号同样是句末）
    $parts = preg_split('/[\n\x{3002}\x{FF01}\x{FF1F}!\?]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
    $parts = array_values(array_filter(array_map('trim', $parts), static fn($s) => $s !== ''));

    $subjects = [];
    $retention = [];
    foreach ($parts as $i => $p) {
        $n = $i + 1;
        $subjects[] = "<Subject {$n}> is the person in <Picture {$n}>. {$p}";
        $retention[] = "<Subject {$n}> - fully_preserved - identity, clothing and key features from the reference image are retained.";
    }
    if (!$subjects) {
        $subjects[] = '<Subject 1> is the person in <Picture 1>.';
        $retention[] = '<Subject 1> - fully_preserved - identity, clothing and key features from the reference image are retained.';
    }

    // summary = 整体动作/场景；detailed_description = 镜头与画面细节
    $summary = implode(' ', $parts);
    $detailed = 'Natural cinematic motion, stable facial identity, consistent lighting, '
        . 'the subject performs the described action continuously and coherently across the whole clip.';

    return json_encode([
        'subject_definitions' => $subjects,
        'summary' => $summary,
        'retention_analysis' => $retention,
        'detailed_description' => $detailed,
        'overall_soundscape' => 'N/A',
        'non_diegetic_music' => 'N/A',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** 把 aspect 字符串（16:9 / 3:4 / 480x640 / auto）转成 ComfyUI ResolutionSelector 认的值。 */
function h3_aspect(string $aspect): string
{
    $a = trim($aspect);
    if ($a === '') {
        return '16:9 (Widescreen)';
    }
    if (preg_match('/^(\d+)\s*x\s*(\d+)$/i', $a, $m)) {
        $w = (int) $m[1];
        $h = (int) $m[2];
        $r = $w / max(1, $h);
        if ($r > 2.2) {
            return '21:9 (Cinematic)';
        }
        if ($r > 1.4) {
            return '16:9 (Widescreen)';
        }
        if ($r > 1.1) {
            return '4:3 (Standard)';
        }
        if ($r > 0.9) {
            return '1:1 (Square)';
        }
        if ($r > 0.65) {
            return '3:4 (Portrait)';
        }
        return '9:16 (Vertical)';
    }
    $map = [
        '16:9' => '16:9 (Widescreen)',
        '4:3' => '4:3 (Standard)',
        '1:1' => '1:1 (Square)',
        '3:4' => '3:4 (Portrait)',
        '9:16' => '9:16 (Vertical)',
        '21:9' => '21:9 (Cinematic)',
        'auto' => '16:9 (Widescreen)',
    ];
    return $map[$a] ?? '16:9 (Widescreen)';
}

// ---------------------------------------------------------------- 工作流改写

/**
 * 载入工作流模板。
 */
function comfy_load_workflow(string $name): array
{
    $path = __DIR__ . '/../workflows/' . $name . '.json';
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $wf = json_decode((string) $raw, true);
    return is_array($wf) ? $wf : [];
}

/**
 * 按参考图/视频/参数改写工作流。
 *
 * @param array $wf
 * @param array $imageNames 云机侧参考图文件名（按用户传入顺序）
 * @param string $videoName 云机侧原视频文件名（空 = 纯图生视频）
 * @param string $promptJson
 * @param float $seconds
 * @param int $seed
 * @param int $steps
 * @param string $aspect
 * @param float $megapixels
 */
function comfy_patch_workflow(
    array $wf,
    array $imageNames,
    string $videoName,
    string $promptJson,
    float $seconds,
    int $seed,
    int $steps,
    string $aspect,
    float $megapixels
): array {
    // 1) 参考图 -> LoadImage 节点（按 H3 槽位真序，不按节点号升序）
    foreach (H3_SLOT_IMAGES as $i => $nid) {
        if (!isset($wf[$nid])) {
            continue;
        }
        $name = $imageNames[$i] ?? ($imageNames ? end($imageNames) : null);
        if ($name !== null && $name !== '') {
            $wf[$nid]['inputs']['image'] = $name;
        }
    }

    // 2) 原视频 -> VHS_LoadVideo
    if ($videoName !== '' && isset($wf[SLOT_VIDEO])) {
        $wf[SLOT_VIDEO]['inputs']['video'] = $videoName;
    }

    // 3) prompt
    if (isset($wf[SLOT_PROMPT])) {
        $wf[SLOT_PROMPT]['inputs']['value'] = $promptJson;
    }

    // 4) 时长 / 分辨率 / seed / steps
    if (isset($wf[SLOT_SECONDS])) {
        $wf[SLOT_SECONDS]['inputs']['value'] = max(1.0, $seconds);
    }
    if (isset($wf[SLOT_ASPECT])) {
        $wf[SLOT_ASPECT]['inputs']['aspect_ratio'] = h3_aspect($aspect);
        $wf[SLOT_ASPECT]['inputs']['megapixels'] = $megapixels > 0 ? $megapixels : 0.9;
    }
    if (isset($wf[SLOT_SEED])) {
        $wf[SLOT_SEED]['inputs']['noise_seed'] = $seed;
    }
    if (isset($wf[SLOT_STEPS])) {
        $wf[SLOT_STEPS]['inputs']['value'] = max(1, $steps);
    }

    // 5) 音轨一致性：ref_audios 直连原视频 AUDIO 出口
    if (isset($wf[SLOT_H3])) {
        $h3 = &$wf[SLOT_H3];
        if ($videoName !== '') {
            foreach (array_keys($h3['inputs']) as $key) {
                if (str_starts_with($key, 'ref_audios.')) {
                    $h3['inputs'][$key] = [SLOT_VIDEO, 2];   // VHS_LoadVideo -> AUDIO
                }
            }
        } else {
            // 没有原视频 -> 纯参考图生视频，断开视频/音频槽位
            foreach (array_keys($h3['inputs']) as $key) {
                if (str_starts_with($key, 'ref_videos.') || str_starts_with($key, 'ref_audios.')) {
                    unset($h3['inputs'][$key]);
                }
            }
        }
        unset($h3);
    }

    return $wf;
}

// ---------------------------------------------------------------- 任务账本

function state_dir(): string
{
    if (!is_dir(STATE_DIR)) {
        @mkdir(STATE_DIR, 0775, true);
    }
    return STATE_DIR;
}

function state_file(): string
{
    return state_dir() . '/tasks.json';
}

/**
 * 读取任务账本。task_id => [prompt_id, status, filename, subfolder, created_at, meta]
 */
function state_load(): array
{
    $f = state_file();
    if (!is_file($f)) {
        return [];
    }
    $raw = @file_get_contents($f);
    if ($raw === false || $raw === '') {
        return [];
    }
    $j = json_decode((string) $raw, true);
    return is_array($j) ? $j : [];
}

function state_save(array $st): void
{
    $f = state_file();
    @file_put_contents($f, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

/** 写一条任务记录。 */
function state_put(string $taskId, array $rec): void
{
    $st = state_load();
    $rec['updated_at'] = time();
    $st[$taskId] = $rec;
    state_save($st);
}

function state_get(string $taskId): ?array
{
    $st = state_load();
    return $st[$taskId] ?? null;
}

/** 清理超过 N 小时的旧记录。 */
function state_prune(int $maxAgeSeconds = 86400): void
{
    $st = state_load();
    $now = time();
    foreach ($st as $k => $v) {
        $ts = (int) ($v['updated_at'] ?? $v['created_at'] ?? 0);
        if ($ts > 0 && $now - $ts > $maxAgeSeconds) {
            unset($st[$k]);
        }
    }
    state_save($st);
}
