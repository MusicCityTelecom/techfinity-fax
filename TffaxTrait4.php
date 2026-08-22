<?php
namespace FreePBX\modules;
trait TffaxTrait4 {


    public function streamPdfPath($path,$filename='fax-preview.pdf',$download=false,$deleteAfter=false){
        if(!$path||!is_file($path)||!is_readable($path)){$this->fileHttpError(404,'Preview document is unavailable.');}
        $size=(int)filesize($path);if($size<=0)$this->fileHttpError(500,'Preview document is empty.');
        $fh=@fopen($path,'rb');$sig=$fh?fread($fh,5):false;if($fh)fclose($fh);if($sig!=='%PDF-')$this->fileHttpError(500,'Preview document is not a valid PDF.');
        if($deleteAfter){register_shutdown_function(function() use($path){if(is_file($path))@unlink($path);});}
        while(ob_get_level()>0){@ob_end_clean();}@ini_set('zlib.output_compression','Off');@set_time_limit(0);
        header_remove('Content-Type');header_remove('Content-Length');header('Content-Type: application/pdf');
        header('Content-Disposition: '.($download?'attachment':'inline').'; filename="'.preg_replace('/[^A-Za-z0-9._-]/','_',basename($filename)).'"');
        header('Content-Transfer-Encoding: binary');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');header('Content-Length: '.$size);
        readfile($path);exit;
    }

    public function serveJobFile($id,$download=false){
        $id=(int)$id;
        $st=$this->db->prepare("SELECT id,user_name,pdf_path,document_path FROM tffax_jobs WHERE id=?");
        $st->execute([$id]);
        $j=$st->fetch(\PDO::FETCH_ASSOC);
        if(!$j){ $this->fileHttpError(404,'Fax record not found.'); }
        // ACP requests are already authenticated by the administration shell. User-level
        // authorization will be enforced separately by the UCP adapter.
        $path=(!empty($j['pdf_path']) && is_file($j['pdf_path'])) ? $j['pdf_path'] : $j['document_path'];
        if(!$path || !is_file($path) || !is_readable($path)){ $this->fileHttpError(404,'Stored fax document is unavailable.'); }

        $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
        $mime=($ext==='pdf') ? 'application/pdf' : 'application/octet-stream';
        $size=(int)filesize($path);
        if($size<=0){ $this->fileHttpError(500,'Stored fax document is empty.'); }
        if($ext==='pdf'){
            $fh=@fopen($path,'rb');
            $sig=$fh ? fread($fh,5) : false;
            if($fh){ fclose($fh); }
            if($sig!=='%PDF-'){ $this->fileHttpError(500,'Stored fax preview is not a valid PDF.'); }
        }

        // config.php normally renders the administration shell before module pages. File
        // responses are requested with quietmode=1 and we defensively remove any
        // buffered HTML/whitespace so it cannot be prepended to the PDF bytes.
        while(ob_get_level()>0){ @ob_end_clean(); }
        @ini_set('zlib.output_compression','Off');
        @set_time_limit(0);

        $filename='fax-'.$id.'.'.($ext ?: 'bin');
        header_remove('Content-Type');
        header_remove('Content-Length');
        header('Content-Type: '.$mime);
        header('Content-Disposition: '.($download?'attachment':'inline').'; filename="'.$filename.'"');
        header('Content-Transfer-Encoding: binary');
        header('X-Content-Type-Options: nosniff');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $start=0; $end=$size-1;
        if(!$download && !empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/',$_SERVER['HTTP_RANGE'],$m)){
            if($m[1]!=='' ){ $start=(int)$m[1]; }
            if($m[2]!=='' ){ $end=(int)$m[2]; }
            if($m[1]==='' && $m[2]!=='') { $suffix=(int)$m[2]; $start=max(0,$size-$suffix); $end=$size-1; }
            if($start<0 || $end<$start || $start>=$size){
                header('HTTP/1.1 416 Range Not Satisfiable');
                header('Content-Range: bytes */'.$size);
                exit;
            }
            $end=min($end,$size-1);
            $length=$end-$start+1;
            header('HTTP/1.1 206 Partial Content');
            header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
            header('Content-Length: '.$length);
            $fp=fopen($path,'rb');
            fseek($fp,$start);
            $remaining=$length;
            while($remaining>0 && !feof($fp)){
                $chunk=fread($fp,min(1048576,$remaining));
                if($chunk===false || $chunk===''){ break; }
                echo $chunk;
                $remaining-=strlen($chunk);
                flush();
            }
            fclose($fp);
            exit;
        }

        header('Content-Length: '.$size);
        $fp=fopen($path,'rb');
        while(!feof($fp)){
            $chunk=fread($fp,1048576);
            if($chunk===false){ break; }
            echo $chunk;
            flush();
        }
        fclose($fp);
        exit;
    }
    private function fileHttpError($code,$message){
        while(ob_get_level()>0){ @ob_end_clean(); }
        http_response_code((int)$code);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo (string)$message;
        exit;
    }
    private function convertToPdf($in,$out){
        $ext=strtolower(pathinfo($in,PATHINFO_EXTENSION));
        if($ext==='pdf'){if(!@copy($in,$out))throw new \RuntimeException('Unable to copy PDF document.');return;}
        if(in_array($ext,['tif','tiff'],true)){$bin=$this->which('tiff2pdf');if(!$bin)throw new \RuntimeException('tiff2pdf is required to preview TIFF documents.');exec(escapeshellcmd($bin).' -o '.escapeshellarg($out).' '.escapeshellarg($in).' 2>&1',$o,$rc);if($rc!==0||!is_file($out))throw new \RuntimeException('TIFF to PDF conversion failed.');return;}
        $gs=$this->which('gs'); if(!$gs)throw new \RuntimeException('Ghostscript is required to preview image documents.');
        $cmd=escapeshellcmd($gs).' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite -sOutputFile='.escapeshellarg($out).' '.escapeshellarg($in).' 2>&1';exec($cmd,$o,$rc);if($rc!==0||!is_file($out))throw new \RuntimeException('PDF preview conversion failed: '.implode(' ',array_slice($o,-3)));
    }
    private function renderCoverPage(array $cover,$out,array $vars){
        $style=$this->normalizeCoverStyle($cover['template_style']??'professional');
        $text=(string)$cover['template_html']; foreach($vars as $k=>$v){$text=str_replace('{{'.$k.'}}',(string)$v,$text);}
        // Strip any unresolved variables so optional profile fields do not print raw tokens.
        $text=preg_replace('/\{\{[a-zA-Z0-9_]+\}\}/','',$text);
        $rawLines=preg_split('/\r?\n/',$text);$lines=[];
        foreach($rawLines as $line){
            $line=rtrim((string)$line);
            if(strlen($line)<=88){$lines[]=$line;continue;}
            $wrapped=wordwrap($line,88,"\n",true);foreach(explode("\n",$wrapped) as $w)$lines[]=$w;
        }
        $esc=function($v){return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],(string)$v);};
        $fromCompany=$esc($vars['from_company']??'');$fromName=$esc($vars['from_name']??'');$fromPhone=$esc($vars['from_phone']??'');$fromFax=$esc($vars['from_fax']??($vars['from_number']??''));$fromEmail=$esc($vars['from_email']??'');$fromAddress=$esc($vars['from_address']??'');$website=$esc($vars['from_website']??'');
        $ps="%!PS-Adobe-3.0\n%%Pages: 1\n%%BoundingBox: 0 0 612 792\n";
        if($style==='classic'){
            $ps.="2 setlinewidth 30 30 552 732 rectstroke\n/Helvetica-Bold findfont 34 scalefont setfont 54 688 moveto (FAX) show\n";
            $ps.="/Helvetica-Bold findfont 18 scalefont setfont 330 700 moveto (".$fromCompany.") show\n/Helvetica findfont 9 scalefont setfont 330 682 moveto (".$fromName.") show\n330 668 moveto (".$fromPhone.") show\n330 654 moveto (Fax: ".$fromFax.") show\n330 640 moveto (".$fromEmail.") show\n";
            $ps.="0.7 setgray 54 620 moveto 504 620 lineto stroke 0 setgray\n";$y=594;
        }elseif($style==='minimal'){
            $ps.="0.88 setgray 0 736 612 56 rectfill 0 setgray\n/Helvetica-Bold findfont 30 scalefont setfont 48 752 moveto (FAX) show\n/Helvetica-Bold findfont 14 scalefont setfont 320 756 moveto (".$fromCompany.") show\n/Helvetica findfont 9 scalefont setfont 320 742 moveto (".$fromName.") show\n";
            $ps.="/Helvetica findfont 9 scalefont setfont 48 714 moveto (".$fromAddress.") show\n48 700 moveto (".$fromPhone."   Fax: ".$fromFax."   ".$fromEmail.") show\n48 686 moveto (".$website.") show\n";$y=650;
        }else{
            $ps.="0.10 setgray 0 706 612 86 rectfill 1 setgray /Helvetica-Bold findfont 34 scalefont setfont 46 742 moveto (FAX) show\n/Helvetica-Bold findfont 17 scalefont setfont 290 756 moveto (".$fromCompany.") show\n/Helvetica findfont 9 scalefont setfont 290 739 moveto (".$fromName.") show\n290 724 moveto (".$fromPhone."   Fax: ".$fromFax.") show\n290 710 moveto (".$fromEmail.") show\n0 setgray\n";
            $ps.="/Helvetica findfont 9 scalefont setfont 48 684 moveto (".$fromAddress.") show\n48 670 moveto (".$website.") show\n0.75 setgray 48 652 moveto 516 652 lineto stroke 0 setgray\n";$y=626;
        }
        $ps.="/Helvetica findfont 10.5 scalefont setfont\n";
        foreach($lines as $line){
            if($y<66){$ps.="showpage\n/Helvetica findfont 10.5 scalefont setfont\n";$y=738;}
            $safe=$esc($line);
            if(preg_match('/^(TO:|FROM:|SUBJECT:|RE:|MESSAGE:|COMMENTS:)/i',$line)){$ps.="/Helvetica-Bold findfont 10.5 scalefont setfont\n48 $y moveto ($safe) show\n/Helvetica findfont 10.5 scalefont setfont\n";}else{$ps.="48 $y moveto ($safe) show\n";}
            $y-=16;
        }
        $ps.="showpage\n"; $tmp=$out.'.ps'; if(file_put_contents($tmp,$ps)===false)throw new \RuntimeException('Unable to create cover page source.');
        $gs=$this->which('gs');if(!$gs){@unlink($tmp);throw new \RuntimeException('Ghostscript is required for cover pages.');}
        exec(escapeshellcmd($gs).' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite -sOutputFile='.escapeshellarg($out).' '.escapeshellarg($tmp).' 2>&1',$o,$rc);@unlink($tmp);if($rc!==0||!is_file($out))throw new \RuntimeException('Cover page generation failed.');
    }
    private function mergePdfs(array $files,$out){
        $gs=$this->which('gs');if(!$gs)throw new \RuntimeException('Ghostscript is required to merge cover pages.'); $args='';foreach($files as $x){$args.=' '.escapeshellarg($x);}exec(escapeshellcmd($gs).' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite -sOutputFile='.escapeshellarg($out).$args.' 2>&1',$o,$rc);if($rc!==0||!is_file($out))throw new \RuntimeException('Unable to merge fax PDF preview.');
    }
    private function convertToTiff($in,$out){
        $ext=strtolower(pathinfo($in,PATHINFO_EXTENSION)); if(in_array($ext,['tif','tiff'],true)){if(!@copy($in,$out))throw new \RuntimeException('Unable to copy TIFF document.');return;}
        $gs=$this->which('gs'); if(!$gs)throw new \RuntimeException('Ghostscript is required to send PDF/image documents. Install ghostscript and retry.');
        $cmd=escapeshellcmd($gs).' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=tiffg4 -r204x196 -sOutputFile='.escapeshellarg($out).' '.escapeshellarg($in).' 2>&1'; exec($cmd,$o,$rc); if($rc!==0||!is_file($out))throw new \RuntimeException('Document conversion failed: '.implode(' ',array_slice($o,-3)));
    }
    private function launchOutboundWorker($job){
        $job=(int)$job;
        $php=$this->which('php') ?: '/usr/bin/php';
        $script=__DIR__.'/bin/outbound-worker.php';
        if(!is_file($script)) throw new \RuntimeException('Outbound fax worker is missing.');
        $cmd=escapeshellcmd($php).' -q '.escapeshellarg($script).' --job '.escapeshellarg((string)$job).' >/dev/null 2>&1 & echo $!';
        $out=[];$rc=1; exec($cmd,$out,$rc);
        if($rc!==0 || empty($out[0])){
            $this->db->prepare("UPDATE tffax_jobs SET status='failed',completed_at=UTC_TIMESTAMP(),status_text=? WHERE id=?")->execute(['Unable to start outbound fax worker',$job]);
            $this->event($job,'WORKER_START_FAILED','Unable to launch outbound fax worker');
            throw new \RuntimeException('Unable to start the outbound fax worker.');
        }
        $this->event($job,'WORKER_STARTED','Outbound fax worker started','PID '.trim((string)$out[0]));
    }

    public function getUserInboundDids($userId){
        $userId=(int)$userId;if($userId<=0)return [];
        $st=$this->db->prepare("SELECT r.did_pattern FROM tffax_users u JOIN tffax_routes r ON r.destination_id=u.primary_destination_id WHERE u.id=? AND r.enabled=1 AND r.did_pattern<>'' ORDER BY r.managed_by_account DESC,r.priority,r.id");
        $st->execute([$userId]);$out=[];
        foreach($st->fetchAll(\PDO::FETCH_COLUMN) as $did){
            $did=trim((string)$did);
            if($did!=='' && strpos($did,'*')===false && strpos($did,'?')===false){$out[$did]=true;}
        }
        return array_keys($out);
    }

    public function sendPortalTestFax($userId){
        $u=$this->getPortalUser((int)$userId);if(!$u)throw new \RuntimeException('Fax account not found.');
        if(empty($u['can_send']))throw new \RuntimeException('Your account is not permitted to send faxes.');
        if(empty($u['default_identity_id']))throw new \RuntimeException('An administrator must assign a default fax identity before you can send a test fax.');
        $covers=$this->getCoverPages(true,(int)$userId,true);$cover=null;
        foreach($covers as $c){if(($c['template_style']??'')==='professional'&&empty($c['owner_user_id'])){$cover=$c;break;}}
        if(!$cover&&!empty($covers))$cover=$covers[0];
        if(!$cover)throw new \RuntimeException('At least one enabled cover page is required for the test fax.');
        $name=trim((string)($u['full_name']??''));if($name==='')$name=(string)$u['user_name'];
        $company=trim((string)($u['company_name']??''));
        $p=[
            'fax_number'=>'18884732963','identity_id'=>(int)$u['default_identity_id'],'coverpage_id'=>(int)$cover['id'],
            'recipient_name'=>'HP Fax Test Service','recipient_company'=>'HP','subject'=>'Fax Platform Test',
            'notes'=>'Automated Fax Platform test from '.$name.($company!==''?' / '.$company:'').'. HP should receive this fax and return a fax to the outbound caller ID presented by this transmission.'
        ];
        $_SESSION['tffax_portal_user_name']=$u['user_name'];
        return $this->submitOutbound($p,[],false);
    }

    public function processOutboundJob($job){
        $job=(int)$job;
        $st=$this->db->prepare("SELECT j.*,i.fax_number,i.station_id,i.header_text,i.outbound_cid,u.fax_number AS user_fax_number,u.full_name AS user_full_name FROM tffax_jobs j LEFT JOIN tffax_identities i ON i.id=j.identity_id LEFT JOIN tffax_users u ON u.user_name=j.user_name WHERE j.id=? AND j.direction='outbound' LIMIT 1");
        $st->execute([$job]);
        $j=$st->fetch(\PDO::FETCH_ASSOC);
        if(!$j) throw new \RuntimeException('Outbound fax job not found.');
        if(in_array($j['status'],['completed','cancelled'],true)) return;
        if(!$this->astman){
            $this->markOriginateFailure($job,'Asterisk Manager is unavailable.');
            return;
        }
        if(empty($j['tiff_path']) || !is_file($j['tiff_path'])){
            $this->markOriginateFailure($job,'Prepared TIFF file is missing.');
            return;
        }
        $number=preg_replace('/[^0-9*#+]/','',(string)$j['destination_number']);
        if($number===''){ $this->markOriginateFailure($job,'Destination fax number is empty.'); return; }
        $context=$this->getSetting('outbound_context','from-internal');
        $station=$j['station_id'] ?: $j['fax_number'];
        $header=$j['header_text'] ?: $this->getSetting('header_text','Fax Platform');
        // Use the caller ID captured when the job was created. This keeps queued
        // jobs stable if the user later changes their sender profile.
        $cid=trim((string)($j['source_number']??''));
        if($cid==='')$cid=trim((string)($j['user_fax_number']??''));
        if($cid==='')$cid=$j['outbound_cid'] ?: $j['fax_number'] ?: $station;
        $cid=str_replace([',','|'],[' ',' '],(string)$cid);
        $vars='TFFAX_JOB_ID='.$job.',TFFAX_FILE='.$j['tiff_path'].',TFFAX_STATION_ID='.str_replace([',','|'],[' ',' '],$station).',TFFAX_HEADER='.str_replace([',','|'],[' ',' '],$header).',__TRUNKCIDOVERRIDE='.$cid.',__TFFAX_OUTBOUND_CID='.$cid;
        $this->db->prepare("UPDATE tffax_jobs SET status='dialing',attempts=attempts+1,started_at=COALESCE(started_at,UTC_TIMESTAMP()),status_text='Dialing destination' WHERE id=?")->execute([$job]);
        $this->event($job,'DIALING','Dialing '.$number.' through '.$context);
        // Synchronous Originate is intentional here, but it runs in this detached CLI worker,
        // not in the web request.  This lets us record BUSY/CONGESTION/no-answer failures
        // that occur before tffax-tx ever starts.
        $res=$this->astman->send_request('Originate',[
            'Channel'=>'Local/'.$number.'@'.$context.'/n',
            'Context'=>'tffax-tx','Exten'=>'s','Priority'=>'1',
            'CallerID'=>$cid,'Variable'=>$vars,'Timeout'=>'60000','Async'=>'false'
        ]);
        $ok=is_array($res) && isset($res['Response']) && strcasecmp((string)$res['Response'],'Success')===0;
        if(!$ok){
            $msg=is_array($res) ? ($res['Message'] ?? json_encode($res)) : (string)$res;
            $this->markOriginateFailure($job,$msg ?: 'Originate failed',is_array($res)?json_encode($res):$res);
            return;
        }
        $this->event($job,'CALL_ANSWERED','Destination answered; fax application started',is_array($res)?json_encode($res):$res);
    }
}
