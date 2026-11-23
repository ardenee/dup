#!/usr/bin/env python3
"""Create a *preview* DSM 7 SPK with private Symfony code and a CLI job service.

Not a release: requires DSM installation, first-run setup and worker acceptance.
"""
from __future__ import annotations
import argparse
import gzip
import hashlib
import io
import json
import re
import tarfile
from pathlib import Path
from build_preview import payload

ROOT=Path(__file__).resolve().parent.parent
DSM=ROOT/"packaging"/"dsm7"
HOOKS=("preinst","postinst","preupgrade","postupgrade","preuninst",
       "postuninst","start-stop-status")

def member(tar:tarfile.TarFile,name:str,data:bytes,mode:int=0o644)->None:
    info=tarfile.TarInfo(name)
    info.uid=info.gid=info.mtime=0
    info.uname=info.gname=""
    info.size=len(data)
    info.mode=mode
    tar.addfile(info,io.BytesIO(data))

def package_contents() -> dict[str,bytes]:
    source=payload()
    files={"app/"+name:data for name,data in source.items()}
    front=b"""<?php
declare(strict_types=1);
// DSM v2 exposes ONLY this front controller and static public assets.
require '/var/packages/dupv2/target/app/public/index.php';
"""
    files["webapp/index.php"]=front
    for name,data in source.items():
        if name.startswith("public/") and name!="public/index.php":
            files["webapp/"+name[len("public/"):]]=data
    files["app/worker-loop.sh"]=(DSM/"worker-loop.sh").read_bytes().replace(b"\r\n",b"\n")
    return files

def make_inner(files:dict[str,bytes])->bytes:
    data=io.BytesIO()
    with gzip.GzipFile(fileobj=data,filename="",mode="wb",mtime=0,compresslevel=7) as zipbuf:
        with tarfile.open(fileobj=zipbuf,mode="w",format=tarfile.PAX_FORMAT) as tar:
            for name,content in sorted(files.items()):
                member(tar,name,content,0o755 if name=="app/worker-loop.sh" else 0o644)
    return data.getvalue()

def metadata(version:str,php_backend:int,files:dict[str,bytes])->dict[str,tuple[bytes,int]]:
    resource=(DSM/"conf"/"resource").read_text(encoding="utf-8")
    resource=resource.replace("__PHP_BACKEND__",str(php_backend))
    parsed=json.loads(resource)
    service=parsed["webservice"]["services"][0]
    assert service["root"]=="dup-v2" and service["service"]=="dupv2"
    assert parsed["webservice"]["pkg_dir_prepare"][0]["source"]=="/var/packages/dupv2/target/webapp"
    assert parsed["webservice"]["pkg_dir_prepare"][0]["target"]=="dup-v2"
    assert parsed["webservice"]["portals"][0]["alias"]=="dup-v2"
    assert "var/packages/dupv2/target/app" in service["php"]["open_basedir"]
    info=(DSM/"INFO").read_text(encoding="utf-8").replace("__VERSION__",version).encode()
    assert b'package="dupv2"' in info and b'adminurl="dup-v2/"' in info
    members={
        "INFO":(info,0o644),
        "package.tgz":(make_inner(files),0o644),
        "conf/resource":(resource.encode(),0o644),
        "conf/privilege":((DSM/"conf"/"privilege").read_bytes(),0o644),
    }
    for hook in HOOKS:
        content=(DSM/"scripts"/hook).read_bytes().replace(b"\r\n",b"\n")
        if not content.startswith(b"#!/bin/sh\n"):
            raise RuntimeError("Invalid DSM script: "+hook)
        members["scripts/"+hook]=(content,0o755)
    for icon in ("PACKAGE_ICON.PNG","PACKAGE_ICON_256.PNG"):
        members[icon]=((DSM/icon).read_bytes(),0o644)
    return members

def build(output:Path,version:str,php_backend:int)->None:
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+",version):
        raise RuntimeError("Invalid numeric preview version")
    if not 8<=php_backend<=30:
        raise RuntimeError("Invalid Synology PHP Web Station backend ID")
    app=package_contents()
    members=metadata(version,php_backend,app)
    output.parent.mkdir(parents=True,exist_ok=True)
    with tarfile.open(output,"w",format=tarfile.USTAR_FORMAT) as outer:
        for name,(data,mode) in sorted(members.items()):
            member(outer,name,data,mode)
    with tarfile.open(output,"r") as outer:
        found={m.name:(outer.extractfile(m).read(),m.mode)
               for m in outer.getmembers() if m.isfile()}
    if found!=members:
        raise RuntimeError("Outer SPK differs from staged source")
    with tarfile.open(fileobj=io.BytesIO(found["package.tgz"][0]),mode="r:gz") as inner:
        rebuilt={m.name:inner.extractfile(m).read()
                 for m in inner.getmembers() if m.isfile()}
    if rebuilt!=app:
        raise RuntimeError("SPK application files did not survive packaging")
    web={key for key in rebuilt if key.startswith("webapp/")}
    if any(key.endswith((".env",".env.local",".db",".sqlite",".log"))
           or key.startswith(("webapp/vendor/","webapp/src/","webapp/config/"))
           for key in web):
        raise RuntimeError("Private source or secrets exposed in DSM web directory")
    if not {"webapp/index.php","webapp/assets/app.css","webapp/assets/folder-picker.js",
            "app/vendor/autoload.php","app/bin/console","app/worker-loop.sh"} <= rebuilt.keys():
        raise RuntimeError("Preview SPK missing required application runtime files")
    if b"DUP_ALLOWED_SCAN_ROOTS=" not in rebuilt["app/.env"]:
        raise RuntimeError("Missing setup safety default")
    print("PASS",output.name, len(app),"files",len(web),"public paths")
    print("SHA256",hashlib.sha256(output.read_bytes()).hexdigest())
    print("PREVIEW ONLY — not installed, not a release")

if __name__=="__main__":
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version",default="0.1.0")
    parser.add_argument("--php-backend",type=int,default=11,
        help="Web Station PHP backend ID, default 11 validated for PHP8.2 on the test DSM")
    parser.add_argument("--output",type=Path,default=None)
    args=parser.parse_args()
    output=args.output or ROOT/"dist"/f"DUP-v2-DSM7-{args.version}-preview.spk"
    build(output,args.version,args.php_backend)
