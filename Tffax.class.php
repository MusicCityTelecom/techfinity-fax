<?php
namespace FreePBX\modules;
use BMO;
use FreePBX_Helpers;

class Tffax extends FreePBX_Helpers implements BMO {
    private $hostApp;
    private $db;
    private $astman;

    public function __construct($hostApp = null) {
        if ($hostApp === null) { throw new \RuntimeException('Host application object was not provided'); }
        $this->hostApp = $hostApp;
        $this->db = $hostApp->Database;
        $this->astman = $hostApp->astman;
    }

    /**
     * Required by the host BMO interface.
     *
     * Database/schema and spool initialization are intentionally handled by
     * install.php so the host framework's normal module installer can perform those
     * operations before/while the module is enabled.  These methods still
     * must exist on every concrete BMO class, including the current host release.
     */
    public function install() {
        return true;
    }

    /**
     * Required by the host BMO interface.  uninstall.php deliberately
     * preserves fax records/documents, so there is no destructive work here.
     */
    public function uninstall() {
        return true;
    }

    // Process module forms from page.tffax.php instead of relying on the
    // Host config-page init hook.  This is important for multipart/form-data
    // uploads and also avoids colliding with the host's own generic `action`
    // request parameter.
    public static function myConfigPageInits() { return []; }

    public function doConfigPageInit($page) { return; }

    public function processPost(array $post, array $files = []) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return false; }
        $action = isset($post['tffax_action']) ? (string)$post['tffax_action'] : '';
        if ($action === '') { return false; }
        try {
            if ($action === 'save_settings') { $this->saveSettings($post); }
            elseif ($action === 'save_identity') { $this->saveIdentity($post); }
            elseif ($action === 'delete_identity') { $this->deleteIdentity((int)($post['id'] ?? 0)); }
            elseif ($action === 'save_destination') { $this->saveDestination($post); }
            elseif ($action === 'delete_destination') { $this->deleteDestination((int)($post['id'] ?? 0)); }
            elseif ($action === 'set_destination_users') { $this->setDestinationUsersPublic((int)($post['id'] ?? 0), isset($post['user_ids'])?(array)$post['user_ids']:[]); }
            elseif ($action === 'save_user') { $this->saveUser($post); }
            elseif ($action === 'save_account') { $this->saveAccount($post); }
            elseif ($action === 'delete_account') { $this->deleteAccount((int)($post['id'] ?? 0)); }
            elseif ($action === 'delete_user') { $this->deleteUser((int)($post['id'] ?? 0)); }
            elseif ($action === 'save_route') { $this->saveRoute($post); }
            elseif ($action === 'save_coverpage') { $this->saveCoverPage($post); }
            elseif ($action === 'delete_coverpage') { $this->deleteCoverPage((int)($post['id'] ?? 0)); }
            elseif ($action === 'delete_route') { $this->deleteRoute((int)($post['id'] ?? 0)); }
            elseif ($action === 'assign_job_destination') { $this->assignJobDestination((int)($post['id'] ?? 0), (int)($post['destination_id'] ?? 0)); }
            elseif ($action === 'send_fax') { $this->submitOutbound($post, $files, false); }
            elseif ($action === 'save_draft') { $this->submitOutbound($post, $files, true); }
            elseif ($action === 'send_draft') { $this->sendDraft((int)($post['id'] ?? 0)); }
            elseif ($action === 'delete_job') { $this->deleteJob((int)($post['id'] ?? 0)); }
            elseif ($action === 'clear_failed_jobs') { $this->clearFailedJobs(); }
            else { throw new \InvalidArgumentException('Unknown Fax Platform action.'); }
            if (function_exists('needreload') && !in_array($action, ['send_fax','save_draft','send_draft','delete_job','clear_failed_jobs'], true)) { needreload(); }
            return true;
        } catch (\Throwable $e) {
            $_SESSION['tffax_error'] = $e->getMessage();
            return false;
        }
    }

    public function getActionBar($request) { return []; }
    public function ajaxRequest($req, &$setting) { return false; }
    public function ajaxHandler() { return false; }

    public function render($view, $request = []) {
        $base = __DIR__.'/views/';
        $data = ['module'=>$this, 'view'=>$view, 'request'=>$request];
        switch ($view) {
            case 'dashboard': $data['stats']=$this->getStats(); $data['jobs']=$this->getJobs(10); $data['destinations']=$this->getDestinations(); break;
            case 'history': $data['jobs']=$this->getJobs(200); $data['destinations']=$this->getDestinations(); break;
            case 'identities': $data['identities']=$this->getIdentities(); break;
            case 'destinations': $data['destinations']=$this->getDestinations(); $data['identities']=$this->getIdentities(); $data['users']=$this->getUsers(); break;
            case 'users': $data['users']=$this->getUsers(); $data['identities']=$this->getIdentities(); break;
            case 'accounts': $data['accounts']=$this->getAccounts(); $data['identities']=$this->getIdentities(); $data['editAccount']=!empty($request['edit'])?$this->getAccount((int)$request['edit']):null; break;
            case 'routing': $data['routes']=$this->getRoutes(); $data['destinations']=$this->getDestinations(); break;
            case 'send': $data['identities']=$this->getIdentities(true); $data['coverpages']=$this->getCoverPages(true); break;
            case 'coverpages': $data['coverpages']=$this->getCoverPages(); break;
            case 'settings': $data['settings']=$this->getSettings(); break;
            case 'diagnostics': $data['diagnostics']=$this->diagnostics(); break;
        }
        extract($data);
        ob_start(); include $base.'layout.php'; return ob_get_clean();
    }

    /** Generic standalone branding used by the administration and user portal. */
    public function getPlatformBranding() {
        return [
            'product_name' => 'Fax Platform',
            'edition' => '',
            'tagline' => 'Multi-user fax management',
            'company_name' => '',
            'accent' => '#337ab7',
            'logo_url' => '',
            'compact_logo_url' => '',
            'icon_logo_url' => ''
        ];
    }

    /** No external branding assets are exposed by the generic build. */
    public function getPlatformBrandAssetPath($role = 'logo') { return null; }

    public function getSetting($key, $default = null) {
        $st=$this->db->prepare("SELECT value FROM tffax_settings WHERE `key`=?"); $st->execute([$key]);
        $v=$st->fetchColumn(); return $v===false ? $default : $v;
    }
    public function getSettings() {
        $rows=$this->db->query("SELECT `key`,`value` FROM tffax_settings")->fetchAll(\PDO::FETCH_KEY_PAIR); return $rows ?: [];
    }

    public function getDisplayTimezone() {
        $tz = trim((string)$this->getSetting('timezone','America/Chicago'));
        if ($tz === '') { $tz = 'America/Chicago'; }
        try { new \DateTimeZone($tz); return $tz; } catch (\Throwable $e) { return 'America/Chicago'; }
    }

    /** Format a database timestamp stored in UTC for user-facing display. */
    public function formatTimestamp($value, $format='m/d/Y g:i A T') {
        $value = trim((string)$value);
        if ($value === '') { return ''; }
        try {
            $dt = new \DateTime($value, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone($this->getDisplayTimezone()));
            return $dt->format($format);
        } catch (\Throwable $e) { return $value; }
    }

    /** Current local time for cover pages and other user-visible content. */
    public function localNow($format='m/d/Y g:i A T') {
        try {
            $dt = new \DateTime('now', new \DateTimeZone($this->getDisplayTimezone()));
            return $dt->format($format);
        } catch (\Throwable $e) { return gmdate($format); }
    }
    private function setSettingValue($key,$value) {
        $st=$this->db->prepare("INSERT INTO tffax_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)"); $st->execute([$key,$value]);
    }
    public function saveSettings($p) {
        $ecm = strtolower(trim((string)($p['ecm'] ?? $this->getSetting('ecm','yes'))));
        if (!in_array($ecm, ['yes','no'], true)) { throw new \InvalidArgumentException('ECM must be Enabled or Disabled.'); }

        $allowedRates = [2400,4800,7200,9600,12000,14400];
        $minRate = (int)($p['min_rate'] ?? $this->getSetting('min_rate','4800'));
        $maxRate = (int)($p['max_rate'] ?? $this->getSetting('max_rate','14400'));
        if (!in_array($minRate, $allowedRates, true) || !in_array($maxRate, $allowedRates, true)) {
            throw new \InvalidArgumentException('Fax rates must be one of: '.implode(', ', $allowedRates).' bps.');
        }
        if ($minRate > $maxRate) { throw new \InvalidArgumentException('Minimum fax rate cannot be greater than maximum fax rate.'); }

        $t38 = strtolower(trim((string)($p['t38_mode'] ?? $this->getSetting('t38_mode','auto'))));
        if (!in_array($t38, ['auto','prefer','audio','t38'], true)) { throw new \InvalidArgumentException('Invalid T.38 policy.'); }

        $attempts = max(1, min(10, (int)($p['max_attempts'] ?? $this->getSetting('max_attempts','3'))));
        $retain = max(0, min(3650, (int)($p['retain_days'] ?? $this->getSetting('retain_days','365'))));
        $context = trim((string)($p['outbound_context'] ?? $this->getSetting('outbound_context','from-internal')));
        if ($context === '') { $context = 'from-internal'; }
        if (!preg_match('/^[A-Za-z0-9_.@-]+$/', $context)) { throw new \InvalidArgumentException('Invalid outbound dial context.'); }

        $spool = rtrim(trim((string)($p['spool_dir'] ?? $this->getSetting('spool_dir','/var/spool/asterisk/tffax'))), '/');
        if ($spool === '' || $spool[0] !== '/') { throw new \InvalidArgumentException('Spool directory must be an absolute path.'); }

        $emailFrom=trim((string)($p['email_from']??$this->getSetting('email_from','')));
        if($emailFrom!==''&&!filter_var($emailFrom,FILTER_VALIDATE_EMAIL)){throw new \InvalidArgumentException('Email From Address must be a valid email address.');}
        $timezone=trim((string)($p['timezone']??$this->getSetting('timezone','America/Chicago'))); if($timezone==='')$timezone='America/Chicago'; try{new \DateTimeZone($timezone);}catch(\Throwable $e){throw new \InvalidArgumentException('Display Timezone must be a valid IANA timezone such as America/Chicago.');}
        $uiTheme=strtolower(trim((string)($p['ui_theme']??$this->getSetting('ui_theme','refined'))));if(!in_array($uiTheme,['refined','classic'],true)){$uiTheme='refined';}
        $settings = [
            'spool_dir' => $spool,
            'local_station_id' => trim((string)($p['local_station_id'] ?? '')),
            'header_text' => trim((string)($p['header_text'] ?? '')),
            'ecm' => $ecm,
            'min_rate' => (string)$minRate,
            'max_rate' => (string)$maxRate,
            'max_attempts' => (string)$attempts,
            'retain_days' => (string)$retain,
            'outbound_context' => $context,
            't38_mode' => $t38,
            'email_from' => $emailFrom,
            'timezone' => $timezone,
            'ui_theme' => $uiTheme,
            'email_tpl_inbound_subject' => (string)($p['email_tpl_inbound_subject'] ?? $this->getSetting('email_tpl_inbound_subject','Inbound fax from {{sender}}')),
            'email_tpl_inbound_body' => (string)($p['email_tpl_inbound_body'] ?? $this->getSetting('email_tpl_inbound_body','')),
            'email_tpl_success_subject' => (string)($p['email_tpl_success_subject'] ?? $this->getSetting('email_tpl_success_subject','Fax sent successfully to {{to_number}}')),
            'email_tpl_success_body' => (string)($p['email_tpl_success_body'] ?? $this->getSetting('email_tpl_success_body','')),
            'email_tpl_failure_subject' => (string)($p['email_tpl_failure_subject'] ?? $this->getSetting('email_tpl_failure_subject','Fax failed to {{to_number}}')),
            'email_tpl_failure_body' => (string)($p['email_tpl_failure_body'] ?? $this->getSetting('email_tpl_failure_body','')),
        ];
        foreach ($settings as $k=>$v) { $this->setSettingValue($k,$v); }
        foreach (['incoming','outgoing','queue','done','failed','tmp'] as $d) {
            $path=$spool.'/'.$d; if(!is_dir($path) && !@mkdir($path,0770,true)) { throw new \RuntimeException('Unable to create fax spool directory: '.$path); }
            @chown($path,'asterisk'); @chgrp($path,'asterisk'); @chmod($path,0770);
        }
        $_SESSION['tffax_notice']='Settings saved.';
    }

    public function getStats() {
        $q = "SELECT direction, status, COUNT(*) c FROM tffax_jobs GROUP BY direction,status";
        $rows=$this->db->query($q)->fetchAll(\PDO::FETCH_ASSOC);
        $s=['inbound'=>0,'outbound'=>0,'failed'=>0,'active'=>0];
        foreach($rows as $r){
            $s[$r['direction']] += (int)$r['c'];
            if($r['status']==='failed')$s['failed']+=(int)$r['c'];
            if(in_array($r['status'],['queued','dialing','sending'],true))$s['active']+=(int)$r['c'];
        }
        return $s;
    }
    public function getJobs($limit=100) {
        $st=$this->db->prepare("SELECT j.*, i.name identity_name, d.name destination_name FROM tffax_jobs j LEFT JOIN tffax_identities i ON i.id=j.identity_id LEFT JOIN tffax_destinations d ON d.id=j.destination_id ORDER BY j.id DESC LIMIT ".(int)$limit); $st->execute(); return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function getJob($id){$st=$this->db->prepare("SELECT j.*,i.name identity_name,i.fax_number identity_fax,i.station_id identity_station,i.header_text,d.name destination_name FROM tffax_jobs j LEFT JOIN tffax_identities i ON i.id=j.identity_id LEFT JOIN tffax_destinations d ON d.id=j.destination_id WHERE j.id=?");$st->execute([(int)$id]);return $st->fetch(\PDO::FETCH_ASSOC)?:null;}

    public function getIdentities($enabledOnly=false){$sql="SELECT * FROM tffax_identities".($enabledOnly?" WHERE enabled=1":"")." ORDER BY name";return $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);}
    public function getIdentity($id){$st=$this->db->prepare("SELECT * FROM tffax_identities WHERE id=?");$st->execute([(int)$id]);return $st->fetch(\PDO::FETCH_ASSOC)?:null;}
    public function getIdentityStationId($id){if(!$id)return $this->getSetting('local_station_id','');$i=$this->getIdentity($id);return $i?($i['station_id']?:$i['fax_number']):$this->getSetting('local_station_id','');}
    public function saveIdentity($p){
        $id=(int)($p['id']??0);$name=trim((string)($p['name']??''));if($name==='')throw new \InvalidArgumentException('Identity name is required.');
        $vals=[$name,trim((string)($p['fax_number']??'')),trim((string)($p['station_id']??'')),trim((string)($p['header_text']??'')),trim((string)($p['email']??'')),trim((string)($p['outbound_cid']??'')),isset($p['enabled'])?1:0];
        if($id){$st=$this->db->prepare("UPDATE tffax_identities SET name=?,fax_number=?,station_id=?,header_text=?,email=?,outbound_cid=?,enabled=? WHERE id=?");$vals[]=$id;}else{$st=$this->db->prepare("INSERT INTO tffax_identities (name,fax_number,station_id,header_text,email,outbound_cid,enabled) VALUES (?,?,?,?,?,?,?)");}
        $st->execute($vals);$_SESSION['tffax_notice']='Fax identity saved.';
    }
    public function deleteIdentity($id){$this->db->prepare("UPDATE tffax_jobs SET identity_id=NULL WHERE identity_id=?")->execute([$id]);$this->db->prepare("UPDATE tffax_destinations SET identity_id=NULL WHERE identity_id=?")->execute([$id]);$this->db->prepare("UPDATE tffax_users SET default_identity_id=NULL WHERE default_identity_id=?")->execute([$id]);$this->db->prepare("DELETE FROM tffax_identities WHERE id=?")->execute([$id]);$_SESSION['tffax_notice']='Fax identity deleted.';}

    public function getDestinations(){
        $sql="SELECT d.*,i.name identity_name,GROUP_CONCAT(u.user_name ORDER BY u.user_name SEPARATOR ', ') assigned_users FROM tffax_destinations d LEFT JOIN tffax_identities i ON i.id=d.identity_id LEFT JOIN tffax_destination_users du ON du.destination_id=d.id LEFT JOIN tffax_users u ON u.id=du.user_id GROUP BY d.id ORDER BY d.name";return $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function getDestination($id){$st=$this->db->prepare("SELECT * FROM tffax_destinations WHERE id=?");$st->execute([(int)$id]);return $st->fetch(\PDO::FETCH_ASSOC)?:null;}
    public function saveDestination($p){
        $id=(int)($p['id']??0);$name=trim((string)($p['name']??''));if($name==='')throw new \InvalidArgumentException('Destination name is required.');
        $identity=(($p['identity_id']??'')==='')?null:(int)$p['identity_id'];$vals=[$name,$identity,trim((string)($p['email_to']??'')),in_array(($p['attach_format']??'pdf'),['pdf','tif'],true)?$p['attach_format']:'pdf',isset($p['keep_copy'])?1:0,isset($p['enabled'])?1:0];
        if($id){$st=$this->db->prepare("UPDATE tffax_destinations SET name=?,identity_id=?,email_to=?,attach_format=?,keep_copy=?,enabled=? WHERE id=?");$vals[]=$id;}else{$st=$this->db->prepare("INSERT INTO tffax_destinations (name,identity_id,email_to,attach_format,keep_copy,enabled) VALUES (?,?,?,?,?,?)");}
        $st->execute($vals);if(!$id)$id=(int)$this->db->lastInsertId();$this->setDestinationUsers($id,isset($p['user_ids'])?(array)$p['user_ids']:[]);$_SESSION['tffax_notice']='Inbound destination saved.';
    }
    public function deleteDestination($id){$this->db->prepare("UPDATE tffax_jobs SET destination_id=NULL WHERE destination_id=?")->execute([$id]);$this->db->prepare("DELETE FROM tffax_destination_users WHERE destination_id=?")->execute([$id]);$this->db->prepare("DELETE FROM tffax_routes WHERE destination_id=?")->execute([$id]);$this->db->prepare("DELETE FROM tffax_destinations WHERE id=?")->execute([$id]);$_SESSION['tffax_notice']='Inbound destination deleted.';}

    public function getUsers(){return $this->db->query("SELECT u.*,i.name default_identity_name FROM tffax_users u LEFT JOIN tffax_identities i ON i.id=u.default_identity_id ORDER BY u.user_name")->fetchAll(\PDO::FETCH_ASSOC);}
    public function getUser($id){$st=$this->db->prepare("SELECT * FROM tffax_users WHERE id=?");$st->execute([(int)$id]);return $st->fetch(\PDO::FETCH_ASSOC)?:null;}
    public function saveUser($p){
        $id=(int)($p['id']??0);$name=trim((string)($p['user_name']??''));if($name==='')throw new \InvalidArgumentException('User name is required.');
        $email=trim((string)($p['email']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Enter a valid email address.');
        $identity=(($p['default_identity_id']??'')==='')?null:(int)$p['default_identity_id'];$vals=[$name,$email,isset($p['can_send'])?1:0,isset($p['can_receive'])?1:0,isset($p['can_view_all'])?1:0,isset($p['can_delete'])?1:0,$identity];
        if($id){$st=$this->db->prepare("UPDATE tffax_users SET user_name=?,email=?,can_send=?,can_receive=?,can_view_all=?,can_delete=?,default_identity_id=? WHERE id=?");$vals[]=$id;}else{$st=$this->db->prepare("INSERT INTO tffax_users (user_name,email,can_send,can_receive,can_view_all,can_delete,default_identity_id) VALUES (?,?,?,?,?,?,?)");}
        $st->execute($vals);$_SESSION['tffax_notice']='Fax user saved. This table is Fax Platform-owned and does not modify system-managed user accounts.';
    }
    public function deleteUser($id){$id=(int)$id;if($id<=0)return;$this->db->prepare("UPDATE tffax_jobs SET coverpage_id=NULL WHERE coverpage_id IN (SELECT id FROM tffax_coverpages WHERE owner_user_id=?)")->execute([$id]);$this->db->prepare("DELETE FROM tffax_coverpages WHERE owner_user_id=?")->execute([$id]);$this->db->prepare("DELETE FROM tffax_destination_users WHERE user_id=?")->execute([$id]);$this->db->prepare("DELETE FROM tffax_users WHERE id=?")->execute([$id]);$_SESSION['tffax_notice']='Fax user removed from Fax Platform.';}

    private function setDestinationUsers($destinationId,$ids){$destinationId=(int)$destinationId;$this->db->prepare("DELETE FROM tffax_destination_users WHERE destination_id=?")->execute([$destinationId]);$st=$this->db->prepare("INSERT INTO tffax_destination_users (destination_id,user_id) VALUES (?,?)");foreach(array_unique(array_map('intval',$ids)) as $uid){if($uid>0)$st->execute([$destinationId,$uid]);}}
    public function setDestinationUsersPublic($id,$ids){$this->setDestinationUsers($id,$ids);$_SESSION['tffax_notice']='Mailbox user assignments updated.';}

    public function getAccounts(){
        return $this->db->query("SELECT u.*, i.name default_identity_name, d.name mailbox_name, d.email_to mailbox_email,
                     GROUP_CONCAT(DISTINCT CASE WHEN r.enabled=1 THEN NULLIF(r.did_pattern,'') END ORDER BY r.priority,r.id SEPARATOR '\n') inbound_dids
              FROM tffax_users u
              LEFT JOIN tffax_identities i ON i.id=u.default_identity_id
              LEFT JOIN tffax_destinations d ON d.id=u.primary_destination_id
              LEFT JOIN tffax_routes r ON r.destination_id=d.id AND r.managed_by_account=1
              GROUP BY u.id ORDER BY u.user_name")->fetchAll(\PDO::FETCH_ASSOC);
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
    private function validateRoutePattern($raw,$label){
        $p=trim((string)$raw);if($p==='')return '';
        $p=preg_replace('/[()\s.-]+/','',$p);
        if($p==='')return '';
        if(!preg_match('/^[0-9+*?]+$/',$p))throw new \InvalidArgumentException($label.' may contain only digits, +, * and ?.');
        if(substr_count($p,'+')>1 || (strpos($p,'+')!==false && strpos($p,'+')!==0))throw new \InvalidArgumentException($label.' may use + only as a leading country-code marker.');
        if(strlen($p)>64)throw new \InvalidArgumentException($label.' is too long.');
        return $p;
    }
    private function patternRegex($pattern){
        $pattern=trim((string)$pattern);if($pattern==='')return '.*';$out='';$len=strlen($pattern);
        for($i=0;$i<$len;$i++){$c=$pattern[$i];if($c==='*')$out.='.*';elseif($c==='?')$out.='.';else$out.=preg_quote($c,'/');}
        return '^'.$out.'$';
    }
    /** Asterisk expression matching normalized DID candidates (original, digits-only, 10/11 digit NANP) and CID. */
    public function routeExpression($did,$cid){
        $parts=[];
        if(trim((string)$did)!==''){
            $r=$this->patternRegex($did);$parts[]='(("${REGEX("'.$this->escapeDialplan($r).'" ${TFFAX_DID})}"="1") | ("${REGEX("'.$this->escapeDialplan($r).'" ${FILTER(0-9,${TFFAX_DID})})}"="1") | ("${REGEX("'.$this->escapeDialplan($r).'" ${TFFAX_DID10})}"="1") | ("${REGEX("'.$this->escapeDialplan($r).'" ${TFFAX_DID11})}"="1"))';
        }
        if(trim((string)$cid)!==''){$r=$this->patternRegex($cid);$parts[]='("${REGEX("'.$this->escapeDialplan($r).'" ${TFFAX_CID})}"="1")';}
        return implode(' & ',$parts) ?: '1';
    }
    public function saveRoute($p){
        $id=(int)($p['id']??0);$did=$this->validateRoutePattern($p['did_pattern']??'','DID pattern');$cid=$this->validateRoutePattern($p['cid_pattern']??'','Caller ID pattern');
        $dest=(int)($p['destination_id']??0);if(!$this->getDestination($dest))throw new \InvalidArgumentException('Select a valid inbound destination.');
        $priority=max(1,min(999999,(int)($p['priority']??100)));$desc=trim((string)($p['description']??''));$enabled=isset($p['enabled'])?1:0;
        if($did===''&&$cid==='')throw new \InvalidArgumentException('At least one DID or Caller ID pattern is required.');
        if($id){$st=$this->db->prepare("UPDATE tffax_routes SET priority=?,did_pattern=?,cid_pattern=?,destination_id=?,description=?,enabled=? WHERE id=?");$st->execute([$priority,$did,$cid,$dest,$desc,$enabled,$id]);}else{$st=$this->db->prepare("INSERT INTO tffax_routes (priority,did_pattern,cid_pattern,destination_id,description,enabled) VALUES (?,?,?,?,?,?)");$st->execute([$priority,$did,$cid,$dest,$desc,$enabled]);}
        $_SESSION['tffax_notice']='Fax route saved.';
    }
    public function deleteRoute($id){$this->db->prepare("DELETE FROM tffax_routes WHERE id=?")->execute([(int)$id]);$_SESSION['tffax_notice']='Fax route deleted.';}

    public function getCoverPages($enabledOnly=false,$ownerUserId=null,$includeGlobals=true){
        $where=[];$args=[];if($enabledOnly)$where[]='enabled=1';if($ownerUserId!==null){if($includeGlobals){$where[]='(owner_user_id IS NULL OR owner_user_id=?)';}else{$where[]='owner_user_id=?';}$args[]=(int)$ownerUserId;}elseif(!$includeGlobals){$where[]='owner_user_id IS NOT NULL';}
        $sql='SELECT * FROM tffax_coverpages'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY CASE WHEN owner_user_id IS NULL THEN 0 ELSE 1 END,name';$st=$this->db->prepare($sql);$st->execute($args);return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function getCoverPage($id){$st=$this->db->prepare("SELECT * FROM tffax_coverpages WHERE id=?");$st->execute([(int)$id]);return $st->fetch(\PDO::FETCH_ASSOC)?:null;}
    public function saveCoverPage($p){$id=(int)($p['id']??0);$name=trim((string)($p['name']??''));if($name==='')throw new \InvalidArgumentException('Cover page name is required.');$template=(string)($p['template_html']??'');$style=$this->normalizeCoverStyle($p['template_style']??'professional');$enabled=isset($p['enabled'])?1:0;if($id){$st=$this->db->prepare("UPDATE tffax_coverpages SET name=?,template_html=?,template_style=?,enabled=? WHERE id=? AND owner_user_id IS NULL");$st->execute([$name,$template,$style,$enabled,$id]);}else{$st=$this->db->prepare("INSERT INTO tffax_coverpages (name,template_html,template_style,enabled,owner_user_id) VALUES (?,?,?,?,NULL)");$st->execute([$name,$template,$style,$enabled]);}$_SESSION['tffax_notice']='Cover page saved.';}
    public function deleteCoverPage($id){$this->db->prepare("UPDATE tffax_jobs SET coverpage_id=NULL WHERE coverpage_id=?")->execute([(int)$id]);$this->db->prepare("DELETE FROM tffax_coverpages WHERE id=? AND owner_user_id IS NULL")->execute([(int)$id]);$_SESSION['tffax_notice']='Cover page deleted.';}

    public function getModuleDestinations() {
        $out=[['destination'=>'tffax-router,s,1','description'=>'Automatic DID/CID Router','category'=>'Fax Platform']];
        foreach($this->getDestinations() as $d){if(!$d['enabled'])continue;$out[]=['destination'=>'tffax-inbound,'.$d['id'].',1','description'=>$d['name'],'category'=>'Fax Platform'];}
        $out[]=['destination'=>'tffax-inbound,0,1','description'=>'Unassigned Inbox','category'=>'Fax Platform'];
        return $out;
    }
    public function destinations() { return $this->getModuleDestinations(); }
    public function getModuleDestinationInfo($dest) {
        if(is_array($dest))$dest=implode(',',$dest);$dest=(string)$dest;
        if($dest==='tffax-router,s,1')return ['description'=>'Fax Platform: Automatic DID/CID Router','edit_url'=>'config.php?display=tffax&view=routing'];
        if($dest==='tffax-inbound,0,1')return ['description'=>'Fax Platform: Unassigned Inbox','edit_url'=>'config.php?display=tffax&view=history'];
        if(preg_match('/^tffax-inbound,(\d+),1$/',$dest,$m)){$d=$this->getDestination((int)$m[1]);if($d)return ['description'=>'Fax Platform: '.$d['name'],'edit_url'=>'config.php?display=tffax&view=destinations'];}
        return false;
    }
    public function destinations_getdestinfo($dest) { return $this->getModuleDestinationInfo($dest); }
    public function getModuleCheckDestinations($dest=true) {
        $list=[];if($dest===true){$st=$this->db->query("SELECT id FROM tffax_destinations");foreach($st->fetchAll(\PDO::FETCH_COLUMN) as $id)$list[]='tffax-inbound,'.(int)$id.',1';$list[]='tffax-inbound,0,1';$list[]='tffax-router,s,1';return $list;}
        $info=$this->getModuleDestinationInfo($dest);return $info?$info:false;
    }
    public function destinations_check($dest=true){return $this->getModuleCheckDestinations($dest);}
    public function changeModuleDestination($old,$new){
        if(!is_string($old)||!is_string($new))return false;
        if(preg_match('/^tffax-inbound,(\d+),1$/',$old,$m)&&preg_match('/^tffax-inbound,(\d+),1$/',$new,$n)){$this->db->prepare("UPDATE tffax_routes SET destination_id=? WHERE destination_id=?")->execute([(int)$n[1],(int)$m[1]]);return true;}
        return false;
    }
    public function destinations_change($old,$new){return $this->changeModuleDestination($old,$new);}
    public function identifyDestinations($dest){$i=$this->getModuleDestinationInfo($dest);return $i?[$i]:[];}
    public function destinations_identif($dest){return $this->identifyDestinations($dest);}

    private function normalizePhone($s){return preg_replace('/[^0-9+]/','',(string)$s);}
    private function safeFileName($n){$n=preg_replace('/[^A-Za-z0-9_.-]+/','_',basename((string)$n));return trim($n,'._')?:'document';}
    private function assertFileUnderSpool($path){$spool=realpath($this->getSetting('spool_dir','/var/spool/asterisk/tffax'));$real=realpath((string)$path);if(!$spool||!$real||strpos($real,$spool.DIRECTORY_SEPARATOR)!==0)throw new \RuntimeException('Requested file is outside the fax spool.');return $real;}
    private function fileHttpError($code,$message){http_response_code((int)$code);header('Content-Type: text/plain; charset=UTF-8');echo $message;exit;}
    public function serveJobFile($jobId,$download=false){$j=$this->getJob($jobId);if(!$j)$this->fileHttpError(404,'Fax record not found.');$path=(!empty($j['pdf_path'])&&is_file($j['pdf_path']))?$j['pdf_path']:$j['tiff_path'];if(!$path||!is_file($path))$this->fileHttpError(404,'Fax document not found.');$path=$this->assertFileUnderSpool($path);$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));$mime=$ext==='pdf'?'application/pdf':($ext==='tif'||$ext==='tiff'?'image/tiff':'application/octet-stream');header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('X-Content-Type-Options: nosniff');header('Content-Disposition: '.($download?'attachment':'inline').'; filename="fax-'.$j['id'].'.'.$ext.'"');readfile($path);exit;}

    public function submitOutbound($p,$files=[],$draft=false){
        $to=$this->normalizePhone($p['to_number']??''); if($to==='')throw new \InvalidArgumentException('Destination fax number is required.');
        $identity=(int)($p['identity_id']??0);$i=$this->getIdentity($identity);if(!$i||!$i['enabled'])throw new \InvalidArgumentException('Select a valid fax identity.');
        $cover=(int)($p['coverpage_id']??0);$cp=$cover?$this->getCoverPage($cover):null;
        $hasUpload=isset($files['fax_file'])&&!empty($files['fax_file']['tmp_name'])&&is_uploaded_file($files['fax_file']['tmp_name']);
        if(!$hasUpload&&!$cp)throw new \InvalidArgumentException('Attach a document or choose a cover page. A cover-page-only fax is allowed.');
        $spool=rtrim($this->getSetting('spool_dir','/var/spool/asterisk/tffax'),'/'); $uuid=$this->uuid4();
        foreach(['tmp','queue','outgoing'] as $d){if(!is_dir($spool.'/'.$d))@mkdir($spool.'/'.$d,0770,true);}
        $doc=null;
        if($hasUpload){$orig=$this->safeFileName($files['fax_file']['name']??'document');$ext=strtolower(pathinfo($orig,PATHINFO_EXTENSION));if(!in_array($ext,['pdf','tif','tiff','png','jpg','jpeg'],true))throw new \InvalidArgumentException('Supported outbound formats: PDF, TIFF, PNG, JPG.');$doc=$spool.'/tmp/'.$uuid.'-'.$orig;if(!move_uploaded_file($files['fax_file']['tmp_name'],$doc))throw new \RuntimeException('Unable to store uploaded fax document.');@chown($doc,'asterisk');@chgrp($doc,'asterisk');@chmod($doc,0660);}
        $st=$this->db->prepare("INSERT INTO tffax_jobs (uuid,direction,identity_id,user_name,source_number,destination_number,recipient_name,recipient_company,subject,notes,coverpage_id,status,status_text,max_attempts,document_path,created_at) VALUES (?,'outbound',?,?,?,?,?,?,?,?,?,?,?, ?,?,UTC_TIMESTAMP())");
        $user=trim((string)($p['user_name']??''));$src=$i['fax_number']?:$i['outbound_cid'];$status=$draft?'draft':'queued';$st->execute([$uuid,$identity,$user,$src,$to,trim((string)($p['recipient_name']??'')),trim((string)($p['recipient_company']??'')),trim((string)($p['subject']??'')),(string)($p['notes']??''),$cover?:$cover:null,$status,$draft?'Saved draft':'Queued for transmission',(int)$this->getSetting('max_attempts','3'),$doc]);$job=(int)$this->db->lastInsertId();$this->event($job,$draft?'DRAFT_SAVED':'QUEUED',$draft?'Outbound fax saved as draft':'Outbound fax queued');if(!$draft)$this->spawnWorker($job);$_SESSION['tffax_notice']=$draft?'Fax saved as draft.':'Fax queued for transmission.';return $job;
    }
    public function sendDraft($id){$j=$this->getJob($id);if(!$j||$j['direction']!=='outbound'||$j['status']!=='draft')throw new \RuntimeException('Fax draft not found.');$this->db->prepare("UPDATE tffax_jobs SET status='queued',status_text='Queued for transmission' WHERE id=?")->execute([(int)$id]);$this->event((int)$id,'QUEUED','Draft queued for transmission');$this->spawnWorker((int)$id);$_SESSION['tffax_notice']='Fax queued for transmission.';}
    public function deleteJob($id){$j=$this->getJob($id);if(!$j)return;if(in_array($j['status'],['dialing','sending'],true))throw new \RuntimeException('Cannot delete an active fax.');foreach(['document_path','cover_path','tiff_path','pdf_path'] as $f){if(!empty($j[$f])&&is_file($j[$f])){@unlink($j[$f]);}}$this->db->prepare("DELETE FROM tffax_events WHERE job_id=?")->execute([$id]);$this->db->prepare("DELETE FROM tffax_jobs WHERE id=?")->execute([$id]);$_SESSION['tffax_notice']='Fax record deleted.';}
    public function clearFailedJobs(){foreach($this->getJobs(500) as $j){if($j['status']==='failed')$this->deleteJob($j['id']);}$_SESSION['tffax_notice']='Failed fax records cleared.';}
    public function assignJobDestination($jobId,$destinationId){$j=$this->getJob($jobId);if(!$j||$j['direction']!=='inbound')throw new \RuntimeException('Inbound fax not found.');if($destinationId>0&&!$this->getDestination($destinationId))throw new \RuntimeException('Destination not found.');$this->db->prepare("UPDATE tffax_jobs SET destination_id=? WHERE id=?")->execute([$destinationId?:null,$jobId]);$_SESSION['tffax_notice']='Inbound fax destination updated.';}

    private function spawnWorker($job){$script=__DIR__.'/bin/outbound-worker.php';$cmd='/usr/bin/php -q '.escapeshellarg($script).' --job '.(int)$job.' >/dev/null 2>&1 &';exec($cmd);}

    public function processOutboundJob($job){
        $j=$this->getJob($job);if(!$j||$j['direction']!=='outbound')throw new \RuntimeException('Outbound fax job not found.');if(!in_array($j['status'],['queued','failed'],true))return;
        $i=$this->getIdentity($j['identity_id']);if(!$i)throw new \RuntimeException('Fax identity no longer exists.');
        $this->db->prepare("UPDATE tffax_jobs SET status='dialing',status_text='Dialing destination',attempts=attempts+1,started_at=UTC_TIMESTAMP() WHERE id=?")->execute([$job]);$this->event($job,'DIALING','Preparing outbound fax');
        $tif=$this->prepareOutboundTiff($j,$i);$this->db->prepare("UPDATE tffax_jobs SET tiff_path=? WHERE id=?")->execute([$tif,$job]);
        $context=$this->getSetting('outbound_context','from-internal');$station=$i['station_id']?:($i['fax_number']?:$this->getSetting('local_station_id',''));$header=$i['header_text']?:$this->getSetting('header_text','Fax Platform');$cid=$i['outbound_cid']?:$i['fax_number'];
        $vars=['TFFAX_JOB_ID'=>(string)$job,'TFFAX_FILE'=>$tif,'TFFAX_STATION_ID'=>$station,'TFFAX_HEADER'=>$header,'TFFAX_IDENTITY_ID'=>(string)$i['id'],'TFFAX_CALLERID'=>$cid];
        $res=$this->originateLocal($j['destination_number'],$context,$vars,$cid);if(!$res['ok']){$this->markOriginateFailure($job,$res['message'],$res['raw']);throw new \RuntimeException($res['message']);}$this->event($job,'ORIGINATE_ACCEPTED','Asterisk accepted outbound fax call');
    }

    private function prepareOutboundTiff($j,$i){
        $spool=rtrim($this->getSetting('spool_dir','/var/spool/asterisk/tffax'),'/');$parts=[];
        if($j['coverpage_id']){$cp=$this->getCoverPage($j['coverpage_id']);if($cp){$cover=$this->renderCoverPdf($j,$i,$cp);$parts[]=$cover;$this->db->prepare("UPDATE tffax_jobs SET cover_path=? WHERE id=?")->execute([$cover,$j['id']]);}}
        if(!empty($j['document_path']))$parts[]=$j['document_path'];if(!$parts)throw new \RuntimeException('Fax has no document or cover page.');
        $preview=$spool.'/outgoing/'.$j['uuid'].'-preview.pdf';$this->combineToPdf($parts,$preview);$this->db->prepare("UPDATE tffax_jobs SET pdf_path=? WHERE id=?")->execute([$preview,$j['id']]);$tif=$spool.'/queue/'.$j['uuid'].'.tif';$this->pdfToFaxTiff($preview,$tif);@chown($tif,'asterisk');@chgrp($tif,'asterisk');@chmod($tif,0660);return $tif;
    }

    private function renderCoverText($j,$i,$cp){$u=!empty($j['user_name'])?$this->getUserByName($j['user_name']):null;$profile=$this->coverProfile($j,$i,$u);$vars=['{{to_name}}'=>$j['recipient_name'],'{{to_company}}'=>$j['recipient_company'],'{{to_number}}'=>$j['destination_number'],'{{from_name}}'=>$profile['from_name'],'{{from_company}}'=>$profile['from_company'],'{{from_phone}}'=>$profile['from_phone'],'{{from_fax}}'=>$profile['from_fax'],'{{from_email}}'=>$profile['from_email'],'{{from_address}}'=>$profile['from_address'],'{{from_website}}'=>$profile['from_website'],'{{subject}}'=>$j['subject'],'{{date}}'=>$this->localNow('m/d/Y g:i A T'),'{{message}}'=>$j['notes']];return strtr($cp['template_html'],$vars);}
    private function getUserByName($name){$st=$this->db->prepare("SELECT * FROM tffax_users WHERE user_name=? LIMIT 1");$st->execute([(string)$name]);return $st->fetch(\PDO::FETCH_ASSOC)?:null;}
    private function coverProfile($j,$i,$u=null){$u=is_array($u)?$u:[];$address=trim(implode(', ',array_filter([trim((string)($u['address1']??'')),trim((string)($u['address2']??'')),trim(trim((string)($u['city']??'')).(trim((string)($u['state']??''))!==''?', '.trim((string)$u['state']):'').' '.trim((string)($u['postal_code']??'')))])));return ['from_name'=>trim((string)($u['full_name']??''))?:trim((string)($j['user_name']??'')),'from_company'=>trim((string)($u['company_name']??'')),'from_phone'=>trim((string)($u['phone_number']??'')),'from_fax'=>trim((string)($u['fax_number']??''))?:trim((string)($i['fax_number']??'')),'from_email'=>trim((string)($u['email']??''))?:trim((string)($i['email']??'')),'from_address'=>$address,'from_website'=>trim((string)($u['website']??''))];}
    private function normalizeCoverStyle($style){$style=strtolower(trim((string)$style));return in_array($style,['professional','classic','minimal'],true)?$style:'professional';}
    private function coverHtml($j,$i,$cp,$u=null){$p=$this->coverProfile($j,$i,$u);$text=$this->renderCoverText($j,$i,$cp);$esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');};$message=nl2br($esc($j['notes']??''));$style=$this->normalizeCoverStyle($cp['template_style']??'professional');$date=$esc($this->localNow('m/d/Y g:i A T'));$toName=$esc($j['recipient_name']??'');$toCompany=$esc($j['recipient_company']??'');$toFax=$esc($j['destination_number']??'');$subject=$esc($j['subject']??'');$fromName=$esc($p['from_name']);$fromCompany=$esc($p['from_company']);$fromPhone=$esc($p['from_phone']);$fromFax=$esc($p['from_fax']);$fromEmail=$esc($p['from_email']);$fromAddress=$esc($p['from_address']);$fromWebsite=$esc($p['from_website']);
        if($style==='classic'){$body='<div class="sender"><div><strong>'.$fromCompany.'</strong><br>'.$fromAddress.'<br>'.$fromPhone.'<br>'.$fromEmail.'</div><div class="sender-name">FAX</div></div><div class="bigfax">FAX</div><table class="lines"><tr><th>To:</th><td>'.$toName.'</td><th>Fax:</th><td>'.$toFax.'</td></tr><tr><th>From:</th><td>'.$fromName.'</td><th>Date:</th><td>'.$date.'</td></tr><tr><th>Re:</th><td colspan="3">'.$subject.'</td></tr><tr><th>Company:</th><td colspan="3">'.$toCompany.'</td></tr></table><div class="checks">□ Urgent &nbsp;&nbsp; □ For Review &nbsp;&nbsp; □ Please Comment &nbsp;&nbsp; □ Please Reply</div><div class="comments"><strong>Comments:</strong> '.$message.'</div>';}
        elseif($style==='minimal'){$body='<div class="minimal-title">FAX</div><div class="minimal-date">Date: '.$date.'</div><h2>TO:</h2><div class="indent"><strong>Name:</strong> '.$toName.'<br><strong>Fax Number:</strong> '.$toFax.'<br><strong>Company:</strong> '.$toCompany.'</div><h2>FROM:</h2><div class="indent"><strong>Name:</strong> '.$fromName.'<br><strong>Company:</strong> '.$fromCompany.'<br><strong>Contact Number:</strong> '.$fromPhone.'<br><strong>Fax:</strong> '.$fromFax.'</div><div class="subjectbox"><strong>Subject:</strong> '.$subject.'</div><div class="messagebox"><strong>Message:</strong><br><br>'.$message.'</div>';}
        else{$body='<div class="pro-head"><div><div class="pro-fax">FAX</div><div class="muted">Fax Cover Sheet</div></div><div class="pro-company"><strong>'.$fromCompany.'</strong><br>'.$fromName.'<br>'.$fromAddress.'<br>'.$fromPhone.($fromFax!==''?' · Fax '.$fromFax:'').'<br>'.$fromEmail.'<br>'.$fromWebsite.'</div></div><div class="divider"></div><div class="grid"><div><span>TO</span><strong>'.$toName.'</strong><small>'.$toCompany.'</small></div><div><span>FAX</span><strong>'.$toFax.'</strong></div><div><span>DATE</span><strong>'.$date.'</strong></div><div><span>SUBJECT</span><strong>'.$subject.'</strong></div></div><div class="message"><div class="message-title">MESSAGE</div>'.$message.'</div>';}
        return '<!doctype html><html><head><meta charset="utf-8"><style>@page{size:letter;margin:.48in}*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#151922;font-size:11pt;margin:0}.muted{color:#687386}.divider{height:3px;background:#0b6fa4;margin:16px 0 20px}.pro-head{display:flex;justify-content:space-between;align-items:flex-start}.pro-fax{font-size:38pt;font-weight:800;color:#0b6fa4;letter-spacing:2px}.pro-company{text-align:right;line-height:1.45;max-width:55%}.grid{display:grid;grid-template-columns:1.6fr 1fr;gap:0;border:1px solid #b8c2cc}.grid>div{padding:12px;border-bottom:1px solid #d9dfe5;min-height:63px}.grid>div:nth-child(odd){border-right:1px solid #d9dfe5}.grid span{display:block;font-size:8pt;color:#637083;font-weight:bold;letter-spacing:1px}.grid strong{display:block;font-size:12pt;margin-top:5px}.grid small{display:block;margin-top:3px;color:#5e6673}.message{margin-top:18px;border:1px solid #b8c2cc;min-height:330px;padding:16px;line-height:1.5}.message-title{font-size:8pt;color:#637083;font-weight:bold;letter-spacing:1px;margin-bottom:14px}.sender{display:flex;justify-content:space-between;margin:35px 35px 10px;font-size:9pt;line-height:1.35}.sender-name{font-size:28pt;font-weight:bold}.bigfax{font-size:44pt;font-weight:bold;margin:75px 35px 38px}.lines{width:calc(100% - 70px);margin:0 35px;border-collapse:collapse}.lines td,.lines th{padding:8px 5px;border-bottom:1px solid #444;text-align:left}.lines th{width:10%;font-size:9pt}.checks{margin:28px 35px;border-bottom:1px solid #777;padding-bottom:18px}.comments{margin:20px 35px;line-height:1.5}.minimal-title{font-size:42pt;font-weight:bold;color:#355b91;margin:25px 15px}.minimal-date{float:right;margin-top:-80px;border-bottom:1px solid #333;padding:5px 55px 5px 8px}.indent{margin:0 80px 24px;line-height:1.8}.subjectbox,.messagebox{margin:18px 15px;border:1px solid #333;padding:10px}.messagebox{min-height:350px}h2{color:#355b91}</style></head><body>'.$body.'</body></html>';
    }
    private function renderCoverPdf($j,$i,$cp,$u=null,$target=null){$spool=rtrim($this->getSetting('spool_dir','/var/spool/asterisk/tffax'),'/');$pdf=$target?:$spool.'/tmp/'.$j['uuid'].'-cover.pdf';$html=$spool.'/tmp/'.$j['uuid'].'-cover.html';file_put_contents($html,$this->coverHtml($j,$i,$cp,$u));$this->htmlToPdf($html,$pdf);@unlink($html);return $pdf;}
    private function htmlToPdf($html,$pdf){$wk=$this->which('wkhtmltopdf');if($wk){exec(escapeshellcmd($wk).' --quiet --page-size Letter --margin-top 8mm --margin-right 8mm --margin-bottom 8mm --margin-left 8mm '.escapeshellarg($html).' '.escapeshellarg($pdf).' 2>&1',$o,$rc);if($rc===0&&is_file($pdf))return;}$weasy=$this->which('weasyprint');if($weasy){exec(escapeshellcmd($weasy).' '.escapeshellarg($html).' '.escapeshellarg($pdf).' 2>&1',$o,$rc);if($rc===0&&is_file($pdf))return;}throw new \RuntimeException('Cover page PDF rendering requires wkhtmltopdf or weasyprint.');}
    private function combineToPdf($parts,$out){$pdfs=[];foreach($parts as $p){$ext=strtolower(pathinfo($p,PATHINFO_EXTENSION));if($ext==='pdf'){$pdfs[]=$p;continue;}$tmp=$out.'.part'.count($pdfs).'.pdf';$this->toPdf($p,$tmp);$pdfs[]=$tmp;}if(count($pdfs)===1){if(!copy($pdfs[0],$out))throw new \RuntimeException('Unable to prepare fax PDF.');}else{$gs=$this->which('gs');if(!$gs)throw new \RuntimeException('Ghostscript is required to combine fax pages.');$cmd=escapeshellcmd($gs).' -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile='.escapeshellarg($out);foreach($pdfs as $p)$cmd.=' '.escapeshellarg($p);exec($cmd.' 2>&1',$o,$rc);if($rc!==0||!is_file($out))throw new \RuntimeException('Unable to combine fax PDF: '.implode(' ',$o));}foreach($pdfs as $p){if(strpos($p,$out.'.part')===0)@unlink($p);} }
    private function toPdf($src,$out){$ext=strtolower(pathinfo($src,PATHINFO_EXTENSION));if(in_array($ext,['tif','tiff'],true)){$bin=$this->which('tiff2pdf');if(!$bin)throw new \RuntimeException('tiff2pdf is required.');exec(escapeshellcmd($bin).' -o '.escapeshellarg($out).' '.escapeshellarg($src).' 2>&1',$o,$rc);}else{$gs=$this->which('gs');if(!$gs)throw new \RuntimeException('Ghostscript is required for image conversion.');$cmd=escapeshellcmd($gs).' -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile='.escapeshellarg($out).' '.escapeshellarg($src);exec($cmd.' 2>&1',$o,$rc);}if($rc!==0||!is_file($out))throw new \RuntimeException('Unable to convert fax document to PDF: '.implode(' ',$o));}
    private function pdfToFaxTiff($pdf,$tif){$gs=$this->which('gs');if(!$gs)throw new \RuntimeException('Ghostscript is required to create fax TIFF files.');$cmd=escapeshellcmd($gs).' -q -dNOPAUSE -dBATCH -sDEVICE=tiffg4 -r204x196 -sOutputFile='.escapeshellarg($tif).' '.escapeshellarg($pdf);exec($cmd.' 2>&1',$o,$rc);if($rc!==0||!is_file($tif))throw new \RuntimeException('Unable to create fax TIFF: '.implode(' ',$o));}

    public function getUserInboundDids($userId){$st=$this->db->prepare("SELECT DISTINCT r.did_pattern FROM tffax_routes r JOIN tffax_destination_users du ON du.destination_id=r.destination_id WHERE du.user_id=? AND r.enabled=1 AND r.did_pattern<>'' ORDER BY r.priority,r.id");$st->execute([(int)$userId]);return $st->fetchAll(\PDO::FETCH_COLUMN)?:[];}
    private function normalizeComparableDid($value){$s=trim((string)$value);if($s==='')return '';$digits=preg_replace('/\D+/','',$s);if(strlen($digits)===11&&$digits[0]==='1')$digits=substr($digits,1);return $digits;}
    public function sendPortalTestFax($userId){$u=$this->getPortalUser((int)$userId);if(!$u||empty($u['can_send']))throw new \RuntimeException('Your account is not permitted to send faxes.');$identity=(int)$u['default_identity_id'];$i=$identity?$this->getIdentity($identity):null;if(!$i||empty($i['enabled']))throw new \RuntimeException('Your account does not have an enabled default fax identity.');$name=trim((string)($u['full_name']??''))?:$u['user_name'];$company=trim((string)($u['company_name']??''));$cover=0;foreach($this->getCoverPages(true,(int)$userId,true) as $c){$cover=(int)$c['id'];break;}if(!$cover)throw new \RuntimeException('Create or enable a cover page before sending the HP test fax.');$p=['to_number'=>'18884732963','identity_id'=>$identity,'user_name'=>$u['user_name'],'recipient_name'=>'HP Fax Test Service','recipient_company'=>'HP','subject'=>'Fax Platform Test','notes'=>'Automated Fax Platform test from '.$name.($company!==''?' / '.$company:'').'. HP should receive this fax and return a fax to the outbound caller ID presented by this transmission.','coverpage_id'=>$cover];return $this->submitOutbound($p,[],false);}

    public function servePortalOutboundPreview($userId,$p,$files){$u=$this->getPortalUser((int)$userId);if(!$u||empty($u['can_send']))$this->fileHttpError(403,'Your account is not permitted to send faxes.');$to=$this->normalizePhone($p['to_number']??'');if($to==='')$this->fileHttpError(400,'Destination fax number is required.');$identity=(int)$u['default_identity_id'];$i=$this->getIdentity($identity);if(!$i||empty($i['enabled']))$this->fileHttpError(400,'Your account does not have an enabled default fax identity.');$cover=(int)($p['coverpage_id']??0);$cp=$cover?$this->getCoverPage($cover):null;if($cp && !empty($cp['owner_user_id']) && (int)$cp['owner_user_id']!==(int)$userId)$this->fileHttpError(403,'You do not have access to that cover page.');$hasUpload=isset($files['fax_file'])&&!empty($files['fax_file']['tmp_name'])&&is_uploaded_file($files['fax_file']['tmp_name']);if(!$hasUpload&&!$cp)$this->fileHttpError(400,'Attach a document or choose a cover page.');$spool=rtrim($this->getSetting('spool_dir','/var/spool/asterisk/tffax'),'/');$uuid=$this->uuid4();foreach(['tmp','outgoing'] as $d){if(!is_dir($spool.'/'.$d))@mkdir($spool.'/'.$d,0770,true);} $parts=[];$temps=[];$j=['id'=>0,'uuid'=>$uuid,'user_name'=>$u['user_name'],'recipient_name'=>trim((string)($p['recipient_name']??'')),'recipient_company'=>trim((string)($p['recipient_company']??'')),'destination_number'=>$to,'subject'=>trim((string)($p['subject']??'')),'notes'=>(string)($p['notes']??'')];try{if($cp){$coverPdf=$this->renderCoverPdf($j,$i,$cp,$u);$parts[]=$coverPdf;$temps[]=$coverPdf;}if($hasUpload){$orig=$this->safeFileName($files['fax_file']['name']??'document');$ext=strtolower(pathinfo($orig,PATHINFO_EXTENSION));if(!in_array($ext,['pdf','tif','tiff','png','jpg','jpeg'],true))$this->fileHttpError(400,'Supported outbound formats: PDF, TIFF, PNG, JPG.');$doc=$spool.'/tmp/'.$uuid.'-'.$orig;if(!move_uploaded_file($files['fax_file']['tmp_name'],$doc))throw new \RuntimeException('Unable to store uploaded document for preview.');$parts[]=$doc;$temps[]=$doc;}$preview=$spool.'/outgoing/'.$uuid.'-preview.pdf';$this->combineToPdf($parts,$preview);header('Content-Type: application/pdf');header('Content-Length: '.filesize($preview));header('Content-Disposition: inline; filename="fax-preview.pdf"');header('X-Content-Type-Options: nosniff');readfile($preview);@unlink($preview);foreach($temps as $f)@unlink($f);exit;}catch(\Throwable $e){foreach($temps as $f)@unlink($f);$this->fileHttpError(500,$e->getMessage());}}

    public function getPortalCoverPage($userId,$coverId){$cp=$this->getCoverPage((int)$coverId);if(!$cp)return null;if($cp['owner_user_id']!==null&&(int)$cp['owner_user_id']!==(int)$userId)return null;return $cp;}
    public function savePortalCoverPage($userId,$p){$id=(int)($p['id']??0);$name=trim((string)($p['name']??''));if($name==='')throw new \InvalidArgumentException('Cover page name is required.');$template=(string)($p['template_html']??'');$style=$this->normalizeCoverStyle($p['template_style']??'professional');if($id){$cp=$this->getPortalCoverPage($userId,$id);if(!$cp||$cp['owner_user_id']===null)throw new \RuntimeException('You can edit only your personal cover pages.');$st=$this->db->prepare("UPDATE tffax_coverpages SET name=?,template_html=?,template_style=?,enabled=1 WHERE id=? AND owner_user_id=?");$st->execute([$name,$template,$style,$id,(int)$userId]);return $id;}$st=$this->db->prepare("INSERT INTO tffax_coverpages (name,template_html,template_style,enabled,owner_user_id) VALUES (?,?,?,1,?)");$st->execute([$name,$template,$style,(int)$userId]);return (int)$this->db->lastInsertId();}
    public function copyGlobalCoverPageToUser($userId,$coverId){$cp=$this->getCoverPage((int)$coverId);if(!$cp||$cp['owner_user_id']!==null)throw new \RuntimeException('Cover page template not found.');$st=$this->db->prepare("INSERT INTO tffax_coverpages (name,template_html,template_style,enabled,owner_user_id) VALUES (?,?,?,1,?)");$st->execute([$cp['name'].' - My Copy',$cp['template_html'],$this->normalizeCoverStyle($cp['template_style']??'professional'),(int)$userId]);return (int)$this->db->lastInsertId();}
    public function deletePortalCoverPage($userId,$coverId){$cp=$this->getPortalCoverPage($userId,$coverId);if(!$cp||$cp['owner_user_id']===null)throw new \RuntimeException('You can delete only your personal cover pages.');$this->db->prepare("UPDATE tffax_jobs SET coverpage_id=NULL WHERE coverpage_id=?")->execute([(int)$coverId]);$this->db->prepare("DELETE FROM tffax_coverpages WHERE id=? AND owner_user_id=?")->execute([(int)$coverId,(int)$userId]);}
    public function servePortalCoverPreview($userId,$coverId,$draftData=[]){$u=$this->getPortalUser((int)$userId);if(!$u)$this->fileHttpError(403,'Fax account not found.');if($coverId){$cp=$this->getPortalCoverPage($userId,$coverId);if(!$cp)$this->fileHttpError(404,'Cover page not found.');}else{$cp=['id'=>0,'name'=>trim((string)($draftData['name']??'Cover Preview'))?:'Cover Preview','template_html'=>(string)($draftData['template_html']??''),'template_style'=>$this->normalizeCoverStyle($draftData['template_style']??'professional')];}$identity=(int)$u['default_identity_id'];$i=$identity?$this->getIdentity($identity):['id'=>0,'fax_number'=>$u['fax_number'],'email'=>$u['email']];$j=['id'=>0,'uuid'=>$this->uuid4(),'user_name'=>$u['user_name'],'recipient_name'=>'Example Recipient','recipient_company'=>'Example Company','destination_number'=>'15551234567','subject'=>'Cover Page Preview','notes'=>'This is sample message text so you can see how the cover page will look when faxed.'];$spool=rtrim($this->getSetting('spool_dir','/var/spool/asterisk/tffax'),'/');if(!is_dir($spool.'/tmp'))@mkdir($spool.'/tmp',0770,true);$pdf=$spool.'/tmp/'.$j['uuid'].'-cover-preview.pdf';try{$this->renderCoverPdf($j,$i,$cp,$u,$pdf);header('Content-Type: application/pdf');header('Content-Length: '.filesize($pdf));header('Content-Disposition: inline; filename="cover-page-preview.pdf"');header('X-Content-Type-Options: nosniff');readfile($pdf);@unlink($pdf);exit;}catch(\Throwable $e){@unlink($pdf);$this->fileHttpError(500,$e->getMessage());}}

    private function originateLocal($number,$context,$vars,$callerId=''){
        if(!$this->astman)return ['ok'=>false,'message'=>'Asterisk Manager connection is unavailable.','raw'=>null];$cid=trim((string)$callerId);$variable=$vars;
        $params=['Channel'=>'Local/'.$number.'@'.$context.'/n','Context'=>'tffax-tx','Exten'=>'s','Priority'=>1,'Timeout'=>300000,'Async'=>'true','Variable'=>$variable];
        if($cid!==''){$params['CallerID']=$cid.' <'.$cid.'>';}
        $resp=$this->astman->send_request('Originate',$params);$ok=is_array($resp)&&strcasecmp((string)($resp['Response']??''),'Success')===0;$msg=is_array($resp)?($resp['Message']??($ok?'Originate accepted':'Originate rejected')):'Unexpected AMI response';return ['ok'=>$ok,'message'=>$msg,'raw'=>$resp];
    }

    private function markOriginateFailure($job,$message,$raw){
        $details=is_scalar($raw)?(string)$raw:json_encode($raw,JSON_UNESCAPED_SLASHES);$this->db->prepare("UPDATE tffax_jobs SET status='failed',status_code='ORIGINATE_FAILED',status_text=?,completed_at=UTC_TIMESTAMP() WHERE id=? AND status<>'completed'")->execute([$message,(int)$job]);
        $this->event((int)$job,'ORIGINATE_FAILED',$message,$details);
        $this->notifyOutboundJob((int)$job,false);
    }

    public function event($job,$type,$message='',$details=null){$st=$this->db->prepare("INSERT INTO tffax_events (job_id,event_type,message,details,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())");$st->execute([$job,$type,$message,$details]);}

    private function renderEmailTemplate($template, array $vars) {
        $text=(string)$template;
        foreach($vars as $k=>$v){$text=str_replace('{{'.$k.'}}',(string)$v,$text);}
        return $text;
    }
    private function emailTemplateVars(array $job,array $extra=[]){
        $vars=['job_id'=>(string)($job['id']??''),'direction'=>(string)($job['direction']??''),'from_number'=>(string)($job['source_number']??''),'to_number'=>(string)($job['destination_number']??''),'recipient_name'=>(string)($job['recipient_name']??''),'recipient_company'=>(string)($job['recipient_company']??''),'subject'=>(string)($job['subject']??''),'status'=>(string)($job['status']??''),'status_code'=>(string)($job['status_code']??''),'status_text'=>(string)($job['status_text']??''),'pages'=>(string)($job['pages']??''),'remote_station_id'=>(string)($job['remote_station_id']??''),'local_station_id'=>(string)($job['local_station_id']??''),'destination_name'=>(string)($job['destination_name']??''),'user_name'=>(string)($job['user_name']??''),'date'=>$this->localNow('m/d/Y g:i A T')];
        foreach($extra as $k=>$v){$vars[$k]=(string)$v;} return $vars;
    }
    private function cleanMailSubject($subject){return trim(str_replace(["\r","\n"],' ',(string)$subject));}

    private function splitEmailList($value){
        $out=[]; foreach(preg_split('/[;,\s]+/',(string)$value) as $email){$email=trim($email);if($email!==''&&filter_var($email,FILTER_VALIDATE_EMAIL))$out[strtolower($email)]=$email;} return array_values($out);
    }
    private function mailFromAddress(){
        $from=trim((string)$this->getSetting('email_from','')); if(filter_var($from,FILTER_VALIDATE_EMAIL))return $from;
        $host=preg_replace('/[^A-Za-z0-9.-]/','',(string)gethostname()); if($host===''||strpos($host,'.')===false)$host='localhost.localdomain'; return 'fax@'.$host;
    }
    private function sendFaxEmail(array $recipients,$subject,$body,$attachment=null,$filename=null){
        $recipients=array_values(array_unique(array_filter($recipients,function($x){return filter_var($x,FILTER_VALIDATE_EMAIL);}))); if(!$recipients)return [true,'No recipients'];
        $from=$this->mailFromAddress(); $eol="\r\n"; $headers=['From: Fax Platform <'.$from.'>','MIME-Version: 1.0'];
        if($attachment && is_file($attachment) && is_readable($attachment)){
            $boundary='=_TFFax_'.bin2hex(random_bytes(12));$headers[]='Content-Type: multipart/mixed; boundary="'.$boundary.'"';
            $msg='--'.$boundary.$eol.'Content-Type: text/plain; charset=UTF-8'.$eol.'Content-Transfer-Encoding: 8bit'.$eol.$eol.$body.$eol;
            $data=chunk_split(base64_encode(file_get_contents($attachment)));$name=$filename?:basename($attachment);
            $mime=strtolower(pathinfo($attachment,PATHINFO_EXTENSION))==='pdf'?'application/pdf':'image/tiff';
            $msg.='--'.$boundary.$eol.'Content-Type: '.$mime.'; name="'.str_replace('"','',$name).'"'.$eol.'Content-Transfer-Encoding: base64'.$eol.'Content-Disposition: attachment; filename="'.str_replace('"','',$name).'"'.$eol.$eol.$data.$eol.'--'.$boundary.'--'.$eol;
        } else {$headers[]='Content-Type: text/plain; charset=UTF-8';$msg=$body;}
        $ok=@mail(implode(', ',$recipients),$subject,$msg,implode($eol,$headers)); return [$ok,$ok?'Email accepted by local mail transport':'PHP mail() returned failure'];
    }
    public function notifyInboundJob($jobId){
        $st=$this->db->prepare("SELECT j.*,d.name destination_name,d.email_to,d.attach_format FROM tffax_jobs j LEFT JOIN tffax_destinations d ON d.id=j.destination_id WHERE j.id=? AND j.direction='inbound'");$st->execute([(int)$jobId]);$j=$st->fetch(\PDO::FETCH_ASSOC);if(!$j||$j['status']!=='received')return;
        $recipients=$this->splitEmailList($j['email_to']??'');
        if(!empty($j['destination_id'])){$st=$this->db->prepare("SELECT u.email FROM tffax_destination_users du JOIN tffax_users u ON u.id=du.user_id WHERE du.destination_id=? AND u.can_receive=1 AND u.notify_inbound=1 AND u.email<>''");$st->execute([(int)$j['destination_id']]);foreach($st->fetchAll(\PDO::FETCH_COLUMN) as $e)$recipients=array_merge($recipients,$this->splitEmailList($e));}
        $recipients=array_values(array_unique($recipients)); if(!$recipients)return;
        $path=(!empty($j['pdf_path'])&&is_file($j['pdf_path']))?$j['pdf_path']:$j['tiff_path'];$ext=strtolower(pathinfo((string)$path,PATHINFO_EXTENSION));
        $sender=($j['source_number']?:($j['remote_station_id']?:'Unknown sender'));
        $vars=$this->emailTemplateVars($j,['sender'=>$sender,'received_at'=>$this->formatTimestamp($j['created_at'])]);
        $subject=$this->cleanMailSubject($this->renderEmailTemplate($this->getSetting('email_tpl_inbound_subject','Inbound fax from {{sender}}'),$vars));
        $body=$this->renderEmailTemplate($this->getSetting('email_tpl_inbound_body',"A new inbound fax was received.

From: {{from_number}}
To DID: {{to_number}}
Mailbox: {{destination_name}}
Pages: {{pages}}
Remote station: {{remote_station_id}}
Received: {{received_at}}
"),$vars);
        [$ok,$detail]=$this->sendFaxEmail($recipients,$subject,$body,$path,'inbound-fax-'.$jobId.'.'.($ext?:'pdf'));$this->event((int)$jobId,$ok?'EMAIL_SENT':'EMAIL_FAILED',($ok?'Inbound fax emailed to ':'Unable to email inbound fax to ').implode(', ',$recipients),$detail);
    }
    public function notifyOutboundJob($jobId,$success){
        $st=$this->db->prepare("SELECT j.*,u.email,u.notify_success,u.notify_failure FROM tffax_jobs j LEFT JOIN tffax_users u ON u.user_name=j.user_name WHERE j.id=? AND j.direction='outbound'");$st->execute([(int)$jobId]);$j=$st->fetch(\PDO::FETCH_ASSOC);if(!$j||empty($j['email']))return;
        if($success && empty($j['notify_success']))return;if(!$success && empty($j['notify_failure']))return;
        $vars=$this->emailTemplateVars($j,['result'=>$success?'SUCCESS':'FAILED','completed_at'=>$this->formatTimestamp($j['completed_at']?:$j['created_at'])]);
        if($success){
            $subject=$this->cleanMailSubject($this->renderEmailTemplate($this->getSetting('email_tpl_success_subject','Fax sent successfully to {{to_number}}'),$vars));
            $body=$this->renderEmailTemplate($this->getSetting('email_tpl_success_body',"Your fax was transmitted successfully.

To: {{to_number}}
Status: {{status_text}}
Pages: {{pages}}
Remote station: {{remote_station_id}}
Completed: {{completed_at}}
Job: #{{job_id}}
"),$vars);
        }else{
            $subject=$this->cleanMailSubject($this->renderEmailTemplate($this->getSetting('email_tpl_failure_subject','Fax failed to {{to_number}}'),$vars));
            $body=$this->renderEmailTemplate($this->getSetting('email_tpl_failure_body',"Your fax transmission failed.

To: {{to_number}}
Status: {{status_text}}
Pages: {{pages}}
Remote station: {{remote_station_id}}
Completed: {{completed_at}}
Job: #{{job_id}}
"),$vars);
        }
        $path=$success&& !empty($j['pdf_path'])&&is_file($j['pdf_path'])?$j['pdf_path']:null;[$ok,$detail]=$this->sendFaxEmail([$j['email']],$subject,$body,$path,$path?'fax-'.$j['id'].'.pdf':null);$this->event((int)$jobId,$ok?'EMAIL_SENT':'EMAIL_FAILED',($ok?'Fax status emailed to ':'Unable to email fax status to ').$j['email'],$detail);
    }

    public function diagnostics(){
        $checks=[]; $checks[]=$this->diag('Asterisk Manager',(bool)$this->astman,'Connected','Unavailable');
        $mailBin=$this->which('sendmail');$checks[]=$this->diag('Email transport',function_exists('mail'),$mailBin?('PHP mail() available via '.$mailBin):'PHP mail() available','PHP mail() is unavailable');
        foreach(['gs'=>'Ghostscript','tiff2pdf'=>'tiff2pdf','tiffinfo'=>'tiffinfo'] as $bin=>$label){$p=$this->which($bin);$checks[]=$this->diag($label,(bool)$p,$p?:'Found','Not found');}
        $spool=$this->getSetting('spool_dir','/var/spool/asterisk/tffax');$checks[]=$this->diag('Fax spool',is_dir($spool)&&is_writable($spool),$spool,'Missing or not writable');
        $mods=''; if($this->astman){$r=$this->astman->Command('module show like fax');$mods=is_array($r)?implode("\n",$r):print_r($r,true);} $checks[]=$this->diag('Asterisk fax modules',stripos($mods,'res_fax')!==false,$mods?:'Detected','res_fax not detected');
        $apps=''; if($this->astman){$r=$this->astman->Command('core show application SendFAX');$apps=is_array($r)?implode("\n",$r):print_r($r,true);} $checks[]=$this->diag('SendFAX application',stripos($apps,'SendFAX')!==false,'Available','Unavailable');
        $rx=''; if($this->astman){$r=$this->astman->Command('core show application ReceiveFAX');$rx=is_array($r)?implode("\n",$r):print_r($r,true);} $checks[]=$this->diag('ReceiveFAX application',stripos($rx,'ReceiveFAX')!==false,'Available','Unavailable');
        return $checks;
    }
    private function diag($name,$ok,$good,$bad){return ['name'=>$name,'ok'=>$ok,'detail'=>$ok?$good:$bad];}
    private function which($bin){$out=[];$rc=1;exec('command -v '.escapeshellarg($bin).' 2>/dev/null',$out,$rc);return $rc===0&&isset($out[0])?trim($out[0]):'';}
    public function escapeDialplan($s){return str_replace(['"','\\'],['',''],(string)$s);}
    private function uuid4(){$d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));}
}
