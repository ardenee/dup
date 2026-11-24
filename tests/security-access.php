<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
$rules=Yaml::parseFile(dirname(__DIR__).'/config/packages/security.yaml')['security']['access_control'];
function check(bool $pass,string $text):void {
    if(!$pass) throw new RuntimeException($text);
}
function canReach(array $rules,string $method,string $uri,string $role):bool
{
    foreach($rules as $rule) {
        if(!preg_match('~'.$rule['path'].'~',$uri)) continue;
        if(isset($rule['methods']) && !in_array($method,$rule['methods'],true)) continue;
        if($rule['roles']==='PUBLIC_ACCESS') return true;
        if($rule['roles']==='ROLE_USER') return in_array($role,['ROLE_ADMIN','ROLE_USER'],true);
        if($rule['roles']==='ROLE_ADMIN') return $role==='ROLE_ADMIN';
    }
    return false;
}
foreach(['/','/duplicates','/duplicates/12','/search','/reports','/reports/run/14','/files/2','/files/2/download','/files/2/inline'] as $url) {
    check(canReach($rules,'GET',$url,'ROLE_USER'),'User blocked on read-only endpoint '.$url);
}
foreach(['/accounts','/settings/viewer','/settings/ignored-folders','/profiles','/jobs','/move/3','/actions','/rename','/system'] as $url) {
    check(!canReach($rules,'GET',$url,'ROLE_USER'),'User gained administrative GET '.$url);
    check(canReach($rules,'GET',$url,'ROLE_ADMIN'),'Administrator blocked on '.$url);
}
foreach(['/move/preview','/actions/link/preview/12','/accounts/save','/settings/viewer','/profiles/1/scan','/reports/run/14/remove','/rename/selected'] as $url) {
    check(!canReach($rules,'POST',$url,'ROLE_USER'),'User gained a mutation endpoint '.$url);
    check(canReach($rules,'POST',$url,'ROLE_ADMIN'),'Administrator blocked POST '.$url);
}
foreach(['/accounts','/duplicates','/files/2/download','/settings/viewer'] as $url)
    check(!canReach($rules,'GET',$url,'PUBLIC_ACCESS'),'Anonymous can access '.$url);
check(canReach($rules,'GET','/login','PUBLIC_ACCESS'),'Anonymous login inaccessible');
check(canReach($rules,'GET','/health','PUBLIC_ACCESS'),'Health endpoint inaccessible');
echo "PASS read-only permissions, admin-only mutations, anonymous denial, exact route precedence\n";
