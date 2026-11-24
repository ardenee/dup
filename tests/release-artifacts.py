#!/usr/bin/env python3
"""Verify deployable production files, not source-build fixtures."""
from pathlib import Path
import io, tarfile, zipfile, sys, subprocess, tempfile
root=Path(__file__).resolve().parent.parent
web=root/'dist'/'DUP-Web-0.7.19.zip'
dsm=root/'dist'/'DUP-DSM7-0.7.19.spk'
assert web.is_file() and dsm.is_file()
with zipfile.ZipFile(web) as archive:
    names=archive.namelist()
    assert names and all(x.startswith('dup/') for x in names)
    assert len(names)==len(set(names))
    files={k[len('dup/'):]:archive.read(k) for k in names}
    forbidden=[k for k in files if k.startswith(('tests/','packaging/','var/','backup/'))
        or k=='config/reference.php'
        or k.endswith(('.sqlite','.sqlite3','.db','.log','.pyc','.env.local'))]
    assert not forbidden,forbidden[:15]
    assert files['LICENSE']==files['public/license.txt']==files['public/assets/license.txt']
    assert b'APP_ENV=prod' in files['.env']
    assert b'0.7.19' in files['templates/base.html.twig']
    assert b'Development preview' not in files['templates/base.html.twig']
    assert b'0.7.19' in files['public/setup.php']
    assert b'0.7.19' in files['templates/accounts/login.html.twig']
    assert 'migrations/Version20261124001000.php' in files
    assert 'migrations/Version20261124001100.php' in files
    assert 'templates/_action_sprite.html.twig' in files
    assert 'templates/_table_headers.html.twig' in files
    assert 'src/Core/Table/KeysetPage.php' in files
    assert 'public/assets/table-local.js' in files
    assert b'data-local-table' in files['templates/profiles/index.html.twig']
    assert b'dup-file-filter' in files['templates/duplicates/index.html.twig']
    assert 'src/Module/Jobs/Service/WorkerMaintenance.php' in files
    assert 'src/Module/Jobs/Service/WorkerHeartbeat.php' in files
    assert b'Refresh status' in files['templates/jobs/index.html.twig']
    assert b'jobs_responding' in files['public/assets/jobs.js']
    assert b'bytesCurrent' in files['public/assets/jobs.js']
    assert b'VERIFICATION_FILE_STARTED' in files['src/Module/Duplicates/Service/DuplicateVerificationWorker.php']
    assert b'progress_bytes_current' in files['src/Module/Jobs/Service/JobStore.php']
    assert b'last_failure_reason' in files['migrations/Version20261124001100.php']
    assert b'Finish stalled cancellation' in files['templates/jobs/index.html.twig']
    assert b'app_job_resolve' in files['src/Module/Jobs/Controller/JobsController.php']
    assert b"'duplicate-verify'" in files['src/Module/Jobs/Service/JobHistoryCleanup.php']
    assert b'ownerActive' in files['src/Module/Jobs/Service/WorkerMaintenance.php']
    assert 'src/Module/Jobs/Command/WorkerExitedCommand.php' in files
    assert 'src/Module/Jobs/Service/WorkerLogReader.php' in files
    assert 'src/Module/Jobs/Service/JobDebugLog.php' in files
    assert b'verification.candidates.ready' in files['src/Module/Duplicates/Service/DuplicateVerificationWorker.php']
    assert b'candidate.page.sql.start' in files['src/Module/Duplicates/Service/DuplicateVerificationWorker.php']
    assert b'worker.php_fatal' in files['src/Module/Jobs/Command/WorkJobsCommand.php']
    assert b'app_job_debug_download' in files['src/Module/Jobs/Controller/JobsController.php']
    assert b'Download full JSONL debug log' in files['templates/jobs/worker-log.html.twig']
    assert b'profiles-overview-table' in files['templates/profiles/index.html.twig']
    assert b'job_overdue' in files['src/Module/Profiles/Controller/ProfilesController.php']
    assert b'active_heartbeat' in files['src/Module/Profiles/Repository/ProfileRepository.php']
    assert b'Diagnostic storage check' in files['templates/jobs/worker-log.html.twig']
    assert b'worker_log_readable' in files['src/Module/Jobs/Service/JobDebugLog.php']
    assert b'profile-action-toolbar{display:flex' in files['public/assets/app.css']
    assert b'DUP DEBUG LOG WRITE FAILED' in files['src/Module/Jobs/Service/JobDebugLog.php']
    assert b'function probe():array' in files['src/Module/Jobs/Service/JobDebugLog.php']
    assert b'DUP CLI DEBUG LOG UNAVAILABLE' in files['src/Module/Jobs/Command/WorkJobsCommand.php']
    assert b'app_job_debug_test' in files['src/Module/Jobs/Controller/JobsController.php']
    assert b'Test debug logging now' in files['templates/jobs/worker-log.html.twig']
    assert b'function recentJobs(' in files['src/Module/Jobs/Service/JobDebugLog.php']
    assert 'src/Module/Jobs/Service/VerificationCancelSignal.php' in files
    assert b'app_job_force_stop_verify' in files['src/Module/Jobs/Controller/JobsController.php']
    assert b'VERIFICATION_SUPERVISOR_STOP_REQUESTED' in files['src/Module/Jobs/Service/VerificationCancelSignal.php']
    assert b'Force stop verification worker' in files['templates/jobs/detail.html.twig']
    assert b'verification.cancel.signal_queued' in files['src/Module/Jobs/Service/VerificationCancelSignal.php']
    for name in (
        'src/Core/Diagnostics/DiagnosticFailure.php',
        'src/Core/Diagnostics/CodedRuntimeFailure.php',
        'src/Core/Diagnostics/CodedDomainFailure.php',
        'src/Core/Diagnostics/CodedInvalidArgumentFailure.php',
        'src/Module/Duplicates/Service/VerificationFailureCode.php',
        'src/Module/Jobs/Service/WorkerFailureCode.php',
        'templates/jobs/error-codes.html.twig'
    ):
        assert name in files, f"Missing production diagnostics file {name}"
    assert b'app_job_error_codes' in files['src/Module/Jobs/Controller/JobsController.php']
    assert b'DUP-HASH-105' in files['src/Module/Scans/Service/FileHasher.php']
    assert b'DUP-VERIFY-11001' not in files['src/Module/Duplicates/Service/DuplicateVerificationWorker.php'], "Generated exception codes belong to the stable central catalog"
    assert b'WorkerFailureCode::stalled' in files['src/Module/Jobs/Service/JobStore.php']
    assert b'failure_code' in files['src/Module/Duplicates/Service/DuplicateVerificationWorker.php']
    assert b'Error code reference' in files['templates/jobs/index.html.twig']
    assert 'src/Module/Jobs/Service/JobFailureCode.php' in files
    assert b'function currentLease(' in files['src/Module/Jobs/Service/JobStore.php']
    assert b'job.heartbeat.same_second' in files['src/Module/Jobs/Service/JobStore.php']
    assert b'job.verification_progress.unchanged' in files['src/Module/Jobs/Service/JobStore.php']
    assert b'DUP-JOB-PROGRESS-102' in files['src/Module/Jobs/Service/JobStore.php']
    assert b'DUP-JOB-LEASE-101' in files['src/Module/Jobs/Service/LostLease.php'] or b'DUP-JOB-LEASE-101' in files['src/Module/Jobs/Service/JobStore.php']
    assert b'JobFailureCode::catalog()' in files['src/Module/Jobs/Controller/JobsController.php']
    assert 'templates/jobs/worker-log.html.twig' in files
    assert b'VERIFICATION_CANDIDATE_PAGE_READY' in files['src/Module/Duplicates/Service/DuplicateVerificationWorker.php']
    assert b'worker-process-exit' not in files['templates/base.html.twig']
    with tempfile.TemporaryDirectory(prefix='dup-release-') as temp:
        archive.extractall(temp)
        app=Path(temp)/'dup'
        result=subprocess.run(['php','bin/console','lint:container','--env=prod','--no-debug'],
            cwd=app,capture_output=True,text=True,timeout=120)
        assert result.returncode==0,(result.stdout,result.stderr)
with tarfile.open(dsm,'r') as outer:
    assert outer.extractfile('LICENSE').read()==files['LICENSE']
    info=outer.extractfile('INFO').read()
    resource=outer.extractfile('conf/resource').read()
    assert b'version="0.7.19"' in info
    assert b'development preview' not in info.lower()
    assert b'Preview' not in resource
    package=outer.extractfile('package.tgz').read()
    with tarfile.open(fileobj=io.BytesIO(package),mode='r:gz') as inner:
        entries={m.name:inner.extractfile(m).read() for m in inner if m.isfile()}
    assert 'app/migrations/Version20261124001000.php' in entries
    assert 'app/migrations/Version20261124001100.php' in entries
    assert 'app/templates/_action_sprite.html.twig' in entries
    assert 'app/templates/_table_headers.html.twig' in entries
    assert 'app/src/Module/Jobs/Command/WorkerExitedCommand.php' in entries
    assert 'app/src/Module/Jobs/Service/WorkerLogReader.php' in entries
    assert 'app/src/Module/Jobs/Service/JobDebugLog.php' in entries
    assert 'app/src/Module/Jobs/Service/VerificationCancelSignal.php' in entries
    assert 'app/src/Module/Duplicates/Service/VerificationFailureCode.php' in entries
    assert 'app/src/Module/Jobs/Service/WorkerFailureCode.php' in entries
    assert 'app/src/Module/Jobs/Service/JobFailureCode.php' in entries
    assert 'app/src/Core/Diagnostics/CodedRuntimeFailure.php' in entries
    assert 'app/templates/jobs/error-codes.html.twig' in entries
    assert b'kill -KILL "$pid"' in entries['app/worker-loop.sh']
    assert b'worker-output.log' in entries['app/worker-loop.sh']
    assert 'app/templates/jobs/worker-log.html.twig' in entries
    assert b'dup:jobs:worker-exited' in entries['app/worker-loop.sh']
    assert 'app/src/Core/Table/KeysetPage.php' in entries
    assert 'webapp/assets/table-local.js' in entries
    assert 'webapp/assets/dup.png' in entries
    assert entries['app/LICENSE']==entries['webapp/license.txt']==files['LICENSE']
    assert entries['app/templates/base.html.twig']==files['templates/base.html.twig']
    assert not any(n.startswith(('app/tests/','webapp/tests/','app/packaging/','webapp/vendor/'))
        or n=='app/config/reference.php'
        or n.startswith(('app/vendor/symfony/framework-bundle/Test/',
                         'app/vendor/monolog/monolog/src/Monolog/Test/',
                         'app/vendor/twig/twig/src/Test/'))
        for n in entries)
print('PASS official 0.7.19 ZIP/SPK: production Symfony lint, no test/preview material, proper icons/migrations and matching MIT licenses')
