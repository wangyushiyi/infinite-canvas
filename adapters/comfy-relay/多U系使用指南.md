# comfy-relay · 多 U 系使用指南

> 生成：2026-11-18　｜　中继已上线，16 个 U 系模型可用
> 代码位置：`adapters/comfy-relay/`

---

## 一、核心机制：模型名 → 工作流映射

**画布里选哪个模型名，中继就跑哪个 U 系工作流。** 不需要挂「调用脚本」，不需要改 PHP。

```
画布视频节点（选模型名）
   │  POST /comfy-relay/v1/videos    model=minimax-h3-video-u22
   ▼
中继 create_video.php
   │  ① 按 model 查档案表 config/video_profiles.php
   │  ② 载入该档案指定的 workflows/<file>.json
   │  ③ 按档案的「改写模式」改写工作流（填参考图/prompt/时长/比例/seed）
   ▼
POST 云机 /prompt  →  出片
```

### 档案表长什么样

`config/video_profiles.php`（**自动生成，勿手改**）：

```php
'minimax-h3-video-u22' => [
    'file' => 'u22',                 // workflows/u22.json
    'mode' => 'pickref',             // 改写模式
    'label' => 'U22 · 9图3音 · 10s · 16:9 · 1.5MP · 节点最少(16)',
    'defaults' => ['seconds'=>10.0, 'aspect'=>'16:9', 'megapixels'=>1.5, 'steps'=>8],
    'slots' => ['pick'=>'403', 'h3'=>'265'],
    'supports_video' => true,
],
```

### 两种改写模式

U 系 21 个工作流的接线结构分两派，所以要两套改写逻辑：

| mode | 适用 | 结构特点 | 改写动作 |
|---|---|---|---|
| **`pickref`** | U00闹闹版 / U06V9 / U22 / U22V1 / U30 | 有 `ZealmanH3PickReferenceMedia` 统一入口节点，它一个节点收下 9图+3视频+3音+prompt+时长+比例+像素数 | 只动这**一个**节点 |
| **`slots`** | U01 / U06系列 / U07 / U08 / U09 / U17 / U24×2 / U32 | `LoadImage` / `LoadAudio` / `VHS_LoadVideo` 直接喂 H3 的 `ref_*` 动态槽 | 改若干节点（槽位号逐工作流不同） |

**槽位号是自动反推出来的**（`comfy_adapter/analyze_u_slots.py`）：从 H3 主节点的 `ref_images.ref_image_N` 出发，沿连线反向追踪直到撞上 `LoadImage`。
正确性由 U17 交叉验证——反推结果与 PHP 里原有的硬编码常量**完全一致**（248/249/247/246/279-283 / 256 / 250 / 132 / 115 / 129）。

---

## 二、画布侧怎么配

### 渠道（只配一次）

| 项 | 填什么 |
|---|---|
| 名称 | `ComfyUI 云机` |
| 协议 | **OpenAI** |
| 接口地址 | `https://slategrey-vulture-564350.hostingersite.com/comfy-relay/v1` |
| API Key | `config/config.php` 里的 `RELAY_KEY` |
| 调用脚本 | **留空** ✅ |

### 模型列表（**一键拉取，不用手打**）

渠道编辑抽屉 → 点 **「拉取模型列表」** → 全选 → 确认。中继的 `GET /v1/models`
会返回全部 16 个 U 系模型，画布自动按名字识别能力：

```bash
curl -H "Authorization: Bearer <RELAY_KEY>" \
  https://slategrey-vulture-564350.hostingersite.com/comfy-relay/v1/models
```

**为什么模型名里都带 `video`**：画布用 `guessCapability(name)` 按名字推断能力，
关键词表是 `["video","sora","veo","kling","wan","hailuo"]` —— 名字命中 `video`
才会被自动标成「视频」能力，省得一个个手动指定能力。
（`minimax` 不含 `wan`，`h3-video-*` 不含 `audio`/`image`，不会误判。）

16 个模型名：

```
minimax-h3-video-u17          minimax-h3-video-u00          minimax-h3-video-u01
minimax-h3-video-u06          minimax-h3-video-u06-pure     minimax-h3-video-u06-multi
minimax-h3-video-u06v9        minimax-h3-video-u07          minimax-h3-video-u08
minimax-h3-video-u09          minimax-h3-video-u22          minimax-h3-video-u22v1
minimax-h3-video-u24          minimax-h3-video-u24-t8       minimax-h3-video-u30
minimax-h3-video-u32
```

> **兼容**：老渠道里填过的 `minimax-h3-ref2va` 仍然可用（= U17），
> 但它不出现在拉取列表里 —— 老名字不含 `video`，画布会把它判成 text 能力反而添乱。

> 拿权威清单：`GET /comfy-relay/health` → `video_models[]`（含 label / 模式 / 默认规格）。

### 画布里怎么用

1. 新建**视频生成节点**
2. 节点上选模型 → 下拉里挑 `minimax-h3-video-u22` 之类
3. 把**参考图**连进节点的图片输入（U 系支持 1~9 张）
4. 想要**原视频 + 音轨锁定** → 把原视频连进视频输入（**只有 U17 / U00 / U07 支持视频参考**）
5. 填提示词 → 生成

---

## 三、16 个模型速查

| 模型名 | 工作流 | 图 | 音 | 视频 | 时长 | 比例 | 像素 | 特色 |
|---|---|---|---|---|---|---|---|---|
| `minimax-h3-video-u17` | U17 | 9 | 3 | **1** | 5s | 16:9 | 0.9MP | **唯一支持原视频 + 音轨 bit 级锁定** |
| `minimax-h3-video-u00` | U00 闹闹版 | 9 | 3 | **1** | 10s | 16:9 | 0.4MP | 节点最少(14)，跑得最快 |
| `minimax-h3-video-u01` | U01 基础版 | 3 | 0 | — | 5s | 16:9 | 0.9MP | 最轻 |
| `minimax-h3-video-u06` | U06 V5 | 9 | 3 | — | 5s | 16:9 | 0.9MP | turbo 4step |
| `minimax-h3-video-u06-pure` | U06 9图版 V5 | 9 | 0 | — | 5s | 16:9 | 0.9MP | turbo 8step |
| `minimax-h3-video-u06-multi` | U06 无加速多参 | 9 | 3 | — | 5s | 16:9 | 0.9MP | **开放参数最多(18)**，steps 可调 |
| `minimax-h3-video-u06v9` | U06V9 | 9 | 3 | — | 15s | 16:9 | 1.2MP | Singularity 模型 |
| `minimax-h3-video-u07` | U07-A | 9 | 3 | **3** | 15s | 16:9 | 0.9MP | **唯一支持 3 路参考视频** |
| `minimax-h3-video-u08` | U08 | 9 | 3 | — | 5s | 16:9 | 0.9MP | **唯一带超分链** MMH3UltimateUpscale |
| `minimax-h3-video-u09` | U09 | 9 | 0 | — | 5s | 16:9 | 0.9MP | 音视频潜空间拼接，节点最多 |
| `minimax-h3-video-u22` | U22 | 9 | 3 | — | 10s | 16:9 | 1.5MP | 节点少(16)，画质规格最高 |
| `minimax-h3-video-u22v1` | U22 V1 | 9 | 3 | — | 10s | **9:16** | 0.7MP | **竖屏版** |
| `minimax-h3-video-u24` | U24 文武双修 | 9 | 3 | — | 10s | 16:9 | 0.4MP | **动作戏专用** H3_Combat_V2 |
| `minimax-h3-video-u24-t8` | U24 T8 版 | 9 | 3 | — | 10s | 16:9 | 0.4MP | 动作戏 + T8 采样器 |
| `minimax-h3-video-u30` | U30 | 9 | 3 | — | 10s | 16:9 | 0.7MP | Singularity Pruned + upsampler |
| `minimax-h3-video-u32` | U32 | 9 | 0 | — | 10s | 16:9 | 2.0MP | **打斗武器特化**，像素最高 |

> `minimax-h3-ref2va` = `minimax-h3-video-u17` 的旧名别名，仍可用。

**没纳入的 5 个 U 系**（暂不支持，原因见下）：

| 工作流 | 为什么没纳入 |
|---|---|
| `API-U02-图生视频基础版-V2` | fl2va 首尾帧型，没有 `ref_images.ref_image_N` 分槽 |
| `API-U03-文生视频基础版API-V2` | 同上（文生视频） |
| `API-U03-light2v-文生视频加速版` | 同上 |
| `API-U11-图片音频对口型API` | 参考图走 `ZealmanH3LoadImageToRefImages` 聚合节点，整体连 `ref_images`，不是分槽 |
| `API-U00-minimax_h3提示词QWEN3.8群主版` | 它是**提示词生成器**，不出片（9 图 → 结构化 H3 提示词） |

> 要补这 5 个：给它们各写一条 `mode`（`fl2va` / `aggregate`），扩展 `comfy_patch_workflow()` 的第三个分支。

---

## 四、参数优先级（重要）

| 参数 | 规则 |
|---|---|
| **秒数** | **档案默认优先**。画布传的是 `6`（内置默认）时视为"未设置" → 用档案默认；画布传别的值 → 用画布的 |
| **宽高比** | **始终用档案默认**（u22v1 就是 9:16 竖屏，这是它的身份，不该被覆盖） |
| **百万像素** | **始终用档案默认** |
| **steps** | 用档案默认（U06-multi 想调 steps 得改档案） |
| **prompt** | 画布传什么就是什么（中继会编成 H3 七字段 JSON） |
| **参考图/视频/音频** | 画布连什么给什么，最多 9 图 / 3 视频 / 3 音 |

**为什么比例不开放给画布**：画布的尺寸控件会传 `size=1024x1024` 这类固定值，如果直接采纳，u22v1 的竖屏特色就没了，选模型等于白选。

**生效的规格会回显**：创建任务的响应里 `profile.spec` 字段会写明实际用的 seconds / aspect / megapixels / steps，账本 `state/tasks.json` 也记着，随时可查。

---

## 五、验证状态

| 项目 | 状态 |
|---|---|
| PHP 语法（服务器端 `token_get_all`） | ✅ `bad = 0` |
| 离线自检 `test_relay_logic.py` | ✅ **370 项全过**（原 191 → 370） |
| 16 个工作流上传 | ✅ 28 个文件全部 OK |
| **云机结构预检**（注入假节点，不烧 GPU） | ✅ **17/17 全部被完整接受** |
| 线上 health 返回模型清单 | ✅ 17 个（16 公开 + 1 隐藏别名） |\n| 线上 `GET /v1/models`（画布一键拉取） | ✅ 16 个，全部 `capability=video` |
| **真实出片** | ⏳ 待授权后验证（会烧 GPU） |

**结构预检原理**：往工作流塞一个不存在的节点 `__NotARealNode__`，云机必然返 400；
如果**除了假节点没有任何别的报错**（`node_errors` 为空、顶层 `error` 只指向假节点），
说明整个工作流图（节点类型、连线、枚举、素材文件）都被云机完整接受。
工具：`comfy_adapter/precheck_all_u.py`。

---

## 六、踩坑清单

| 现象 | 真因 | 处置 |
|---|---|---|
| `Node 'ID #_api_config' has no class_type` | U 系工作流都带 `_api_config` 元数据节点 | 剔除 class_type 缺失的节点（`comfy_strip_meta_nodes`，PHP 已有；Python 复刻要补） |
| FTP 上传 550 `Temporary hidden file .in.xxx.json. already exists` | 服务器 STOR 前会建 `.in.<名>.` 临时文件（**末尾带点**），失败后残留 | 重试前删 `.in.<名>.`（少写那个点删不掉） |
| 画布报裸「请求失败」看不到状态码 | 云机 4xx 响应没 CORS 头，浏览器把状态码吃掉 | 走中继（服务端到服务端），或给云机 nginx 加 `always;` 的 CORS 头 |
| 选 u22v1 却出横屏 | 画布尺寸覆盖了档案比例 | 比例按档案固定，画布不覆盖 |
| 音轨是模型念 prompt 的声音 | 独立音频槽只是"音色参考"，H3 会自己生成新音轨 | 传原视频 → 中继把音轨直连原视频 AUDIO 出口 |
| 每次出片一模一样 | seed 命中 ComfyUI 缓存 | 服务端每次用随机 seed |

---

## 七、维护：加一个新的 U 系

```bash
# 1. 从云机/面板拿工作流 JSON，存成 API 格式
cp <新工作流>.json  comfy_adapter/workflows_u/API-UXX-....json

# 2. 自动反推槽位 -> 生成 PHP 档案表
cd comfy_adapter
python analyze_u_slots.py          # -> u_profiles.json
# 在 gen_video_profiles.py 的 MODEL_MAP / DEFAULTS / ASCII_NAME 里加一条
python gen_video_profiles.py       # -> video_profiles.php
cp video_profiles.php ../fork-infinite-canvas/adapters/comfy-relay/config/

# 3. 工作流按 ASCII 名放进中继目录
cp workflows_u/API-UXX-....json ../fork-infinite-canvas/adapters/comfy-relay/workflows/uxx.json

# 4. 自检 + 部署 + 预检
cd ../fork-infinite-canvas/adapters/comfy-relay
python scripts/test_relay_logic.py            # 期望 0 失败
python scripts/deploy_comfy_relay.py lint     # 期望 bad = 0
python scripts/deploy_comfy_relay.py upload
cd ../../../../comfy_adapter && python precheck_all_u.py   # 期望 17/17
```

**改 PHP 时记得同步改 `scripts/test_relay_logic.py`**（它复刻 PHP 逻辑，本机没 php.exe 只能靠它）。
服务器端还有一道真语法检查：`deploy_comfy_relay.py lint`（上传一次性脚本用 `token_get_all(..., TOKEN_PARSE)` 解析，读完自删）。
