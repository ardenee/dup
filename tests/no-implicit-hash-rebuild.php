<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

function assertFastActions(bool $condition,string $message):void {
    if(!$condition) throw new RuntimeException($message);
}
$root=dirname(__DIR__);
$paths=[
    'src/Module/FileActions/Service/FileDeletionWorker.php',
    'src/Module/FileActions/Service/FileMoveWorker.php',
    'src/Module/FileActions/Service/FileActionWorker.php',
    'src/Module/FileActions/Service/FileLinkWorker.php',
    'src/Module/FileActions/Service/BulkDuplicateWorker.php',
    'src/Module/Renaming/Service/RenameWorker.php',
];
foreach($paths as $name) {
    $contents=file_get_contents($root.'/'.$name);
    assertFastActions(is_string($contents),'Missing action worker: '.$name);
    assertFastActions(!preg_match('/\b(?:hash_file|hash_init|hash_update|verifyDigest|FileHasher::digest)\s*\(/',$contents),
        'Implicit file-content hashing reintroduced in '.$name);
    assertFastActions(!str_contains($contents,"'kind'=>'rebuild-duplicates'"),
        'An unsolicited duplicate rebuild is queued in '.$name);
    assertFastActions(str_contains($contents,'assertRunning(') &&
        str_contains($contents,'checkedStat('),
        'Mutation worker removed basic lease and file metadata safety: '.$name);
}
$safe=file_get_contents($root.'/src/Module/FileActions/Service/SafeFileSystem.php');
assertFastActions(!str_contains($safe,'verifyDigest') &&
    !str_contains($safe,"hash_init('sha256')"),
    'File-management filesystem helper still contains a full-file digest reader.');
$scan=file_get_contents($root.'/src/Module/Scans/Service/FileHasher.php');
$explicit=file_get_contents($root.'/src/Module/Duplicates/Service/DuplicateVerificationWorker.php');
assertFastActions(str_contains($scan,'hash_init(') && str_contains($explicit,'FileHasher::digest('),
    'Explicit scan/verification functionality was accidentally removed.');
$delete=file_get_contents($root.'/src/Module/FileActions/Service/FileDeletionWorker.php');
assertFastActions(str_contains($delete,'$this->fs->unlinkApproved($source)') &&
    str_contains($delete,"UPDATE file_entries SET error_code='DELETED'"),
    'Fast deletion must still delete only approved files and mark their indexed state.');
echo "PASS no full-file hash or automatic duplicate rebuild in all six file-action workers; explicit verification retained\n";
