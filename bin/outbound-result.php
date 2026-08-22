#!/usr/bin/env php
<?php
require_once __DIR__.'/bootstrap.php';
$m=FreePBX::Tffax();$db=FreePBX::Database();
$job=(int)tffax_arg('job',0);if(!$job)exit(1);
$status=trim((string)tffax_arg('status',''));
$statusstr=trim((string)tffax_arg('statusstr',''));
$error=trim((string)tffax_arg('error',''));
$remote=trim((string)tffax_arg('remote',''));
$pages=(int)tffax_arg('pages',0);
$rate=trim((string)tffax_arg('rate',''));
$resolution=trim((string)tffax_arg('resolution',''));
$mode=trim((string)tffax_arg('mode',''));
$ecm=trim((string)tffax_arg('ecm',''));
$ok=strtoupper($status)==='SUCCESS';
if($status===''){$status='FAILED';if($error==='')$error='Fax application ended without a final FAXSTATUS';}
$text=$ok?'Fax transmitted successfully':($statusstr!==''?$statusstr:($error!==''?$error:$status));
$code=$ok?'SUCCESS':($error!==''?$error:$status);
$st=$db->prepare("UPDATE tffax_jobs SET status=?,status_code=?,status_text=?,remote_station_id=?,pages=?,bitrate=?,resolution=?,transport=?,ecm=?,completed_at=UTC_TIMESTAMP() WHERE id=? AND status<>'cancelled'");
$st->execute([$ok?'completed':'failed',$code,$text,$remote,$pages,$rate,$resolution,$mode,$ecm,$job]);
$details=json_encode(['faxstatus'=>$status,'statusstr'=>$statusstr,'faxerror'=>$error,'remote_station_id'=>$remote,'pages'=>$pages,'rate'=>$rate,'resolution'=>$resolution,'mode'=>$mode,'ecm'=>$ecm],JSON_UNESCAPED_SLASHES);
$m->event($job,$ok?'FAX_COMPLETED':'FAX_FAILED',$ok?'Outbound fax completed: '.$pages.' page(s), '.$rate.' bps'.($mode!==''?', '.$mode:''):'Outbound fax failed: '.$text,$details);

$m->notifyOutboundJob($job,$ok);
