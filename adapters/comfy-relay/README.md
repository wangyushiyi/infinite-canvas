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
| 模型名 | `minimax-h3-ref2va`（视频） / `sdxl`（图片） |
| 模型能力 | 视频 / 图片 |
| 调用脚本 | **留空** ✅ |

> 接口地址以 `/v1` 结尾时画布不会重复补 `/v1`（`buildApiUrl` 会识别已有版本号路径）。

---

## 路由

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/comfy-relay/health` | 健康检查（不需要 key） |
| POST | `/comfy-relay/v1/videos` | 创建视频任务（multipart） |
| GET | `/comfy-relay/v1/videos/{id}` | 查询任务状态 |
| GET | `/comfy-relay/v1/videos/{id}/content` | 下载成片 |
| POST | `/comfy-relay/v1/images/generations` | 文生图（JSON） |
| POST | `/comfy-relay/v1/images/edits` | 参考图生图（multipart） |

所有响应（含 4xx/5xx）都带 `Access-Control-Allow-Origin: *`，
**这是关键**——否则浏览器又会把状态码吃掉。

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

---

## 干跑探针

`config/config.php` 里把 `DRY_RUN` 设成 `true`：
只记录请求形态、**绝不转发上游、绝不产生视频/图片**。
用来先验证画布侧接线是否正确。

---

## 排障

| 现象 | 真因 | 处置 |
|---|---|---|
| 画布显示「请求失败」（裸） | 画布没挂调用脚本，走原生 `/v1/videos`，ComfyUI 返 405 且无 CORS 头 | 本服务已解决：画布接口地址指向本服务 |
| 401 | `RELAY_KEY` 不一致 | 对齐 `config/config.php` 与画布渠道 |
| 502 云机不可达 | AutoDL 实例关机/换机 | 改 `COMFY_BASE` |
| 出片但音轨是念 prompt 的声音 | 画布没传 `videos`（原视频） | 画布里把原视频连进视频节点的参考 |
| 每次出片都一样 | seed 命中缓存 | 服务端已用随机 seed；手改工作流时注意 |

---

## 文件

```
index.php                    路由 + CORS
config/config.php            配置（三段式密钥）
config/comfy_core.php        ComfyUI 客户端 / H3 提示词编排 / 工作流改写 / 任务账本
scripts/create_video.php     POST /v1/videos
scripts/poll_video.php       GET  /v1/videos/{id}
scripts/fetch_content.php    GET  /v1/videos/{id}/content
scripts/create_image.php     POST /v1/images/generations|edits
scripts/deploy_comfy_relay.py  部署脚本
workflows/*.json             工作流模板
```
