<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Core\Table\KeysetPage;
use Doctrine\DBAL\DriverManager;

function assertPage(bool $pass,string $reason):void {
    if(!$pass)throw new RuntimeException($reason);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE entries(id INT PRIMARY KEY,name TEXT,bytes INT,state TEXT,created_at TEXT)');
for($id=1;$id<=173;$id++) {
    $db->insert('entries',[
        'id'=>$id,
        'name'=>'item-'.($id%8), // deliberate ties: ID is deterministic
        'bytes'=>($id%6)*11,
        'state'=>$id%4===0?'failed':'completed',
        'created_at'=>sprintf('2026-11-%02d 08:00:00',$id%25+1)
    ]);
}
$relation='FROM entries t WHERE 1=1 AND t.state=:state';
$params=['state'=>'completed'];
foreach([
    'id'=>'t.id','name'=>'t.name','size'=>'t.bytes',
    'created'=>"COALESCE(t.created_at,'')"
] as $sort=>$expression) {
    foreach(['ASC','DESC'] as $direction) {
        $all=$db->fetchFirstColumn(
            "SELECT t.id $relation ORDER BY $expression $direction,t.id $direction",
            $params
        );
        $expected=array_map('intval',$all);
        $total=count($expected);
        $seen=[];$cursor=null;$segments=[];$n=0;
        do {
            $page=KeysetPage::fetch($db,'t.id,t.name,t.bytes,t.state,t.created_at',
                $relation,$params,'t.id',$expression,$direction,17,$cursor,null);
            $batch=array_map(static fn(array $r):int=>(int)$r['id'],$page['rows']);
            assertPage(count($batch)<=17,'Exceeded the configured page limit');
            assertPage($page['total']===$total,
                'Filter count changed during paging '.$sort.' '.$direction);
            if($n===0)assertPage($page['previous']===null,'Unexpected first-page previous');
            if($batch) {
                assertPage($page['start']===count($seen)+1,'Sorted global rank is wrong');
                $segments[]=['rows'=>$batch,'start'=>$page['start'],'next'=>$page['next'],
                    'previous'=>$page['previous']];
            }
            array_push($seen,...$batch);
            $cursor=$page['next'];
            $n++;
            assertPage($n<=20,'Paging loop never reached the end');
        }while($cursor!==null);
        assertPage($seen===$expected,'Sorting skipped/duplicated records '.$sort.' '.$direction);
        if(count($segments)>=2) {
            $final=end($segments);
            $back=KeysetPage::fetch($db,'t.id,t.name,t.bytes,t.state,t.created_at',
                $relation,$params,'t.id',$expression,$direction,17,
                null,$final['previous']);
            $prior=$segments[count($segments)-2];
            assertPage(array_map(static fn(array $r):int=>(int)$r['id'],$back['rows'])===
                $prior['rows'],'Previous-page link returned wrong rows: '.$sort.' '.$direction);
        }
    }
}
$empty=KeysetPage::fetch($db,'t.id',$relation,['state'=>'unknown'],
    't.id','t.id','DESC',25);
assertPage($empty['rows']===[] && $empty['total']===0,'Empty filter returned results');
$deletedCursor=KeysetPage::fetch($db,'t.id',$relation,$params,'t.id','t.id','DESC',25,99999);
assertPage(count($deletedCursor['rows'])===25,'ID paging from high deleted cursor failed.');
try {
    KeysetPage::fetch($db,'t.id',$relation,$params,'t.id','t.id','DESC',25,1,2);
    throw new RuntimeException('Accepted two simultaneous paging cursors.');
} catch(DomainException) {}
echo "PASS global keyset sorting ASC/DESC across 173 records, ties, multi-page navigation, filter isolation and deleted cursors\n";
