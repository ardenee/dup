#!/usr/bin/env python3
"""Validate a built DSM v2 upgrade without installing or modifying an NAS."""
import io
import sys
import tarfile
from pathlib import Path

root = Path(__file__).resolve().parent.parent
spk = Path(sys.argv[1] if len(sys.argv) > 1 else root / "dist" / "DUP-DSM7-0.7.12.spk")
with tarfile.open(spk, "r") as outer:
    info = outer.extractfile("INFO").read()
    hook = outer.extractfile("scripts/postupgrade").read()
    privilege = outer.extractfile("conf/privilege").read()
    source = outer.extractfile("package.tgz").read()
    assert b'version="0.7.12"' in info, "Incorrect package version"
    assert b'"run-as": "package"' in privilege, "Expected restricted package runtime"
    assert hook == (root / "packaging" / "dsm7" / "scripts" / "postupgrade").read_bytes().replace(b"\r\n", b"\n")
    for safety in (b"setup.complete", b".env.local", b"find_php", b"doctrine:migrations:migrate",
                   b"cache:clear", b"exit 1"):
        assert safety in hook, f"Missing guarded upgrade step: {safety!r}"
    assert b"schema:drop" not in hook and b"CREATE DATABASE" not in hook
with tarfile.open(fileobj=io.BytesIO(source), mode="r:gz") as inner:
    names = {m.name for m in inner if m.isfile()}
    for required in (
        "app/migrations/Version20261124000500.php",
        "app/migrations/Version20261124000600.php",
        "app/migrations/Version20261124000700.php",
        "app/migrations/Version20261124000800.php",
        "app/migrations/Version20261124000900.php",
        "app/migrations/Version20261124001000.php",
        "app/migrations/Version20261124001100.php",
        "app/templates/_action_sprite.html.twig",
        "app/templates/_action_icons.html.twig",
        "app/templates/_table_headers.html.twig",
        "app/src/Core/Table/KeysetPage.php",
        "webapp/assets/table-local.js",
        "app/src/Module/Duplicates/Service/DuplicateVerificationWorker.php",
        "app/src/Module/FileActions/Service/FileDeletionWorker.php",
        "app/src/Module/Profiles/Service/ScanScheduleService.php",
        "app/src/Module/Reports/Service/ScanRetentionService.php",
        "app/src/Module/FileActions/Service/FileActionHistoryCleanup.php",
        "app/src/Module/Jobs/Service/JobHistoryCleanup.php",
        "app/src/Module/Jobs/Service/WorkerMaintenance.php",
        "app/src/Module/Jobs/Service/WorkerHeartbeat.php",
        "webapp/assets/jobs.js",
        "app/templates/system/index.html.twig",
        "webapp/assets/app.css",
        "webapp/assets/dup.png",
        "app/LICENSE",
        "webapp/license.txt",
    ):
        assert required in names, f"Missing upgrade payload file: {required}"
    assert not any(name.startswith(("webapp/vendor/", "webapp/src/", "webapp/migrations/"))
                   or name.endswith((".env.local", ".sqlite", ".db"))
                   for name in names), "Private runtime data exposed in webapp"
print("PASS DSM 0.7.12 upgrade version, guarded migrations, new features, restricted privileges and public/private split")
