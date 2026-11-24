<?php
declare(strict_types=1);
namespace App\Module\Dashboard\Controller;

use Doctrine\DBAL\Connection;
use App\Module\Jobs\Repository\JobHistoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/',name:'app_dashboard',methods:['GET'])]
    public function index(Connection $connection,JobHistoryRepository $history): Response
    {
        $metrics=[
            'profiles'=>(int)$connection->fetchOne('SELECT COUNT(*) FROM scan_profiles'),
            'completed_scans'=>(int)$connection->fetchOne("SELECT COUNT(*) FROM scan_runs WHERE state IN ('completed','archived')"),
            // Count only each profile's latest successful snapshot; summing scan
            // history would inflate results when the same folders are rescanned.
            'files'=>(int)$connection->fetchOne("SELECT COALESCE(SUM(r.files_indexed),0) FROM scan_runs r JOIN (SELECT profile_id,MAX(id) AS latest_run FROM scan_runs WHERE state='completed' GROUP BY profile_id) latest ON latest.latest_run=r.id"),
            'duplicate_groups'=>(int)$connection->fetchOne("SELECT COALESCE(SUM(r.duplicate_groups),0) FROM scan_runs r JOIN (SELECT profile_id,MAX(id) AS latest_run FROM scan_runs WHERE state='completed' GROUP BY profile_id) latest ON latest.latest_run=r.id"),
            'verified_potential_bytes'=>(int)$connection->fetchOne(
                "SELECT COALESCE(SUM(g.reclaimable_bytes),0) FROM duplicate_groups g " .
                "JOIN scan_runs r ON r.id=g.run_id WHERE g.rules_version=1 " .
                "AND r.state='completed' AND r.id=(SELECT MAX(id) FROM scan_runs " .
                "WHERE profile_id=r.profile_id AND state='completed')"
            ),
            'active_jobs'=>(int)$connection->fetchOne("SELECT COUNT(*) FROM jobs WHERE state IN ('queued','running','cancelling')")
        ];
        $jobs=$history->attachActionOutcomes($connection->fetchAllAssociative(
            'SELECT j.id,j.kind,j.phase,j.state,j.progress_current,j.progress_total,'.
            'j.created_at,j.run_id,r.state AS run_state '.
            'FROM jobs j LEFT JOIN scan_runs r ON r.id=j.run_id ORDER BY j.id DESC LIMIT 8'
        ));
        $scans=$connection->fetchAllAssociative(
            'SELECT r.id,r.profile_id,r.state,r.finished_at,r.started_at,'.
            'r.files_indexed,r.duplicate_groups,p.name AS profile_name '.
            'FROM scan_runs r JOIN scan_profiles p ON p.id=r.profile_id '.
            "WHERE r.state IN ('completed','archived','failed','cancelled') ".
            'ORDER BY r.id DESC LIMIT 8'
        );
        $firstProfileId=$connection->fetchOne('SELECT id FROM scan_profiles ORDER BY name,id LIMIT 1');
        $firstProfileId=$firstProfileId===false?null:(int)$firstProfileId;
        return $this->render('dashboard/index.html.twig',
            compact('metrics','jobs','scans','firstProfileId'));
    }
}
