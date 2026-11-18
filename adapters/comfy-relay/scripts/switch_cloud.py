# -*- coding: utf-8 -*-
"""换云机后的中继切换脚本（手动，一条命令搞定）。

AutoDL 实例是按小时计费的临时机，**换机器/换 IP 后公网入口会变**
（域名形如 u1002042-<实例ID>.<区域>.seetacloud.com，实例ID 随机器变）。

画布侧**不用改**（画布填的是中继地址，中继地址固定）。
只有中继要知道云机的新地址 —— 这个脚本负责告诉它。

用法:
    # 1) 切到新地址（探活 -> 改 config.php -> 重新部署 -> 验证）
    python scripts/switch_cloud.py --base https://u1002042-XXXX.bjb2.seetacloud.com:8443

    # 2) 只看当前状态 + 上游探活
    python scripts/switch_cloud.py --status

做了四件事：
  1) 先探活新地址（不通就不改，避免把中继指向死地址导致画布全挂）
  2) 更新本地 config/config.php 的 COMFY_BASE
  3) 重新部署 config.php 到 Hostinger
  4) 打印验证结果（当前 upstream + 上游探活）

前置：~/.workbuddy/user-*/credentials.local.json 里有 ftp 段。
"""
import argparse
import io
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, HERE)

SITE = "https://slategrey-vulture-564350.hostingersite.com"
RELAY = SITE + "/comfy-relay"


def read_config() -> str:
    return io.open(os.path.join(ROOT, "config", "config.php"), encoding="utf-8").read()


def write_config(src: str) -> None:
    io.open(os.path.join(ROOT, "config", "config.php"), "w", encoding="utf-8").write(src)


def http(method: str, url: str, headers=None, data=None, timeout=45):
    import urllib.request
    import urllib.error
    req = urllib.request.Request(url, method=method, data=data, headers=headers or {})
    # 走直连，避开本机代理（实测代理链路不稳）
    op = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    try:
        r = op.open(req, timeout=timeout)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")
    except Exception as e:  # noqa: BLE001
        return 0, repr(e)


def probe(base: str, timeout: int = 12):
    """探活指定地址。返回 (ok, 说明, 详情)

    AutoDL 网关的返回码有明确含义（实测）：
      200 -> 通
      404 -> 实例ID 或区域不对
      307 -> 实例在，但端口映射没开
      0   -> 连不上（实例没开机 / 映射未开）
    """
    code, text = http("GET", base.rstrip("/") + "/system_stats", timeout=timeout)
    if code == 200:
        try:
            ver = json.loads(text).get("system", {}).get("comfyui_version")
        except Exception:
            ver = None
        return True, "通 (ComfyUI %s)" % (ver or "?"), {"http_status": code}
    if code == 404:
        return False, "网关 404：实例ID 或区域不对（域名应为 u1002042-<实例ID>.<区域>.seetacloud.com）", {"http_status": code}
    if code == 307:
        return False, "网关 307：实例在，但 AutoDL 控制台的端口映射没开（需开 8443 -> 容器 6006）", {"http_status": code}
    if code == 0:
        return False, "连不上：实例可能没开机，或端口映射未开", {"http_status": code}
    return False, "HTTP %s" % code, {"http_status": code, "body": text[:200]}


def deploy_config() -> bool:
    """把 config/config.php 重新部署到 Hostinger。"""
    import time

    import deploy_comfy_relay as d
    try:
        host, user, pw = d.load_ftp()
    except Exception as e:  # noqa: BLE001
        print("  [warn] 没有 FTP 凭据，跳过部署:", e)
        return False
    with io.open(d.resolve_local("config/config.php"), "rb") as fh:
        data = fh.read()
    ok, msg = False, ""
    for _ in range(3):
        ok, msg = d.send(host, user, pw, d.REMOTE_DIR + "/config/config.php", data)
        if ok:
            break
        time.sleep(2)
    print("  部署 config.php:", "OK" if ok else "FAIL %s" % msg)
    return ok


def status():
    code, text = http("GET", RELAY + "/health", timeout=30)
    print("=== 中继当前状态 ===")
    if code != 200:
        print("  HTTP %s  %s" % (code, text[:200]))
        return
    d = json.loads(text)
    upstream = d.get("upstream", "")
    print("  upstream        :", upstream)
    if upstream:
        ok, why, _ = probe(upstream, timeout=20)
        print("  上游探活        :", "OK" if ok else "FAIL")
        print("                   ", why)
    print()
    code, text = http("GET", RELAY + "/diag/upstream", timeout=45)
    print("=== /diag/upstream ===")
    if code == 200:
        d = json.loads(text)
        print("  ok              :", d.get("ok"))
        print("  latency_ms     :", d.get("latency_ms"))
        print("  comfyui_version :", d.get("comfyui_version"))
        print("  error           :", d.get("error"))
    else:
        print("  HTTP %s  %s" % (code, text[:300]))


def main() -> int:
    ap = argparse.ArgumentParser(description="换云机后切换中继指向")
    ap.add_argument("--base", help="新的云机公网入口，如 https://u1002042-XXXX.bjb2.seetacloud.com:8443")
    ap.add_argument("--status", action="store_true", help="只看当前状态")
    ap.add_argument("--no-deploy", action="store_true", help="不重新部署 config.php")
    ap.add_argument("--force", action="store_true", help="探活失败也强行切换（不推荐）")
    args = ap.parse_args()

    if args.status:
        status()
        return 0

    if not args.base:
        ap.print_help()
        return 1

    base = args.base.rstrip("/")
    if not re.match(r"^https://[A-Za-z0-9._\-]+(:\d+)?$", base):
        print("base 格式不对：", base)
        return 1

    print("=== 切换中继指向 ===")
    print("  新地址:", base)

    # 1) 先探活 —— 不通就不改，避免把中继指向死地址导致画布全挂
    ok, why, _ = probe(base)
    print("  探活:", "OK" if ok else "FAIL", "-", why)
    if not ok and not args.force:
        print("\n⚠️ 新地址不通，**未切换**（中继继续用旧地址，画布不受影响）。")
        print("   处理：确认实例已开机 + 控制台已开 8443 端口映射 + 域名格式正确。")
        print("   确实要强切：加 --force")
        print()
        status()
        return 1

    # 2) 改 config.php
    src = read_config()
    pat = r"define\(\s*'COMFY_BASE'\s*,\s*'[^']*'\)"
    if not re.search(pat, src):
        print("  [warn] config.php 里没找到 COMFY_BASE 定义")
    else:
        new = re.sub(pat, "define('COMFY_BASE', '%s')" % base, src)
        if new == src:
            print("  COMFY_BASE 已经是该地址，无需改动")
        else:
            write_config(new)
            print("  已更新 config/config.php")

    # 3) 重新部署
    if not args.no_deploy:
        deploy_config()

    print()
    status()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
