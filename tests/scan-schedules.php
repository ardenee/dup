<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Module\Jobs\Service\JobStore;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\Profiles\Service\ScanScheduleService;
use Doctrine\DBAL\DriverManager;

function ckSchedule(bool $okay,string $message):void {
    if(!$okay) throw new RuntimeException($message);
}
$folder=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-schedule-'.bin2hex(random_bytes(5));
ckSchedule(mkdir($folder,0700,true),'Failed to create schedule fixture root');
try {
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    foreach([
        'CREATE TABLE scan_profiles(id INT PRIMARY KEY,name TEXT,enabled INT,source_root TEXT,profile_type_id INT,hash_mode TEXT,minimum_bytes INT)',
        'CREATE TABLE profile_types(id INT PRIMARY KEY,extensions TEXT)',
        'CREATE TABLE profile_ignore_rules(id INT PRIMARY KEY,profile_id INT,folder_name TEXT,enabled INT)',
        'CREATE TABLE scan_runs(id INTEGER PRIMARY KEY AUTOINCREMENT,profile_id INT,state TEXT,settings_json TEXT)',
        'CREATE TABLE jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,profile_id INT,active_profile_id INT UNIQUE,run_id INT,state TEXT,phase TEXT,progress_current INT,created_at TEXT,attempts INT)',
        'CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE audit_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE scan_schedules(profile_id INT PRIMARY KEY,interval_minutes INT,next_run_at TEXT,last_queued_at TEXT,last_attempt_at TEXT,last_error TEXT,updated_at TEXT)'
    ] as $sql) $db->executeStatement($sql);
    $db->insert('profile_types',['id'=>1,'extensions'=>'mp3']);
    $db->insert('scan_profiles',[
        'id'=>1,'name'=>'Audio','enabled'=>1,'source_root'=>realpath($folder),
        'profile_type_id'=>1,'hash_mode'=>'size','minimum_bytes'=>0
    ]);
    $db->insert('scan_profiles',[
        'id'=>2,'name'=>'Disabled','enabled'=>0,'source_root'=>realpath($folder),
        'profile_type_id'=>1,'hash_mode'=>'size','minimum_bytes'=>0
    ]);
    $jobs=new JobStore($db,new DirectoryBrowser());
    $schedule=new ScanScheduleService($db,$jobs);
    try {
        $schedule->save(1,20,8);
        throw new RuntimeException('Accepted unsupported scan interval.');
    } catch(DomainException) {}
    try {
        $schedule->save(123,60,8);
        throw new RuntimeException('Accepted schedule for nonexistent profile.');
    } catch(DomainException) {}
    $schedule->save(1,60,8);
    ckSchedule((int)$db->fetchOne('SELECT interval_minutes FROM scan_schedules WHERE profile_id=1')===60,
        'Scan schedule failed to save interval.');
    ckSchedule($schedule->dispatchDue()===[],'New schedule ran immediately.');
    $db->executeStatement("UPDATE scan_schedules SET next_run_at='2020-01-01 01:00:00' WHERE profile_id=1");
    $queued=$schedule->dispatchDue();
    ckSchedule(count($queued)===1,'Due scheduled scan did not queue.');
    $runId=(int)$db->fetchOne('SELECT run_id FROM jobs WHERE id=?',[$queued[0]]);
    ckSchedule($runId>0,'Queued scan did not create a run.');
    ckSchedule((string)$db->fetchOne('SELECT kind FROM jobs WHERE id=?',[$queued[0]])==='scan',
        'Schedule created wrong job type.');
    ckSchedule((int)$db->fetchOne('SELECT COUNT(*) FROM job_events WHERE job_id=?',[$queued[0]])===2,
        'Scheduled scan event not recorded.');
    ckSchedule($schedule->dispatchDue()===[],'Scheduled job queued twice.');
    ckSchedule((string)$db->fetchOne('SELECT next_run_at FROM scan_schedules WHERE profile_id=1') >
        gmdate('Y-m-d H:i:s'),'Next automatic run not advanced.');
    ckSchedule($db->fetchOne('SELECT last_queued_at FROM scan_schedules WHERE profile_id=1')!==null,
        'Last queued time not persisted.');

    $db->executeStatement("UPDATE scan_schedules SET next_run_at='2020-01-01 01:00:00' WHERE profile_id=1");
    ckSchedule($schedule->dispatchDue()===[],'Busy profile should not allow a second scan.');
    ckSchedule((int)$db->fetchOne("SELECT COUNT(*) FROM jobs WHERE kind='scan'")===1,
        'Busy profile duplicate scheduled job.');
    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL WHERE id=?",[$queued[0]]);
    $db->executeStatement("UPDATE scan_runs SET state='completed' WHERE id=?",[$runId]);
    ckSchedule(count($schedule->dispatchDue())===1,
        'Due schedule failed to restart after completed job.');
    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL");
    $schedule->save(2,60,8);
    $db->executeStatement("UPDATE scan_schedules SET next_run_at='2020-01-01 01:00:00' WHERE profile_id=2");
    ckSchedule($schedule->dispatchDue()===[],'Disabled profile unexpectedly scanned.');
    // Bad scan paths must be visible in the UI and back off, not silently
    // retry on every worker cycle.
    $db->insert('scan_profiles',[
        'id'=>3,'name'=>'Broken','enabled'=>1,
        'source_root'=>$folder.DIRECTORY_SEPARATOR.'missing',
        'profile_type_id'=>1,'hash_mode'=>'size','minimum_bytes'=>0
    ]);
    $schedule->save(3,60,8);
    $db->executeStatement("UPDATE scan_schedules SET next_run_at='2020-01-01 01:00:00' WHERE profile_id=3");
    ckSchedule($schedule->dispatchDue()===[],
        'Invalid scheduled scan path unexpectedly queued.');
    ckSchedule((string)$db->fetchOne('SELECT last_error FROM scan_schedules WHERE profile_id=3')!=='',
        'Failed schedule did not record its reason.');
    ckSchedule((string)$db->fetchOne('SELECT next_run_at FROM scan_schedules WHERE profile_id=3') >
        gmdate('Y-m-d H:i:s'),'Invalid scheduled scan did not back off.');
    $schedule->save(1,0,8);
    ckSchedule((int)$db->fetchOne('SELECT COUNT(*) FROM scan_schedules WHERE profile_id=1')===0,
        'Schedule disabling did not remove stored schedule.');
    ckSchedule((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='PROFILE_SCAN_SCHEDULE_CHANGED'")===4,
        'Schedule changes not audited.');
    echo "PASS per-profile scan schedule, 6 allowed intervals, worker dispatch, busy lock, disabled profile and audit\n";
} finally {
    @rmdir($folder);
}
