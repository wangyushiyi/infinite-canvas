<?php
/**
 * ComfyUI 云机渠道适配层 · 配置
 * 敏感文件，禁止直接访问（.htaccess 已加 Deny from all）。
 *
 * 三段式密钥：
 *   ① RELAY_KEY   —— 画布渠道里填的 key（客户端 -> 本服务）
 *   ② COMFY_TOKEN —— 云机侧凭据（服务端 -> 云机），画布永远看不到
 *   ③ COMFY_BASE  —— 云机公网入口
 */
declare(strict_types=1);

// ① 画布「视频渠道」里填的 key
define('RELAY_KEY', 'CHANGE_ME_RELAY_KEY');

// ② 云机入口（AutoDL 实例地址会变，换机后只改这里）
define('COMFY_BASE', 'https://u1002042-794958dd10c3.bjb2.seetacloud.com:8443');

// ③ 云机鉴权头（云机无鉴权时留空串；面板模式需要 Bearer token）
define('COMFY_TOKEN', '');

// ④ 对外基地址（画布渠道的「接口地址」= PUBLIC_BASE + '/v1'）
define('PUBLIC_BASE', 'https://slategrey-vulture-564350.hostingersite.com/comfy-relay');

// ⑤ 云机工作流名（对应 workflows/ 下的文件名，不含 .json）
define('COMFY_VIDEO_WORKFLOW', 'API-U17-9图1视频3音频V1-api');
define('COMFY_IMAGE_WORKFLOW', 'API-U17-9图1视频3音频V1-api');

// ⑥ 轮询间隔与超时（秒）
define('POLL_INTERVAL', 5);
define('POLL_MAX_SECONDS', 1800);

// ⑦ 任务账本目录（保存 prompt_id -> 画布 task id 的映射）
define('STATE_DIR', __DIR__ . '/../state');

// ⑧ 干跑探针开关（true = 只记录请求形态，绝不转发上游、绝不产生视频）
define('DRY_RUN', false);

// ⑨ 允许的画布来源（留空 = 不限制；多个用逗号分隔）
define('ALLOWED_ORIGINS', 'https://infinite-canvas-nine-sandy.vercel.app');
