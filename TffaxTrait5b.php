<?php
namespace FreePBX\modules;
trait TffaxTrait5b {
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
