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
    # Portal wrappers are source-controlled copies of the verified live DSM
    # endpoints. Keep them in Git so an upgrade cannot regress login/session
    # handling or the setup completion screen.
    files["webapp/index.php"]=(DSM/"webapp"/"index.php").read_bytes().replace(b"\r\n",b"\n")
    files["webapp/setup.php"]=(DSM/"webapp"/"setup.php").read_bytes().replace(b"\r\n",b"\n")
    for name,data in source.items():
        if name.startswith("public/") and name not in {
            "public/index.php","public/setup.php","public/.htaccess","public/web.config"
        }:
            files["webapp/"+name[len("public/"):]]=data
    files["app/worker-loop.sh"]=(DSM/"worker-loop.sh").read_bytes().replace(b"\r\n",b"\n")
    files["app/misc/nginx_global.mustache"]=(DSM/"misc"/"nginx_global.mustache").read_bytes().replace(b"\r\n",b"\n")
    # DSM desktop launcher files are not activated until the base package
    # successfully passes Package Center's upload validation.
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
    assert service["root"]=="dup" and service["service"]=="dup"
    assert parsed["webservice"]["pkg_dir_prepare"][0]["source"]=="/var/packages/dup/target/webapp"
    assert parsed["webservice"]["pkg_dir_prepare"][0]["target"]=="dup"
    assert parsed["webservice"]["portals"][0]["alias"]=="dup"
    assert "var/packages/dup/target/app" in service["php"]["open_basedir"]
    assert "iconv" in service["php"]["extensions"]
    assert "/run/mysqld" in service["php"]["open_basedir"]
    assert service["custom_rule"]["global_rule"] == "/var/packages/dup/target/app/misc/nginx_global.mustache"
    info=(DSM/"INFO").read_text(encoding="utf-8").replace("__VERSION__",version).encode()
    assert b'package="dup"' in info and b'adminurl="dup/"' in info
    assert b'adminprotocol="https"' in info and b'adminport="443"' in info
    assert b'dsmuidir=' not in info and b'dsmappname=' not in info
    members={
        "INFO":(info,0o644),
        "LICENSE":((ROOT/"LICENSE").read_bytes(),0o644),
        "package.tgz":(make_inner(files),0o644),
        "conf/resource":(resource.encode(),0o644),
        "conf/privilege":((DSM/"conf"/"privilege").read_bytes(),0o644),
    }
    for hook in HOOKS:
        content=(DSM/"scripts"/hook).read_bytes().replace(b"\r\n",b"\n")
        if not content.startswith(b"#!/bin/sh\n"):
            raise RuntimeError("Invalid DSM script: "+hook)
        members["scripts/"+hook]=(content,0o755)
    # Synology accepts only these documented Package Center icons at SPK root.
    # Keep the original 120px artwork inside app/webapp, not as an extra root entry.
    for icon in ("PACKAGE_ICON.PNG","PACKAGE_ICON_256.PNG"):
        members[icon]=((DSM/icon).read_bytes(),0o644)
    # DSM Package Center consumes exactly 64px and 256px PNG assets.
    # Both must be generated from the uploaded 120px master, not the old 16px icon.
    def png_size(data:bytes)->tuple[int,int]:
        if not data.startswith(b"\x89PNG\r\n\x1a\n") or data[12:16] != b"IHDR":
            raise RuntimeError("Invalid PNG package artwork")
        import struct
        return struct.unpack(">II",data[16:24])
    assert png_size(members["PACKAGE_ICON.PNG"][0]) == (64,64)
    assert png_size(members["PACKAGE_ICON_256.PNG"][0]) == (256,256)
    assert png_size((DSM/"PACKAGE_ICON_120.PNG").read_bytes()) == (120,120)
    assert (DSM/"PACKAGE_ICON_120.PNG").read_bytes() == files["webapp/assets/dup.png"]
    assert b"img-src 'self'" in files["app/public/setup.php"]
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
        # Match the entry ordering of the previously installed DUP SPK.
        # INFO is first, with bare (not ./-prefixed) USTAR member paths.
        for name,(data,mode) in sorted(members.items()):
            member(outer,name,data,mode)
    with tarfile.open(output,"r") as outer:
        actual=outer.getmembers()
        if not actual or actual[0].name!="INFO" or any(m.name.startswith("./") for m in actual):
            raise RuntimeError("Invalid DSM package header layout")
        found={m.name:(outer.extractfile(m).read(),m.mode)
               for m in actual if m.isfile()}
    if found!=members:
        raise RuntimeError("Outer SPK differs from staged source")
    with tarfile.open(fileobj=io.BytesIO(found["package.tgz"][0]),mode="r:gz") as inner:
        entries=inner.getmembers()
        rebuilt={m.name:inner.extractfile(m).read()
                 for m in entries if m.isfile()}
    if rebuilt!=app:
        raise RuntimeError("SPK application files did not survive packaging")
    if found["LICENSE"][0] != rebuilt["app/LICENSE"] or found["LICENSE"][0] != rebuilt["webapp/license.txt"] or found["LICENSE"][0] != rebuilt["webapp/assets/license.txt"]:
        raise RuntimeError("MIT LICENSE must be identical in Package Center, app and public web assets")
    web={key for key in rebuilt if key.startswith("webapp/")}
    if any(key.endswith((".env",".env.local",".db",".sqlite",".log"))
           or key.startswith(("webapp/vendor/","webapp/src/","webapp/config/"))
           for key in web):
        raise RuntimeError("Private source or secrets exposed in DSM web directory")
    if not {"webapp/index.php","webapp/setup.php","webapp/assets/app.css","webapp/assets/folder-picker.js",
            "app/vendor/autoload.php","app/public/setup.php","app/bin/console","app/worker-loop.sh",
            "app/misc/nginx_global.mustache","app/LICENSE","webapp/license.txt","webapp/assets/license.txt","webapp/assets/dup.png"} <= rebuilt.keys():
        raise RuntimeError("Preview SPK missing required application runtime files")
    print("PASS",output.name, len(app),"files",len(web),"public paths")
    print("SHA256",hashlib.sha256(output.read_bytes()).hexdigest())
    print("PREVIEW ONLY — not installed, not a release")

if __name__=="__main__":
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version",default="0.7.6")
    parser.add_argument("--php-backend",type=int,default=11,
        help="Web Station PHP backend ID, default 11 validated for PHP8.2 on the test DSM")
    parser.add_argument("--output",type=Path,default=None)
    args=parser.parse_args()
    output=args.output or ROOT/"dist"/f"DUP-DSM7-{args.version}-compat.spk"
    build(output,args.version,args.php_backend)
