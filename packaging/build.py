#!/usr/bin/env python3
"""Build and verify generic DUP web ZIP + optional DSM 7 SPK from the same files."""
from __future__ import annotations
import argparse
import gzip
import hashlib
import io
import json
import re
import tarfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DSM = ROOT / "packaging" / "dsm7"
SCRIPTS = ("preinst", "postinst", "preuninst", "postuninst",
           "preupgrade", "postupgrade", "start-stop-status")
ESSENTIAL = {"index.php", "db.php", "dup.php", "action-list-files.php",
             "action-files-thread.php", "scan-files.php", "scan-files-thread.php",
             "profile-view.php", "setup.php", "setup-save.php", "database-schema.php",
             "database-migrations.php", "bin/migrate.php", "duplicate-filters.php"}
ASSETS = {".js", ".css", ".png", ".gif", ".jpg", ".jpeg",
          ".svg", ".ico", ".html", ".htm", ".txt"}
SCHEMA = set()  # Raw SQL is never published into the HTTP document root.

def source() -> dict[str, bytes]:
    out = {}
    for entry in sorted(ROOT.glob("*.php")):
        if entry.is_symlink():
            raise RuntimeError(f"Symlink not supported: {entry}")
        if entry.name not in {"dup-test.php", "config.local.php", "db.local.php"}:
            out[entry.name] = entry.read_bytes()
    for p in sorted((ROOT / "bin").glob("*.php")):
        if p.is_symlink():
            raise RuntimeError(f"Symlink not supported: {p}")
        out[p.relative_to(ROOT).as_posix()] = p.read_bytes()
    for p in (ROOT / ".htaccess",):
        if p.is_file() and not p.is_symlink():
            out[p.name] = p.read_bytes()
    for folder in ("files", "images"):
        for p in sorted((ROOT / folder).rglob("*")):
            if p.is_symlink():
                raise RuntimeError(f"Symlink not supported: {p}")
            if not p.is_file():
                continue
            name = p.relative_to(ROOT).as_posix()
            if p.suffix.lower() in ASSETS or name in SCHEMA or p.name == ".htaccess":
                out[name] = p.read_bytes()
    out["logs/.gitkeep"] = b""
    out["backup/.gitkeep"] = b""
    if missing := ESSENTIAL - out.keys():
        raise RuntimeError(f"Missing required application files: {sorted(missing)}")
    for name in out:
        if name.endswith((".db", ".sqlite", ".log", ".bak")) or name.startswith(
                ("orignal/", "old/", "packaging/", ".git/", "config.local", "db.local")):
            raise RuntimeError(f"Unsafe package file: {name}")
    return out

def add(tar, name: str, content: bytes, mode: int = 0o644) -> None:
    info = tarfile.TarInfo(name)
    info.mode, info.uid, info.gid, info.mtime = mode, 0, 0, 0
    info.size = len(content)
    tar.addfile(info, io.BytesIO(content))

def directory(tar, name: str) -> None:
    info = tarfile.TarInfo(name.rstrip("/") + "/")
    info.type, info.mode, info.mtime = tarfile.DIRTYPE, 0o755, 0
    tar.addfile(info)

def webzip(path: Path, files: dict[str, bytes]) -> None:
    with zipfile.ZipFile(path, "w") as zipout:
        for name, content in sorted(files.items()):
            info = zipfile.ZipInfo("dup/" + name, (1980, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            zipout.writestr(info, content, compress_type=zipfile.ZIP_DEFLATED)

def spkfiles(version: str, files: dict[str, bytes]) -> dict[str, tuple[bytes, int]]:
    memory = io.BytesIO()
    with gzip.GzipFile(fileobj=memory, mode="wb", filename="", mtime=0) as gz:
        with tarfile.open(fileobj=gz, mode="w", format=tarfile.USTAR_FORMAT) as tar:
            directory(tar, "webapp")
            directory(tar, "webapp/logs")
            directory(tar, "webapp/backup")
            for name, content in sorted(files.items()):
                add(tar, "webapp/" + name, content)
    info = (DSM / "INFO").read_text(encoding="utf-8").replace(
        "__VERSION__", version).encode("utf-8")
    outer = {"INFO": (info, 0o644), "package.tgz": (memory.getvalue(), 0o644)}
    for name in ("resource", "privilege"):
        outer["conf/" + name] = ((DSM / "conf" / name).read_bytes(), 0o644)
    for name in SCRIPTS:
        data = (DSM / "scripts" / name).read_bytes().replace(b"\r\n", b"\n")
        if not data.startswith(b"#!/bin/sh\n"):
            raise RuntimeError(f"Bad Synology script: {name}")
        outer["scripts/" + name] = (data, 0o755)
    for name in ("PACKAGE_ICON.PNG", "PACKAGE_ICON_256.PNG"):
        outer[name] = ((DSM / name).read_bytes(), 0o644)
    return outer

def spk(path: Path, members: dict[str, tuple[bytes, int]]) -> None:
    with tarfile.open(path, "w", format=tarfile.USTAR_FORMAT) as tar:
        for name in ("INFO", "package.tgz"):
            add(tar, name, *members[name])
        directory(tar, "scripts")
        directory(tar, "conf")
        for name in sorted(members.keys() - {"INFO", "package.tgz"}):
            add(tar, name, *members[name])

def verify(zpath: Path, spath: Path, version: str, files: dict[str, bytes]) -> None:
    with zipfile.ZipFile(zpath) as z:
        found = {n.removeprefix("dup/"): z.read(n) for n in z.namelist()}
    if found != files:
        raise RuntimeError("Web ZIP differs from generic application source.")
    expected = spkfiles(version, files)
    with tarfile.open(spath, "r:") as tar:
        names = {m.name for m in tar.getmembers() if m.isfile()}
        if names != expected.keys():
            raise RuntimeError("Unexpected outer SPK members.")
        for name, (body, mode) in expected.items():
            m = tar.getmember(name)
            if tar.extractfile(m).read() != body or m.mode != mode:
                raise RuntimeError(f"SPK metadata differs: {name}")
        innerbytes = tar.extractfile("package.tgz").read()
    with tarfile.open(fileobj=io.BytesIO(innerbytes), mode="r:gz") as tar:
        found = {m.name.removeprefix("webapp/"): tar.extractfile(m).read()
                 for m in tar.getmembers() if m.isfile()}
    if found != files:
        raise RuntimeError("SPK package.tgz differs from generic application source.")

def main() -> None:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--version", default="0.7.0")
    p.add_argument("--output-dir", type=Path, default=ROOT / "dist")
    a = p.parse_args()
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9.]+)?", a.version):
        raise SystemExit("Invalid version format.")
    resource = json.loads((DSM / "conf/resource").read_text(encoding="utf-8"))
    service = resource["webservice"]["services"][0]
    if service["root"] != "dup" or resource["webservice"]["pkg_dir_prepare"][0]["target"] != "dup":
        raise RuntimeError("Invalid DSM Web Station source/portal mapping.")
    files = source()
    a.output_dir.mkdir(parents=True, exist_ok=True)
    zpath = a.output_dir / f"DUP-Web-{a.version}.zip"
    spath = a.output_dir / f"DUP-DSM7-{a.version}.spk"
    webzip(zpath, files)
    spk(spath, spkfiles(a.version, files))
    verify(zpath, spath, a.version, files)
    for path in (zpath, spath):
        print(f"PASS {path.name}: {len(files)} app files, {path.stat().st_size} bytes, "
              f"SHA256 {hashlib.sha256(path.read_bytes()).hexdigest()}")

if __name__ == "__main__":
    main()
