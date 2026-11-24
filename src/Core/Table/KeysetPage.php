<?php
declare(strict_types=1);
namespace App\Core\Table;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Sort expression/direction must come from a code-owned allowlist. */
final class KeysetPage
{
    /**
     * @param array<string,mixed> $filterParams
     * @return array{rows:list<array<string,mixed>>,total:int,next:?int,previous:?int}
     */
    public static function fetch(
        Connection $db,string $select,string $relation,array $filterParams,
        string $idColumn,string $expression,string $direction,int $limit,
        ?int $before=null,?int $after=null,bool $numeric=false
    ):array {
        if($before!==null && $after!==null)
            throw new \DomainException('Only one table cursor is allowed.');
        if(!in_array($direction,['ASC','DESC'],true))
            throw new \InvalidArgumentException('Invalid sort direction.');
        if($limit<1 || $limit>500)
            throw new \InvalidArgumentException('Invalid table page size.');
        $total=(int)$db->fetchOne('SELECT COUNT(*) '.$relation,$filterParams);
        $sortTypes=$numeric?['sort_a'=>ParameterType::INTEGER,'sort_b'=>ParameterType::INTEGER]:[];
        $cursorId=$before??$after;
        $actualSort=$direction;
        $extra='';
        $params=$filterParams;
        if($cursorId!==null) {
            $row=$db->fetchAssociative(
                "SELECT $expression AS cursor_value $relation AND $idColumn=:cursor_lookup",
                $filterParams+['cursor_lookup'=>$cursorId]
            );
            if(!$row) {
                // Numeric ID cursors can remain meaningful even if the
                // referenced item was deleted or the cursor lies beyond
                // the current table range.
                if($expression===$idColumn) $row=['cursor_value'=>$cursorId];
                else return ['rows'=>[],'total'=>$total,'next'=>null,'previous'=>null];
            }
            $cmp=self::operator($direction,$before!==null?'next':'previous');
            $extra=" AND (($expression $cmp :sort_a) OR ".
                "($expression = :sort_b AND $idColumn $cmp :cursor_id))";
            $params['sort_a']=$row['cursor_value'];
            $params['sort_b']=$row['cursor_value'];
            $params['cursor_id']=$cursorId;
            if($after!==null) $actualSort=$direction==='ASC'?'DESC':'ASC';
        }
        $rows=$db->fetchAllAssociative(
            "SELECT $select $relation $extra ORDER BY $expression $actualSort,".
            " $idColumn $actualSort LIMIT ".($limit+1),$params,$sortTypes
        );
        if(count($rows)>$limit) array_pop($rows);
        if($after!==null) $rows=array_reverse($rows);
        $first=$rows?(int)$rows[0]['id']:null;
        $last=$rows?(int)$rows[count($rows)-1]['id']:null;
        $exists=static function(int $id,string $step) use (
            $db,$relation,$filterParams,$idColumn,$expression,$direction,$sortTypes
        ):bool {
            $value=$db->fetchOne(
                "SELECT $expression $relation AND $idColumn=:point",
                $filterParams+['point'=>$id]
            );
            if($value===false) return false;
            $cmp=self::operator($direction,$step);
            return (bool)$db->fetchOne(
                "SELECT 1 $relation AND (($expression $cmp :sort_a) OR ".
                "($expression=:sort_b AND $idColumn $cmp :cursor_id)) LIMIT 1",
                $filterParams+['sort_a'=>$value,'sort_b'=>$value,'cursor_id'=>$id],$sortTypes
            );
        };
        $start=0;
        if($first!==null) {
            $firstValue=$db->fetchOne(
                "SELECT $expression $relation AND $idColumn=:point",
                $filterParams+['point'=>$first]
            );
            $cmp=self::operator($direction,'previous');
            $start=1+(int)$db->fetchOne(
                "SELECT COUNT(*) $relation AND (($expression $cmp :sort_a) OR ".
                "($expression=:sort_b AND $idColumn $cmp :cursor_id))",
                $filterParams+['sort_a'=>$firstValue,'sort_b'=>$firstValue,'cursor_id'=>$first],$sortTypes
            );
        }
        return [
            'rows'=>$rows,'total'=>$total,'start'=>$start,
            'previous'=>$first!==null && $exists($first,'previous')?$first:null,
            'next'=>$last!==null && $exists($last,'next')?$last:null
        ];
    }

    private static function operator(string $direction,string $step):string
    {
        return ($direction==='ASC')===($step==='next')?'>':'<';
    }
}
