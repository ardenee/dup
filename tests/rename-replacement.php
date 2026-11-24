<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Module\Renaming\Service\RenameRules;

function verifyReplace(bool $condition,string $message):void {
    if(!$condition) throw new RuntimeException($message);
}
verifyReplace(
    RenameRules::cleaned('My.Show_Name.MP3','',1,1,true,'Show Name','Audiobook')==='My Audiobook.MP3',
    'Find/Replace was not applied after dots and underscores.');
verifyReplace(
    RenameRules::cleaned('Title_01.m4b','',0,1,true,'Title 01','Renamed')==='Renamed.m4b',
    'Find/Replace changed the file extension.');
verifyReplace(
    RenameRules::cleaned('Copy_Intro.mp3','Copy',0,1,true,'Intro','Chapter 1')==='Chapter 1.mp3',
    'Text replacement must run after remove-text cleanup.');
verifyReplace(
    RenameRules::cleaned('File.Name.txt','',0,0,false,'','anything')==='File.Name.txt',
    'Empty find should leave original unchanged.');
verifyReplace(
    RenameRules::cleaned('Music_1.mp3','',0,0,false,'Music','')==='_1.mp3',
    'An empty replacement should remove the literal matching substring.');
try {
    RenameRules::cleaned('Song.mp3','',0,0,false,'Song','Bad/Name');
    throw new RuntimeException('Unsafe replacement created forbidden filename.');
} catch(DomainException) {}
try {
    RenameRules::cleaned('Song.mp3','',0,0,false,'A',str_repeat('x',256));
    throw new RuntimeException('Unbounded replacement accepted.');
} catch(DomainException) {}
$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'));
foreach([
    'path'=>static fn($name,$args=[])=>'/'.$name,
    'asset'=>static fn($name)=>'/assets/'.$name,
    'csrf_token'=>static fn($name)=>'token-'.$name,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn()=>true
] as $name=>$fn)$twig->addFunction(new Twig\TwigFunction($name,$fn));
$html=$twig->render('rename/index.html.twig',[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'profiles'=>[['id'=>1,'name'=>'Audio']],'profile'=>1,'filters'=>'',
    'dots'=>1,'unders'=>1,'collapse'=>true,'dups'=>false,
    'collision'=>'reject','find'=>'Show','replace'=>'Book',
    'candidates'=>['rows'=>[],'next'=>null,'scanned'=>0],
    'batches'=>[],'paging'=>[]
]);
foreach(['name="find"','name="replace"','value="Show"','value="Book"',
    'Applied after all other filename cleanup rules'] as $needle)
    verifyReplace(str_contains($html,$needle),'Find/Replace UI missing '.$needle);
echo "PASS rename what/with after cleanup, extension safety, literal matching, collision rules and UI\n";
