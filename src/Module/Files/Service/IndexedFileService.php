<?php
declare(strict_types=1);
namespace App\Module\Files\Service;

use App\Module\FileActions\Service\SafeFileSystem;
use Doctrine\DBAL\Connection;

/**
 * Resolve ONLY latest completed, indexed files within their scan root.
 * No caller-supplied filenames/paths, symlinks or stale file metadata.
 */
final class IndexedFileService
{
    public const TEXT_MAX=1048576;
    public const INLINE_MAX=40*1048576;

    private const TEXT=[
        'txt'=>true,'log'=>true,'json'=>true,'xml'=>true,'csv'=>true,
        'nfo'=>true,'srt'=>true,'md'=>true,'php'=>true,'css'=>true,
        'js'=>true,'html'=>true,'htm'=>true,'ini'=>true,'conf'=>true
    ];
    private const IMAGES=[
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
        'gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp'
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly SafeFileSystem $fs,
        private readonly ViewerSettings $viewer
    ) {}

    public function get(int $fileId): array
    {
        $row=$this->db->fetchAssociative(
            'SELECT f.id,f.filename,f.size_bytes,f.mtime_epoch,f.hash_value,'.
            'f.hash_method,f.error_code,d.path AS directory,p.source_root,'.
            'p.id AS profile_id,p.name AS profile_name '.
            'FROM file_entries f JOIN directories d ON d.id=f.directory_id '.
            'JOIN scan_runs r ON r.id=f.run_id '.
            'JOIN scan_profiles p ON p.id=r.profile_id '.
            "WHERE f.id=? AND f.error_code IS NULL AND r.state='completed' ".
            "AND r.id=(SELECT MAX(x.id) FROM scan_runs x WHERE x.profile_id=p.id ".
            "AND x.state='completed') LIMIT 1",[$fileId]
        );
        if(!$row) throw new \DomainException('File not found in the latest completed scan.');
        $root=$this->fs->checkedRoot((string)$row['source_root']);
        $directory=$this->fs->checkedRoot((string)$row['directory']);
        $normalizedRoot=rtrim(str_replace('\\','/',$root),'/');
        $normalizedDir=str_replace('\\','/',$directory);
        if(!$this->fs->samePath($root,$directory) &&
           !str_starts_with(
               PHP_OS_FAMILY==='Windows'?mb_strtolower($normalizedDir.'/'): $normalizedDir.'/',
               (PHP_OS_FAMILY==='Windows'?mb_strtolower($normalizedRoot):$normalizedRoot).'/'
           ))
            throw new \DomainException('The recorded file is outside the scan root.');
        $path=$this->fs->checkedPath($directory,(string)$row['filename']);
        $this->fs->checkedStat($path,(int)$row['size_bytes'],(int)$row['mtime_epoch']);
        if(!is_readable($path))
            throw new \DomainException('File is not readable by the web process.');
        $row['absolute_path']=$path;
        $row['extension']=strtolower((string)pathinfo((string)$row['filename'],PATHINFO_EXTENSION));
        $row['preview_type']=$this->viewer->previewType($row['extension'],(int)$row['size_bytes']);
        return $row;
    }

    public static function previewType(string $ext,int $size): string
    {
        if(isset(self::TEXT[$ext])) return 'text';
        if($size<=self::INLINE_MAX && isset(self::IMAGES[$ext])) return 'image';
        if($size<=self::INLINE_MAX && $ext==='pdf') return 'pdf';
        return 'none';
    }

    public static function inlineContentType(string $ext): string
    {
        return self::IMAGES[$ext]??($ext==='pdf'?'application/pdf':'application/octet-stream');
    }

    public function textPreview(array $file): array
    {
        if($file['preview_type']!=='text') throw new \DomainException('Not a text preview.');
        $path=(string)$file['absolute_path'];
        $handle=@fopen($path,'rb');
        if(!$handle) throw new \DomainException('Cannot read the requested file.');
        try {
            $this->verifyHandle($handle,$file);
            $text=(string)stream_get_contents($handle,self::TEXT_MAX);
        } finally {fclose($handle);}
        // Untrusted files are escaped by Twig, never executed or injected as HTML.
        $text=mb_convert_encoding($text,'UTF-8','UTF-8');
        return [$text,(int)$file['size_bytes']>self::TEXT_MAX];
    }

    /** Reject a replaced file after its path was checked. */
    public function verifyHandle(mixed $handle,array $file): void
    {
        $stat=@fstat($handle);
        $current=$this->fs->checkedStat(
            (string)$file['absolute_path'],(int)$file['size_bytes'],(int)$file['mtime_epoch']
        );
        if(!is_array($stat) ||
           (int)$stat['size']!==(int)$file['size_bytes'] ||
           (int)$stat['mtime']!==(int)$file['mtime_epoch'] ||
           (string)$stat['dev']!==(string)$current['dev'] ||
           (string)$stat['ino']!==(string)$current['ino'])
            throw new \DomainException('File changed since the scan. Run a new scan.');
    }
}
