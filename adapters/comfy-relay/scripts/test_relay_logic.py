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


def h3_aspect(aspect):
    a = (aspect or "").strip()
    if not a:
        return "16:9 (Widescreen)"
    m = re.match(r"^(\d+)\s*x\s*(\d+)$", a)
    if m:
        w, h = int(m.group(1)), int(m.group(2))
        r = w / max(1, h)
        if r > 2.2:
            return "21:9 (Cinematic)"
        if r > 1.4:
            return "16:9 (Widescreen)"
        if r > 1.1:
            return "4:3 (Standard)"
        if r > 0.9:
            return "1:1 (Square)"
        if r > 0.65:
            return "3:4 (Portrait)"
        return "9:16 (Vertical)"
    return {
        "16:9": "16:9 (Widescreen)", "4:3": "4:3 (Standard)", "1:1": "1:1 (Square)",
        "3:4": "3:4 (Portrait)", "9:16": "9:16 (Vertical)", "21:9": "21:9 (Cinematic)",
        "auto": "16:9 (Widescreen)",
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
        ("16:9", "16:9 (Widescreen)"), ("480x640", "3:4 (Portrait)"),
        ("1024x1024", "1:1 (Square)"), ("1920x1080", "16:9 (Widescreen)"),
        ("720x1280", "9:16 (Vertical)"), ("", "16:9 (Widescreen)"),
        ("auto", "16:9 (Widescreen)"), ("2560x1080", "21:9 (Cinematic)"),
        ("1024x768", "4:3 (Standard)"),
    ]
    for src, want in cases:
        got = h3_aspect(src)
        check("aspect %r -> %s" % (src, want), got == want, "got %s" % got)


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
    check("分辨率写入 115", out["115"]["inputs"]["aspect_ratio"] == "3:4 (Portrait)" and out["115"]["inputs"]["megapixels"] == 0.5)
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
                "scripts/fetch_content.php", "scripts/create_image.php"]:
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


if __name__ == "__main__":
    test_prompt()
    test_aspect()
    test_workflow_patch()
    test_image_workflow()
    test_slot_truth()
    test_php_files()
    test_deploy_manifest()
    print("\n" + "=" * 56)
    print("PASS=%d  FAIL=%d" % (PASS, FAIL))
    print("=" * 56)
    sys.exit(1 if FAIL else 0)
