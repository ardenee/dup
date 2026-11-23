<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'));
foreach (['path','asset','csrf_token','dup_navigation'] as $function) {
    $twig->addFunction(new \Twig\TwigFunction($function,static fn()=>null));
}
$files = [
    'profiles/new.html.twig',
    'profiles/index.html.twig',
    'search/index.html.twig',
    'rename/index.html.twig',
    'rename/file.html.twig',
    'rename/selected.html.twig',
    'profiles/types.html.twig',
    'files/view.html.twig',
    'jobs/index.html.twig',
    'actions/index.html.twig',
    'actions/detail.html.twig',
    'actions/move.html.twig',
    'profiles/settings/ignore-folders.html.twig',
    'duplicates/group.html.twig',
    'duplicates/index.html.twig',
    'duplicates/_members.html.twig',
    'duplicates/_format.html.twig',
    'duplicates/_pager.html.twig',
    'reports/run.html.twig',
    'reports/index.html.twig'
];
foreach ($files as $file) {
    $source=file_get_contents(dirname(__DIR__).'/templates/'.$file);
    $twig->parse($twig->tokenize(new \Twig\Source($source,$file)));
    echo 'PASS '.$file.PHP_EOL;
}
