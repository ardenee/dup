<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Doctrine\DBAL\DriverManager;
use App\Module\Files\Service\ViewerSettings;
function verifyViewer(bool $ok,string $message):void {
    if(!$ok) throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE viewer_settings (setting_key TEXT PRIMARY KEY,setting_value TEXT,updated_at TEXT)');
$v=new ViewerSettings($db);
verifyViewer($v->all()['view_enable']==='1','Default viewer disabled');
verifyViewer($v->previewType('txt',300)==='text','Default text missing');
verifyViewer($v->previewType('jpg',200)==='image','Default image missing');
verifyViewer($v->previewType('pdf',200)==='pdf','Default PDF missing');
verifyViewer($v->previewType('pdf',41*1048576)==='none','Large PDF not blocked');
verifyViewer($v->previewType('exe',120)==='none','Executable inline preview allowed');
foreach([
    ['view_enable'=>1,'view_image_exts'=>'svg','view_text_exts'=>'txt','view_pdf_exts'=>'pdf'],
    ['view_enable'=>1,'view_image_exts'=>'jpg','view_text_exts'=>'txt','view_pdf_exts'=>'html,exe'],
    ['view_enable'=>1,'view_image_exts'=>'jpg','view_text_exts'=>'*','view_pdf_exts'=>'pdf'],
] as $bad) {
    try {$v->normalized($bad);throw new RuntimeException('Accepted invalid preview extension list');}
    catch(DomainException){}
}
$v->save(['view_enable'=>false,'view_image_exts'=>'jpg,png','view_text_exts'=>'txt,log','view_pdf_exts'=>'pdf']);
verifyViewer($v->previewType('txt',100)==='none','Disabled viewer still previews');
$v->save(['view_enable'=>true,'view_image_exts'=>'.PNG, jpg,jpg','view_text_exts'=>'md,log','view_pdf_exts'=>'']);
verifyViewer($v->previewType('jpg',100)==='image','Image setting failed');
verifyViewer($v->previewType('png',100)==='image','Image normalization failed');
verifyViewer($v->previewType('txt',100)==='none','Text extension disable failed');
verifyViewer($v->previewType('md',100)==='text','Text extension enable failed');
verifyViewer($v->previewType('pdf',100)==='none','PDF extension disable failed');
verifyViewer($v->all()['view_image_exts']==='png,jpg','Extension list not normalized');
verifyViewer((int)$db->fetchOne('SELECT COUNT(*) FROM viewer_settings')===4,'Settings persistence failed');
echo "PASS viewer defaults, configurable extensions, safe formats, disabled preview, size limits, database persistence\n";
