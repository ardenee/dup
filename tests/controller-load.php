<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

$controllers = [
    \App\Module\Duplicates\Controller\DuplicatesController::class,
    \App\Module\Duplicates\Controller\DuplicateMembersController::class,
    \App\Module\Renaming\Controller\RenameController::class,
    \App\Module\Profiles\Controller\ProfilesController::class,
    \App\Module\Jobs\Controller\JobsController::class,
    \App\Module\Profiles\Controller\ProfileTypesController::class,
    \App\Module\Files\Controller\FileViewerController::class,
    \App\Module\Files\Controller\ViewerSettingsController::class,
    \App\Module\Accounts\Controller\AccountsController::class,
    \App\Module\FileActions\Controller\FileMoveController::class,
    \App\Module\FileActions\Controller\ActionSettingsController::class,
    \App\Module\FileActions\Controller\BulkDuplicateController::class,
    \App\Module\FileActions\Controller\FileActionController::class,
];
foreach($controllers as $controller) {
    if(!class_exists($controller))
        throw new \RuntimeException('Controller cannot be loaded: '.$controller);
    if(!is_subclass_of($controller,\Symfony\Bundle\FrameworkBundle\Controller\AbstractController::class))
        throw new \RuntimeException('Invalid controller superclass: '.$controller);
    echo 'PASS '.$controller.PHP_EOL;
}
