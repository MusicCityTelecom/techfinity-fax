<?php
namespace FreePBX\modules;
trait TffaxTrait3 {

    public function getPortalUser($id){$st=$this->db->prepare("SELECT u.*,i.name default_identity_name FROM tffax_users u LEFT JOIN tffax_identities i ON i.id=u.default_identity_id WHERE u.id=? AND u.portal_enabled=1");$st->execute([(int)$id]);return $st->fetch(\PDO::FETCH_ASSOC)?:null;}
    public function updatePortalPreferences($userId,$p){
        $userId=(int)$userId; $u=$this->getPortalUser($userId); if(!$u)throw new \RuntimeException('Fax account not found.');
        $email=trim((string)($p['email']??'')); if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Enter a valid email address.');
        $vals=[
            $email, trim((string)($p['full_name']??'')), trim((string)($p['company_name']??'')), trim((string)($p['phone_number']??'')), trim((string)($p['fax_number']??'')),
            trim((string)($p['address1']??'')), trim((string)($p['address2']??'')), trim((string)($p['city']??'')), trim((string)($p['state']??'')), trim((string)($p['postal_code']??'')), trim((string)($p['website']??'')),
            isset($p['notify_inbound'])?1:0,isset($p['notify_success'])?1:0,isset($p['notify_failure'])?1:0,$userId
        ];
        $this->db->prepare("UPDATE tffax_users SET email=?,full_name=?,company_name=?,phone_number=?,fax_number=?,address1=?,address2=?,city=?,state=?,postal_code=?,website=?,notify_inbound=?,notify_success=?,notify_failure=?,preview_before_send=0 WHERE id=?")->execute($vals);
    }
    public function changePortalPassword($userId,$current,$new,$confirm){
        $u=$this->getPortalUser((int)$userId); if(!$u)throw new \RuntimeException('Fax account not found.');
        if(!password_verify((string)$current,(string)$u['password_hash']))throw new \RuntimeException('Current password is incorrect.');
        if(strlen((string)$new)<8)throw new \InvalidArgumentException('New password must be at least 8 characters.');
        if(!hash_equals((string)$new,(string)$confirm))throw new \InvalidArgumentException('New password and confirmation do not match.');
        $this->db->prepare("UPDATE tffax_users SET password_hash=? WHERE id=?")->execute([password_hash((string)$new,PASSWORD_DEFAULT),(int)$userId]);
    }
    public function getPortalJobs($userId,$limit=200){
        $u=$this->getPortalUser($userId);if(!$u)return [];$limit=max(1,min(500,(int)$limit));
        if(!empty($u['can_view_all'])){$st=$this->db->query("SELECT j.*,i.name identity_name,d.name destination_name FROM tffax_jobs j LEFT JOIN tffax_identities i ON i.id=j.identity_id LEFT JOIN tffax_destinations d ON d.id=j.destination_id ORDER BY j.id DESC LIMIT ".$limit);return $st->fetchAll(\PDO::FETCH_ASSOC);}
        $sql="SELECT DISTINCT j.*,i.name identity_name,d.name destination_name FROM tffax_jobs j LEFT JOIN tffax_identities i ON i.id=j.identity_id LEFT JOIN tffax_destinations d ON d.id=j.destination_id LEFT JOIN tffax_destination_users du ON du.destination_id=j.destination_id WHERE (j.direction='outbound' AND j.user_name=?) OR (j.direction='inbound' AND du.user_id=?) ORDER BY j.id DESC LIMIT ".$limit;
        $st=$this->db->prepare($sql);$st->execute([$u['user_name'],(int)$userId]);return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function portalCanAccessJob($userId,$jobId){foreach($this->getPortalJobs((int)$userId,500) as $j){if((int)$j['id']===(int)$jobId)return true;}return false;}
    public function servePortalJobFile($userId,$jobId,$download=false){if(!$this->portalCanAccessJob($userId,$jobId))$this->fileHttpError(403,'You do not have access to this fax.');$this->serveJobFile($jobId,$download);}

    public function getRoutes(){
        return $this->db->query("SELECT r.*,d.name destination_name FROM tffax_routes r LEFT JOIN tffax_destinations d ON d.id=r.destination_id ORDER BY r.priority,r.id")->fetchAll(\PDO::FETCH_ASSOC);
    }
    private function validateRoutePattern($pattern,$label){
        $pattern=trim((string)$pattern);
        if($pattern===''||$pattern==='*')return $pattern;
        if(!preg_match('/^[0-9+*?]+$/',$pattern))throw new \InvalidArgumentException($label.' may contain only digits, +, * and ?.');
        return $pattern;
    }
    public function saveRoute($p){
        $destinationId=(int)($p['destination_id']??0);if(!$this->getDestination($destinationId))throw new \InvalidArgumentException('Select a valid inbound fax mailbox.');
        $did=$this->validateRoutePattern($p['did_pattern']??'','DID pattern');$cid=$this->validateRoutePattern($p['cid_pattern']??'','Caller ID pattern');
        if(($did===''||$did==='*')&&($cid===''||$cid==='*'))throw new \InvalidArgumentException('A routing rule must restrict DID and/or Caller ID. Unmatched calls already fall back to the Unassigned Inbox.');
        $vals=[max(1,min(9999,(int)($p['priority']??100))),$did,$cid,$destinationId,trim((string)($p['description']??'')),isset($p['enabled'])?1:0];
        $id=(int)($p['id']??0);if($id){$st=$this->db->prepare("UPDATE tffax_routes SET priority=?,did_pattern=?,cid_pattern=?,destination_id=?,description=?,enabled=? WHERE id=?");$vals[]=$id;$st->execute($vals);}else{$st=$this->db->prepare("INSERT INTO tffax_routes (priority,did_pattern,cid_pattern,destination_id,description,enabled) VALUES (?,?,?,?,?,?)");$st->execute($vals);}
        $_SESSION['tffax_notice']='Inbound fax routing rule saved. Apply Config to activate it.';
    }
    public function deleteRoute($id){$id=(int)$id;if($id>0)$this->db->prepare("DELETE FROM tffax_routes WHERE id=?")->execute([$id]);$_SESSION['tffax_notice']='Inbound fax routing rule deleted. Apply Config to remove it from dialplan.';}
    public function buildRouteCondition($didPattern,$cidPattern){
        $parts=[];
        foreach([['p'=>(string)$didPattern,'v'=>'${TFFAX_DID}'],['p'=>(string)$cidPattern,'v'=>'${TFFAX_CID}']] as $x){
            $p=trim($x['p']);if($p===''||$p==='*')continue;
            if(strpos($p,'*')===false&&strpos($p,'?')===false){$parts[]='"'.$x['v'].'"="'.$this->escapeDialplan($p).'"';continue;}
            $regex=preg_quote($p,'/');$regex=str_replace(['\\*','\\?'],['.*','.'],$regex);
            $regex=str_replace('"','',$regex);
            $parts[]='${REGEX("^'.$regex.'$" '.$x['v'].')}=1';
        }
        return $parts?implode(' & ',$parts):'1';
    }

    public function destinations() {
        $out=[['destination'=>'tffax-router,s,1','description'=>'Automatic DID/CID Router','category'=>'Fax Platform']];
        foreach($this->getDestinations() as $d){if(!$d['enabled'])continue;$out[]=['destination'=>'tffax-inbound,'.$d['id'].',1','description'=>$d['name'],'category'=>'Fax Platform'];}
        $out[]=['destination'=>'tffax-inbound,0,1','description'=>'Unassigned Inbox','category'=>'Fax Platform'];
        return $out;
    }
    public function destinations_check($dest=true){return [];}
    public function destinations_change($old_dest,$new_dest){return true;}
    public function destinations_getdestinfo($dest){
        if($dest==='tffax-router,s,1')return ['description'=>'Fax Platform: Automatic DID/CID Router','edit_url'=>'config.php?display=tffax&view=routing'];
        if($dest==='tffax-inbound,0,1')return ['description'=>'Fax Platform: Unassigned Inbox','edit_url'=>'config.php?display=tffax&view=history'];
        if(preg_match('/^tffax-inbound,(\d+),1$/',$dest,$m)){$d=$this->getDestination((int)$m[1]);if($d)return ['description'=>'Fax Platform: '.$d['name'],'edit_url'=>'config.php?display=tffax&view=destinations'];}
        return false;
    }
    public function destinations_identif($dests){$r=[];foreach((array)$dests as $d){$i=$this->destinations_getdestinfo($d);if($i)$r[$d]=$i;}return $r;}

    public function assignJobDestination($jobId,$destinationId){
        $jobId=(int)$jobId;$destinationId=(int)$destinationId;if($jobId<=0||$destinationId<=0||!$this->getDestination($destinationId))throw new \InvalidArgumentException('Select a valid fax mailbox.');
        $st=$this->db->prepare("UPDATE tffax_jobs SET destination_id=? WHERE id=? AND direction='inbound'");$st->execute([$destinationId,$jobId]);
        if(!$st->rowCount())throw new \RuntimeException('Inbound fax record was not found or could not be assigned.');
        $this->event($jobId,'FAX_ASSIGNED','Inbound fax manually assigned to mailbox #'.$destinationId);
        $_SESSION['tffax_notice']='Inbound fax #'.$jobId.' assigned.';
    }

    public function getJobs($limit=100){$st=$this->db->prepare("SELECT j.*,i.name identity_name,d.name destination_name,c.name coverpage_name FROM tffax_jobs j LEFT JOIN tffax_identities i ON i.id=j.identity_id LEFT JOIN tffax_destinations d ON d.id=j.destination_id LEFT JOIN tffax_coverpages c ON c.id=j.coverpage_id ORDER BY j.id DESC LIMIT ".(int)$limit);$st->execute();return $st->fetchAll(\PDO::FETCH_ASSOC);}
    public function getStats(){return ['inbound'=>(int)$this->db->query("SELECT COUNT(*) FROM tffax_jobs WHERE direction='inbound'")->fetchColumn(),'outbound'=>(int)$this->db->query("SELECT COUNT(*) FROM tffax_jobs WHERE direction='outbound'")->fetchColumn(),'failed'=>(int)$this->db->query("SELECT COUNT(*) FROM tffax_jobs WHERE status='failed'")->fetchColumn(),'queued'=>(int)$this->db->query("SELECT COUNT(*) FROM tffax_jobs WHERE status IN ('queued','dialing','sending')")->fetchColumn()];}
    public function deleteJob($id,$setNotice=true){
        $id=(int)$id;
        if($id<=0) return;
        $st=$this->db->prepare("SELECT document_path,cover_path,tiff_path,pdf_path FROM tffax_jobs WHERE id=?");
        $st->execute([$id]);
        $j=$st->fetch(\PDO::FETCH_ASSOC);
        if(!$j) return;
        foreach($j as $path){ if($path && is_file($path)) @unlink($path); }
        $this->db->prepare("DELETE FROM tffax_events WHERE job_id=?")->execute([$id]);
        $this->db->prepare("DELETE FROM tffax_jobs WHERE id=?")->execute([$id]);
        if($setNotice){$_SESSION['tffax_notice']='Fax job #'.$id.' deleted.';}
    }

    public function clearFailedJobs(){
        $st=$this->db->query("SELECT id FROM tffax_jobs WHERE status IN ('failed','cancelled') ORDER BY id");
        $ids=$st->fetchAll(\PDO::FETCH_COLUMN);
        $count=0;
        foreach($ids as $id){ $this->deleteJob((int)$id); $count++; }
        $_SESSION['tffax_notice']=$count ? ($count.' failed/cancelled fax record'.($count===1?'':'s').' deleted.') : 'There are no failed/cancelled fax records to delete.';
    }

    public function submitOutbound($p,$files,$draft=false){
        $number=preg_replace('/[^0-9*#+]/','',(string)($p['fax_number']??''));
        if(!$draft && $number==='') throw new \InvalidArgumentException('Fax number is required.');
        $identity=$this->getIdentity((int)($p['identity_id']??0)); if(!$identity) throw new \InvalidArgumentException('Select a valid fax identity.');
        $coverId=(int)($p['coverpage_id']??0); $cover=$coverId>0?$this->getCoverPage($coverId):null; if($coverId>0&&!$cover) throw new \InvalidArgumentException('Selected cover page does not exist.');
        // Personal cover pages may only be used by their owner. Global templates remain available to everyone.
        $portalUid=(int)($_SESSION['tffax_portal_uid']??0);
        if($cover && !empty($cover['owner_user_id']) && (int)$cover['owner_user_id']!==$portalUid) throw new \RuntimeException('You do not have access to that cover page.');
        $hasDocument=!empty($files['document']['tmp_name']) && is_uploaded_file($files['document']['tmp_name']);
        if(!$hasDocument && !$cover) throw new \InvalidArgumentException('Attach a fax document or select a cover page. A cover page may be sent by itself.');
        $spool=rtrim($this->getSetting('spool_dir','/var/spool/asterisk/tffax'),'/'); $uuid=$this->uuid4();
        $orig=null; $basePdf=null;
        if($hasDocument){
            $orig=$spool.'/outgoing/'.$uuid.'-'.preg_replace('/[^A-Za-z0-9._-]/','_',basename($files['document']['name']));
            if(!@move_uploaded_file($files['document']['tmp_name'],$orig)) throw new \RuntimeException('Unable to store uploaded fax document.');
            $basePdf=$spool.'/tmp/'.$uuid.'-document.pdf'; $this->convertToPdf($orig,$basePdf);
        }
        $recipientName=trim((string)($p['recipient_name']??'')); $recipientCompany=trim((string)($p['recipient_company']??'')); $subject=trim((string)($p['subject']??'')); $notes=trim((string)($p['notes']??''));
        $preview=$spool.'/outgoing/'.$uuid.'-preview.pdf'; $coverPath=null;
        if($cover){
            $coverPath=$spool.'/tmp/'.$uuid.'-cover.pdf';
            $portalUser=$portalUid>0?$this->getPortalUser($portalUid):null;
            $brand=$this->getPlatformBranding();
            $senderName=trim((string)($portalUser['full_name']??'')); if($senderName==='')$senderName=trim((string)($p['from_name']??$identity['name']));
            $senderCompany=trim((string)($portalUser['company_name']??'')); if($senderCompany==='')$senderCompany=trim((string)($p['from_company']??($brand['company_name']??'')));
            $senderFax=trim((string)($portalUser['fax_number']??'')); if($senderFax==='')$senderFax=$identity['fax_number']?:$identity['station_id'];
            $addressParts=[]; foreach(['address1','address2'] as $ak){$av=trim((string)($portalUser[$ak]??''));if($av!=='')$addressParts[]=$av;}
            $cityLine=trim(trim((string)($portalUser['city']??'')) . (trim((string)($portalUser['state']??''))!=='' ? ', '.trim((string)$portalUser['state']) : '') . (trim((string)($portalUser['postal_code']??''))!=='' ? ' '.trim((string)$portalUser['postal_code']) : '')); if($cityLine!=='')$addressParts[]=$cityLine;
            $this->renderCoverPage($cover,$coverPath,[
                'from_name'=>$senderName, 'from_company'=>$senderCompany, 'from_number'=>$senderFax, 'from_fax'=>$senderFax,
                'from_phone'=>trim((string)($portalUser['phone_number']??'')), 'from_email'=>trim((string)($portalUser['email']??'')),
                'from_address'=>implode(', ',$addressParts), 'from_website'=>trim((string)($portalUser['website']??'')),
                'to_name'=>$recipientName, 'to_company'=>$recipientCompany, 'to_number'=>$number, 'subject'=>$subject, 'message'=>$notes, 'date'=>$this->localNow('m/d/Y g:i A T')
            ]);
        }
        if($coverPath && $basePdf){$this->mergePdfs([$coverPath,$basePdf],$preview);}
        elseif($coverPath){if(!@copy($coverPath,$preview))throw new \RuntimeException('Unable to create cover-page-only fax preview.');}
        elseif($basePdf){if(!@copy($basePdf,$preview)) throw new \RuntimeException('Unable to create fax preview PDF.');}
        else{throw new \RuntimeException('Unable to prepare fax content.');}
        $tif=$spool.'/queue/'.$uuid.'.tif'; $this->convertToTiff($preview,$tif);
        $user=$_SESSION['tffax_portal_user_name'] ?? ($_SESSION['AMP_user']->_username ?? 'admin');
        $portalSource=isset($portalUser)&&is_array($portalUser)?trim((string)($portalUser['fax_number']??'')):'';
        $source=$portalSource!==''?$portalSource:($identity['fax_number'] ?: ($identity['outbound_cid'] ?: ($identity['station_id'] ?: '')));
        $status=$draft?'draft':'queued'; $statusText=$draft?'Draft ready for preview':'Queued for transmission';
        $st=$this->db->prepare("INSERT INTO tffax_jobs (uuid,direction,identity_id,user_name,source_number,destination_number,recipient_name,recipient_company,subject,notes,coverpage_id,local_station_id,status,status_text,max_attempts,document_path,cover_path,tiff_path,pdf_path,created_at) VALUES (?,'outbound',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
        $st->execute([$uuid,(int)$identity['id'],$user,$source,$number,$recipientName,$recipientCompany,$subject,$notes,$coverId?:null,$identity['station_id']?:$identity['fax_number'],$status,$statusText,(int)$this->getSetting('max_attempts','3'),$orig,$coverPath,$tif,$preview]);
        $job=(int)$this->db->lastInsertId(); $this->event($job,$draft?'DRAFT_SAVED':'JOB_CREATED',$draft?'Outbound fax prepared for preview':'Outbound fax queued to '.$number);
        if(!$draft){$this->launchOutboundWorker($job);$_SESSION['tffax_notice']='Fax job #'.$job.' queued for '.$number.'.';}
        else{$_SESSION['tffax_notice']='Fax draft #'.$job.' is ready to preview before sending.';}
        return $job;
    }
    public function sendDraft($id){
        $id=(int)$id; $st=$this->db->prepare("SELECT * FROM tffax_jobs WHERE id=? AND direction='outbound' AND status='draft'");$st->execute([$id]);$j=$st->fetch(\PDO::FETCH_ASSOC);
        if(!$j) throw new \RuntimeException('Fax draft was not found.');
        if(trim((string)$j['destination_number'])==='') throw new \RuntimeException('This draft has no destination fax number. Edit/recreate it before sending.');
        if(empty($j['tiff_path'])||!is_file($j['tiff_path'])) throw new \RuntimeException('Draft fax document is missing.');
        $this->db->prepare("UPDATE tffax_jobs SET status='queued',status_text='Queued for transmission',completed_at=NULL WHERE id=?")->execute([$id]);
        $this->event($id,'DRAFT_QUEUED','Draft queued for transmission'); $this->launchOutboundWorker($id); $_SESSION['tffax_notice']='Fax draft #'.$id.' queued for transmission.';
    }
    public function servePortalOutboundPreview($userId,$p,$files){
        $job=$this->submitOutbound($p,$files,true);
        if(!$this->portalCanAccessJob((int)$userId,$job)){$this->deleteJob($job,false);throw new \RuntimeException('Unable to authorize the generated fax preview.');}
        register_shutdown_function(function() use($job){try{$this->deleteJob((int)$job,false);}catch(\Throwable $e){}});
        $this->servePortalJobFile((int)$userId,$job,false);
    }
}
