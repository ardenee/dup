#!/usr/bin/env python3
"""Build a reproducible DUP v2 generic web-hosting PREVIEW ZIP; not a release."""
from __future__ import annotations
import argparse,hashlib,re,zipfile
from pathlib import Path

ROOT=Path(__file__).resolve().parent.parent
FOLDERS=("bin","config","src","templates","migrations","public","vendor")
ROOT_FILES=("composer.json","composer.lock","symfony.lock","README.md")
DEPLOY_ENV="""APP_ENV=prod
# Provide APP_SECRET and DATABASE_URL through private runtime configuration.
APP_SECRET=
DATABASE_URL=
DEFAULT_URI=http://localhost
# Configure allowed server filesystem roots for Windows, Linux or DSM.
DUP_ALLOWED_SCAN_ROOTS=
APP_SHARE_DIR=var/share
"""

def payload() -> dict[str,bytes]:
    result={}
    for filename in ROOT_FILES:
        file=ROOT/filename
        if not file.is_file():
            raise RuntimeError(f"Missing required source: {filename}")
        result[filename]=file.read_bytes()
    result[".env"]=DEPLOY_ENV.encode("utf-8")
    for folder in FOLDERS:
        base=ROOT/folder
        if not base.is_dir():
            raise RuntimeError(f"Missing required folder: {folder}")
        for item in sorted(base.rglob("*")):
            if item.is_symlink():
                raise RuntimeError(f"Refusing symlink in deployment: {item}")
            if not item.is_file():
                continue
            name=item.relative_to(ROOT).as_posix()
            if any(p in {".git",".github",".idea",".vscode","__pycache__"} for p in item.parts):
                continue
            if item.name in {".env.local",".env.local.php",".DS_Store"}:
                continue
            if item.suffix.lower() in {".db",".sqlite",".sqlite3",".log",".pyc"}:
                raise RuntimeError(f"Runtime file in deployment tree: {name}")
            result[name]=item.read_bytes()
    if not any(f.startswith("vendor/symfony/framework-bundle/") for f in result):
        raise RuntimeError("Symfony vendor dependencies are not installed")
    required=("public/index.php","bin/console","config/services.yaml",
              "src/Kernel.php","migrations/Version20261123000100.php")
    if not all(k in result for k in required):
        raise RuntimeError("Missing mandatory v2 foundation files")
    return result

def build(target: Path) -> None:
    files=payload()
    target.parent.mkdir(parents=True,exist_ok=True)
    with zipfile.ZipFile(target,"w",compression=zipfile.ZIP_DEFLATED,compresslevel=7) as archive:
        for name,data in sorted(files.items()):
            meta=zipfile.ZipInfo("dup/"+name,(1980,1,1,0,0,0))
            meta.compress_type=zipfile.ZIP_DEFLATED
            meta.external_attr=0o644<<16
            archive.writestr(meta,data)
    with zipfile.ZipFile(target) as archive:
        entries={p.removeprefix("dup/"):archive.read(p) for p in archive.namelist()}
    if entries!=files:
        raise RuntimeError("ZIP verification failed")
    if any(name.startswith(("var/","backup/","packaging/","tests/")) for name in entries):
        raise RuntimeError("Runtime data or development artifacts in ZIP")
    if any("config.local" in name or ".env.local" in name for name in entries):
        raise RuntimeError("Private configuration file leaked into ZIP")
    if re.search(r"APP_SECRET=\S+",entries[".env"].decode()):
        raise RuntimeError("Packaged environment contains a secret")
    print(f"PASS {target.name}: {len(files)} application files, {target.stat().st_size:,} bytes")
    print("SHA256 "+hashlib.sha256(target.read_bytes()).hexdigest())
    print("PREVIEW ONLY: reports and reversible actions included; MySQL, Linux and DSM live validation pending")

if __name__=="__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("--output",type=Path,default=ROOT/"dist"/"DUP-v2-Web-Preview.zip")
    args=parser.parse_args()
    build(args.output)
