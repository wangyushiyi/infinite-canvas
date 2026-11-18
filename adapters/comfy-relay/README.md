# ComfyUI 云机渠道适配层（comfy-relay）

把 **ComfyUI 原生协议**包装成画布认识的 **OpenAI 视频/图片协议**，
让画布可以像用 apigo 那样直接调用你的 AutoDL 云机，**不需要挂「调用脚本」**。

---

## 为什么需要它

云机的 ComfyUI **没有** `/v1/videos` 这类 OpenAI 端点。画布没挂调用脚本时会走
`createOpenAIVideoTask()` → `POST {base}/v1/videos` → ComfyUI 返 **405**。

更要命的是：**云机 nginx 只给 200 响应加 CORS 头，4xx 响应没有**。
浏览器按 Fetch 规范拦截跨域错误响应 → JS 侧拿不到 response，只有 `ERR_NETWORK`
→ 画布 `readAxiosError()` 落到兜底分支 → 节点上只显示一句 **「请求失败」**，
**405 的状态码被 CORS 吃掉了，永远看不到**。

本服务跑在服务端（画布 → 本服务 → 云机），全程服务端到服务端，不受 CORS 约束。

---

## 画布侧配置（照抄）

| 项 | 填什么 |
|---|---|
| 名称 | `ComfyUI 云机` |
| 协议 | **OpenAI** |
| 接口地址 | `https://slategrey-vulture-564350.hostingersite.com/comfy-relay/v1` |
| API Key | `config/config.php` 里的 `RELAY_KEY` |
| 模型名 | 点「拉取模型列表」自动获取 16 个 U 系 / `sdxl`（图片） |
| 模型能力 | 视频 / 图片（拉取时按名字自动判定） |
| 调用脚本 | **留空** ✅ |

### 多 U 系（2026-11-18 新增）

**画布里选模型名 = 选工作流**，不用挂脚本、不用改 PHP：

| 模型名 | 对应 |
|---|---|
| `minimax-h3-video-u17` ~ `minimax-h3-video-u32`（16 个） | U 系 16 个工作流 |
| `minimax-h3-ref2va` | 旧名别名（= U17），仍可用 |

拉取模型列表的中继端点：`GET /comfy-relay/v1/models`。
完整说明见 **[多U系使用指南.md](多U系使用指南.md)**。

档案表 `config/video_profiles.php` 由 `comfy_adapter/analyze_u_slots.py`
（自动反推槽位）+ `comfy_adapter/gen_video_profiles.py`（翻译成 PHP）生成，**勿手改**。

> 接口地址以 `/v1` 结尾时画布不会重复补 `/v1`（`buildApiUrl` 会识别已有版本号路径）。

---

## 上传流程：为什么文件不过中继

实测（2026-11-18）：

| 路径 | 结果 |
|---|---|
| Hostinger → 云机 `GET /system_stats` | 615ms 通，但 **8 次里 2 次整次超时**（跨境链路不稳） |
| Hostinger → 云机 multipart 上传 16KB | 成功，服务端只花 0.7s |
| Hostinger → 云机 multipart 上传 190KB | **超时**（curl 等 66s 无响应） |
| 浏览器 → 云机 multipart 上传 190KB | **成功**（ComfyUI 带 `--enable-cors-header *`，预检和实际 POST 都回 `ACAO:*`） |
| Hostinger → apigo | 稳定 0.9–2.7s，5/5 全通（说明是 AutoDL 公网入口对海外主机的链路问题） |

**结论**：文件走「浏览器 → 云机」直传，中继只负责编排工作流 JSON（已验证 POST 通）。

### 流程

```
1) 画布 POST /v1/uploads/issue   →  { upload_id, cloud_name, upload_url }
2) 画布 POST upload_url（云机）   →  云机返回 cloud_name
3) 画布 POST /v1/videos          →  带 upload_id + cloud_name
4) 中继查账本拿 cloud_name，填进工作流
```

### 路由

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/comfy-relay/health` | 健康检查（不需要 key） |
| GET | `/comfy-relay/diag/upstream` | 上游连通性诊断（延迟/版本） |
| POST | `/comfy-relay/v1/uploads/issue` | 签发上传令牌 |
| POST | `/comfy-relay/v1/videos` | 创建视频任务（multipart） |
| GET | `/comfy-relay/v1/videos/{id}` | 查询任务状态 |
| GET | `/comfy-relay/v1/videos/{id}/content` | 下载成片 |
| POST | `/comfy-relay/v1/images/generations` | 文生图（JSON） |
| POST | `/comfy-relay/v1/images/edits` | 参考图生图（multipart） |
| GET | `/comfy-relay/v1/images/{id}` | 查询生图任务（返回 b64_json + dataURL） |

所有响应（含 4xx/5xx）都带 `Access-Control-Allow-Origin: *`，
**这是关键**——否则浏览器又会把状态码吃掉。

> 生图是**异步**的：提交即返回 task id，画布用 `GET /v1/images/{id}` 轮询。
> 原因：Hostinger 共享主机的 PHP 执行上限约 30s，同步等出图必超时。

---

## 能力与实现要点

### 视频（H3）

- **参考图真序**（按 H3 节点接线，**不是节点号升序**）：
  `ref_image_0←248 / 1←249 / 2←247 / 3←246 / 4..8←279..283`
- **音轨一致性**：把 `ref_audios.*` 直连原视频的 AUDIO 出口
  （`VHS_LoadVideo` 的 index **2**），旁路掉工作流自带的 `LoadAudio→TrimAudioDuration`。
  → H3 锁定真实人声，不会拿 prompt 念语音。
  → 代价：不裁音频时长，**`seconds` 必须 ≤ 原视频时长**。
- **没有原视频**时自动断开 `ref_videos.*` / `ref_audios.*`，退化成纯图生视频。
- **时长**：写进 node 132（PrimitiveFloat），帧数由 node 131 的表达式算：
  `max(5, round(a*24)) + (5 - (max(5, round(a*24)) % 17)) % 17`
  （H3 的帧数对齐规则，不是简单 `seconds*24`）
- **seed 每次随机**：固定 seed 会命中 ComfyUI 缓存，出片一模一样。

### H3 提示词编排

用户填一句自然语言，服务自动编成 H3 的七字段 JSON：

```json
{
  "subject_definitions": ["<Subject 1> is the person in <Picture 1>. 用户描述"],
  "summary": "用户描述",
  "retention_analysis": ["<Subject 1> - fully_preserved - ..."],
  "detailed_description": "Natural cinematic motion, stable facial identity...",
  "overall_soundscape": "N/A",
  "non_diegetic_music": "N/A"
}
```

用户直接填 JSON 的话则原样透传（`prompt_json` 等价物）。

### 图片（SDXL）

`CheckpointLoaderSimple → CLIPTextEncode×2 → EmptyLatentImage / VAEEncode
→ KSampler → VAEDecode → SaveImage`，图生图时 `latent_image` 换成 `VAEEncode(参考图)`，
`denoise=0.65`。

---

## 部署

```bash
python scripts/deploy_comfy_relay.py probe    # 建目录 + 看 FTP
python scripts/deploy_comfy_relay.py lint     # 服务器端 PHP 语法检查
python scripts/deploy_comfy_relay.py upload   # 上传
python scripts/deploy_comfy_relay.py verify   # 验证
```

远端目录：`comfy-relay/`（站点根下）。

> 部署前必须改 `config/config.php`：
> - `RELAY_KEY`（画布填的 key）
> - `COMFY_BASE`（云机地址，**AutoDL 换机后会变**）
> - `PUBLIC_BASE`（对外基地址）

### 换云机怎么办（AutoDL 实例是按小时计费的临时机）

**画布侧一个字都不用改** —— 画布填的是中继地址（固定），只有中继要知道云机的新地址。

AutoDL 公网入口格式：`https://u1002042-<实例ID>.<区域>.seetacloud.com:8443`
（实例ID 随机器变；区域是 `bjb1` / `bjb2` / `westd` 等）

换机后三步：

```bash
# 1) AutoDL 控制台：确认新实例已开机，并开自定义服务映射 8443 -> 容器 6006
# 2) 改中继的云机地址（改 config.php + 重新部署 + 注册 + 探活，一条命令）
python scripts/switch_cloud.py --base https://u1002042-<新实例ID>.bjb2.seetacloud.com:8443
# 3) 验证
python scripts/switch_cloud.py --status
```

> `switch_cloud.py` 属于「按需手动」的工具，不在 Git 里（本地保留即可）。
> 它做四件事：改 config.php → 重新部署 → 调用 `/admin/register-cloud` 注册 → 探活并打印结果。

**探活失败时中继不会切换**（保持旧地址，画布不至于全挂）。网关返回码有明确含义：

| 返回 | 含义 |
|---|---|
| 404 | 实例ID 或区域不对 |
| 307 | 实例在，但**端口映射没开**（控制台要开 8443 → 6006） |
| 0 | 连不上（实例没开机，或映射未开） |

**画布侧也不需要改**：`config.php` 的 `COMFY_BASE` 只是兜底，
实际生效地址由中继的动态注册机制接管（`state/cloud.json`）。

---

## 干跑探针

`config/config.php` 里把 `DRY_RUN` 设成 `true`：
只记录请求形态、**绝不转发上游、绝不产生视频/图片**。
用来先验证画布侧接线是否正确。

---

## 排障

### ⚠️ 排障第一课：ComfyUI 的错误不在 /history 里

`/history/{id}` 只返回 `status_str` 和 `messages`。**节点级校验错误（枚举非法、
图片不存在、工作流缺节点）只写进云机的 comfyui 日志**：

```bash
ssh -p 28741 root@connect.bjb2.seetacloud.com
grep -n 'Value not in list\|Invalid image file\|Failed to validate' \
  /root/ComfyUI/user/comfyui_6006.log | tail -20
```

典型症状：**`status_str: success` 但 `outputs` 为空** —— 不是云机挂了，是校验失败。

### 已踩过的坑（都有日志佐证）

| 现象 | 真因 | 处置 |
|---|---|---|
| success 但 outputs 空 | `aspect_ratio` 枚举非法 | 用云机**实际**枚举（见下） |
| /prompt 返 400 `Node '_api_config' has no class_type` | 工作流带元数据节点 | `comfy_strip_meta_nodes()` 自动剔除 |
| 轮询恒返回「非法 task id」 | 路由没把 id 传给脚本 | index.php 注入 `$taskId` |
| 错误信息只有 HTTP 400 没有原因 | 真实错误在顶层 `error.message` | 三处 fallback 提取 |
| 画布显示裸「请求失败」 | 画布没挂脚本 → 走原生 `/v1/videos` → 405 且无 CORS 头 → 浏览器拦截 | 用本服务；或给云机 nginx 加 `always;` 的 CORS 头 |
| 401 | `RELAY_KEY` 不一致 | 对齐 `config/config.php` 与画布渠道 |
| 502 云机不可达 | AutoDL 实例关机/换机 | 改 `COMFY_BASE` |
| 出片但音轨是念 prompt 的声音 | 画布没传 `videos`（原视频） | 画布里把原视频连进视频节点的参考 |
| 每次出片都一样 | seed 命中缓存 | 服务端已用随机 seed |
| 上传超时 | Hostinger→云机大文件链路不稳 | 文件走浏览器直传（见上） |

### 云机实测枚举（别凭印象写）

`ResolutionSelector.aspect_ratio`：

```
1:1 (Square) | 2:3 (Portrait Photo) | 3:2 (Photo) | 3:4 (Portrait Standard)
4:3 (Standard) | 9:16 (Portrait Widescreen) | 16:9 (Widescreen) | 21:9 (Ultrawide)
```

> 拿当前枚举：`curl -s https://<云机>/object_info/ResolutionSelector | jq .ResolutionSelector.input.required.aspect_ratio`

---

## 文件

```
index.php                    路由 + CORS
config/config.php            配置（三段式密钥，**不入 Git**）
config/config.example.php    配置模板
config/comfy_core.php        ComfyUI 客户端 / H3 提示词编排 / 工作流改写 / 任务账本
scripts/upload_issue.php     上传令牌 + 参考图收集（直传优先）
scripts/create_video.php     POST /v1/videos
scripts/poll_video.php       GET  /v1/videos/{id}
scripts/fetch_content.php    GET  /v1/videos/{id}/content
scripts/create_image.php     POST /v1/images/generations|edits
scripts/poll_image.php       GET  /v1/images/{id}
scripts/deploy_comfy_relay.py  部署脚本（probe / lint / upload / verify）
scripts/test_relay_logic.py  离线自检 191 项
workflows/*.json             工作流模板
```

## 离线自检

```bash
python scripts/test_relay_logic.py     # 191 项，0 失败
```

本机没有 `php.exe`，所以用 Python 等价复刻 PHP 逻辑来验证
（工作流改写 / 槽位真序 / 音轨直连 / 提示词编排 / 枚举合法性 /
PHP 语法平衡 / 路由参数传递 / 部署清单完整性）。
**改 PHP 时记得同步改这个脚本并重跑。**
服务器端还有一道真语法检查：`deploy_comfy_relay.py lint`
（上传一次性脚本到服务器用 `token_get_all(..., TOKEN_PARSE)` 解析，读完自删）。
