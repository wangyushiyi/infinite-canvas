<?php
/**
 * 视频模型档案表（由 analyze_u_slots.py + gen_video_profiles.py 自动生成，勿手改）
 * ------------------------------------------------------------------
 * 画布侧只要在渠道里填「模型名」，中继就按名字选工作流 + 改写策略，
 * 不需要在画布挂任何脚本。新增一个 U 系 = 这里加一条 + workflows/ 放一份 JSON。
 *
 * mode:
 *   pickref —— 统一入口型：ZealmanH3PickReferenceMedia 一个节点收下
 *              9图 + 3视频 + 3音 + prompt + 时长 + 比例 + 像素数
 *   slots   —— 真序接线型：LoadImage / LoadAudio / VHS_LoadVideo
 *              直接喂 H3 的 ref_* 动态槽（槽位节点号逐工作流不同）
 *
 * slots 字段说明：
 *   images/audios/videos : H3 槽序号 => 源节点号
 *   h3                    : H3 主节点号
 *   prompt                : [节点号, 输入键]  (value | prompt)
 *   duration              : 时长节点号（PrimitiveFloat）
 *   seed                  : seed 节点号（RandomNoise）
 *   aspect                : 分辨率节点号（ResolutionSelector）
 *   pick                  : 统一入口节点号（仅 pickref 模式）
 *   video_audio_source    : 视频音轨出口槽（VHS_LoadVideo 的 index 2）
 */
declare(strict_types=1);

function comfy_video_profiles(): array
{
    return [
        'minimax-h3-video-u17' => [
            'file' => 'u17',
            'mode' => 'slots',
            'label' => 'U17 · 9图1视频3音 · 唯一支持原视频+音轨锁定 · 5s',
            'defaults' => ['seconds' => 5.0, 'aspect' => '16:9', 'megapixels' => 0.9, 'steps' => 8],
            'slots' => ['h3' => '275', 'images' => ['0' => '248', '1' => '249', '2' => '247', '3' => '246', '4' => '279', '5' => '280', '6' => '281', '7' => '282', '8' => '283'], 'audios' => ['0' => '259', '1' => '260', '2' => '261'], 'videos' => ['0' => '256'], 'prompt' => ['250', 'value'], 'duration' => '132', 'seed' => '129', 'aspect' => '115'],
            'supports_video' => true,
        ],
        'minimax-h3-video-u00' => [
            'file' => 'u00-naonao',
            'mode' => 'pickref',
            'label' => 'U00 闹闹版 · 9图1视频3音 · 10s · 16:9 · 0.4MP · 节点最少跑得最快',
            'defaults' => ['seconds' => 10.0, 'aspect' => '16:9', 'megapixels' => 0.4, 'steps' => 8],
            'slots' => ['pick' => '259', 'h3' => '303'],
            'supports_video' => true,
        ],
        'minimax-h3-video-u01' => [
            'file' => 'u01-basic',
            'mode' => 'slots',
            'label' => 'U01 基础版 · 3图 · 无音频 · 最轻',
            'defaults' => ['seconds' => 5.0, 'aspect' => '16:9', 'megapixels' => 0.9, 'steps' => 8],
            'slots' => ['h3' => '136', 'images' => ['0' => '137', '1' => '139', '2' => '141'], 'audios' => [], 'videos' => [], 'prompt' => ['146', 'prompt'], 'duration' => '147', 'seed' => '129'],
            'supports_video' => false,
        ],
        'minimax-h3-video-u06' => [
            'file' => 'u06-9img3aud-v5',
            'mode' => 'slots',
            'label' => 'U06 V5 · 9图3音 · 5s · turbo 4step',
            'defaults' => ['seconds' => 5.0, 'aspect' => '16:9', 'megapixels' => 0.9, 'steps' => 8],
            'slots' => ['h3' => '136', 'images' => ['0' => '137', '1' => '139', '2' => '144', '3' => '151', '4' => '653', '5' => '654', '6' => '655', '7' => '656', '8' => '657'], 'audios' => ['0' => '719', '1' => '720', '2' => '721'], 'videos' => [], 'prompt' => ['664', 'prompt'], 'duration' => '132', 'seed' => '727'],
            'supports_video' => false,
        ],
        'minimax-h3-video-u06-pure' => [
            'file' => 'u06-9img-v5',
            'mode' => 'slots',
            'label' => 'U06 9图版 V5 · 9图无音频 · turbo 8step',
            'defaults' => ['seconds' => 5.0, 'aspect' => '16:9', 'megapixels' => 0.9, 'steps' => 8],
            'slots' => ['h3' => '136', 'images' => ['0' => '137', '1' => '139', '2' => '144', '3' => '151', '4' => '653', '5' => '654', '6' => '655', '7' => '656', '8' => '657'], 'audios' => [], 'videos' => [], 'prompt' => ['664', 'prompt'], 'duration' => '132', 'seed' => '727'],
            'supports_video' => false,
        ],
        'minimax-h3-video-u06-multi' => [
            'file' => 'u06-multiparam',
            'mode' => 'slots',
            'label' => 'U06 无加速多参 · 9图3音 · 开放参数最多(18) · steps 可调',
            'defaults' => ['seconds' => 5.0, 'aspect' => '16:9', 'megapixels' => 0.9, 'steps' => 8],
            'slots' => ['h3' => '1312', 'images' => ['0' => '137', '1' => '139', '2' => '144', '3' => '151', '4' => '653', '5' => '654', '6' => '655', '7' => '656', '8' => '657'], 'audios' => ['0' => '719', '1' => '720', '2' => '721'], 'videos' => [], 'prompt' => ['664', 'prompt'], 'duration' => '132', 'seed' => '727', 'aspect' => '665'],
            'supports_video' => false,
        ],
        'minimax-h3-video-u06v9' => [
            'file' => 'u06v9',
            'mode' => 'pickref',
            'label' => 'U06V9 · 9图3音 · 15s · Singularity 模型',
            'defaults' => ['seconds' => 15.0, 'aspect' => '16:9', 'megapixels' => 1.2, 'steps' => 8],
            'slots' => ['pick' => '3892', 'h3' => '3950'],
            'supports_video' => false,
        ],
        'minimax-h3-video-u07' => [
            'file' => 'u07-a',
            'mode' => 'slots',
            'label' => 'U07-A · 9图3音 · 唯一支持 3 路参考视频',
            'defaults' => ['seconds' => 15.0, 'aspect' => '16:9', 'megapixels' => 0.9, 'steps' => 8],
            'slots' => ['h3' => '333', 'images' => ['0' => '375', '1' => '376', '2' => '377', '3' => '378', '4' => '379', '5' => '380', '6' => '381', '7' => '382', '8' => '383'], 'audios' => ['0' => '384', '1' => '385', '2' => '386'], 'videos' => ['0' => '387', '1' => '388', '2' => '389'], 'prompt' => ['312', 'value'], 'duration' => '323', 'seed' => '321'],
            'supports_video' => true,
        ],
        'minimax-h3-video-u08' => [
            'file' => 'u08',
            'mode' => 'slots',
            'label' => 'U08 · 9图3音 · 唯一带超分链(MMH3UltimateUpscale)',
            'defaults' => ['seconds' => 5.0, 'aspect' => '16:9', 'megapixels' => 0.9, 'steps' => 8],
            'slots' => ['h3' => '108', 'images' => ['0' => '104', '1' => '105', '2' => '139', '3' => '140', '4' => '141', '5' => '142', '6' => '143', '7' => '144', '8' => '145'], 'audios' => [], 'videos' => [], 'prompt' => ['103', 'value'], 'duration' => '8', 'seed' => '11', 'aspect' => '7'],
            'supports_video' => false,
        ],
        'minimax-h3-video-u09' => [
            'file' => 'u09',
            'mode' => 'slots',
            'label' => 'U09 · 9图无音频 · 音视频潜空间拼接 · 节点最多',
            'defaults' => ['seconds' => 5.0, 'aspect' => '16:9', 'megapixels' => 0.9, 'steps' => 8],
            'slots' => ['h3' => '186', 'images' => ['0' => '137', '1' => '139', '2' => '146', '3' => '147', '4' => '148', '5' => '149', '6' => '150', '7' => '151', '8' => '170'], 'audios' => [], 'videos' => [], 'prompt' => ['138', 'value'], 'duration' => '132', 'seed' => '129', 'aspect' => '320'],
            'supports_video' => false,
        ],
        'minimax-h3-video-u22' => [
            'file' => 'u22',
            'mode' => 'pickref',
            'label' => 'U22 · 9图3音 · 10s · 16:9 · 1.5MP · 节点最少(16)',
            'defaults' => ['seconds' => 10.0, 'aspect' => '16:9', 'megapixels' => 1.5, 'steps' => 8],
            'slots' => ['pick' => '403', 'h3' => '265'],
            'supports_video' => false,
        ],
        'minimax-h3-video-u22v1' => [
            'file' => 'u22v1',
            'mode' => 'pickref',
            'label' => 'U22 V1 · 9图3音 · 10s · 9:16 竖屏 · 0.7MP',
            'defaults' => ['seconds' => 10.0, 'aspect' => '9:16', 'megapixels' => 0.7, 'steps' => 8],
            'slots' => ['pick' => '290', 'h3' => '265'],
            'supports_video' => false,
        ],
        'minimax-h3-video-u24' => [
            'file' => 'u24',
            'mode' => 'slots',
            'label' => 'U24 文武双修 · 9图3音 · 10s · 动作戏专用 H3_Combat_V2',
            'defaults' => ['seconds' => 10.0, 'aspect' => '16:9', 'megapixels' => 0.4, 'steps' => 8],
            'slots' => ['h3' => '95', 'images' => ['0' => '66', '1' => '65', '2' => '58', '3' => '59', '4' => '60', '5' => '61', '6' => '62', '7' => '63', '8' => '64'], 'audios' => [], 'videos' => [], 'prompt' => ['28', 'prompt'], 'duration' => '27', 'seed' => '11', 'aspect' => '29'],
            'supports_video' => false,
        ],
        'minimax-h3-video-u24-t8' => [
            'file' => 'u24-t8',
            'mode' => 'slots',
            'label' => 'U24 T8 版 · 9图3音 · 10s · 动作戏专用 · T8 采样器',
            'defaults' => ['seconds' => 10.0, 'aspect' => '16:9', 'megapixels' => 0.4, 'steps' => 8],
            'slots' => ['h3' => '95', 'images' => ['0' => '66', '1' => '65', '2' => '58', '3' => '59', '4' => '60', '5' => '61', '6' => '62', '7' => '63', '8' => '64'], 'audios' => [], 'videos' => [], 'prompt' => ['28', 'prompt'], 'duration' => '27', 'seed' => '11', 'aspect' => '29'],
            'supports_video' => false,
        ],
        'minimax-h3-video-u30' => [
            'file' => 'u30',
            'mode' => 'pickref',
            'label' => 'U30 Singularity · 9图3音 · 10s · 16:9 · 0.7MP',
            'defaults' => ['seconds' => 10.0, 'aspect' => '16:9', 'megapixels' => 0.7, 'steps' => 8],
            'slots' => ['pick' => '259', 'h3' => '303'],
            'supports_video' => false,
        ],
        'minimax-h3-video-u32' => [
            'file' => 'u32',
            'mode' => 'slots',
            'label' => 'U32 · 9图无音频 · 打斗武器特化',
            'defaults' => ['seconds' => 10.0, 'aspect' => '16:9', 'megapixels' => 2.0, 'steps' => 8],
            'slots' => ['h3' => '90', 'images' => ['0' => '86', '1' => '87', '2' => '97', '3' => '98', '4' => '100', '5' => '99', '6' => '102', '7' => '101', '8' => '103'], 'audios' => [], 'videos' => [], 'prompt' => ['88', 'value'], 'duration' => '27', 'aspect' => '29'],
            'supports_video' => false,
        ],
        'minimax-h3-ref2va' => [
            'file' => 'u17',
            'mode' => 'slots',
            'label' => 'U17 · 9图1视频3音（旧名，兼容保留）',
            'defaults' => ['seconds' => 5.0, 'aspect' => '16:9', 'megapixels' => 0.9, 'steps' => 8],
            'slots' => ['h3' => '275', 'images' => ['0' => '248', '1' => '249', '2' => '247', '3' => '246', '4' => '279', '5' => '280', '6' => '281', '7' => '282', '8' => '283'], 'audios' => ['0' => '259', '1' => '260', '2' => '261'], 'videos' => ['0' => '256'], 'prompt' => ['250', 'value'], 'duration' => '132', 'seed' => '129', 'aspect' => '115'],
            'supports_video' => true,
            'hidden' => true,
        ],
    ];
}

/**
 * 按模型名取档案；找不到时回落到默认档案（U17）。
 */
function comfy_video_profile(string $model): array
{
    $all = comfy_video_profiles();
    if (isset($all[$model])) {
        return $all[$model];
    }
    $lower = strtolower($model);
    foreach ($all as $name => $p) {
        if (strtolower($name) === $lower) {
            return $p;
        }
    }
    return $all['minimax-h3-u17'];
}
