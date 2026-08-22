<?php
namespace FreePBX\modules;
trait TffaxTrait1 {


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
        if ($context === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $context)) { throw new \InvalidArgumentException('Outbound dial context contains invalid characters.'); }

        $timezone = trim((string)($p['timezone'] ?? $this->getSetting('timezone','America/Chicago')));
        try { new \DateTimeZone($timezone); } catch (\Throwable $e) { throw new \InvalidArgumentException('Invalid IANA timezone name. Example: America/Chicago.'); }

        $uiTheme = strtolower(trim((string)($p['ui_theme'] ?? $this->getSetting('ui_theme','refined'))));
        if (!in_array($uiTheme, ['refined','classic'], true)) { throw new \InvalidArgumentException('Invalid interface theme.'); }

        $values = [
            'spool_dir' => rtrim(trim((string)($p['spool_dir'] ?? $this->getSetting('spool_dir','/var/spool/asterisk/tffax'))), '/'),
            'local_station_id' => trim((string)($p['local_station_id'] ?? '')),
            'header_text' => trim((string)($p['header_text'] ?? '')),
            'ecm' => $ecm,
            'min_rate' => (string)$minRate,
            'max_rate' => (string)$maxRate,
            't38_mode' => $t38,
            'max_attempts' => (string)$attempts,
            'email_from' => trim((string)($p['email_from'] ?? '')),
            'retain_days' => (string)$retain,
            'outbound_context' => $context,
            'timezone' => $timezone,
            'ui_theme' => $uiTheme,
            'email_tpl_inbound_subject' => (string)($p['email_tpl_inbound_subject'] ?? $this->getSetting('email_tpl_inbound_subject','Inbound fax from {{sender}}')),
            'email_tpl_inbound_body' => (string)($p['email_tpl_inbound_body'] ?? $this->getSetting('email_tpl_inbound_body',"A new inbound fax was received.\n\nFrom: {{from_number}}\nTo DID: {{to_number}}\nMailbox: {{destination_name}}\nPages: {{pages}}\nRemote station: {{remote_station_id}}\nReceived: {{received_at}}\n")),
            'email_tpl_success_subject' => (string)($p['email_tpl_success_subject'] ?? $this->getSetting('email_tpl_success_subject','Fax sent successfully to {{to_number}}')),
            'email_tpl_success_body' => (string)($p['email_tpl_success_body'] ?? $this->getSetting('email_tpl_success_body',"Your fax was transmitted successfully.\n\nTo: {{to_number}}\nStatus: {{status_text}}\nPages: {{pages}}\nRemote station: {{remote_station_id}}\nCompleted: {{completed_at}}\nJob: #{{job_id}}\n")),
            'email_tpl_failure_subject' => (string)($p['email_tpl_failure_subject'] ?? $this->getSetting('email_tpl_failure_subject','Fax failed to {{to_number}}')),
            'email_tpl_failure_body' => (string)($p['email_tpl_failure_body'] ?? $this->getSetting('email_tpl_failure_body',"Your fax transmission failed.\n\nTo: {{to_number}}\nStatus: {{status_text}}\nPages: {{pages}}\nRemote station: {{remote_station_id}}\nCompleted: {{completed_at}}\nJob: #{{job_id}}\n")),
            'default_identity_id' => (string)(int)($p['default_identity_id'] ?? 0),
        ];
        if ($values['spool_dir'] === '' || $values['spool_dir'][0] !== '/') { throw new \InvalidArgumentException('Spool directory must be an absolute path.'); }
        foreach ($values as $k=>$v) { $this->setSettingValue($k, $v); }
        $_SESSION['tffax_notice']='Fax engine settings saved. Apply Config for dialplan changes.';
    }

    public function getIdentities($enabledOnly=false) {
        $sql="SELECT * FROM tffax_identities".($enabledOnly?" WHERE enabled=1":"")." ORDER BY name";
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function getIdentity($id) { $st=$this->db->prepare("SELECT * FROM tffax_identities WHERE id=?");$st->execute([(int)$id]);return $st->fetch(\PDO::FETCH_ASSOC) ?: null; }
    public function getIdentityStationId($id) { $i=$this->getIdentity((int)$id); return $i ? ($i['station_id'] ?: $i['fax_number']) : $this->getSetting('local_station_id',''); }
    public function saveIdentity($p) {
        $vals=[trim((string)($p['name']??'')),trim((string)($p['fax_number']??'')),trim((string)($p['station_id']??'')),trim((string)($p['header_text']??'')),trim((string)($p['email']??'')),trim((string)($p['outbound_cid']??'')),isset($p['enabled'])?1:0];
        if ($vals[0]==='') throw new \InvalidArgumentException('Identity name is required.');
        $id=(int)($p['id']??0);
        if($id){$st=$this->db->prepare("UPDATE tffax_identities SET name=?,fax_number=?,station_id=?,header_text=?,email=?,outbound_cid=?,enabled=? WHERE id=?");$vals[]=$id;$st->execute($vals);}else{$st=$this->db->prepare("INSERT INTO tffax_identities (name,fax_number,station_id,header_text,email,outbound_cid,enabled) VALUES (?,?,?,?,?,?,?)");$st->execute($vals);}
    }
    public function deleteIdentity($id){if($id>0){$st=$this->db->prepare("DELETE FROM tffax_identities WHERE id=?");$st->execute([$id]);}}

    public function getCoverPages($enabledOnly=false, $ownerUserId=null, $includeGlobal=true) {
        $where=[]; $args=[];
        if($enabledOnly){ $where[]='enabled=1'; }
        if($ownerUserId===null){
            // Administrator/global cover pages only. Personal user templates are private.
            $where[]='owner_user_id IS NULL';
        } else {
            $ownerUserId=(int)$ownerUserId;
            if($includeGlobal){ $where[]='(owner_user_id IS NULL OR owner_user_id=?)'; $args[]=$ownerUserId; }
            else { $where[]='owner_user_id=?'; $args[]=$ownerUserId; }
        }
        $sql='SELECT * FROM tffax_coverpages'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY owner_user_id IS NOT NULL, name';
        $st=$this->db->prepare($sql); $st->execute($args); return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function getCoverPage($id){$st=$this->db->prepare("SELECT * FROM tffax_coverpages WHERE id=?");$st->execute([(int)$id]);return $st->fetch(\PDO::FETCH_ASSOC) ?: null;}
    public function getPortalCoverPage($userId,$id){$st=$this->db->prepare("SELECT * FROM tffax_coverpages WHERE id=? AND owner_user_id=?");$st->execute([(int)$id,(int)$userId]);return $st->fetch(\PDO::FETCH_ASSOC)?:null;}
    public function saveCoverPage($p){
        $name=trim((string)($p['name']??'')); $tpl=(string)($p['template_html']??''); $style=$this->normalizeCoverStyle($p['template_style']??'professional'); $enabled=isset($p['enabled'])?1:0; $id=(int)($p['id']??0);
        if($name==='') throw new \InvalidArgumentException('Cover page name is required.');
        if(trim($tpl)==='') throw new \InvalidArgumentException('Cover page template cannot be empty.');
        if($id){$st=$this->db->prepare("UPDATE tffax_coverpages SET name=?,template_html=?,template_style=?,enabled=? WHERE id=? AND owner_user_id IS NULL");$st->execute([$name,$tpl,$style,$enabled,$id]);}
        else{$st=$this->db->prepare("INSERT INTO tffax_coverpages (name,template_html,template_style,enabled,owner_user_id) VALUES (?,?,?,?,NULL)");$st->execute([$name,$tpl,$style,$enabled]);}
        $_SESSION['tffax_notice']='Cover page saved.';
    }
    public function deleteCoverPage($id){if((int)$id>0){$this->db->prepare("UPDATE tffax_jobs SET coverpage_id=NULL WHERE coverpage_id=?")->execute([(int)$id]);$this->db->prepare("DELETE FROM tffax_coverpages WHERE id=? AND owner_user_id IS NULL")->execute([(int)$id]);$_SESSION['tffax_notice']='Cover page deleted.';}}
    public function savePortalCoverPage($userId,$p){
        $userId=(int)$userId; if($userId<=0) throw new \InvalidArgumentException('Invalid fax user.');
        $name=trim((string)($p['name']??'')); $tpl=(string)($p['template_html']??''); $style=$this->normalizeCoverStyle($p['template_style']??'professional'); $id=(int)($p['id']??0);
        if($name==='') throw new \InvalidArgumentException('Cover page name is required.');
        if(trim($tpl)==='') throw new \InvalidArgumentException('Cover page template cannot be empty.');
        if($id){
            if(!$this->getPortalCoverPage($userId,$id)) throw new \RuntimeException('You can only modify your own cover pages.');
            $st=$this->db->prepare("UPDATE tffax_coverpages SET name=?,template_html=?,template_style=?,enabled=1 WHERE id=? AND owner_user_id=?");$st->execute([$name,$tpl,$style,$id,$userId]);
        } else {
            $st=$this->db->prepare("INSERT INTO tffax_coverpages (name,template_html,template_style,enabled,owner_user_id) VALUES (?,?,?,1,?)");$st->execute([$name,$tpl,$style,$userId]);
        }
    }
    private function normalizeCoverStyle($style){$style=strtolower(trim((string)$style));return in_array($style,['professional','classic','minimal'],true)?$style:'professional';}
}
