<?php
namespace FreePBX\modules;
trait TffaxTrait5a {


    private function markOriginateFailure($job,$message,$details=null){
        $message=(string)$message;
        $this->db->prepare("UPDATE tffax_jobs SET status='failed',status_code='ORIGINATE_FAILED',status_text=?,completed_at=UTC_TIMESTAMP() WHERE id=? AND status<>'completed'")->execute([$message,(int)$job]);
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
}
