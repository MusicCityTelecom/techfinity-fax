<?php
namespace FreePBX\modules;
trait TffaxTrait2 {



    public function getPortalAccessibleCoverPage($userId,$id){
        $st=$this->db->prepare("SELECT * FROM tffax_coverpages WHERE id=? AND enabled=1 AND (owner_user_id IS NULL OR owner_user_id=?) LIMIT 1");
        $st->execute([(int)$id,(int)$userId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function portalCoverPreviewVars($userId){
        $u=$this->getPortalUser((int)$userId); if(!$u) throw new \RuntimeException('Fax account not found.');
        $identity=!empty($u['default_identity_id'])?$this->getIdentity((int)$u['default_identity_id']):null;
        $senderName=trim((string)($u['full_name']??'')); if($senderName==='')$senderName=(string)$u['user_name'];
        $senderCompany=trim((string)($u['company_name']??''));
        $senderFax=trim((string)($u['fax_number']??'')); if($senderFax===''&&$identity)$senderFax=$identity['fax_number']?:$identity['station_id'];
        $addressParts=[]; foreach(['address1','address2'] as $ak){$av=trim((string)($u[$ak]??''));if($av!=='')$addressParts[]=$av;}
        $city=trim((string)($u['city']??''));$state=trim((string)($u['state']??''));$zip=trim((string)($u['postal_code']??''));
        $cityLine=trim($city.($state!==''?($city!==''?', ':'').$state:'').($zip!==''?' '.$zip:''));if($cityLine!=='')$addressParts[]=$cityLine;
        return [
            'from_name'=>$senderName,'from_company'=>$senderCompany,'from_number'=>$senderFax,'from_fax'=>$senderFax,
            'from_phone'=>trim((string)($u['phone_number']??'')),'from_email'=>trim((string)($u['email']??'')),
            'from_address'=>implode(', ',$addressParts),'from_website'=>trim((string)($u['website']??'')),
            'to_name'=>'Sample Recipient','to_company'=>'Sample Company','to_number'=>'18884732963',
            'subject'=>'Fax Cover Page Preview','message'=>'This is a sample message so you can see exactly how this cover page will look when it is sent.','date'=>$this->localNow('m/d/Y g:i A T')
        ];
    }

    public function servePortalCoverPreview($userId,$coverId=0,$override=null){
        $userId=(int)$userId;if($userId<=0)throw new \InvalidArgumentException('Invalid fax user.');
        if(is_array($override)){
            $tpl=(string)($override['template_html']??'');if(trim($tpl)==='')throw new \InvalidArgumentException('Cover page template cannot be empty.');
            $cover=['name'=>trim((string)($override['name']??'Cover Page Preview')),'template_html'=>$tpl,'template_style'=>$this->normalizeCoverStyle($override['template_style']??'professional')];
        }else{
            $cover=$this->getPortalAccessibleCoverPage($userId,(int)$coverId);if(!$cover)throw new \RuntimeException('Cover page was not found or is not available to your account.');
        }
        $spool=rtrim($this->getSetting('spool_dir','/var/spool/asterisk/tffax'),'/');
        $dir=$spool.'/tmp';if(!is_dir($dir))@mkdir($dir,0770,true);
        $tmp=$dir.'/cover-preview-'.$this->uuid4().'.pdf';
        $this->renderCoverPage($cover,$tmp,$this->portalCoverPreviewVars($userId));
        $this->streamPdfPath($tmp,'fax-cover-preview.pdf',false,true);
    }

    public function copyGlobalCoverPageToUser($userId,$id){
        $userId=(int)$userId;$id=(int)$id;if($userId<=0||$id<=0)throw new \InvalidArgumentException('Invalid cover page request.');
        $st=$this->db->prepare("SELECT * FROM tffax_coverpages WHERE id=? AND owner_user_id IS NULL AND enabled=1");$st->execute([$id]);$c=$st->fetch(\PDO::FETCH_ASSOC);
        if(!$c)throw new \RuntimeException('Built-in cover page was not found.');
        $base='My '.$c['name'];$name=$base;$n=2;
        while(true){$chk=$this->db->prepare("SELECT id FROM tffax_coverpages WHERE owner_user_id=? AND name=? LIMIT 1");$chk->execute([$userId,$name]);if(!$chk->fetchColumn())break;$name=$base.' '.$n++;}
        $ins=$this->db->prepare("INSERT INTO tffax_coverpages (name,template_html,template_style,enabled,owner_user_id) VALUES (?,?,?,1,?)");
        $ins->execute([$name,$c['template_html'],$this->normalizeCoverStyle($c['template_style']??'professional'),$userId]);
        return (int)$this->db->lastInsertId();
    }

    public function deletePortalCoverPage($userId,$id){
        $userId=(int)$userId;$id=(int)$id;if($id<=0)return;
        if(!$this->getPortalCoverPage($userId,$id))throw new \RuntimeException('You can only delete your own cover pages.');
        $this->db->prepare("UPDATE tffax_jobs SET coverpage_id=NULL WHERE coverpage_id=?")->execute([$id]);
        $this->db->prepare("DELETE FROM tffax_coverpages WHERE id=? AND owner_user_id=?")->execute([$id,$userId]);
    }

    public function getDestinations(){
        $sql="SELECT d.*, i.name identity_name, i.fax_number,
                     GROUP_CONCAT(DISTINCT u.user_name ORDER BY u.user_name SEPARATOR ', ') assigned_users,
                     GROUP_CONCAT(DISTINCT du.user_id ORDER BY du.user_id SEPARATOR ',') assigned_user_ids
              FROM tffax_destinations d
              LEFT JOIN tffax_identities i ON i.id=d.identity_id
              LEFT JOIN tffax_destination_users du ON du.destination_id=d.id
              LEFT JOIN tffax_users u ON u.id=du.user_id
              GROUP BY d.id ORDER BY d.name";
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function getDestination($id){
        $st=$this->db->prepare("SELECT d.*,i.name identity_name,i.fax_number FROM tffax_destinations d LEFT JOIN tffax_identities i ON i.id=d.identity_id WHERE d.id=?");
        $st->execute([(int)$id]); return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    public function saveDestination($p){
        $name=trim((string)($p['name']??'')); if($name==='') throw new \InvalidArgumentException('Destination name is required.');
        $vals=[$name,($p['identity_id']??'')===''?null:(int)$p['identity_id'],trim((string)($p['email_to']??'')),in_array(($p['attach_format']??'pdf'),['pdf','tif','tiff'])?($p['attach_format']??'pdf'):'pdf',isset($p['keep_copy'])?1:0,isset($p['enabled'])?1:0];
        $id=(int)($p['id']??0);
        if($id){
            $st=$this->db->prepare("UPDATE tffax_destinations SET name=?,identity_id=?,email_to=?,attach_format=?,keep_copy=?,enabled=? WHERE id=?");$vals[]=$id;$st->execute($vals);
        }else{
            $st=$this->db->prepare("INSERT INTO tffax_destinations (name,identity_id,email_to,attach_format,keep_copy,enabled) VALUES (?,?,?,?,?,?)");$st->execute($vals);$id=(int)$this->db->lastInsertId();
        }
        $this->setDestinationUsers($id, isset($p['user_ids'])?(array)$p['user_ids']:[]);
        $_SESSION['tffax_notice']='Inbound fax mailbox saved. Apply Config so destinations and automatic routing are regenerated.';
    }
    public function setDestinationUsersPublic($destinationId,array $userIds){
        if(!$this->getDestination((int)$destinationId))throw new \InvalidArgumentException('Inbound fax mailbox not found.');
        $this->setDestinationUsers((int)$destinationId,$userIds);
        $_SESSION['tffax_notice']='Mailbox user assignments updated.';
    }
    private function setDestinationUsers($destinationId,array $userIds){
        $destinationId=(int)$destinationId;
        $this->db->prepare("DELETE FROM tffax_destination_users WHERE destination_id=?")->execute([$destinationId]);
        $st=$this->db->prepare("INSERT IGNORE INTO tffax_destination_users (destination_id,user_id) VALUES (?,?)");
        foreach($userIds as $uid){$uid=(int)$uid;if($uid>0)$st->execute([$destinationId,$uid]);}
    }
    public function deleteDestination($id){
        $id=(int)$id;if($id<=0)return;
        $this->db->prepare("DELETE FROM tffax_destination_users WHERE destination_id=?")->execute([$id]);
        $this->db->prepare("DELETE FROM tffax_routes WHERE destination_id=?")->execute([$id]);
        $this->db->prepare("UPDATE tffax_jobs SET destination_id=NULL WHERE destination_id=?")->execute([$id]);
        $this->db->prepare("DELETE FROM tffax_destinations WHERE id=?")->execute([$id]);
        $_SESSION['tffax_notice']='Inbound fax mailbox deleted. Rules pointing to it were also removed.';
    }

    public function getUsers(){return $this->db->query("SELECT u.*,i.name default_identity_name FROM tffax_users u LEFT JOIN tffax_identities i ON i.id=u.default_identity_id ORDER BY u.user_name")->fetchAll(\PDO::FETCH_ASSOC);}
    public function saveUser($p){
        $name=trim((string)($p['user_name']??''));if($name==='')throw new \InvalidArgumentException('User name is required.');
        if(!preg_match('/^[A-Za-z0-9_.@-]+$/',$name))throw new \InvalidArgumentException('User name contains unsupported characters.');
        $vals=[$name,isset($p['can_send'])?1:0,isset($p['can_receive'])?1:0,isset($p['can_view_all'])?1:0,isset($p['can_delete'])?1:0,($p['default_identity_id']??'')===''?null:(int)$p['default_identity_id'],trim((string)($p['email']??''))];
        $id=(int)($p['id']??0);
        if($id){$st=$this->db->prepare("UPDATE tffax_users SET user_name=?,can_send=?,can_receive=?,can_view_all=?,can_delete=?,default_identity_id=?,email=? WHERE id=?");$vals[]=$id;$st->execute($vals);}else{$st=$this->db->prepare("INSERT INTO tffax_users (user_name,can_send,can_receive,can_view_all,can_delete,default_identity_id,email) VALUES (?,?,?,?,?,?,?)");$st->execute($vals);}
        $_SESSION['tffax_notice']='Fax user saved. This table is Fax Platform-owned and does not modify system-managed user accounts.';
    }
    public function deleteUser($id){$id=(int)$id;if($id<=0)return;$this->db->prepare("UPDATE tffax_jobs SET coverpage_id=NULL WHERE coverpage_id IN (SELECT id FROM tffax_coverpages WHERE owner_user_id=?)")->execute([$id]);$this->db->prepare("DELETE FROM tffax_coverpages WHERE owner_user_id=?")->execute([$id]);$this->db->prepare("DELETE FROM tffax_destination_users WHERE user_id=?")->execute([$id]);$this->db->prepare("DELETE FROM tffax_users WHERE id=?")->execute([$id]);$_SESSION['tffax_notice']='Fax user removed from Fax Platform.';}



    /**
     * Simplified account model. One Fax Account can create/manage the common
     * user + mailbox + DID route combination in a single transaction while the
     * legacy Users/Mailboxes/Routing tables remain available for advanced use.
     */
    public function getAccounts(){
        $sql="SELECT u.*, i.name default_identity_name, d.name mailbox_name, d.email_to mailbox_email,
                     GROUP_CONCAT(DISTINCT CASE WHEN r.enabled=1 THEN NULLIF(r.did_pattern,'') END ORDER BY r.priority,r.id SEPARATOR ', ') inbound_dids
              FROM tffax_users u
              LEFT JOIN tffax_identities i ON i.id=u.default_identity_id
              LEFT JOIN tffax_destinations d ON d.id=u.primary_destination_id
              LEFT JOIN tffax_routes r ON r.destination_id=d.id
              GROUP BY u.id ORDER BY u.user_name";
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getAccount($id){
        $st=$this->db->prepare("SELECT u.*, i.name default_identity_name, d.name mailbox_name, d.email_to mailbox_email,
                     GROUP_CONCAT(DISTINCT CASE WHEN r.enabled=1 THEN NULLIF(r.did_pattern,'') END ORDER BY r.priority,r.id SEPARATOR '\n') inbound_dids
              FROM tffax_users u
              LEFT JOIN tffax_identities i ON i.id=u.default_identity_id
              LEFT JOIN tffax_destinations d ON d.id=u.primary_destination_id
              LEFT JOIN tffax_routes r ON r.destination_id=d.id AND r.managed_by_account=1
              WHERE u.id=? GROUP BY u.id");
        $st->execute([(int)$id]);return $st->fetch(\PDO::FETCH_ASSOC)?:null;
    }

    private function normalizeDidList($raw){
        $items=preg_split('/[\r\n,;]+/',(string)$raw);$out=[];
        foreach((array)$items as $v){$v=trim($v);if($v==='')continue;$v=$this->validateRoutePattern($v,'Inbound DID');if($v===''||$v==='*')throw new \InvalidArgumentException('Fax Account inbound DID cannot be blank or match every DID.');$out[$v]=true;}
        return array_keys($out);
    }

    public function saveAccount($p){
        $id=(int)($p['id']??0);
        $name=trim((string)($p['user_name']??''));
        $email=trim((string)($p['email']??''));
        if($name==='' || !preg_match('/^[A-Za-z0-9_.@-]+$/',$name))throw new \InvalidArgumentException('Enter a valid fax account login name.');
        if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Enter a valid email address.');
        $identity=(($p['default_identity_id']??'')==='')?null:(int)$p['default_identity_id'];
        if($identity!==null && !$this->getIdentity($identity))throw new \InvalidArgumentException('Select a valid default fax identity.');
        $dids=$this->normalizeDidList($p['inbound_dids']??'');
        $password=(string)($p['portal_password']??'');
        if($id<=0 && strlen($password)<8)throw new \InvalidArgumentException('New fax portal accounts require a password of at least 8 characters.');
        if($password!=='' && strlen($password)<8)throw new \InvalidArgumentException('Portal password must be at least 8 characters.');
        $canSend=isset($p['can_send'])?1:0;$canReceive=isset($p['can_receive'])?1:0;$canDelete=isset($p['can_delete'])?1:0;$canViewAll=isset($p['can_view_all'])?1:0;
        $this->db->beginTransaction();
        try{
            if($id>0){
                $st=$this->db->prepare("SELECT * FROM tffax_users WHERE id=? FOR UPDATE");$st->execute([$id]);$old=$st->fetch(\PDO::FETCH_ASSOC);if(!$old)throw new \RuntimeException('Fax account not found.');
                $destId=(int)$old['primary_destination_id'];
                $hash=$password!==''?password_hash($password,PASSWORD_DEFAULT):(string)$old['password_hash'];
                $st=$this->db->prepare("UPDATE tffax_users SET user_name=?,email=?,can_send=?,can_receive=?,can_view_all=?,can_delete=?,default_identity_id=?,password_hash=?,portal_enabled=? WHERE id=?");
                $st->execute([$name,$email,$canSend,$canReceive,$canViewAll,$canDelete,$identity,$hash,isset($p['portal_enabled'])?1:0,$id]);
            }else{
                $hash=password_hash($password,PASSWORD_DEFAULT);
                $st=$this->db->prepare("INSERT INTO tffax_users (user_name,email,can_send,can_receive,can_view_all,can_delete,default_identity_id,password_hash,portal_enabled) VALUES (?,?,?,?,?,?,?,?,?)");
                $st->execute([$name,$email,$canSend,$canReceive,$canViewAll,$canDelete,$identity,$hash,isset($p['portal_enabled'])?1:0]);
                $id=(int)$this->db->lastInsertId();$destId=0;
            }
            if($canReceive){
                if($destId<=0){$st=$this->db->prepare("INSERT INTO tffax_destinations (name,identity_id,email_to,attach_format,keep_copy,enabled) VALUES (?,?,'','pdf',1,1)");$st->execute([$name.' Fax',$identity]);$destId=(int)$this->db->lastInsertId();}
                else{$st=$this->db->prepare("UPDATE tffax_destinations SET name=?,identity_id=?,enabled=1 WHERE id=?");$st->execute([$name.' Fax',$identity,$destId]);}
                $this->db->prepare("UPDATE tffax_users SET primary_destination_id=? WHERE id=?")->execute([$destId,$id]);
                $this->setDestinationUsers($destId,[$id]);
                $this->db->prepare("DELETE FROM tffax_routes WHERE destination_id=? AND managed_by_account=1")->execute([$destId]);
                $pri=100;
                $st=$this->db->prepare("INSERT INTO tffax_routes (priority,did_pattern,cid_pattern,destination_id,description,enabled,managed_by_account) VALUES (?,?,'',?, ?,1,1)");
                foreach($dids as $did){$st->execute([$pri++,$did,$destId,'Fax Account: '.$name]);}
            }elseif($destId>0){
                $this->db->prepare("UPDATE tffax_destinations SET enabled=0 WHERE id=?")->execute([$destId]);
                $this->db->prepare("DELETE FROM tffax_routes WHERE destination_id=? AND managed_by_account=1")->execute([$destId]);
            }
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $_SESSION['tffax_notice']='Fax Account saved. User, mailbox, permissions and DID routing were updated together. Apply Config for routing changes.';
    }

    public function deleteAccount($id){
        $id=(int)$id;if($id<=0)return;
        $st=$this->db->prepare("SELECT primary_destination_id FROM tffax_users WHERE id=?");$st->execute([$id]);$dest=(int)$st->fetchColumn();
        $this->db->beginTransaction();
        try{
            if($dest>0){$this->db->prepare("DELETE FROM tffax_routes WHERE destination_id=? AND managed_by_account=1")->execute([$dest]);$this->db->prepare("DELETE FROM tffax_destination_users WHERE destination_id=?")->execute([$dest]);$this->db->prepare("UPDATE tffax_jobs SET destination_id=NULL WHERE destination_id=?")->execute([$dest]);$this->db->prepare("DELETE FROM tffax_destinations WHERE id=?")->execute([$dest]);}
            $this->db->prepare("UPDATE tffax_jobs SET coverpage_id=NULL WHERE coverpage_id IN (SELECT id FROM tffax_coverpages WHERE owner_user_id=?)")->execute([$id]);$this->db->prepare("DELETE FROM tffax_coverpages WHERE owner_user_id=?")->execute([$id]);$this->db->prepare("DELETE FROM tffax_destination_users WHERE user_id=?")->execute([$id]);$this->db->prepare("DELETE FROM tffax_users WHERE id=?")->execute([$id]);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $_SESSION['tffax_notice']='Fax Account deleted. Historical fax records were retained; its managed mailbox/rules were removed.';
    }

    public function portalAuthenticate($username,$password){
        $st=$this->db->prepare("SELECT * FROM tffax_users WHERE user_name=? AND portal_enabled=1 LIMIT 1");$st->execute([trim((string)$username)]);$u=$st->fetch(\PDO::FETCH_ASSOC);
        if(!$u || empty($u['password_hash']) || !password_verify((string)$password,$u['password_hash']))return false;
        return $u;
    }
}
