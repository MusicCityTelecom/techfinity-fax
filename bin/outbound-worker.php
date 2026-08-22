#!/usr/bin/env php
<?php
require_once __DIR__.'/bootstrap.php';
$m=FreePBX::Tffax();
$job=(int)tffax_arg('job',0);
if(!$job){fwrite(STDERR,"Missing --job\n");exit(2);}
try{$m->processOutboundJob($job);exit(0);}catch(Throwable $e){
    try{
        $db=FreePBX::Database();
        $db->prepare("UPDATE tffax_jobs SET status='failed',status_code='WORKER_EXCEPTION',status_text=?,completed_at=UTC_TIMESTAMP() WHERE id=? AND status<>'completed'")->execute([$e->getMessage(),$job]);
        $m->event($job,'WORKER_EXCEPTION',$e->getMessage());
    }catch(Throwable $ignored){}
    fwrite(STDERR,$e->getMessage()."\n");exit(1);
}
