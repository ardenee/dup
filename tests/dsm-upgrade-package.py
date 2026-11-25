#!/usr/bin/env python3
"""Validate a built DSM v2 upgrade without installing or modifying an NAS."""
import io
import sys
import tarfile
from pathlib import Path

root = Path(__file__).resolve().parent.parent
spk = Path(sys.argv[1] if len(sys.argv) > 1 else root / "dist" / "DUP-DSM7-0.7.21.spk")
with tarfile.open(spk, "r") as outer:
    info = outer.extractfile("INFO").read()
    hook = outer.extractfile("scripts/postupgrade").read()
    privilege = outer.extractfile("conf/privilege").read()
    source = outer.extractfile("package.tgz").read()
    assert b'version="0.7.21"' in info, "Incorrect package version"
    assert b'"run-as": "package"' in privilege, "Expected restricted package runtime"
    assert hook == (root / "packaging" / "dsm7" / "scripts" / "postupgrade").read_bytes().replace(b"\r\n", b"\n")
    supervisor = (root / "packaging" / "dsm7" / "worker-loop.sh").read_bytes()
    for check in (b"dup:jobs:work", b"dup:jobs:worker-exited", b"pdo_mysql",
                  b"mbstring", b"wait ", b"trap ", b"restarting worker"):
        assert check in supervisor, f"DSM crash supervisor missing {check!r}"
    assert b'exec "$PHP"' not in supervisor
    for check in (b'worker-control',b'verify-$pid.request',b'kill -TERM "$pid"',
                  b'kill -KILL "$pid"',b'now-requested_at',b'restarting worker'):
        assert check in supervisor, f"DSM cancellation supervision missing {check!r}"
    for check in (b'worker-output.log',b'supervisor.log',b'chgrp http',b'chmod 0640'):
        assert check in supervisor, f"Admin-readable DSM diagnostics missing {check!r}"

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
        "app/src/Module/Jobs/Service/WorkerLogReader.php",
        "app/src/Module/Jobs/Service/JobDebugLog.php",
        "app/src/Module/Jobs/Service/VerificationCancelSignal.php",
        "app/src/Module/Duplicates/Service/VerificationFailureCode.php",
        "app/src/Module/Jobs/Service/WorkerFailureCode.php",
        "app/src/Module/Jobs/Service/JobFailureCode.php",
        "app/src/Core/Diagnostics/DiagnosticFailure.php",
        "app/src/Core/Diagnostics/CodedRuntimeFailure.php",
        "app/src/Core/Diagnostics/CodedDomainFailure.php",
        "app/src/Core/Diagnostics/CodedInvalidArgumentFailure.php",
        "app/templates/jobs/error-codes.html.twig",
        "app/templates/jobs/worker-log.html.twig",
        "app/src/Module/Jobs/Command/WorkerExitedCommand.php",
        "app/templates/jobs/worker-log.html.twig",
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
print("PASS DSM 0.7.21 upgrade version, guarded migrations, new features, restricted privileges and public/private split")
