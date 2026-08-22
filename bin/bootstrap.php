<?php
$bootstrap='/etc/freepbx.conf'; if(!is_file($bootstrap)){$bootstrap='/var/www/html/admin/bootstrap.php';}
require_once $bootstrap;
function tffax_arg($name,$default=''){global $argv;foreach($argv as $i=>$v){if($v==='--'.$name && isset($argv[$i+1]))return $argv[$i+1];if(strpos($v,'--'.$name.'=')===0)return substr($v,strlen($name)+3);}return $default;}
