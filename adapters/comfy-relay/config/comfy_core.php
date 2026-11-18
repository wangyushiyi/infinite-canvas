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

// 无原视频时的占位文件：云机镜像自带，且 u00-naonao 模板就把它当默认参考视频。
// VHS_LoadVideo 读空串会把 '' 拼成 /root/ComfyUI/input 目录交给 cv2 -> ValueError。
// 塞这个文件让加载节点有合法输入、旁路链（如 clearCacheAll sink）能跑通；
// H3 的 ref_videos. 与 ref_audios. 两组槽仍会被断开，占位视频画面/音轨不进入生成，出片语义不变。
const COMFY_PLACEHOLDER_VIDEO = 'zealman-blank-video.mp4';

// 无参考图时的占位文件：云机镜像自带，u24 等 9 图槽模板就用它填未用的槽。
// LoadImage 读空串会把空路径拼成 /root/ComfyUI/input 目录交给 pyav -> IsADirectoryError。
// 塞这个文件让加载节点有合法输入；空白图不引入人物或物体特征，不影响出片主体。
const COMFY_PLACEHOLDER_IMAGE = 'zealman-blank-image.png';

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

// ---------------------------------------------------------------- 上游探活

/**
 * 探活上游云机，并把「云机死了」翻译成人话。
 *
 * 为什么需要（2026-11-18 实测）：
 *   AutoDL 实例是按小时计费的临时机，回收后网关仍然在线，但**所有路径秒回 404**，
 *   响应体是 AutoDL 的一整页 HTML（"查看FAQ文档"）。
 *   旧行为是把这段 HTML 原样塞进 error.message 抛给画布 ->
 *   画布弹出一屏 HTML 源码，用户完全看不懂。
 *   现在统一探测 + 给出可执行的处置建议。
 *
 * 判别码（与 scripts/switch_cloud.py 的口径一致）：
 *   200 -> 通
 *   404 -> 网关不认这个实例（实例ID/区域不对，或实例已回收）
 *   307 -> 实例在，但端口映射没开
 *   0   -> 连不上
 *
 * @param int $timeout 单次请求超时（秒）
 * @return array{alive:bool, status:int, reason:string, hint:string, latency_ms:int}
 */
function comfy_upstream_probe(int $timeout = 10): array
{
    $t0 = microtime(true);
    $r = http_request('GET', COMFY_BASE . '/system_stats', null, [], ['timeout' => $timeout]);
    $ms = (int) ((microtime(true) - $t0) * 1000);
    $status = (int) $r['status'];
    $body = (string) $r['body'];

    // 云机真的活着：ComfyUI 的 /system_stats 一定返回 JSON，且带 system 段
    if ($status === 200 && is_array($r['json']) && isset($r['json']['system'])) {
        return [
            'alive' => true,
            'status' => 200,
            'reason' => '云机正常',
            'hint' => '',
            'latency_ms' => $ms,
        ];
    }

    // 响应体是 AutoDL 的 HTML 错误页 -> 实例不存在 / 已回收
    $looksLikeGatewayPage = (stripos($body, 'autodl') !== false
        && stripos($body, '<html') !== false);

    if ($status === 404) {
        $reason = '云机网关返回 404';
        $hint = $looksLikeGatewayPage
            ? 'AutoDL 实例已回收或实例ID/区域不对（网关回的是 AutoDL 的 HTML 404 页）。'
              . '换新实例后运行：python scripts/switch_cloud.py --base https://u1002042-<新实例ID>.<区域>.seetacloud.com:8443'
            : '云机网关 404：实例ID 或区域不对。域名格式应为 u1002042-<实例ID>.<区域>.seetacloud.com。';
    } elseif ($status === 307) {
        $reason = '云机实例在，但端口映射没开';
        $hint = '去 AutoDL 控制台「自定义服务」开一条指向 8443 的端口映射。';
    } elseif ($status === 0) {
        $reason = '连不上云机';
        $hint = '上游地址不可达：实例可能已关机/回收，或 8443 端口映射未开。'
              . '换新实例后运行 scripts/switch_cloud.py --base <新地址>。';
    } else {
        $reason = '云机响应异常 HTTP ' . $status;
        $hint = '云机网关返回了非预期状态码，检查实例状态与端口映射。';
    }

    return [
        'alive' => false,
        'status' => $status,
        'reason' => $reason,
        'hint' => $hint,
        'latency_ms' => $ms,
    ];
}

/**
 * 净化上游返回的错误体：把 HTML 页面换成一句话。
 *
 * 旧行为直接 substr($raw, 0, 300) 抛出去 -> 画布弹一屏 HTML。
 * 现在识别 HTML 特征（<html / <body / <!DOCTYPE）并替换为可读说明。
 */
function comfy_clean_error_body(string $raw, int $max = 300): string
{
    $t = trim($raw);
    if ($t === '') {
        return '(空响应体)';
    }
    $isHtml = (stripos($t, '<html') !== false
        || stripos($t, '<body') !== false
        || stripos($t, '<!doctype') !== false);
    if ($isHtml) {
        // 抽出 <title> / <h1> 里的文字作为线索
        $hint = '';
        if (preg_match('/<title>(.*?)<\/title>/is', $t, $m)) {
            $hint = trim(strip_tags($m[1]));
        } elseif (preg_match('/<h1>(.*?)<\/h1>/is', $t, $m)) {
            $hint = trim(strip_tags($m[1]));
        }
        return '(云机网关返回 HTML 错误页'
            . ($hint !== '' ? '：' . $hint : '')
            . ' —— 实例多半已回收或端口映射未开)';
    }
    return substr($t, 0, $max);
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
        return ['ok' => false, 'error' => '上传返回异常 HTTP ' . $status . ': ' . comfy_clean_error_body((string) $raw)];
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

    // 关键（根治「画面描述被当台词念」）：subject_definitions 只锁身份，绝不写动作。
    //   skill `minimax-h3-zh-prompt` 规范第 71 行：「subject_definitions 一行一句，
    //   锁身份，不写动作、运镜、对白」。实测把动作句写进这里（旧版
    //   "<Subject N> is the person in <Picture N>. {动作句}"），H3 会把动作句当台词念出来。
    //   正确落位：动作句写进 detailed_description 当纯视觉动作；不写 <d> 标签即无台词
    //   （规范第 64 行「不要说话 → 不写 <d>」），而不是全局硬性禁人声。
    $subjects = [];
    $retention = [];
    $count = max(1, count($parts));
    for ($i = 0; $i < $count; $i++) {
        $n = $i + 1;
        $subjects[] = "<Subject {$n}> is the person in <Picture {$n}>.";
        $retention[] = "<Subject {$n}> - fully_preserved - identity, clothing and key features from the reference image are retained.";
    }

    // summary = 成片目标：不复述用户动作原文（原文只出现在 detailed 的视觉动作里），
    //   避免 H3 把 summary 的句子也当成要念的内容。
    $summary = 'A short cinematic clip following the reference subject in natural, coherent on-screen motion.';

    // detailed_description = 成片正文：用户动作在这里写成纯视觉动作。
    //   「shown as on-screen body movement only, not as spoken dialogue」只针对本段动作，
    //   不做全局禁人声——画面该有的环境声、现场声仍由 soundscape 决定。
    $actions = implode(' ', $parts);
    $detailed = 'Natural cinematic motion, stable facial identity, consistent lighting. '
        . 'The subject performs the following visible actions: ' . $actions
        . '. All of it is shown as on-screen body movement only, not as spoken dialogue. '
        . 'No on-screen text, no subtitles, no captions, no written words or titles appear anywhere in the frame.';

    // 环境音：从用户描述里提取明确提到的现场声（鸟叫/鸡鸣/溪流/雨…），拼进 soundscape；
    //   没提到就用安静底噪兜底。不再写死 "no human voice / no dialogue"——
    //   要不要人声交给画面本身与 <d> 标签决定，这里只负责环境底噪。
    $ambient = comfy_extract_ambience($text);
    $soundscape = 'Continuous natural ambient bed of the scene: ' . $ambient . '.';

    return json_encode([
        'subject_definitions' => $subjects,
        'summary' => $summary,
        'retention_analysis' => $retention,
        'detailed_description' => $detailed,
        'overall_soundscape' => $soundscape,
        'non_diegetic_music' => 'N/A',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * 从用户描述里提取环境音关键词，映射成 H3 soundscape 的英文描述。
 * 无所提及则返回安静现场底噪兜底。环境音因场景而定，故做成关键词表而非写死。
 */
function comfy_extract_ambience(string $text): string
{
    $text = mb_strtolower($text);
    $table = [
        '鸟叫' => 'distant birdsong', '鸟鸣' => 'distant birdsong', '鸟儿' => 'distant birdsong',
        '鸡叫' => 'rooster crowing', '鸡鸣' => 'rooster crowing',
        '狗叫' => 'dog barking in the distance', '犬吠' => 'dog barking in the distance',
        '虫鸣' => 'insect chirping', '蟋蟀' => 'cricket chirping', '蝉' => 'cicada drones',
        '溪' => 'a nearby stream', '流水' => 'flowing water', '河' => 'river water', '水声' => 'water sounds',
        '雨' => 'light rain', '风' => 'a gentle breeze', '树叶' => 'leaves rustling',
        '乡村' => 'quiet countryside air', '农村' => 'quiet rural air', '田野' => 'open field air',
        '海' => 'distant ocean surf', '浪' => 'ocean waves', '篝火' => 'a crackling fire',
        '脚步' => 'soft footsteps', '车' => 'faint distant traffic', '集市' => 'distant marketplace murmur',
        '宁静' => 'near silence', '安静' => 'near silence', '安静无声' => 'near silence',
        'bird' => 'distant birdsong', 'rain' => 'light rain', 'wind' => 'a gentle breeze',
        'ocean' => 'distant ocean surf', 'stream' => 'a nearby stream', 'fire' => 'a crackling fire',
    ];
    $hits = [];
    foreach ($table as $kw => $desc) {
        if (mb_stripos($text, $kw) !== false && !in_array($desc, $hits, true)) {
            $hits[] = $desc;
        }
    }
    if (!$hits) {
        return 'a very quiet, still room tone with no identifiable source';
    }
    return implode(', ', array_slice($hits, 0, 4));
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

/**
 * 解析画布提交过来的视频规格，让画布的时长/比例/画质真正生效。
 *
 * 画布 createOpenAIVideoTask 传的 multipart 字段：
 *   seconds         : "4".."30"（clampVideoSeconds 已裁过）
 *   size            : "WIDTHxHEIGHT"（normalizeVideoSize 算出的实际像素，如 480x854）
 *   ratio           : "16:9"/"9:16"/.../"auto"（auto 时画布不传这个字段）
 *   resolution_name : "480p"/"720p"
 *
 * 解析优先级：画布值 > 档案默认。auto/缺失/非法才回退档案。
 *   - seconds   : 画布值直接采信（clamp 4..30）
 *   - aspect    : 先取 ratio 枚举；缺失时从 size 的 W/H 比换算；再回退档案
 *   - megapixels: 从 size 的 W*H/1e6 算；缺失时回退档案
 *
 * @return array{seconds:float, aspect:string, megapixels:float}
 */
function comfy_resolve_spec(array $post, array $defaults): array
{
    $defSeconds = (float) ($defaults['seconds'] ?? 5.0);
    $defAspect = h3_aspect((string) ($defaults['aspect'] ?? '16:9'));
    $defMp = (float) ($defaults['megapixels'] ?? 0.9);

    // --- 秒数：画布传了就用（clamp 4..30），否则档案默认
    $seconds = $defSeconds;
    $rawSec = $post['seconds'] ?? '';
    if ($rawSec !== '' && is_numeric($rawSec)) {
        $s = (float) $rawSec;
        if ($s >= 4.0 && $s <= 30.0) {
            $seconds = $s;
        }
    }

    // --- 画布的 size / ratio
    $size = strtolower(trim((string) ($post['size'] ?? '')));
    $ratio = strtolower(trim((string) ($post['ratio'] ?? '')));
    $wh = [];
    if (preg_match('/^(\d+)\s*x\s*(\d+)$/i', $size, $m)) {
        $wh = [(int) $m[1], (int) $m[2]];
    }

    // --- 比例：ratio 枚举优先；否则从 size 的 W/H 比换算；再回退档案
    $aspect = $defAspect;
    if ($ratio !== '' && $ratio !== 'auto') {
        // h3_aspect 会把 "9:16" 这类映射成云机枚举；非法值它回落到 16:9
        $aspect = h3_aspect($ratio);
    } elseif ($wh) {
        $r = $wh[0] / max(1, $wh[1]);
        if ($r > 2.2) {
            $aspect = '21:9 (Ultrawide)';
        } elseif ($r > 1.4) {
            $aspect = '16:9 (Widescreen)';
        } elseif ($r > 1.1) {
            $aspect = '4:3 (Standard)';
        } elseif ($r > 0.9) {
            $aspect = '1:1 (Square)';
        } elseif ($r > 0.62) {
            $aspect = '3:4 (Portrait Standard)';
        } else {
            $aspect = '9:16 (Portrait Widescreen)';
        }
    }

    // --- 百万像素：从 size 的实际像素算（÷1e6，clamp 0.1..16）；否则档案默认
    $megapixels = $defMp;
    if ($wh) {
        $mp = ($wh[0] * $wh[1]) / 1000000.0;
        if ($mp >= 0.1 && $mp <= 16.0) {
            $megapixels = round($mp, 3);
        }
    }

    return ['seconds' => $seconds, 'aspect' => $aspect, 'megapixels' => $megapixels];
}

/**
 * 把一句自然语言提示词编排成 fl2va（U02/U03）用的自然语言 prompt。
 *
 * fl2va 的 H3 节点（MiniMaxH3ImageToVideo）的 prompt 是**纯文本**，
 * 不是 ref2va 的七字段 JSON。实测模板里的 prompt 是这种形状：
 *
 *   <整体风格描述>
 *
 *   Timeline:
 *   [0s-2s] ...
 *   [2s-5s] ...
 *
 *   Audio: ...
 *
 * 所以这里按同样的形状编排：把用户那句话拆成时间轴上的视觉动作。
 *
 * @param string $text 用户提示词
 * @param float $seconds 目标时长
 * @param int $imageCount 参考图数量（U02 首尾帧）
 * @return string
 */
function h3_build_fl2va_prompt(string $text, float $seconds, int $imageCount = 0): string
{
    $text = trim($text);
    if ($text === '') {
        $text = 'A short cinematic clip.';
    }

    // 切句：中文/英文句末标点 + 换行
    $parts = preg_split('/[\n\x{3002}\x{FF01}\x{FF1F}!\?]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
    $parts = array_values(array_filter(array_map('trim', $parts), static fn($s) => $s !== ''));
    if (!$parts) {
        $parts = [$text];
    }

    // 整体风格行
    $style = 'Cinematic live-action footage, natural lighting, stable camera, photorealistic detail.';
    if ($imageCount > 0) {
        $style = 'Cinematic live-action footage, natural lighting, stable camera, photorealistic detail, '
            . 'consistent identity and wardrobe with the reference image.';
    }

    // 时间轴：把句子均分到时长区间里
    $sec = max(1.0, $seconds);
    $n = count($parts);
    $lines = [];
    for ($i = 0; $i < $n; $i++) {
        $from = $sec * $i / $n;
        $to = $sec * ($i + 1) / $n;
        // 时间轴标签用一位小数，与模板一致
        $lines[] = sprintf('[%.1fs-%.1fs] %s', $from, $to, $parts[$i]);
    }

    // 音频：沿用环境音提取（fl2va 也是音画同生成）
    $ambient = comfy_extract_ambience($text);
    $audio = 'Audio: continuous natural ambient bed of the scene: ' . $ambient . '.';

    $tail = 'All actions are shown as on-screen body movement only, not as spoken dialogue. '
        . 'No on-screen text, no subtitles, no captions, no written words or titles appear anywhere in the frame.';

    return $style . "\n\nTimeline:\n" . implode("\n", $lines) . "\n\n" . $audio . "\n\n" . $tail;
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

    // 1) 参考图：填入的写真名，未用的槽塞占位图。
    //    模板默认就是把闲置槽填 zealman-blank-image.png；留空串会让
    //    PickReferenceMedia 内部读空路径抛异常（与 LoadImage 同源）。
    for ($i = 0; $i < 9; $i++) {
        $k = 'ref_image_' . $i;
        if (!array_key_exists($k, $ins)) {
            continue;
        }
        $realImage = (string) ($imageNames[$i] ?? '');
        $ins[$k] = $realImage !== '' ? $realImage : COMFY_PLACEHOLDER_IMAGE;
    }
    // 2) 参考视频：只有槽 0 用真实文件，其余清空。
    //    槽 0 没传视频时塞占位文件（与 u00-naonao 模板默认值一致），
    //    绝不能留空串——否则 PickReferenceMedia 内部读空路径会抛 ValueError。
    for ($i = 0; $i < 3; $i++) {
        $k = 'ref_video_' . $i;
        if (!array_key_exists($k, $ins)) {
            continue;
        }
        if ($i === 0) {
            $ins[$k] = ($videoName !== '') ? $videoName : COMFY_PLACEHOLDER_VIDEO;
        } else {
            $ins[$k] = '';
        }
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
 * 把「比例 + 百万像素」换算成 fl2va 用的整数宽高。
 *
 * fl2va（U02/U03）不用 ResolutionSelector，而是 WJILatentPreset 的
 * 「自定义宽 / 自定义高」，且模板里带「缩放倍数: 32」——
 * 宽高必须是 32 的整数倍，否则 VAE 对不齐会报错。
 *
 * @param string $aspect 云机枚举形式，如 '16:9 (Widescreen)'
 * @param float $megapixels 目标百万像素
 * @return array{0:int, 1:int}
 */
function h3_dimensions(string $aspect, float $megapixels): array
{
    // 从枚举字符串里抠出 "16:9" 前缀
    $ratio = 16.0 / 9.0;
    if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*:\s*(\d+(?:\.\d+)?)/', $aspect, $m)) {
        $ratio = (float) $m[1] / max(0.0001, (float) $m[2]);
    }
    $mp = $megapixels > 0 ? $megapixels : 0.9;
    $total = $mp * 1000000.0;
    $w = (int) round(sqrt($total * $ratio));
    $h = (int) round($total / max(1, $w));
    // 对齐到 32 的整数倍
    $w = max(256, ((int) ($w / 32)) * 32);
    $h = max(256, ((int) ($h / 32)) * 32);
    return [$w, $h];
}

/**
 * fl2va 形态（U02 图生视频 / U03 文生视频）的改写。
 *
 * 与 ref2va（slots 形态）的三处根本差异：
 *  1) H3 主节点是 MiniMaxH3ImageToVideo，**prompt 是内联字符串**
 *     （不是连线到 PrimitiveStringMultiline）—— 直接覆盖 inputs.prompt。
 *  2) 宽高走 WJILatentPreset 的「自定义宽 / 自定义高」，不是 ResolutionSelector。
 *  3) 没有 ref_* 动态槽；U02 有 first_frame/last_frame，U03 纯文生视频。
 *
 * @param array $wf
 * @param array $imageNames 参考图文件名（U02 用第 1 张当首帧、第 2 张当尾帧）
 * @param string $promptText 提示词（fl2va 用自然语言，不是七字段 JSON）
 * @param array $profile
 * @return array
 */
function comfy_patch_fl2va_workflow(
    array $wf,
    array $imageNames,
    string $promptText,
    float $seconds,
    int $seed,
    int $steps,
    string $aspect,
    float $megapixels,
    array $profile = []
): array {
    $slots = $profile['slots'] ?? [];
    $h3Id = (string) ($slots['h3'] ?? '');
    if ($h3Id === '' || !isset($wf[$h3Id])) {
        fail(500, 'fl2va 工作流缺少 H3 主节点（h3=' . $h3Id . '）');
    }

    // 1) prompt：内联字符串，直接覆盖
    $wf[$h3Id]['inputs']['prompt'] = $promptText;

    // 2) 宽高 -> WJILatentPreset（自定义宽/高，必须 32 的整数倍）
    $resNode = (string) ($slots['resolution'] ?? '');
    if ($resNode !== '' && isset($wf[$resNode]['inputs'])) {
        [$w, $h] = h3_dimensions($aspect, $megapixels);
        $wf[$resNode]['inputs']['预设分辨率'] = '自定义';
        $wf[$resNode]['inputs']['自定义宽'] = $w;
        $wf[$resNode]['inputs']['自定义高'] = $h;
    }

    // 3) 时长 -> PrimitiveFloat（下游 ComfyMathExpression 自己算帧数）
    $durNode = (string) ($slots['duration'] ?? '');
    if ($durNode !== '' && isset($wf[$durNode]['inputs']['value'])) {
        $wf[$durNode]['inputs']['value'] = max(1.0, $seconds);
    }

    // 4) seed
    $seedNode = (string) ($slots['seed'] ?? '');
    if ($seedNode !== '' && isset($wf[$seedNode]['inputs'])) {
        $wf[$seedNode]['inputs']['noise_seed'] = $seed;
    }

    // 5) steps（fl2va 的 steps 在 BasicScheduler 里，不是 PrimitiveInt）
    $stepNode = (string) ($slots['steps'] ?? '');
    if ($stepNode !== '' && isset($wf[$stepNode]['inputs'])) {
        if (array_key_exists('steps', $wf[$stepNode]['inputs'])) {
            $wf[$stepNode]['inputs']['steps'] = max(1, $steps);
        }
    }

    // 6) 首帧 / 尾帧（U02 有，U03 没有）
    foreach ([['first_frame', 0], ['last_frame', 1]] as $pair) {
        $key = $pair[0];
        $idx = $pair[1];
        $nid = (string) ($slots[$key] ?? '');
        if ($nid === '' || !isset($wf[$nid]['inputs'])) {
            continue;
        }
        $real = (string) ($imageNames[$idx] ?? '');
        // 没有对应图时塞占位图：绝不能留空串（LoadImage 读空路径会
        // 把空路径拼成 /root/ComfyUI/input 目录交给 pyav -> IsADirectoryError）
        $wf[$nid]['inputs']['image'] = $real !== '' ? $real : COMFY_PLACEHOLDER_IMAGE;
    }

    return $wf;
}

/**
 * 聚合形态（U11 图片+音频对口型）的改写。
 *
 * 与 slots 形态的差异：
 *  1) H3 的 ref_images 是**整体接线**（不是 ref_images.ref_image_N 分槽），
 *     源头是 ZealmanH3LoadImageToRefImages 聚合节点 -> 只改它的 image_1。
 *  2) 音频走 ref_audios.ref_audio_0，源头是 VHS_LoadAudioUpload。
 *  3) prompt 由 LLM 链（ZealmanLLM_Generate）现场生成，我们不覆盖它，
 *     只在档案指定时改它的输入。
 *
 * @param array $wf
 * @param array $imageNames
 * @param string $audioName 云机侧音频文件名（可为空）
 * @param string $promptText
 * @param array $profile
 * @return array
 */
function comfy_patch_aggregate_workflow(
    array $wf,
    array $imageNames,
    string $audioName,
    string $promptText,
    float $seconds,
    int $seed,
    int $steps,
    string $aspect,
    float $megapixels,
    array $profile = []
): array {
    $slots = $profile['slots'] ?? [];
    $h3Id = (string) ($slots['h3'] ?? '');
    if ($h3Id === '' || !isset($wf[$h3Id])) {
        fail(500, '聚合工作流缺少 H3 主节点（h3=' . $h3Id . '）');
    }

    // 1) 图片 -> 聚合节点的 image_1 -> H3.ref_images
    $aggNode = (string) ($slots['aggregate'] ?? '');
    $imgNode = (string) ($slots['image'] ?? '');
    if ($imgNode !== '' && isset($wf[$imgNode]['inputs'])) {
        $real = (string) ($imageNames[0] ?? '');
        $wf[$imgNode]['inputs']['image'] = $real !== '' ? $real : COMFY_PLACEHOLDER_IMAGE;
    }
    if ($aggNode !== '' && isset($wf[$aggNode]['inputs'])) {
        // 聚合节点里除 image_1 外的其它 image_N 一律断开，
        // 否则它会去读云机上不存在的图片文件。
        foreach (array_keys($wf[$aggNode]['inputs']) as $k) {
            if (preg_match('/^image_\d+$/', $k) && $k !== 'image_1') {
                unset($wf[$aggNode]['inputs'][$k]);
            }
        }
        // 聚合节点还带 video_/audio_ 槽，这里不喂视频，只保留 image
        foreach (array_keys($wf[$aggNode]['inputs']) as $k) {
            if (str_starts_with($k, 'video_') || str_starts_with($k, 'audio_')) {
                unset($wf[$aggNode]['inputs'][$k]);
            }
        }
    }

    // 2) 音频 -> VHS_LoadAudioUpload -> H3.ref_audios.ref_audio_0
    $audNode = (string) ($slots['audio'] ?? '');
    if ($audNode !== '' && isset($wf[$audNode]['inputs'])) {
        if ($audioName !== '') {
            $wf[$audNode]['inputs']['audio'] = $audioName;
            // 音频时长决定出片长度（下游 ComfyMathExpression 从音频读秒数）
            if (isset($wf[$audNode]['inputs']['duration'])) {
                $wf[$audNode]['inputs']['duration'] = max(1, (int) ceil($seconds));
            }
        } else {
            // 没有音频 -> 断开 H3 的音频槽，否则模型会去读云机上的默认音频
            unset($wf[$h3Id]['inputs']['ref_audios.ref_audio_0']);
            unset($wf[$h3Id]['inputs']['ref_audios.ref_audio_1']);
        }
    }

    // 3) 宽高 -> WJILatentPreset
    $resNode = (string) ($slots['resolution'] ?? '');
    if ($resNode !== '' && isset($wf[$resNode]['inputs'])) {
        [$w, $h] = h3_dimensions($aspect, $megapixels);
        $wf[$resNode]['inputs']['预设分辨率'] = '自定义';
        $wf[$resNode]['inputs']['自定义宽'] = $w;
        $wf[$resNode]['inputs']['自定义高'] = $h;
    }

    // 4) seed
    $seedNode = (string) ($slots['seed'] ?? '');
    if ($seedNode !== '' && isset($wf[$seedNode]['inputs'])) {
        $wf[$seedNode]['inputs']['noise_seed'] = $seed;
    }

    // 5) steps
    $stepNode = (string) ($slots['steps'] ?? '');
    if ($stepNode !== '' && isset($wf[$stepNode]['inputs'])
        && array_key_exists('steps', $wf[$stepNode]['inputs'])) {
        $wf[$stepNode]['inputs']['steps'] = max(1, $steps);
    }

    // 6) prompt：默认不动（由 LLM 链生成）；档案指定了才覆盖 LLM 的输入提示词
    $promptNode = (string) ($slots['prompt'] ?? '');
    if ($promptNode !== '' && isset($wf[$promptNode]['inputs'])) {
        $key = (string) ($slots['prompt_key'] ?? '提示词');
        if (array_key_exists($key, $wf[$promptNode]['inputs'])) {
            $wf[$promptNode]['inputs'][$key] = $promptText;
        }
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
    if ($mode === 'fl2va') {
        // fl2va 的 prompt 是自然语言，不是七字段 JSON —— 单独传。
        return comfy_patch_fl2va_workflow(
            $wf,
            $imageNames,
            (string) ($profile['prompt_text'] ?? $promptJson),
            $seconds,
            $seed,
            $steps,
            $aspect,
            $megapixels,
            $profile
        );
    }
    if ($mode === 'aggregate') {
        return comfy_patch_aggregate_workflow(
            $wf,
            $imageNames,
            (string) ($profile['audio_name'] ?? ''),
            (string) ($profile['prompt_text'] ?? $promptJson),
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
        // 只有真传了的图槽用真实文件，其余一律塞占位图。
        // 绝不能留空串：LoadImage 读空路径会拼成 /root/ComfyUI/input 目录，
        // pyav 拿目录当文件读抛 IsADirectoryError（与视频槽同源的 bug）。
        $realImage = (string) ($imageNames[(int) $idx] ?? '');
        $wf[$nid]['inputs']['image'] = $realImage !== '' ? $realImage : COMFY_PLACEHOLDER_IMAGE;
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
        // 只有槽 0 吃真实视频；其余槽、以及"压根没传视频"时的槽 0，
        // 一律塞占位文件——绝不能留空串，否则 VHS_LoadVideo 会把空路径
        // 拼成 /root/ComfyUI/input 目录交给 cv2，抛 ValueError 中断出片。
        $realVideo = ((int) $idx === 0 && $videoName !== '') ? $videoName : '';
        $wf[$nid]['inputs']['video'] = $realVideo !== '' ? $realVideo : COMFY_PLACEHOLDER_VIDEO;
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
