#!/usr/bin/env php
<?php
require_once __DIR__.'/bootstrap.php';
$m=FreePBX::Tffax();$db=FreePBX::Database();
$dest=(int)tffax_arg('dest',0);$file=tffax_arg('file');$did=tffax_arg('did');$caller=tffax_arg('caller');$remote=tffax_arg('remote');$pages=(int)tffax_arg('pages',0);$rate=tffax_arg('rate');$resolution=tffax_arg('resolution');$mode=tffax_arg('mode');$ecm=tffax_arg('ecm');$status=tffax_arg('status');$statusstr=tffax_arg('statusstr');$error=tffax_arg('error');
$uuid=basename($file,'.tif');$pdf=preg_replace('/\.tif$/i','.pdf',$file);$ok=(strtoupper($status)==='SUCCESS');$finalStatus=$ok?'received':'failed';
$text=$ok?'Fax received successfully':($statusstr!==''?$statusstr:($error!==''?$error:$status));
$code=$ok?'SUCCESS':($error!==''?$error:$status);
$st=$db->prepare("INSERT INTO tffax_jobs (uuid,direction,destination_id,source_number,destination_number,remote_station_id,status,status_code,status_text,pages,transport,ecm,bitrate,resolution,tiff_path,pdf_path,created_at,started_at,completed_at) VALUES (?,'inbound',?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$st->execute([$uuid,$dest,$caller,$did,$remote,$finalStatus,$code,$text,$pages,$mode,$ecm,$rate,$resolution,$file,$pdf]);$job=(int)$db->lastInsertId();
$details=json_encode(['faxstatus'=>$status,'statusstr'=>$statusstr,'faxerror'=>$error,'remote_station_id'=>$remote,'pages'=>$pages,'rate'=>$rate,'resolution'=>$resolution,'mode'=>$mode,'ecm'=>$ecm],JSON_UNESCAPED_SLASHES);
$m->event($job,$ok?'FAX_RECEIVED':'FAX_FAILED',$ok?'Inbound fax received: '.$pages.' page(s), '.$rate.' bps'.($mode!==''?', '.$mode:''):'Inbound fax failed: '.$text,$details);
if(is_file($file)){
 $tiff2pdf=trim((string)shell_exec('command -v tiff2pdf 2>/dev/null')); if($tiff2pdf){exec(escapeshellcmd($tiff2pdf).' -o '.escapeshellarg($pdf).' '.escapeshellarg($file).' 2>&1',$o,$rc);if($rc===0&&is_file($pdf)){$db->prepare("UPDATE tffax_jobs SET pdf_path=? WHERE id=?")->execute([$pdf,$job]);$m->event($job,'DOCUMENT_CONVERTED','Inbound TIFF converted to PDF');}}
}
if($ok){$m->notifyInboundJob($job);}
