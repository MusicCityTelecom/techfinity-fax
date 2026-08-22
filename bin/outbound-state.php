#!/usr/bin/env php
<?php
require_once __DIR__.'/bootstrap.php';
$m=FreePBX::Tffax();$db=FreePBX::Database();
$job=(int)tffax_arg('job',0);$status=strtolower((string)tffax_arg('status',''));
if(!$job || !in_array($status,['sending','dialing'],true)) exit(1);
$db->prepare("UPDATE tffax_jobs SET status=?,status_text=? WHERE id=? AND status NOT IN ('completed','failed','cancelled')")->execute([$status,$status==='sending'?'Fax transmission in progress':'Dialing destination',$job]);
$m->event($job,strtoupper($status),$status==='sending'?'Fax transmission started':'Dialing destination');
