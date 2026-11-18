# -*- coding: utf-8 -*-
"""comfy-relay 的离线自检：把 PHP 的关键逻辑用 Python 等价复刻，验证正确性。

本机没有 php.exe，所以不能直接跑 PHP。但这套逻辑是整个服务的大脑
（工作流改写 / 槽位真序 / 音轨直连 / H3 提示词 / 尺寸换算），
必须先在 Python 侧把等价语义钉死，再部署。

改 PHP 时记得同步改这里，并重跑本脚本。

用法: python test_relay_logic.py
"""
import io
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)          # adapters/comfy-relay
WF_DIR = os.path.join(ROOT, "workflows")

H3_SLOT_IMAGES = ["248", "249", "247", "246", "279", "280", "281", "282", "283"]
SLOT_VIDEO = "256"
SLOT_PROMPT = "250"
SLOT_SECONDS = "132"
SLOT_ASPECT = "115"
SLOT_SEED = "129"
SLOT_STEPS = "184"
SLOT_SAVE = "180"
SLOT_H3 = "275"

PASS = 0
FAIL = 0


def test_profiles_patch(profiles):
    print("\n[17] 档案驱动的改写行为")
    img_names = ["a.png", "b.png"]
    vid_name = "src.mp4"
    pj = '{"summary":"x"}'

    for name, p in profiles.items():
        wf = load_wf(p["file"])
        out = patch_dispatch(wf, img_names, vid_name, pj, 7.0, 12345, 8, "16:9", 0.9, p)
        slots = p.get("slots") or {}

        # 参考图：用了的写对、没用清空
        imgs = slots.get("images") or {}
        ok_used = all(out[nid]["inputs"]["image"] == img_names[int(i)]
                      for i, nid in imgs.items() if int(i) < len(img_names))
        ok_blank = all(out[nid]["inputs"]["image"] == ""
                       for i, nid in imgs.items() if int(i) >= len(img_names))
        check("%s 参考图按槽位写入" % name, ok_used)
        check("%s 未用图槽已清空（不会读到模板示例图）" % name, ok_blank)

        # prompt
        ps = slots.get("prompt")
        if p["mode"] == "pickref":
            check("%s prompt 已写入 Pick 节点" % name,
                  out[slots["pick"]]["inputs"].get("prompt") == pj)
            # media_files 只有模板里存在时才需要清空（有的 pickref 模板没这个键）
            check("%s media_files 已清空" % name,
                  out[slots["pick"]]["inputs"].get("media_files", "[]") == "[]",
                  "键是否存在: %s" % ("media_files" in out[slots["pick"]]["inputs"]))
            check("%s 独立音频槽已清空" % name,
                  all(out[slots["pick"]]["inputs"].get("ref_audio_%d" % i) == "" for i in range(3)))
            check("%s 音轨锁定接 Pick[12]" % name,
                  out[slots["h3"]]["inputs"].get("ref_video_audios.ref_video_audio_0") == [slots["pick"], 12])
            check("%s 独立音频槽已从 H3 断开" % name,
                  not any(k.startswith("ref_audios.") for k in out[slots["h3"]]["inputs"]))
        else:
            if isinstance(ps, list) and len(ps) == 2:
                check("%s prompt 已写入节点 %s" % (name, ps[0]),
                      out[ps[0]]["inputs"].get(ps[1]) == pj)
            check("%s 时长已写入" % name,
                  abs(float(out[slots["duration"]]["inputs"]["value"]) - 7.0) < 1e-6)
            if slots.get("seed"):
                check("%s seed 已写入" % name,
                      out[slots["seed"]]["inputs"].get("noise_seed") == 12345)
            if slots.get("aspect"):
                check("%s 比例已写入且合法" % name,
                      out[slots["aspect"]]["inputs"].get("aspect_ratio") in VALID_ASPECTS)
            # 音轨直连原视频 AUDIO 出口
            h3 = out[slots["h3"]]["inputs"]
            aud_slots = [k for k in h3 if k.startswith("ref_audios.") or k.startswith("ref_video_audios.")]
            if aud_slots:
                check("%s 音轨直连原视频 AUDIO 出口" % name,
                      all(h3[k] == [str((slots.get("videos") or {}).get("0", SLOT_VIDEO)), 2] for k in aud_slots))

    # 无视频时：视频/音频槽必须断开（否则会引用云机上不存在的文件）
    broken = []
    for name, p in profiles.items():
        wf = load_wf(p["file"])
        out = patch_dispatch(wf, img_names, "", pj, 7.0, 1, 8, "16:9", 0.9, p)
        slots = p.get("slots") or {}
        h3 = out[slots["h3"]]["inputs"]
        for k in h3:
            if k.startswith("ref_videos.") or k.startswith("ref_audios.") or k.startswith("ref_video_audios."):
                broken.append("%s:%s" % (name, k))
    check("无原视频时视频/音频槽全部断开", not broken, "; ".join(broken[:6]))

    # 未知模型回落到 U17
    check("未知模型回落到默认档案",
          load_profiles_php().get("minimax-h3-video-u17") is not None)


def test_profile_workflow_pairing(profiles):
    print("\n[18] 档案与工作流的配对一致性")
    # U17 的槽位必须与 PHP 里原有的硬编码常量一致（防止自动反推跑偏）
    p = profiles.get("minimax-h3-u17")
    if p:
        slots = p.get("slots") or {}
        check("U17 图槽与原硬编码常量一致",
              [slots.get("images", {})[str(i)] for i in range(9)] == H3_SLOT_IMAGES,
              str([slots.get("images", {}).get(str(i)) for i in range(9)]))
        check("U17 H3 节点一致", slots.get("h3") == SLOT_H3, str(slots.get("h3")))
        check("U17 视频节点一致", (slots.get("videos") or {}).get("0") == SLOT_VIDEO)
        check("U17 prompt 节点一致", slots.get("prompt") == [SLOT_PROMPT, "value"], str(slots.get("prompt")))
        check("U17 时长节点一致", slots.get("duration") == SLOT_SECONDS)
        check("U17 分辨率节点一致", slots.get("aspect") == SLOT_ASPECT)
        check("U17 seed 节点一致", slots.get("seed") == SLOT_SEED)

    # 每个工作流文件里都能找到对应的主节点
    miss = []
    for name, p in profiles.items():
        wf = load_wf(p["file"])
        if p["mode"] == "pickref":
            if not any(n.get("class_type") == "ZealmanH3PickReferenceMedia" for n in wf.values()):
                miss.append(name + "(无统一入口)")
        else:
            if not any("H3" in (n.get("class_type") or "") for n in wf.values()):
                miss.append(name + "(无 H3 节点)")
    check("每个工作流都含预期的主节点", not miss, "; ".join(miss[:6]))

    # 覆盖度
    check("可用模型数 >= 12", len(profiles) >= 12, "共 %d 个" % len(profiles))
    picks = [n for n, p in profiles.items() if p["mode"] == "pickref"]
    slots_m = [n for n, p in profiles.items() if p["mode"] == "slots"]
    check("两种模式都有覆盖", len(picks) >= 3 and len(slots_m) >= 5,
          "pickref=%d slots=%d" % (len(picks), len(slots_m)))


def patch_slots(wf, image_names, video_name, prompt_json, seconds, seed, steps, aspect, megapixels, profile):
    """profile 驱动的 slots 型改写复刻。"""
    slots = profile.get("slots") or {}
    h3_id = str(slots.get("h3") or SLOT_H3)

    img_map = slots.get("images") or {}
    if not img_map:
        img_map = {str(i): nid for i, nid in enumerate(H3_SLOT_IMAGES)}
    for idx, nid in img_map.items():
        if nid in wf:
            wf[nid]["inputs"]["image"] = image_names[int(idx)] if int(idx) < len(image_names) else ""

    vid_map = slots.get("videos") or {}
    if not vid_map and SLOT_VIDEO in wf:
        vid_map = {"0": SLOT_VIDEO}
    for idx, nid in vid_map.items():
        if nid in wf:
            wf[nid]["inputs"]["video"] = video_name if int(idx) == 0 else ""

    ps = slots.get("prompt")
    if isinstance(ps, list) and len(ps) == 2 and ps[0] in wf and ps[1] in wf[ps[0]]["inputs"]:
        wf[ps[0]]["inputs"][ps[1]] = prompt_json
    elif SLOT_PROMPT in wf:
        wf[SLOT_PROMPT]["inputs"]["value"] = prompt_json

    dur = str(slots.get("duration") or SLOT_SECONDS)
    if dur in wf and "value" in wf[dur]["inputs"]:
        wf[dur]["inputs"]["value"] = max(1.0, seconds)
    asp = str(slots.get("aspect") or SLOT_ASPECT)
    if asp in wf:
        wf[asp]["inputs"]["aspect_ratio"] = h3_aspect(aspect)
        wf[asp]["inputs"]["megapixels"] = megapixels if megapixels > 0 else 0.9
    sd = str(slots.get("seed") or SLOT_SEED)
    if sd in wf:
        wf[sd]["inputs"]["noise_seed"] = seed
    if SLOT_STEPS in wf:
        wf[SLOT_STEPS]["inputs"]["value"] = max(1, steps)

    if h3_id in wf:
        h3 = wf[h3_id]["inputs"]
        if video_name:
            vid_node = str(vid_map.get("0") or SLOT_VIDEO)
            for k in list(h3.keys()):
                if k.startswith("ref_audios.") or k.startswith("ref_video_audios."):
                    h3[k] = [vid_node, 2]
        else:
            for k in list(h3.keys()):
                if k.startswith("ref_videos.") or k.startswith("ref_audios.") or k.startswith("ref_video_audios."):
                    del h3[k]
    return wf


def patch_pickref(wf, image_names, video_name, prompt_json, seconds, seed, steps, aspect, megapixels, profile):
    """comfy_patch_pickref_workflow 的 Python 复刻。"""
    slots = profile.get("slots") or {}
    pick_id = str(slots.get("pick") or "")
    h3_id = str(slots.get("h3") or "")
    if pick_id not in wf:
        raise ValueError("统一入口节点缺失: " + pick_id)
    ins = wf[pick_id]["inputs"]

    for i in range(9):
        k = "ref_image_%d" % i
        if k in ins:
            ins[k] = image_names[i] if i < len(image_names) else ""
    for i in range(3):
        k = "ref_video_%d" % i
        if k in ins:
            ins[k] = video_name if i == 0 else ""
    for i in range(3):
        k = "ref_audio_%d" % i
        if k in ins:
            ins[k] = ""
    if "media_files" in ins:
        ins["media_files"] = "[]"

    if "prompt" in ins:
        ins["prompt"] = prompt_json
    if "seconds" in ins:
        ins["seconds"] = max(1.0, seconds)
    if "aspect_ratio" in ins:
        ins["aspect_ratio"] = h3_aspect(aspect)
    if "megapixels" in ins:
        ins["megapixels"] = megapixels if megapixels > 0 else 0.9

    if slots.get("seed") and slots["seed"] in wf:
        wf[slots["seed"]]["inputs"]["noise_seed"] = seed

    if h3_id and h3_id in wf:
        h3 = wf[h3_id]["inputs"]
        if video_name:
            h3["ref_video_audios.ref_video_audio_0"] = [pick_id, 12]
            for k in list(h3.keys()):
                if k.startswith("ref_audios."):
                    del h3[k]
        else:
            for k in list(h3.keys()):
                if k.startswith("ref_videos.") or k.startswith("ref_audios.") or k.startswith("ref_video_audios."):
                    del h3[k]
    return wf


def patch_dispatch(wf, image_names, video_name, prompt_json, seconds, seed, steps, aspect, megapixels, profile):
    if profile.get("mode") == "pickref":
        return patch_pickref(wf, image_names, video_name, prompt_json, seconds, seed, steps, aspect, megapixels, profile)
    return patch_slots(wf, image_names, video_name, prompt_json, seconds, seed, steps, aspect, megapixels, profile)


VALID_ASPECTS = {
    "1:1 (Square)", "2:3 (Portrait Photo)", "3:2 (Photo)", "3:4 (Portrait Standard)",
    "4:3 (Standard)", "9:16 (Portrait Widescreen)", "16:9 (Widescreen)", "21:9 (Ultrawide)",
}


def parse_php_array(src):
    """解析 PHP 数组字面量（=> 风格），返回 Python 结构。"""
    state = {"i": 0}

    def skip_ws():
        while state["i"] < len(src) and src[state["i"]] in " \t\r\n":
            state["i"] += 1

    def parse_value():
        skip_ws()
        i = state["i"]
        if src.startswith("true", i):
            state["i"] = i + 4
            return True
        if src.startswith("false", i):
            state["i"] = i + 5
            return False
        if src.startswith("null", i):
            state["i"] = i + 4
            return None
        if src[i] == "'":
            j = i + 1
            buf = []
            while j < len(src):
                if src[j] == "\\":
                    buf.append(src[j + 1])
                    j += 2
                    continue
                if src[j] == "'":
                    break
                buf.append(src[j])
                j += 1
            state["i"] = j + 1
            return "".join(buf)
        if src[i] == "[":
            state["i"] = i + 1
            items = []
            while True:
                skip_ws()
                if state["i"] >= len(src):
                    break
                if src[state["i"]] == "]":
                    state["i"] += 1
                    break
                first = parse_value()
                skip_ws()
                if src.startswith("=>", state["i"]):
                    state["i"] += 2
                    val = parse_value()
                    items.append((first, val))
                else:
                    items.append((None, first))
                skip_ws()
                if state["i"] < len(src) and src[state["i"]] == ",":
                    state["i"] += 1
            if items and all(k is not None for k, _ in items):
                return dict(items)
            return [v for _, v in items]
        j = i
        while j < len(src) and (src[j].isdigit() or src[j] in ".-+eE"):
            j += 1
        if j > i:
            txt = src[i:j]
            state["i"] = j
            try:
                return int(txt)
            except ValueError:
                return float(txt)
        raise ValueError("无法解析: " + src[state["i"]:state["i"] + 40])

    return parse_value()


def load_wf(name):
    path = os.path.join(WF_DIR, name + ".json")
    data = json.load(io.open(path, encoding="utf-8"))
    if isinstance(data.get("prompt"), dict):
        return data["prompt"]
    return data


def check(name, cond, extra=""):
    global PASS, FAIL
    if cond:
        PASS += 1
        print("  OK   %s" % name)
    else:
        FAIL += 1
        print("  FAIL %s %s" % (name, extra))


# --------------------------------------------------------------- 提示词编排

def h3_build_prompt(text, image_count, mode="reference"):
    text = (text or "").strip()
    if not text:
        text = "A short cinematic clip following the reference subject."
    if text.startswith("{") and text.endswith("}"):
        try:
            return json.dumps(json.loads(text), ensure_ascii=False)
        except Exception:
            pass
    parts = [p.strip() for p in re.split(r"[\n\u3002\uff01\uff1f!?]+", text) if p.strip()]
    subjects, retention = [], []
    for i, p in enumerate(parts):
        n = i + 1
        subjects.append("<Subject %d> is the person in <Picture %d>. %s" % (n, n, p))
        retention.append("<Subject %d> - fully_preserved - identity, clothing and key features from the reference image are retained." % n)
    if not subjects:
        subjects.append("<Subject 1> is the person in <Picture 1>.")
        retention.append("<Subject 1> - fully_preserved - identity, clothing and key features from the reference image are retained.")
    return json.dumps({
        "subject_definitions": subjects,
        "summary": " ".join(parts),
        "retention_analysis": retention,
        "detailed_description": ("Natural cinematic motion, stable facial identity, consistent lighting, "
                                 "the subject performs the described action continuously and coherently across the whole clip."),
        "overall_soundscape": "N/A",
        "non_diegetic_music": "N/A",
    }, ensure_ascii=False)


# 云机 ResolutionSelector 的**实际**枚举（2026-11-18 实测自 ComfyUI 0.44.0）
# ⚠️ 枚举写错 -> ComfyUI 报 "Value not in list" -> 表现为 success 但 outputs 为空
ASPECT_ENUM = [
    "1:1 (Square)", "2:3 (Portrait Photo)", "3:2 (Photo)", "3:4 (Portrait Standard)",
    "4:3 (Standard)", "9:16 (Portrait Widescreen)", "16:9 (Widescreen)", "21:9 (Ultrawide)",
]


def h3_aspect(aspect):
    if aspect in ASPECT_ENUM:
        return aspect
    a = (aspect or "").strip()
    if not a:
        return "16:9 (Widescreen)"
    m = re.match(r"^(\d+)\s*x\s*(\d+)$", a)
    if m:
        w, h = int(m.group(1)), int(m.group(2))
        r = w / max(1, h)
        if r > 2.2:
            return "21:9 (Ultrawide)"
        if r > 1.4:
            return "16:9 (Widescreen)"
        if r > 1.1:
            return "4:3 (Standard)"
        if r > 0.9:
            return "1:1 (Square)"
        if r > 0.62:
            return "3:4 (Portrait Standard)"
        return "9:16 (Portrait Widescreen)"
    return {
        "16:9": "16:9 (Widescreen)", "4:3": "4:3 (Standard)", "1:1": "1:1 (Square)",
        "3:2": "3:2 (Photo)", "2:3": "2:3 (Portrait Photo)",
        "3:4": "3:4 (Portrait Standard)", "9:16": "9:16 (Portrait Widescreen)",
        "21:9": "21:9 (Ultrawide)", "auto": "16:9 (Widescreen)",
    }.get(a, "16:9 (Widescreen)")


def comfy_patch_workflow(wf, image_names, video_name, prompt_json, seconds, seed, steps, aspect, megapixels):
    for i, nid in enumerate(H3_SLOT_IMAGES):
        if nid not in wf:
            continue
        name = image_names[i] if i < len(image_names) else (image_names[-1] if image_names else None)
        if name:
            wf[nid]["inputs"]["image"] = name
    if video_name and SLOT_VIDEO in wf:
        wf[SLOT_VIDEO]["inputs"]["video"] = video_name
    if SLOT_PROMPT in wf:
        wf[SLOT_PROMPT]["inputs"]["value"] = prompt_json
    if SLOT_SECONDS in wf:
        wf[SLOT_SECONDS]["inputs"]["value"] = max(1.0, seconds)
    if SLOT_ASPECT in wf:
        wf[SLOT_ASPECT]["inputs"]["aspect_ratio"] = h3_aspect(aspect)
        wf[SLOT_ASPECT]["inputs"]["megapixels"] = megapixels if megapixels > 0 else 0.9
    if SLOT_SEED in wf:
        wf[SLOT_SEED]["inputs"]["noise_seed"] = seed
    if SLOT_STEPS in wf:
        wf[SLOT_STEPS]["inputs"]["value"] = max(1, steps)
    if SLOT_H3 in wf:
        h3 = wf[SLOT_H3]["inputs"]
        if video_name:
            for k in list(h3.keys()):
                if k.startswith("ref_audios."):
                    h3[k] = [SLOT_VIDEO, 2]
        else:
            for k in list(h3.keys()):
                if k.startswith("ref_videos.") or k.startswith("ref_audios."):
                    del h3[k]
    return wf


# --------------------------------------------------------------- 测试

def test_prompt():
    print("\n[1] H3 提示词编排")
    p = json.loads(h3_build_prompt("图中人物在喝茶", 1))
    check("七字段齐全", set(p.keys()) == {
        "subject_definitions", "summary", "retention_analysis",
        "detailed_description", "overall_soundscape", "non_diegetic_music"}, str(list(p.keys())))
    check("summary 回填提示词", p["summary"] == "图中人物在喝茶", p["summary"])
    check("subject 含 Picture 1", "<Picture 1>" in p["subject_definitions"][0])
    check("retention 条数匹配", len(p["retention_analysis"]) == len(p["subject_definitions"]))

    p2 = json.loads(h3_build_prompt("第一句。第二句！第三句", 3))
    check("多句切分成多个 subject", len(p2["subject_definitions"]) == 3, str(len(p2["subject_definitions"])))

    raw = json.dumps({"summary": "x", "subject_definitions": ["a"]}, ensure_ascii=False)
    p3 = json.loads(h3_build_prompt(raw, 1))
    check("用户直给 JSON 原样透传", p3["summary"] == "x")

    p4 = json.loads(h3_build_prompt("", 1))
    check("空提示词有兜底", bool(p4["summary"]))


def test_aspect():
    print("\n[2] 尺寸 -> aspect 换算")
    cases = [
        ("16:9", "16:9 (Widescreen)"), ("480x640", "3:4 (Portrait Standard)"),
        ("1024x1024", "1:1 (Square)"), ("1920x1080", "16:9 (Widescreen)"),
        ("720x1280", "9:16 (Portrait Widescreen)"), ("", "16:9 (Widescreen)"),
        ("auto", "16:9 (Widescreen)"), ("2560x1080", "21:9 (Ultrawide)"),
        ("1024x768", "4:3 (Standard)"), ("3:4", "3:4 (Portrait Standard)"),
        ("9:16", "9:16 (Portrait Widescreen)"), ("2:3", "2:3 (Portrait Photo)"),
        ("3:2", "3:2 (Photo)"), ("21:9", "21:9 (Ultrawide)"),
    ]
    for src, want in cases:
        got = h3_aspect(src)
        check("aspect %r -> %s" % (src, want), got == want, "got %s" % got)
    # 关键：所有返回值必须落在云机真实枚举里
    for src, _ in cases:
        got = h3_aspect(src)
        check("aspect %r 在云机枚举内" % src, got in ASPECT_ENUM, got)
    # 已经是合法枚举的原样返回
    for e in ASPECT_ENUM:
        check("枚举原样透传 %s" % e, h3_aspect(e) == e)


def test_workflow_patch():
    print("\n[3] 工作流改写（含视频）")
    wf = json.load(io.open(os.path.join(WF_DIR, "API-U17-9图1视频3音频V1-api.json"), encoding="utf-8"))
    check("工作流 59 节点", len(wf) == 59, str(len(wf)))

    names = ["r1.png", "r2.png"]
    out = comfy_patch_workflow(json.loads(json.dumps(wf)), names, "src.mp4",
                               h3_build_prompt("喝茶", 2), 6, 12345, 8, "480x640", 0.5)

    check("槽位真序: ref_image_0 <- 248", out["248"]["inputs"]["image"] == "r1.png", out["248"]["inputs"]["image"])
    check("槽位真序: ref_image_1 <- 249", out["249"]["inputs"]["image"] == "r2.png", out["249"]["inputs"]["image"])
    check("不足的槽位用最后一张补齐", out["247"]["inputs"]["image"] == "r2.png", out["247"]["inputs"]["image"])
    check("原视频进 VHS_LoadVideo", out["256"]["inputs"]["video"] == "src.mp4")
    check("时长写入 132", out["132"]["inputs"]["value"] == 6)
    check("分辨率写入 115", out["115"]["inputs"]["aspect_ratio"] == "3:4 (Portrait Standard)" and out["115"]["inputs"]["megapixels"] == 0.5)
    check("seed 写入 129", out["129"]["inputs"]["noise_seed"] == 12345)
    check("steps 写入 184", out["184"]["inputs"]["value"] == 8)
    h3i = out["275"]["inputs"]
    check("音轨直连 AUDIO 出口", all(h3i[k] == ["256", 2] for k in h3i if k.startswith("ref_audios.")))
    check("视频槽位保留", "ref_videos.ref_video_0" in h3i)
    prompt = json.loads(out["250"]["inputs"]["value"])
    check("prompt 是合法七字段 JSON", "subject_definitions" in prompt)

    print("\n[4] 工作流改写（无原视频 = 纯图生视频）")
    out2 = comfy_patch_workflow(json.loads(json.dumps(wf)), names, "",
                                h3_build_prompt("喝茶", 2), 5, 999, 8, "16:9", 0.9)
    h3i2 = out2["275"]["inputs"]
    check("断开 ref_videos", not any(k.startswith("ref_videos.") for k in h3i2))
    check("断开 ref_audios", not any(k.startswith("ref_audios.") for k in h3i2))
    check("参考图仍在", out2["248"]["inputs"]["image"] == "r1.png")
    check("时长写入", out2["132"]["inputs"]["value"] == 5)


def test_image_workflow():
    print("\n[5] 生图工作流")
    wf = json.load(io.open(os.path.join(WF_DIR, "API-image-sdxl.json"), encoding="utf-8"))
    check("10 节点", len(wf) == 10, str(len(wf)))
    check("有 CheckpointLoaderSimple", wf["1"]["class_type"] == "CheckpointLoaderSimple")
    check("有 KSampler", wf["5"]["class_type"] == "KSampler")
    check("有 VAEEncode(图生图)", wf["9"]["class_type"] == "VAEEncode")
    check("有 SaveImage", wf["7"]["class_type"] == "SaveImage")
    # 引用完整性
    refs = []
    for nid, node in wf.items():
        for k, v in node.get("inputs", {}).items():
            if isinstance(v, list) and len(v) == 2 and isinstance(v[0], str) and v[0] in wf:
                refs.append((nid, k, v[0]))
    dangling = [(n, k, t) for n, k, t in refs if t not in wf]
    check("无断引用", not dangling, str(dangling))


def test_slot_truth():
    print("\n[6] 槽位真序交叉验证（对照工作流真实接线）")
    wf = json.load(io.open(os.path.join(WF_DIR, "API-U17-9图1视频3音频V1-api.json"), encoding="utf-8"))
    h3 = wf["275"]["inputs"]
    expect = {
        "ref_images.ref_image_0": "248", "ref_images.ref_image_1": "249",
        "ref_images.ref_image_2": "247", "ref_images.ref_image_3": "246",
        "ref_images.ref_image_4": "279", "ref_images.ref_image_5": "280",
        "ref_images.ref_image_6": "281", "ref_images.ref_image_7": "282",
        "ref_images.ref_image_8": "283",
    }
    for key, node in expect.items():
        check("%s <- %s" % (key, node), h3.get(key) == [node, 0], str(h3.get(key)))


def _strip_php_strings(src):
    """去掉 PHP 字符串/注释，避免正则里的括号造成误报。"""
    out = []
    i = 0
    n = len(src)
    while i < n:
        c = src[i]
        if c == "'" or c == '"':
            q = c
            i += 1
            while i < n:
                if src[i] == "\\":
                    i += 2
                    continue
                if src[i] == q:
                    i += 1
                    break
                i += 1
            out.append(' ')
            continue
        if src.startswith('//', i):
            j = src.find("\n", i)
            i = n if j < 0 else j
            continue
        if src.startswith('/*', i):
            j = src.find('*/', i + 2)
            i = n if j < 0 else j + 2
            continue
        out.append(c)
        i += 1
    return "".join(out)


def test_php_files():
    print("\n[7] PHP 文件静态检查（无 php.exe，做字符串感知的括号平衡与关键符号检查）")
    for rel in ["index.php", "config/config.php", "config/comfy_core.php",
                "scripts/create_video.php", "scripts/poll_video.php",
                "scripts/fetch_content.php", "scripts/create_image.php",
                "scripts/poll_image.php", "scripts/upload_issue.php"]:
        p = os.path.join(ROOT, rel)
        src = io.open(p, encoding="utf-8").read()
        check("%s 存在" % rel, os.path.isfile(p))
        code = _strip_php_strings(src)
        check("%s 花括号平衡" % rel, code.count("{") - code.count("}") == 0,
              "{=%d }=%d" % (code.count("{"), code.count("}")))
        check("%s 圆括号平衡" % rel, code.count("(") - code.count(")") == 0,
              "(=%d )=%d" % (code.count("("), code.count(")")))
        check("%s <?php 开头" % rel, src.lstrip().startswith("<?php"))
        # 被 include 的库文件不强制 declare
        if rel != "config/comfy_core.php":
            check("%s 有 declare(strict_types=1)" % rel, "declare(strict_types=1)" in src)


def test_deploy_manifest():
    print("\n[8] 部署清单完整性")
    sys.path.insert(0, os.path.join(HERE, "scripts"))
    import deploy_comfy_relay as d
    for name in d.FILES:
        p = d.resolve_local(name)
        check("清单文件存在: %s" % name, os.path.isfile(p), p)
    check("清单包含全部 php", sum(1 for f in d.FILES if f.endswith(".php")) >= 7)
    check("清单包含 H3 工作流", any("U17" in f for f in d.FILES))
    check("清单包含生图工作流", any("image-sdxl" in f for f in d.FILES))
    # 真实 config 不应入库
    gitignore = io.open(os.path.join(ROOT, ".gitignore"), encoding="utf-8").read()
    check("真实 config.php 已 gitignore", "config/config.php" in gitignore)
    check("提供配置模板", os.path.isfile(os.path.join(ROOT, "config", "config.example.php")))
    real = io.open(os.path.join(ROOT, "config", "config.php"), encoding="utf-8").read()
    check("真实配置里没有占位符", "CHANGE_ME_RELAY_KEY" not in real)


def strip_prefix(path, marker="/comfy-relay"):
    """与 PHP index.php 的前缀剥离逻辑等价。"""
    pos = path.find(marker)
    if pos >= 0:
        path = path[pos + len(marker):]
    return "/" + path.strip("/")


def test_routing():
    print("\n[9] 路由前缀剥离（与 PHP 等价）")
    cases = [
        ("/comfy-relay", "/"),
        ("/comfy-relay/", "/"),
        ("/comfy-relay/health", "/health"),
        ("/comfy-relay/v1/videos", "/v1/videos"),
        ("/comfy-relay/v1/videos/abc123", "/v1/videos/abc123"),
        ("/comfy-relay/v1/videos/abc123/content", "/v1/videos/abc123/content"),
        ("/comfy-relay/v1/images/generations", "/v1/images/generations"),
        ("/comfy-relay/v1/images/edits", "/v1/images/edits"),
    ]
    for src, want in cases:
        got = strip_prefix(src)
        check("路由 %s -> %s" % (src, want), got == want, "got %s" % got)


def test_htaccess():
    print("\n[10] .htaccess 路由规则")
    ha = io.open(os.path.join(ROOT, ".htaccess"), encoding="utf-8").read()
    check("有 RewriteEngine On", "RewriteEngine On" in ha)
    check("有通用 rewrite 到 index.php", "index.php [QSA,L]" in ha)
    check("排除真实文件", "%{REQUEST_FILENAME} !-f" in ha)
    check("排除真实目录", "%{REQUEST_FILENAME} !-d" in ha)
    for d in ("state/", "config/", "workflows/", "scripts/"):
        check("屏蔽目录 %s" % d, ("RewriteRule ^%s - [F,L]" % d) in ha)
    check("保护 config.php", 'Files "config.php"' in ha)
    check("禁止列目录", "Options -Indexes" in ha)
    # 屏蔽规则必须在通用 rewrite 之前
    i_block = ha.find("RewriteRule ^state/")
    i_general = ha.find("RewriteRule ^(.*)$ index.php")
    check("屏蔽规则在通用 rewrite 之前", 0 <= i_block < i_general, "block@%d general@%d" % (i_block, i_general))


def test_strip_meta_nodes():
    print("\n[11] 元数据节点剔除（防 _api_config 类 400）")
    wf = json.load(io.open(os.path.join(WF_DIR, "API-U17-9图1视频3音频V1-api.json"), encoding="utf-8"))
    check("原始含 _api_config", "_api_config" in wf, "keys=%s" % list(wf.keys())[:5])
    stripped = {k: v for k, v in wf.items()
                if isinstance(v, dict) and v.get("class_type")}
    check("剔除后不含 _api_config", "_api_config" not in stripped)
    check("剔除后节点数 = 原数-1", len(stripped) == len(wf) - 1, "%d vs %d" % (len(stripped), len(wf)))
    check("剔除后仍保留 275", "275" in stripped)
    check("剔除后仍保留 250", "250" in stripped)
    # 模拟 ComfyUI 的校验：所有节点必须有 class_type
    bad = [k for k, v in stripped.items() if not v.get("class_type")]
    check("所有节点都有 class_type", not bad, str(bad))


def test_upload_flow():
    print("\n[12] 上传令牌流程约定")
    up = io.open(os.path.join(ROOT, "scripts", "upload_issue.php"), encoding="utf-8").read()
    check("有 uploads/issue 路由", "'/v1/uploads/issue'" in io.open(os.path.join(ROOT, "index.php"), encoding="utf-8").read())
    check("collect_references 支持 upload_id", "upload_id" in up)
    check("collect_references 支持 cloud_name", "cloud_name" in up)
    # ⚠️ 多图必须用数组式字段名：PHP 解析 multipart 时同名字段只保留最后一个
    check("多图支持 cloud_names[]", "cloud_names" in up and "is_array($cloudNames)" in up)
    check("多图支持 upload_ids[]", "upload_ids" in up and "is_array($uploadIds)" in up)
    check("数组式与单数式都支持", "'cloud_names[]'" in up and "'upload_id'" in up)
    check("数组式做数量校验", "数量必须一一对应" in up)
    check("原视频也支持数组式", "video_cloud_names" in up)
    # 画布本身传多图用的就是 image[] / images（PHP 天然支持数组）
    cv2 = io.open(os.path.join(ROOT, "scripts", "create_video.php"), encoding="utf-8").read()
    check("create_video 走 collect_references", "collect_references()" in cv2)
    check("cloud_name 有白名单校验", "preg_match" in up and "A-Za-z0-9._\\-" in up)
    check("collect_video 返回 ?array", "function collect_video(): ?array" in up)
    check("直传优先于中继上传", "direct" in up and "relay" in up)
    cv = io.open(os.path.join(ROOT, "scripts", "create_video.php"), encoding="utf-8").read()
    check("create_video 引入 upload_issue", "require __DIR__ . '/upload_issue.php'" in cv)
    check("create_video 用 collect_references", "collect_references()" in cv)
    check("create_video 用 collect_video", "collect_video()" in cv)
    # 换机工具（手动，不依赖任何自动注册机制）
    sw_p = os.path.join(ROOT, "scripts", "switch_cloud.py")
    check("switch_cloud.py 存在", os.path.isfile(sw_p))
    if os.path.isfile(sw_p):
        sw = io.open(sw_p, encoding="utf-8").read()
        check("switch_cloud 不依赖已回退的注册端点", "/admin/register-cloud" not in sw)
        check("switch_cloud 改 COMFY_BASE", "COMFY_BASE" in sw)
        check("switch_cloud 先探活再改", "probe(base)" in sw and "未切换" in sw)
        check("switch_cloud 能解释 404", "网关 404" in sw)
        check("switch_cloud 能解释 307", "网关 307" in sw)
        check("switch_cloud 有 --force", "--force" in sw)
        check("switch_cloud 能重新部署", "deploy_config" in sw)
        check("switch_cloud 有 --status", "--status" in sw)


def test_retry_and_timeout():
    print("\n[13] 重试与超时（Hostinger->云机 25% 超时率）")
    core = io.open(os.path.join(ROOT, "config", "comfy_core.php"), encoding="utf-8").read()
    check("comfy_request 有重试循环", "for ($attempt = 0; $attempt <= $retries" in core)
    check("连接错误会重试", "if ($last['error'] !== null) {" in core)
    check("5xx/429 会重试", "$last['status'] >= 500 || $last['status'] === 429" in core)
    check("有退避 sleep", "sleep(1 << ($attempt - 1))" in core)
    cv = io.open(os.path.join(ROOT, "scripts", "create_video.php"), encoding="utf-8").read()
    # 超时预算：单次 timeout × (retries+1) + 退避 sleep，必须远小于 PHP 上限(30s)
    budget_fail = []
    for rel in ["scripts/create_video.php", "scripts/poll_video.php",
                "scripts/create_image.php", "scripts/poll_image.php"]:
        src = io.open(os.path.join(ROOT, rel), encoding="utf-8").read()
        for m in re.finditer(r"'timeout'\s*=>\s*(\d+)\s*,\s*'retries'\s*=>\s*(\d+)", src):
            t, r = int(m.group(1)), int(m.group(2))
            total = t * (r + 1) + (2 ** r - 1)
            if total >= 25:
                budget_fail.append("%s: %ds x %d retries = %ds" % (rel, t, r, total))
    check("超时预算均 < 25s（PHP 上限 30s）", not budget_fail, str(budget_fail))
    check("body 类型不是 array 约束", "?array $body = null" not in core)


def test_route_param_passing():
    print("\n[14] 路由参数传递（$argv 补齐，防「非法 task id」）")
    idx = io.open(os.path.join(ROOT, "index.php"), encoding="utf-8").read()
    # 三处带 id 的路由，require 前都必须设 $argv
    routes = [
        ("videos poll", "#^/v1/videos/([A-Za-z0-9_\\-]+)$#", "poll_video.php"),
        ("videos content", "#^/v1/videos/([A-Za-z0-9_\\-]+)/content$#", "fetch_content.php"),
        ("images poll", "#^/v1/images/([A-Za-z0-9_\\-]+)$#", "poll_image.php"),
    ]
    for label, pat, script in routes:
        i = idx.find(pat)
        check("%s 路由存在" % label, i >= 0, pat)
        if i < 0:
            continue
        seg = idx[i:i + 400]
        j = seg.find("require")
        check("%s require 前注入了 $taskId" % label, "$taskId = $m[1];" in seg[:j] if j > 0 else False,
              seg[:160])
        check("%s require 目标正确" % label, script in seg[:j + 80] if j > 0 else False)
    # 脚本侧：优先用 index.php 注入的 $taskId，再兜底 CLI
    for script in ("poll_video.php", "poll_image.php", "fetch_content.php"):
        src = io.open(os.path.join(ROOT, "scripts", script), encoding="utf-8").read()
        check("%s 优先用注入的 $taskId" % script,
              "if (!isset($taskId) || $taskId === '')" in src)
        check("%s 有 id 白名单校验" % script,
              "preg_match('/^[A-Za-z0-9_\\-]+$/', (string) $taskId)" in src)


def test_comment_balance():
    print("\n[15] PHP 注释块闭合（防 /** 被截断成 *）")
    for rel in ["index.php", "config/config.php", "config/comfy_core.php",
                "scripts/create_video.php", "scripts/poll_video.php",
                "scripts/fetch_content.php", "scripts/create_image.php",
                "scripts/poll_image.php", "scripts/upload_issue.php"]:
        src = io.open(os.path.join(ROOT, rel), encoding="utf-8").read()
        opens = src.count("/*")
        closes = src.count("*/")
        check("%s 注释块配平" % rel, opens == closes, "/*=%d */=%d" % (opens, closes))
        # 逐行找「不在注释块内却以 * 开头」的行（典型症状：/** 被截断）
        inblock = False
        bad = []
        for i, line in enumerate(src.split("\n"), 1):
            st = line.strip()
            if inblock:
                if "*/" in st:
                    inblock = False
                continue
            if st.startswith("/*"):
                if "*/" not in st:
                    inblock = True
                continue
            if st.startswith("*"):
                bad.append(i)
        check("%s 无游离的 * 行" % rel, not bad, "行 %s" % bad[:5])


def load_profiles_php():
    """读取 config/video_profiles.php 里的档案表。"""
    path = os.path.join(ROOT, "config", "video_profiles.php")
    src = io.open(path, encoding="utf-8").read()
    start = src.index("return [")
    end = src.index("\n    ];", start)
    return parse_php_array(src[start + len("return "):end + 1])


def test_profiles_table():
    print("\n[16] 多 U 系档案表（config/video_profiles.php）")
    profiles = load_profiles_php()
    check("档案表可解析且非空", isinstance(profiles, dict) and len(profiles) >= 10, "共 %d 个" % len(profiles))
    check("每个档案都有 file/mode/label/defaults",
          all(all(k in p for k in ("file", "mode", "label", "defaults")) for p in profiles.values()))
    check("mode 只有 pickref / slots 两种",
          all(p["mode"] in ("pickref", "slots") for p in profiles.values()),
          str(sorted({p["mode"] for p in profiles.values()})))

    # 工作流文件都在
    missing = [p["file"] for p in profiles.values()
               if not os.path.isfile(os.path.join(WF_DIR, p["file"] + ".json"))]
    check("档案引用的工作流文件全部存在", not missing, "缺失: %s" % missing)

    # 槽位节点号在工作流里真实存在
    bad = []
    for name, p in profiles.items():
        wf = load_wf(p["file"])
        slots = p.get("slots") or {}
        for key in ("h3", "pick"):
            if slots.get(key) and slots[key] not in wf:
                bad.append("%s.%s=%s" % (name, key, slots[key]))
        for grp in ("images", "audios", "videos"):
            for idx, nid in (slots.get(grp) or {}).items():
                if nid not in wf:
                    bad.append("%s.%s[%s]=%s" % (name, grp, idx, nid))
        for key in ("duration", "seed", "aspect"):
            if slots.get(key) and slots[key] not in wf:
                bad.append("%s.%s=%s" % (name, key, slots[key]))
        ps = slots.get("prompt")
        if isinstance(ps, list) and ps[0] not in wf:
            bad.append("%s.prompt=%s" % (name, ps[0]))
    check("档案里的所有槽位节点号在工作流中真实存在", not bad, "; ".join(bad[:6]))

    # 槽位语义正确
    sem = []
    for name, p in profiles.items():
        wf = load_wf(p["file"])
        slots = p.get("slots") or {}
        for idx, nid in (slots.get("images") or {}).items():
            if wf[nid].get("class_type") != "LoadImage":
                sem.append("%s 图槽%s -> %s(%s)" % (name, idx, nid, wf[nid].get("class_type")))
            elif "image" not in (wf[nid].get("inputs") or {}):
                sem.append("%s 图槽%s 节点无 image 输入" % (name, idx))
        for idx, nid in (slots.get("audios") or {}).items():
            ct = wf[nid].get("class_type", "")
            if not ("Audio" in ct or "LoadAudio" in ct):
                sem.append("%s 音槽%s -> %s" % (name, idx, ct))
        for idx, nid in (slots.get("videos") or {}).items():
            if "LoadVideo" not in wf[nid].get("class_type", ""):
                sem.append("%s 视槽%s -> %s" % (name, idx, wf[nid].get("class_type")))
        ps = slots.get("prompt")
        if isinstance(ps, list) and len(ps) == 2:
            if ps[1] not in (wf[ps[0]].get("inputs") or {}):
                sem.append("%s prompt 节点无 %s 输入" % (name, ps[1]))
        if slots.get("duration") and wf[slots["duration"]].get("class_type") != "PrimitiveFloat":
            sem.append("%s duration 不是 PrimitiveFloat" % name)
        if slots.get("seed") and "noise_seed" not in (wf[slots["seed"]].get("inputs") or {}):
            sem.append("%s seed 节点无 noise_seed" % name)
        if slots.get("pick") and wf[slots["pick"]].get("class_type") != "ZealmanH3PickReferenceMedia":
            sem.append("%s pick 节点类型不对" % name)
    check("槽位语义正确（LoadImage/音频/视频/prompt/duration/seed）", not sem, "; ".join(sem[:6]))

    # 档案默认值合法
    bad_def = []
    for name, p in profiles.items():
        d = p.get("defaults") or {}
        if not (0 < float(d.get("seconds", 0)) <= 150):
            bad_def.append("%s seconds=%s" % (name, d.get("seconds")))
        if h3_aspect(str(d.get("aspect", ""))) not in VALID_ASPECTS:
            bad_def.append("%s aspect=%s" % (name, d.get("aspect")))
    check("档案默认秒数/比例合法", not bad_def, "; ".join(bad_def[:6]))
    return profiles


if __name__ == "__main__":
    test_prompt()
    test_aspect()
    test_workflow_patch()
    test_image_workflow()
    test_slot_truth()
    test_php_files()
    test_deploy_manifest()
    test_routing()
    test_htaccess()
    test_strip_meta_nodes()
    test_upload_flow()
    test_retry_and_timeout()
    test_route_param_passing()
    test_comment_balance()
    _profiles = test_profiles_table()
    test_profiles_patch(_profiles)
    test_profile_workflow_pairing(_profiles)
    print("\n" + "=" * 56)
    print("PASS=%d  FAIL=%d" % (PASS, FAIL))
    print("=" * 56)
    sys.exit(1 if FAIL else 0)
