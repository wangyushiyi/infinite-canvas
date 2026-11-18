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

### 四种改写模式

U 系 21 个工作流的接线结构分四派，所以要四套改写逻辑：

| mode | 适用 | 结构特点 | 改写动作 |
|---|---|---|---|
| **`pickref`** | U00闹闹版 / U06V9 / U22 / U22V1 / U30 | 有 `ZealmanH3PickReferenceMedia` 统一入口节点，它一个节点收下 9图+3视频+3音+prompt+时长+比例+像素数 | 只动这**一个**节点 |
| **`slots`** | U01 / U06系列 / U07 / U08 / U09 / U17 / U24×2 / U32 | `LoadImage` / `LoadAudio` / `VHS_LoadVideo` 直接喂 H3 的 `ref_*` 动态槽 | 改若干节点（槽位号逐工作流不同） |
| **`fl2va`** | U02 / U03 / U03-light | H3 节点是 `MiniMaxH3ImageToVideo`，**prompt 是内联纯文本**（不是 JSON），宽高走 `WJILatentPreset` 的自定义宽/高 | 改 prompt + 宽高 + 时长 + seed + steps（+首尾帧） |
| **`aggregate`** | U11 | `ref_images` 是**整体接线**（`ZealmanH3LoadImageToRefImages` 聚合节点），音频走 `VHS_LoadAudioUpload` | 改图片源头 + 音频 + 宽高 + seed + steps |

**槽位号是自动反推出来的**（`comfy_adapter/analyze_u_slots.py`）：从 H3 主节点的 `ref_images.ref_image_N` 出发，沿连线反向追踪直到撞上 `LoadImage`。
正确性由 U17 交叉验证——反推结果与 PHP 里原有的硬编码常量**完全一致**（248/249/247/246/279-283 / 256 / 250 / 132 / 115 / 129）。

**fl2va 的两个硬约束**：
1. prompt 必须是**自然语言**（模板里的形状是「整体风格 + Timeline + Audio」），塞七字段 JSON 会被当成怪文本。
2. 宽高必须是 **32 的整数倍**（模板 `缩放倍数: 32`）—— 中继的 `h3_dimensions()` 负责对齐。

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
会返回全部 20 个 U 系模型，画布自动按名字识别能力：

```bash
curl -H "Authorization: Bearer <RELAY_KEY>" \
  https://slategrey-vulture-564350.hostingersite.com/comfy-relay/v1/models
```

**为什么模型名里都带 `video`**：画布用 `guessCapability(name)` 按名字推断能力，
关键词表是 `["video","sora","veo","kling","wan","hailuo"]` —— 名字命中 `video`
才会被自动标成「视频」能力，省得一个个手动指定能力。
（`minimax` 不含 `wan`，`h3-video-*` 不含 `audio`/`image`，不会误判。）

20 个模型名：

```
minimax-h3-video-u17          minimax-h3-video-u00          minimax-h3-video-u01
minimax-h3-video-u06          minimax-h3-video-u06-pure     minimax-h3-video-u06-multi
minimax-h3-video-u06v9        minimax-h3-video-u07          minimax-h3-video-u08
minimax-h3-video-u09          minimax-h3-video-u22          minimax-h3-video-u22v1
minimax-h3-video-u24          minimax-h3-video-u24-t8       minimax-h3-video-u30
minimax-h3-video-u32          minimax-h3-video-u02          minimax-h3-video-u03
minimax-h3-video-u03-light    minimax-h3-video-u11
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

## 三、20 个模型速查

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
| `minimax-h3-video-u02` | U02 图生视频 | **首尾帧 2** | 0 | — | 5s | 16:9 | 0.4MP | **fl2va 首尾帧**，第 1 张图=首帧、第 2 张=尾帧 |
| `minimax-h3-video-u03` | U03 文生视频 | 0 | 0 | — | 5s | 16:9 | 0.4MP | **fl2va 纯文生**，25 步 |
| `minimax-h3-video-u03-light` | U03 加速版 | 0 | 0 | — | 5s | 16:9 | 0.4MP | **fl2va + light2v turbo**，12 步最快 |
| `minimax-h3-video-u11` | U11 对口型 | 1 | **1** | — | 5s | **9:16** | 1.0MP | **唯一对口型专用**，图+音→唇动 |

> `minimax-h3-ref2va` = `minimax-h3-video-u17` 的旧名别名，仍可用。

**三种新形态怎么用**：

| 形态 | 模型 | 参考图怎么给 | 音频怎么给 | prompt 形态 |
|---|---|---|---|---|
| `fl2va` | U02 / U03 / U03-light | U02：第 1 张当**首帧**、第 2 张当**尾帧**；U03：不需要图 | 不支持 | **自然语言 + Timeline**（不是 JSON） |
| `aggregate` | U11 | 第 1 张图即可（走聚合节点） | 连一个音频文件，**音频时长决定成片长度** | 由工作流内部 LLM 链生成，中继不覆盖 |

**没纳入的 1 个 U 系**：

| 工作流 | 为什么没纳入 |
|---|---|
| `API-U00-minimax_h3提示词QWEN3.8群主版` | 它是**提示词生成器**，不出片（9 图 → 结构化 H3 提示词），不是视频工作流 |

---

## 四、参数优先级（重要）

| 参数 | 规则 |
|---|---|
| **秒数** | **画布优先**。画布传了就用（clamp 4..30），没传/非法才回退档案默认 |
| **宽高比** | 画布的 `ratio` 优先；缺失时从 `size` 的 W/H 比换算；再回退档案默认 |
| **百万像素** | 从画布 `size` 的实际像素算（÷1e6，clamp 0.1..16）；缺失才回退档案默认 |
| **steps** | 用档案默认（U06-multi 想调 steps 得改档案） |
| **prompt** | 画布传什么就是什么（ref2va 编成 H3 七字段 JSON；fl2va 编成自然语言+Timeline） |
| **参考图/视频/音频** | 画布连什么给什么，最多 9 图 / 3 视频 / 3 音 |

**画布的尺寸控件会传 `size=480x854` 这类实际像素**，中继按它算比例与像素数，所以画布上改尺寸是真的生效的。
`fl2va` 形态的宽高还会额外**对齐到 32 的整数倍**（模板里 `缩放倍数: 32` 的硬约束）。

**生效的规格会回显**：创建任务的响应里 `profile.spec` 字段会写明实际用的 seconds / aspect / megapixels / steps，账本 `state/tasks.json` 也记着，随时可查。

---

## 五、云机不可用时怎么办（**当前状态：云机已回收**）

AutoDL 实例是**按小时计费的临时机**，回收后网关仍然在线，但所有路径秒回 404，
响应体是 AutoDL 的一整页 HTML。中继现在会主动探活并给出人话提示：

```
云机不可用：云机网关返回 404。AutoDL 实例已回收或实例ID/区域不对
（网关回的是 AutoDL 的 HTML 404 页）。换新实例后运行：
python scripts/switch_cloud.py --base https://u1002042-<新实例ID>.<区域>.seetacloud.com:8443
（当前上游 https://u1002042-794958dd10c3.bjb2.seetacloud.com:8443）
```

**画布侧一个字都不用改**（画布填的是中继地址，中继地址固定）。处置流程：

```bash
# 1) 开机后先探活（不通就不切换，避免把中继指向死地址）
python scripts/switch_cloud.py --status

# 2) 切到新地址（探活 -> 改 config.php -> 重新部署 -> 验证）
python scripts/switch_cloud.py --base https://u1002042-<新实例ID>.<区域>.seetacloud.com:8443
```

**网关返回码的含义**（`switch_cloud.py` 的口径）：
`404` = 实例ID/区域不对（或实例已回收）；`307` = 实例在但端口映射没开；`0` = 连不上。

**换机后还要做的**（新实例的镜像/模型可能不同）：
1. `python scripts/deploy_comfy_relay.py upload` —— 把工作流同步到中继（工作流是在云机侧生成的）
2. `python comfy_adapter/precheck_all_u.py` —— 零成本结构预检，不烧 GPU

---

## 六、验证状态

| 项目 | 状态 |
|---|---|
| PHP 语法（服务器端 `token_get_all`） | ✅ `bad = 0`（10 个文件全过） |
| 离线自检 `test_relay_logic.py` | ✅ **432 项全过**（原 191 → 370 → 432） |
| 21 份工作流上传 | ✅ 32 个文件全部 OK（含 4 份新工作流） |
| **云机结构预检**（注入假节点，不烧 GPU） | ⏳ **待云机恢复后重跑**（上一轮 17/17 通过；新增 4 模型 × 新场景待验） |
| 线上 health 返回模型清单 | ✅ 21 个（20 公开 + 1 隐藏别名） |
| 线上 `GET /v1/models`（画布一键拉取） | ✅ **20 个**，全部 `capability=video` |
| 上游探活与清晰报错 | ✅ 线上实测：死上游返回人话 + 处置命令（不再是 HTML 源码） |
| **真实出片** | ⏳ 待授权后验证（会烧 GPU）；且**需先恢复云机** |

**结构预检原理**：往工作流塞一个不存在的节点 `__NotARealNode__`，云机必然返 400；
如果**除了假节点没有任何别的报错**（`node_errors` 为空、顶层 `error` 只指向假节点），
说明整个工作流图（节点类型、连线、枚举、素材文件）都被云机完整接受。
工具：`comfy_adapter/precheck_all_u.py`。

**三种新形态的场景设计**（预检按模式分别跑）：
- `slots`/`pickref` → 有视频 / 无视频
- `fl2va` → 有图（首尾帧）/ 无图
- `aggregate` → 有音频 / 无音频

---

## 七、踩坑清单

| 现象 | 真因 | 处置 |
|---|---|---|
| `Node 'ID #_api_config' has no class_type` | U 系工作流都带 `_api_config` 元数据节点 | 剔除 class_type 缺失的节点（`comfy_strip_meta_nodes`，PHP 已有；Python 复刻要补） |
| FTP 上传 550 `Temporary hidden file .in.xxx.json. already exists` | 服务器 STOR 前会建 `.in.<名>.` 临时文件（**末尾带点**），失败后残留 | 重试前删 `.in.<名>.`（少写那个点删不掉） |
| 画布报裸「请求失败」看不到状态码 | 云机 4xx 响应没 CORS 头，浏览器把状态码吃掉 | 走中继（服务端到服务端），或给云机 nginx 加 `always;` 的 CORS 头 |
| **画布弹出一屏 HTML 源码** | 云机已回收，旧版把网关的 HTML 错误页原样塞进 error.message | 已修：中继主动探活 + `comfy_clean_error_body()` 净化 HTML |
| 选 u22v1 却出横屏 | 画布尺寸覆盖了档案比例 | 已修：比例按画布设定走（`comfy_resolve_spec`） |
| 音轨是模型念 prompt 的声音 | 独立音频槽只是"音色参考"，H3 会自己生成新音轨 | 传原视频 → 中继把音轨直连原视频 AUDIO 出口 |
| 每次出片一模一样 | seed 命中 ComfyUI 缓存 | 服务端每次用随机 seed |
| **LoadImage 报 `IsADirectoryError`** | 没用到的图槽被写空串，pyav 把空路径拼成 input **目录** | 未用槽一律塞 `zealman-blank-image.png`（占位图） |
| **VHS_LoadVideo 报 `could not be loaded with cv`** | 同源：视频槽空串拼成 input 目录 | 未用视频槽塞 `zealman-blank-video.mp4`（占位视频） |
| **fl2va 出片报宽高非法** | 模板 `缩放倍数: 32`，宽高必须是 32 倍数 | `h3_dimensions()` 对齐到 32 |
| **PHP multipart 丢多图** | PHP 解析表单时同名字段只保留最后一个 | 用数组式字段名 `upload_ids[]` / `cloud_names[]` |

---

## 八、维护：加一个新的 U 系

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
cd ../../../../comfy_adapter && python precheck_all_u.py   # 期望全部 PASS
```

**改 PHP 时记得同步改 `scripts/test_relay_logic.py`**（它复刻 PHP 逻辑，本机没 php.exe 只能靠它）。
服务器端还有一道真语法检查：`deploy_comfy_relay.py lint`（上传一次性脚本用 `token_get_all(..., TOKEN_PARSE)` 解析，读完自删）。

**四种 mode 各自要盯的槽位**：

| mode | 档案 `slots` 里要填什么 |
|---|---|
| `pickref` | `pick`（统一入口节点）、`h3`、`seed` |
| `slots` | `h3`、`images`、`audios`、`videos`、`prompt`（`[节点号, 键]`）、`duration`、`seed`、`aspect` |
| `fl2va` | `h3`、`resolution`（WJILatentPreset）、`duration`（PrimitiveFloat）、`seed`、`steps`（BasicScheduler）、`first_frame` / `last_frame`（LoadImage，可省） |
| `aggregate` | `h3`、`aggregate`（聚合节点）、`image`（LoadImage）、`audio`（VHS_LoadAudioUpload）、`resolution`、`seed`、`steps` |

**槽位怎么反推**（别手抄）：
- `slots` 形态：`analyze_u_slots.py` 从 `ref_images.ref_image_N` 反向追踪到 `LoadImage`。
- `fl2va` 形态：H3 节点的 `width`/`height` 连到 `WJILatentPreset`；`length` → `ComfyMathExpression` → `PrimitiveFloat`；`first_frame`/`last_frame` → `LoadImage`；`steps` 在 `BasicScheduler`。
- `aggregate` 形态：`ref_images` 整体连到聚合节点，聚合节点的 `image_1` → `LoadImage`；`ref_audios.ref_audio_0` → `VHS_LoadAudioUpload`。

**改完一定跑预检**：它按 mode 分别跑不同场景（slots 有/无视频、fl2va 有/无图、aggregate 有/无音频），
注入假节点看云机是否完整接受——**不烧 GPU**。
