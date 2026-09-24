#!/usr/bin/env python3
import argparse, base64, hashlib, json, os, sys, urllib.request, urllib.error
from pathlib import Path

NS="/wp-json/ph-plugin-bridge/v1"

def env(name):
    v=os.environ.get(name,"").strip()
    if not v: raise SystemExit(f"Missing environment variable: {name}")
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
    req.add_header,"Accept","application/json")
