# -*- coding: utf-8 -*-
"""ComfyUI 云机渠道适配层 · 部署脚本。

Steps:  probe -> lint -> upload -> verify
    python deploy_comfy_relay.py probe
    python deploy_comfy_relay.py lint
    python deploy_comfy_relay.py upload
    python deploy_comfy_relay.py verify

远端目录：comfy-relay/（挂在站点根下，公网地址 .../comfy-relay/）
本机没有 php.exe，所以语法检查在服务器上跑：上传一个一次性 lint 脚本，
用 token_get_all(..., TOKEN_PARSE) 解析，脚本读完自删。
"""
import base64
import ftplib
import glob
import io
import json
import os
import random
import sys
import time
import urllib.error
import urllib.request

SITE = "https://slategrey-vulture-564350.hostingersite.com"
REMOTE_DIR = "comfy-relay"
# 本脚本在 adapters/comfy-relay/scripts/ 下，往上两层才是服务根目录
LOCAL_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# 需要上传的文件（相对 REMOTE_DIR）
FILES = [
    ".htaccess",
    "index.php",
    "config/config.php",
    "config/comfy_core.php",
    "scripts/create_video.php",
    "scripts/poll_video.php",
    "scripts/fetch_content.php",
    "scripts/create_image.php",
    "scripts/poll_image.php",
    "scripts/upload_issue.php",
    "workflows/API-U17-9图1视频3音频V1-api.json",
    "workflows/API-image-sdxl.json",
]

# config/config.php 含真实密钥，**不入 Git**（见 .gitignore）。
# 部署时优先用本地真实配置；本地没有就退回仓库里的模板（部署后必须手工改）。
LOCAL_ONLY = {"config/config.php"}


def resolve_local(name: str) -> str:
    """返回实际要上传的本地路径。"""
    p = os.path.join(LOCAL_DIR, name)
    if name in LOCAL_ONLY and not os.path.isfile(p):
        fallback = os.path.join(LOCAL_DIR, "config", "config.example.php")
        if os.path.isfile(fallback):
            print("WARN  %s 不存在，改用模板（部署后必须手工改 RELAY_KEY）" % name)
            return fallback
    return p


def load_ftp():
    for p in glob.glob(os.path.expanduser("~/.workbuddy/user-*/credentials.local.json")):
        with io.open(p, encoding="utf-8") as fh:
            j = json.load(fh)
            if "ftp" in j:
                c = j["ftp"]
                return c["host"], c["user"], c["pass"]
    raise RuntimeError("no ftp credentials found")


def connect(host, user, pw):
    f = ftplib.FTP()
    f.connect(host, 21, timeout=40)
    f.login(user, pw)
    return f


def ensure_dir(f, path):
    """逐级 cd 进 path，每级不存在就建。注意：FTP 的 cwd 是相对当前目录的，
    不能用 'a/b/' 这种累加路径（会重复进入同一层导致 550）。"""
    for part in [p for p in path.split("/") if p]:
        try:
            f.cwd(part)
        except Exception:
            f.mkd(part)
            f.cwd(part)


def send(host, user, pw, remote, data: bytes, retries=4):
    last = ""
    for attempt in range(retries):
        try:
            f = connect(host, user, pw)
            parts = [p for p in remote.split("/") if p]
            ensure_dir(f, "/".join(parts[:-1]))
            f.storbinary("STOR " + parts[-1], io.BytesIO(data))
            f.quit()
            return True, "stored " + remote
        except Exception as e:  # noqa: BLE001
            last = repr(e)
            time.sleep(1.5)
    return False, last


def get(url, timeout=40):
    req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0 (comfy-relay-deploy)"})
    op = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    try:
        r = op.open(req, timeout=timeout)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")
    except Exception as e:  # noqa: BLE001
        return 0, repr(e)


def main() -> int:
    step = sys.argv[1] if len(sys.argv) > 1 else "probe"
    host, user, pw = load_ftp()

    if step == "probe":
        f = connect(host, user, pw)
        print("pwd:", f.pwd())
        f.retrlines("LIST")
        for d in (REMOTE_DIR, REMOTE_DIR + "/config", REMOTE_DIR + "/scripts",
                  REMOTE_DIR + "/workflows", REMOTE_DIR + "/state"):
            try:
                ensure_dir(f, d)
                print("OK dir", d)
            except Exception as e2:  # noqa: BLE001
                print("FAIL dir", d, repr(e2))
        f.quit()
        return 0

    if step == "lint":
        payload = {}
        for name in FILES:
            if not name.endswith(".php"):
                continue
            path = resolve_local(name)
            with io.open(path, encoding="utf-8") as fh:
                payload[name] = fh.read()
        b64 = base64.b64encode(json.dumps(payload, ensure_ascii=False).encode("utf-8")).decode("ascii")
        code = """<?php
header('Content-Type: text/plain; charset=utf-8');
register_shutdown_function(function () { @unlink(__FILE__); });
$files = json_decode(base64_decode('%s'), true);
$bad = 0;
foreach ($files as $n => $c) {
    try { token_get_all($c, TOKEN_PARSE); echo "OK    $n (" . strlen($c) . " chars)\\n"; }
    catch (ParseError $e) { echo "ERR   $n -> " . $e->getMessage() . " @line " . $e->getLine() . "\\n"; $bad++; }
}
echo "\\nbad = $bad\\n";
""" % b64
        name = "_prelint-" + "".join(random.choice("0123456789abcdef") for _ in range(6)) + ".php"
        ok, msg = send(host, user, pw, name, code.encode("utf-8"))
        print("upload lint script:", ok, msg)
        if not ok:
            return 1
        status, body = get(SITE + "/" + name)
        print("HTTP", status)
        print(body)
        return 0 if ("bad = 0" in body) else 1

    if step == "upload":
        # .htaccess first so config.php is protected the moment it lands
        order = [".htaccess", "index.php", "config/config.php", "config/comfy_core.php",
                 "scripts/create_video.php", "scripts/poll_video.php",
                 "scripts/fetch_content.php", "scripts/create_image.php",
                 "scripts/poll_image.php", "scripts/upload_issue.php",
                 "workflows/API-U17-9图1视频3音频V1-api.json", "workflows/API-image-sdxl.json"]
        rc = 0
        for name in order:
            path = resolve_local(name)
            if not os.path.isfile(path):
                print("%-50s MISSING (local)" % name)
                rc = 1
                continue
            with io.open(path, "rb") as fh:
                data = fh.read()
            ok, msg = send(host, user, pw, REMOTE_DIR + "/" + name, data)
            print("%-50s %s %s" % (name, "OK" if ok else "FAIL", msg))
            if not ok:
                rc = 1
        return rc

    if step == "verify":
        for path in ["/comfy-relay/health", "/comfy-relay/v1/videos/nonexistent"]:
            status, body = get(SITE + path)
            print("==", path, "HTTP", status)
            print(body[:1500])
            print()
        return 0

    print("unknown step:", step)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
