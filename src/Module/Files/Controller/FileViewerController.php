<?php
declare(strict_types=1);
namespace App\Module\Files\Controller;

use App\Module\Files\Service\IndexedFileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Routing\Attribute\Route;

final class FileViewerController extends AbstractController
{
    #[Route('/files/{id<\\d+>}',name:'app_file_view',methods:['GET'])]
    public function view(int $id,IndexedFileService $files): Response
    {
        try {
            $file=$files->get($id);
            $text=null;$truncated=false;
            if($file['preview_type']==='text')
                [$text,$truncated]=$files->textPreview($file);
        } catch(\DomainException $ex) {
            throw $this->createNotFoundException($ex->getMessage(),$ex);
        }
        return $this->render('files/view.html.twig',[
            'file'=>$file,'preview_text'=>$text,'truncated'=>$truncated
        ],new Response('',200,[
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff'
        ]));
    }

    #[Route('/files/{id<\\d+>}/inline',name:'app_file_inline',methods:['GET'])]
    public function inline(int $id,IndexedFileService $files): Response
    {
        try {$file=$files->get($id);}
        catch(\DomainException $ex) {
            throw $this->createNotFoundException($ex->getMessage(),$ex);
        }
        if(!in_array($file['preview_type'],['image','pdf'],true))
            throw $this->createNotFoundException('Inline preview is disabled for this file type or size.');
        return $this->streamFile($files,$file,true);
    }

    #[Route('/files/{id<\\d+>}/download',name:'app_file_download',methods:['GET'])]
    public function download(int $id,IndexedFileService $files): Response
    {
        try {$file=$files->get($id);}
        catch(\DomainException $ex) {
            throw $this->createNotFoundException($ex->getMessage(),$ex);
        }
        return $this->streamFile($files,$file,false);
    }

    private function streamFile(IndexedFileService $files,array $file,bool $inline): StreamedResponse
    {
        $name=(string)$file['filename'];
        $ascii=preg_replace('/[^a-zA-Z0-9._-]/','_',$name)?:'download.bin';
        $disposition=HeaderUtils::makeDisposition(
            $inline?HeaderUtils::DISPOSITION_INLINE:HeaderUtils::DISPOSITION_ATTACHMENT,
            $name,$ascii
        );
        $headers=[
            'Content-Type'=>$inline?
                IndexedFileService::inlineContentType((string)$file['extension']):
                'application/octet-stream',
            'Content-Disposition'=>$disposition,
            'Content-Length'=>(string)$file['size_bytes'],
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
            'Accept-Ranges'=>'none',
            'X-Frame-Options'=>'SAMEORIGIN'
        ];
        return new StreamedResponse(function() use ($files,$file):void {
            $handle=@fopen((string)$file['absolute_path'],'rb');
            if(!$handle) return;
            try {
                $files->verifyHandle($handle,$file);
                while(!feof($handle)) {
                    $chunk=fread($handle,1048576);
                    if($chunk===false || ($chunk==='' && !feof($handle))) break;
                    echo $chunk;
                    if(connection_aborted()) break;
                }
            } finally {fclose($handle);}
        },200,$headers);
    }
}
