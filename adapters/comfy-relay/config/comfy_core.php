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

require __DIR__ . '/video_profiles.php';

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
 * @param mixed $body null|string|array（array 会自动 json_encode）
 * @param array $headers
 * @param array $opts
 * @return array{status:int, body:string, json:?array, error:?string}
 */
function http_request(string $method, string $url, $body = null, array $headers = [], array $opts = []): array
{
    if (is_array($body)) {
        $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
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

/**
 * 云机请求的统一入口：自动带鉴权头 + 重试。
 *
 * 重试是必需的（实测）：Hostinger → AutoDL 云机的跨境链路有 ~25% 超时率
 * （延迟 0.6-2s 正常，但会整次超时）。同一主机 → apigo 则稳定在 1s 内，
 * 说明是 AutoDL 公网入口对海外主机的链路质量问题，不是代码问题。
 *
 * 只对幂等/可重试的失败重试：连接错误、5xx、429。
 */
function comfy_request(string $method, string $path, $body = null, array $headers = [], array $opts = []): array
{
    $headers['User-Agent'] = 'comfy-relay/1.0';
    if (COMFY_TOKEN !== '') {
        $headers['Authorization'] = 'Bearer ' . COMFY_TOKEN;
    }
    $retries = (int) ($opts['retries'] ?? 3);
    $last = ['status' => 0, 'body' => '', 'json' => null, 'error' => null];

    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        if ($attempt > 0) {
            // 退避：1s / 2s / 4s
            sleep(1 << ($attempt - 1));
        }
        $last = http_request($method, COMFY_BASE . $path, $body, $headers, $opts);

        // 连接层失败 -> 重试
        if ($last['error'] !== null) {
            continue;
        }
        // 5xx / 429 -> 重试
        if ($last['status'] >= 500 || $last['status'] === 429) {
            continue;
        }
        return $last;
    }

    return $last;
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
    $upHeaders = COMFY_TOKEN !== '' ? ['Authorization: Bearer ' . COMFY_TOKEN] : [];
    // 禁用 Expect: 100-continue —— 部分共享主机的出栈代理不处理 100-continue 握手，
    // 会让 multipart 上传一直挂到超时。
    $upHeaders[] = 'Expect:';
    curl_setopt_array($ch, [
        CURLOPT_URL => COMFY_BASE . '/upload/image',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_POST => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $upHeaders,
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

/**
 * 把 aspect 字符串（16:9 / 3:4 / 480x640 / auto）转成 ComfyUI ResolutionSelector 认的值。
 *
 * ⚠️ 枚举值必须与云机 **实际** 的 ResolutionSelector 完全一致，否则
 *    ComfyUI 会报 "Value not in list: aspect_ratio: '...' not in [...]"，
 *    而这个错误**不会**出现在 /history 里（只写进 comfyui_6006.log），
 *    表现为「success 但 outputs 为空」，极易误判。
 *
 * 云机实测枚举（2026-11-18，ComfyUI 0.44.0）：
 *   1:1 (Square) | 2:3 (Portrait Photo) | 3:2 (Photo) | 3:4 (Portrait Standard)
 *   4:3 (Standard) | 9:16 (Portrait Widescreen) | 16:9 (Widescreen) | 21:9 (Ultrawide)
 */
function h3_aspect(string $aspect): string
{
    static $exact = [
        '1:1 (Square)' => '1:1 (Square)',
        '2:3 (Portrait Photo)' => '2:3 (Portrait Photo)',
        '3:2 (Photo)' => '3:2 (Photo)',
        '3:4 (Portrait Standard)' => '3:4 (Portrait Standard)',
        '4:3 (Standard)' => '4:3 (Standard)',
        '9:16 (Portrait Widescreen)' => '9:16 (Portrait Widescreen)',
        '16:9 (Widescreen)' => '16:9 (Widescreen)',
        '21:9 (Ultrawide)' => '21:9 (Ultrawide)',
    ];
    // 已经是合法枚举值，原样返回
    if (isset($exact[$aspect])) {
        return $aspect;
    }

    $a = trim($aspect);
    if ($a === '') {
        return '16:9 (Widescreen)';
    }
    if (preg_match('/^(\d+)\s*x\s*(\d+)$/i', $a, $m)) {
        $w = (int) $m[1];
        $h = (int) $m[2];
        $r = $w / max(1, $h);
        if ($r > 2.2) {
            return '21:9 (Ultrawide)';
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
        if ($r > 0.62) {
            return '3:4 (Portrait Standard)';
        }
        return '9:16 (Portrait Widescreen)';
    }
    $map = [
        '16:9' => '16:9 (Widescreen)',
        '4:3' => '4:3 (Standard)',
        '3:2' => '3:2 (Photo)',
        '1:1' => '1:1 (Square)',
        '2:3' => '2:3 (Portrait Photo)',
        '3:4' => '3:4 (Portrait Standard)',
        '9:16' => '9:16 (Portrait Widescreen)',
        '21:9' => '21:9 (Ultrawide)',
        'auto' => '16:9 (Widescreen)',
    ];
    return $map[$a] ?? '16:9 (Widescreen)';
}

// ---------------------------------------------------------------- 工作流改写

/**
 * 剔除工作流里的非节点条目。
 *
 * 实测：面板导出的工作流里带一个 "_api_config" 元数据节点（没有 class_type），
 * ComfyUI 的 /prompt 会直接 400：
 *   "Node 'ID #_api_config' has no class_type. The workflow may be corrupted..."
 * 这类条目以 "_" 开头，是元数据不是节点，必须在提交前去掉。
 */
function comfy_strip_meta_nodes(array $wf): array
{
    foreach (array_keys($wf) as $nid) {
        $node = $wf[$nid];
        if (!is_array($node)) {
            unset($wf[$nid]);
            continue;
        }
        if (!isset($node['class_type']) || $node['class_type'] === '' || $node['class_type'] === null) {
            unset($wf[$nid]);
        }
    }
    return $wf;
}

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
    if (!is_array($wf)) {
        return [];
    }
    return comfy_strip_meta_nodes($wf);
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
function comfy_patch_pickref_workflow(
    array $wf,
    array $imageNames,
    string $videoName,
    string $promptJson,
    float $seconds,
    int $seed,
    int $steps,
    string $aspect,
    float $megapixels,
    array $profile
): array {
    $slots = $profile['slots'] ?? [];
    $pickId = (string) ($slots['pick'] ?? '');
    $h3Id = (string) ($slots['h3'] ?? '');
    if ($pickId === '' || !isset($wf[$pickId])) {
        fail(500, '统一入口节点缺失（pick=' . $pickId . '）');
    }

    $ins = &$wf[$pickId]['inputs'];

    // 1) 参考图：填入的写真名，未用的槽显式清空（否则会读到模板自带的示例图）
    for ($i = 0; $i < 9; $i++) {
        $k = 'ref_image_' . $i;
        if (!array_key_exists($k, $ins)) {
            continue;
        }
        $ins[$k] = (string) ($imageNames[$i] ?? '');
    }
    // 2) 参考视频：只有槽 0 用真实文件，其余清空
    for ($i = 0; $i < 3; $i++) {
        $k = 'ref_video_' . $i;
        if (!array_key_exists($k, $ins)) {
            continue;
        }
        $ins[$k] = ($i === 0) ? $videoName : '';
    }
    // 3) 独立音频：统一入口型不接独立音频（音轨走视频音轨槽），全部清空
    for ($i = 0; $i < 3; $i++) {
        $k = 'ref_audio_' . $i;
        if (!array_key_exists($k, $ins)) {
            continue;
        }
        $ins[$k] = '';
    }
    // media_files 是聚合入口，必须清空，否则会与 ref_* 槽重复
    if (array_key_exists('media_files', $ins)) {
        $ins['media_files'] = '[]';
    }

    // 4) 文本与规格
    if (array_key_exists('prompt', $ins)) {
        $ins['prompt'] = $promptJson;
    }
    if (array_key_exists('seconds', $ins)) {
        $ins['seconds'] = max(1.0, $seconds);
    }
    if (array_key_exists('aspect_ratio', $ins)) {
        $ins['aspect_ratio'] = h3_aspect($aspect);
    }
    if (array_key_exists('megapixels', $ins)) {
        $ins['megapixels'] = $megapixels > 0 ? $megapixels : 0.9;
    }
    unset($ins);

    // 5) seed
    if (!empty($slots['seed']) && isset($wf[$slots['seed']]['inputs'])) {
        $wf[$slots['seed']]['inputs']['noise_seed'] = $seed;
    }

    // 6) H3 侧音轨接线
    if ($h3Id !== '' && isset($wf[$h3Id])) {
        $h3 = &$wf[$h3Id];
        if ($videoName !== '') {
            // ★ 音轨锁定：接原视频自带音轨（Pick 的 12 号输出）
            $h3['inputs']['ref_video_audios.ref_video_audio_0'] = [$pickId, 12];
            // 独立音频槽断开（否则模型会拿音色参考重新念一遍）
            foreach (array_keys($h3['inputs']) as $key) {
                if (str_starts_with($key, 'ref_audios.')) {
                    unset($h3['inputs'][$key]);
                }
            }
        } else {
            // 没有原视频 -> 视频/音频槽全部断开。
            // 断开后 Pick 的对应输出没有下游，H3 拿不到空视频参考，
            // 同时 LoadAudio 链不会执行（也就不会引用云机上不存在的音频文件）。
            foreach (array_keys($h3['inputs']) as $key) {
                if (str_starts_with($key, 'ref_videos.')
                    || str_starts_with($key, 'ref_audios.')
                    || str_starts_with($key, 'ref_video_audios.')) {
                    unset($h3['inputs'][$key]);
                }
            }
        }
        unset($h3);
    }

    return $wf;
}

/**
 * 按档案改写工作流。
 *
 * profile['mode']:
 *   pickref -> 统一入口型（见 comfy_patch_pickref_workflow）
 *   slots   -> 真序接线型：LoadImage / LoadAudio / VHS_LoadVideo 直接喂 H3 的 ref_* 槽
 *
 * profile['slots'] 给出每个工作流自己的槽位节点号（由 analyze_u_slots.py 自动反推）。
 * 缺省时回落到 U17 的硬编码常量（保持向后兼容）。
 *
 * @param array $wf
 * @param array $imageNames
 * @param string $videoName
 * @param string $promptJson
 * @param float $seconds
 * @param int $seed
 * @param int $steps
 * @param string $aspect
 * @param float $megapixels
 * @param array $profile
 * @return array
 */

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
function comfy_patch_workflow(
    array $wf,
    array $imageNames,
    string $videoName,
    string $promptJson,
    float $seconds,
    int $seed,
    int $steps,
    string $aspect,
    float $megapixels,
    array $profile = []
): array {
    $mode = (string) ($profile['mode'] ?? 'slots');
    if ($mode === 'pickref') {
        return comfy_patch_pickref_workflow(
            $wf,
            $imageNames,
            $videoName,
            $promptJson,
            $seconds,
            $seed,
            $steps,
            $aspect,
            $megapixels,
            $profile
        );
    }

    $slots = $profile['slots'] ?? [];
    $h3Id = (string) ($slots['h3'] ?? SLOT_H3);

    // 1) 参考图 -> LoadImage 节点（槽位顺序由档案给出，不按节点号升序）
    $imgMap = $slots['images'] ?? [];
    if (!$imgMap) {
        foreach (H3_SLOT_IMAGES as $i => $nid) {
            $imgMap[(string) $i] = $nid;
        }
    }
    foreach ($imgMap as $idx => $nid) {
        if (!isset($wf[$nid])) {
            continue;
        }
        $wf[$nid]['inputs']['image'] = (string) ($imageNames[(int) $idx] ?? '');
    }

    // 2) 原视频 -> VHS_LoadVideo（只有槽 0 用真实文件）
    $vidMap = $slots['videos'] ?? [];
    if (!$vidMap && isset($wf[SLOT_VIDEO])) {
        $vidMap = ['0' => SLOT_VIDEO];
    }
    foreach ($vidMap as $idx => $nid) {
        if (!isset($wf[$nid])) {
            continue;
        }
        $wf[$nid]['inputs']['video'] = ((int) $idx === 0) ? $videoName : '';
    }

    // 3) prompt
    $promptSlot = $slots['prompt'] ?? null;
    if (is_array($promptSlot) && isset($wf[$promptSlot[0]]['inputs'][$promptSlot[1]])) {
        $wf[$promptSlot[0]]['inputs'][$promptSlot[1]] = $promptJson;
    } elseif (isset($wf[SLOT_PROMPT]['inputs']['value'])) {
        $wf[SLOT_PROMPT]['inputs']['value'] = $promptJson;
    }

    // 4) 时长 / 分辨率 / seed / steps
    $durNode = (string) ($slots['duration'] ?? SLOT_SECONDS);
    if (isset($wf[$durNode]['inputs']['value'])) {
        $wf[$durNode]['inputs']['value'] = max(1.0, $seconds);
    }
    $aspNode = (string) ($slots['aspect'] ?? SLOT_ASPECT);
    if (isset($wf[$aspNode]['inputs'])) {
        $wf[$aspNode]['inputs']['aspect_ratio'] = h3_aspect($aspect);
        $wf[$aspNode]['inputs']['megapixels'] = $megapixels > 0 ? $megapixels : 0.9;
    }
    $seedNode = (string) ($slots['seed'] ?? SLOT_SEED);
    if (isset($wf[$seedNode]['inputs'])) {
        $wf[$seedNode]['inputs']['noise_seed'] = $seed;
    }
    if (isset($wf[SLOT_STEPS]['inputs']['value'])) {
        $wf[SLOT_STEPS]['inputs']['value'] = max(1, $steps);
    }

    // 5) 音轨一致性：把 ref_audios.* 直连原视频的 AUDIO 出口（VHS_LoadVideo 的 index 2），
    //    旁路掉工作流自带的 LoadAudio->TrimAudioDuration 链。
    //    没有原视频时断开视频/音频槽 -> 对应加载节点变成孤儿节点不会执行，
    //    也就不会因为引用了云机上不存在的音频文件而报错。
    if (isset($wf[$h3Id])) {
        $h3 = &$wf[$h3Id];
        if ($videoName !== '') {
            $vidNode = (string) ($vidMap['0'] ?? SLOT_VIDEO);
            foreach (array_keys($h3['inputs']) as $key) {
                if (str_starts_with($key, 'ref_audios.') || str_starts_with($key, 'ref_video_audios.')) {
                    $h3['inputs'][$key] = [$vidNode, 2];   // VHS_LoadVideo -> AUDIO
                }
            }
        } else {
            foreach (array_keys($h3['inputs']) as $key) {
                if (str_starts_with($key, 'ref_videos.')
                    || str_starts_with($key, 'ref_audios.')
                    || str_starts_with($key, 'ref_video_audios.')) {
                    unset($h3['inputs'][$key]);
                }
            }
        }
        unset($h3);
    }

    return $wf;
}

// ---------------------------------------------------------------- 任务账本
