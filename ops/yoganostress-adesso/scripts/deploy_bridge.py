#!/usr/bin/env python3
import argparse, base64, hashlib, json, os, urllib.request, urllib.error
from pathlib import Path

NS="/wp-json/ph-plugin-bridge/v1"

def env(name):
    v=os.environ.get(name,"").strip()
    if not v:
        raise SystemExit(f"Missing environment variable: {name}")
    return v

def auth_header(user,pw):
    token=base64.b64encode(f"{user}:{pw}".encode()).decode()
    return f"Basic {token}"

def request(method, path, payload=None):
    base=env("STAGING_URL").rstrip("/")
    user=env("WP_USER"); pw=env("WP_APP_PASSWORD")
    data=None if payload is None else json.dumps(payload,separators=(",",":")).encode()
    req=urllib.request.Request(base+NS+path, data=data, method=method)
    req.add_header("Authorization", auth_header(user,pw))
    req.add_header("Accept","application/json")
    if data is not None:
        req.add_header("Content-Type","application/json")
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        body=e.read().decode(errors="replace")
        raise SystemExit(f"HTTP {e.code} {path}: {body}")

def verify(slug):
    return request("POST","/plugins/verify",{"slug":slug})

def rollback(backup_id):
    if not backup_id:
        raise SystemExit("No backup_id available for rollback")
    return request("POST","/rollback",{"backup_id":backup_id})

def deploy(zip_path, slug, activate, state_path):
    p=Path(zip_path)
    raw=p.read_bytes()
    fullsha=hashlib.sha256(raw).hexdigest()
    start=request("POST","/upload/start",{
        "filename":p.name,
        "total_size":len(raw),
        "sha256":fullsha,
        "target_slug":slug
    })
    uid=start["upload_id"]
    chunk_max=min(int(start.get("chunk_max",524288)),384*1024)
    index=0
    for off in range(0,len(raw),chunk_max):
        chunk=raw[off:off+chunk_max]
        b64u=base64.urlsafe_b64encode(chunk).decode().rstrip("=")
        request("POST","/upload/chunk",{
            "upload_id":uid,
            "index":index,
            "data_base64url":b64u,
            "chunk_sha256":hashlib.sha256(chunk).hexdigest()
        })
        index+=1
    fin=request("POST","/upload/finalize",{"upload_id":uid})
    if fin.get("sha256")!=fullsha:
        raise SystemExit("Final SHA-256 mismatch")
    dep=request("POST","/deploy",{
        "package_id":fin["package_id"],
        "activate":bool(activate)
    })
    state={
        "sha256":fullsha,
        "finalize":fin,
        "deploy":dep,
        "backup_id":dep.get("backup_id")
    }
    Path(state_path).write_text(json.dumps(state,indent=2),encoding="utf-8")
    ver=verify(slug)
    if not ver.get("active") and activate:
        if state["backup_id"]:
            rollback(state["backup_id"])
        raise SystemExit("Plugin not active after deploy; rollback executed")
    print(json.dumps(state,indent=2))

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--zip")
    ap.add_argument("--slug")
    ap.add_argument("--activate",action="store_true")
    ap.add_argument("--state",default="deploy-state.json")
    ap.add_argument("--verify")
    ap.add_argument("--rollback-from-state")
    args=ap.parse_args()
    if args.verify:
        print(json.dumps(verify(args.verify),indent=2))
        return
    if args.rollback_from_state:
        s=json.loads(Path(args.rollback_from_state).read_text())
        print(json.dumps(rollback(s.get("backup_id")),indent=2))
        return
    if not args.zip or not args.slug:
        ap.error("--zip and --slug required")
    deploy(args.zip,args.slug,args.activate,args.state)

if __name__=="__main__":
    main()
