<?php
declare(strict_types=1);
namespace App\Module\Files\Service;

use Doctrine\DBAL\Connection;

/**
 * Legacy viewer configuration, persisted independently from scan settings.
 * Unknown image formats are never served inline as an image.
 */
final class ViewerSettings
{
    private const DEFAULTS=[
        'view_enable'=>'1',
        'view_image_exts'=>'jpg,jpeg,png,gif,webp,bmp',
        'view_text_exts'=>'txt,log,json,xml,csv,nfo,srt,md,php,css,js,html,htm,ini,conf',
        'view_pdf_exts'=>'pdf'
    ];
    private const IMAGES=['jpg'=>true,'jpeg'=>true,'png'=>true,'gif'=>true,'webp'=>true,'bmp'=>true];

    private ?array $cache=null;

    public function __construct(private readonly Connection $db) {}

    public function all():array
    {
        if($this->cache!==null) return $this->cache;
        $rows=$this->db->fetchAllKeyValue('SELECT setting_key,setting_value FROM viewer_settings');
        $known=[];
        foreach(self::DEFAULTS as $key=>$value)
            $known[$key]=array_key_exists($key,$rows)?(string)$rows[$key]:$value;
        return $this->cache=$known;
    }

    public function previewType(string $extension,int $size):string
    {
        $settings=$this->all();
        if($settings['view_enable']!=='1') return 'none';
        $ext=strtolower(ltrim($extension,'.'));
        if($ext==='') return 'none';
        if($size<=IndexedFileService::INLINE_MAX && isset(self::IMAGES[$ext]) &&
           $this->hasExtension($settings['view_image_exts'],$ext)) return 'image';
        if($size<=IndexedFileService::INLINE_MAX && $ext==='pdf' &&
           $this->hasExtension($settings['view_pdf_exts'],$ext)) return 'pdf';
        if($this->hasExtension($settings['view_text_exts'],$ext)) return 'text';
        return 'none';
    }

    private function hasExtension(string $csv,string $ext):bool
    {
        return in_array($ext,explode(',',$csv),true);
    }

    public function normalized(array $input):array
    {
        $values=['view_enable'=>!empty($input['view_enable'])?'1':'0'];
        foreach(['view_image_exts','view_text_exts','view_pdf_exts'] as $key) {
            $raw=(string)($input[$key]??'');
            if(strlen($raw)>2000) throw new \DomainException('Extension list is too long.');
            $extensions=[];
            foreach(explode(',',$raw) as $token) {
                $ext=strtolower(ltrim(trim($token),'.'));
                if($ext==='') continue;
                if(!preg_match('/^[a-z0-9][a-z0-9_+-]{0,19}$/D',$ext))
                    throw new \DomainException('Use comma-separated extensions without wildcard patterns.');
                if($key==='view_image_exts' && !isset(self::IMAGES[$ext]))
                    throw new \DomainException('Unsupported inline image format: '.$ext);
                if($key==='view_pdf_exts' && $ext!=='pdf')
                    throw new \DomainException('Only PDF may be served in an inline PDF frame.');
                $extensions[$ext]=$ext;
            }
            if(count($extensions)>60) throw new \DomainException('Too many preview extensions.');
            $values[$key]=implode(',',array_values($extensions));
        }
        return $values;
    }

    public function save(array $input):void
    {
        $values=$this->normalized($input);
        $this->db->transactional(function(Connection $db) use ($values):void {
            foreach($values as $key=>$value) {
                $existing=$db->fetchOne('SELECT setting_key FROM viewer_settings WHERE setting_key=?',[$key]);
                if($existing===false) $db->insert('viewer_settings',[
                    'setting_key'=>$key,'setting_value'=>$value,'updated_at'=>gmdate('Y-m-d H:i:s')
                ]);
                else $db->update('viewer_settings',[
                    'setting_value'=>$value,'updated_at'=>gmdate('Y-m-d H:i:s')
                ],['setting_key'=>$key]);
            }
        });
        $this->cache=$values;
    }
}
