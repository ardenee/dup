<?php
declare(strict_types=1);
namespace App\Module\Dashboard\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/',name:'app_dashboard',methods:['GET'])]
    public function index(Connection $connection): Response
    {
        $metrics=[
            'profiles'=>(int)$connection->fetchOne('SELECT COUNT(*) FROM scan_profiles'),
            'completed_scans'=>(int)$connection->fetchOne("SELECT COUNT(*) FROM scan_runs WHERE state='completed'"),
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
        $jobs=$connection->fetchAllAssociative('SELECT id,kind,phase,state,progress_current,progress_total,created_at FROM jobs ORDER BY id DESC LIMIT 8');
        return $this->render('dashboard/index.html.twig',compact('metrics','jobs'));
    }
}
