<?php
session_name('tffax_portal');
session_start();
$bootstrap='/etc/freepbx.conf'; if(!is_file($bootstrap)){$bootstrap='/var/www/html/admin/bootstrap.php';}
require_once $bootstrap;
require_once __DIR__.'/Tffax.class.php';
$hostApp=FreePBX::Create();
$m=new \FreePBX\modules\Tffax($hostApp);
$brand=$m->getPlatformBranding();
$accent=(isset($brand['accent'])&&preg_match('/^#[0-9a-fA-F]{6}$/',(string)$brand['accent']))?$brand['accent']:'#0b6fa4';
$uiTheme=$m->getSetting('ui_theme','refined');if(!in_array($uiTheme,['refined','classic'],true)){$uiTheme='refined';}
if(!empty($_GET['brand_asset'])){$role=in_array($_GET['brand_asset'],['logo','compact','icon'],true)?$_GET['brand_asset']:'logo';$asset=$m->getPlatformBrandAssetPath($role);if(!$asset){http_response_code(404);exit;}$info=@getimagesize($asset);if(!$info||empty($info['mime'])){http_response_code(404);exit;}header('Content-Type: '.$info['mime']);header('Cache-Control: public, max-age=300');header('Content-Length: '.filesize($asset));readfile($asset);exit;}
$portalSelf=isset($_SERVER['SCRIPT_NAME'])&&$_SERVER['SCRIPT_NAME']!==''?$_SERVER['SCRIPT_NAME']:'/fax/';
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function csrf(){if(empty($_SESSION['tffax_portal_csrf']))$_SESSION['tffax_portal_csrf']=bin2hex(random_bytes(24));return $_SESSION['tffax_portal_csrf'];}
function csrf_ok(){return isset($_POST['csrf'],$_SESSION['tffax_portal_csrf'])&&hash_equals($_SESSION['tffax_portal_csrf'],(string)$_POST['csrf']);}
if(isset($_GET['logout'])){$_SESSION=[];session_destroy();header('Location: '.$portalSelf);exit;}
$error='';$notice='';
if(empty($_SESSION['tffax_portal_uid'])&&($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['action']??'')==='login'){$login=$m->portalAuthenticate($_POST['username']??'',$_POST['password']??'');if($login){session_regenerate_id(true);$_SESSION['tffax_portal_uid']=(int)$login['id'];$_SESSION['tffax_portal_user_name']=$login['user_name'];csrf();header('Location: '.$portalSelf);exit;}else{$error='Invalid login or portal access is disabled.';}}
$u=!empty($_SESSION['tffax_portal_uid'])?$m->getPortalUser((int)$_SESSION['tffax_portal_uid']):null;
if(!empty($_SESSION['tffax_portal_uid'])&&!$u){$_SESSION=[];session_destroy();header('Location: '.$portalSelf);exit;}
if($u&&isset($_GET['file'],$_GET['id'])){$m->servePortalJobFile((int)$u['id'],(int)$_GET['id'],$_GET['file']==='download');}
if($u&&isset($_GET['cover_preview'])){$m->servePortalCoverPreview((int)$u['id'],(int)$_GET['cover_preview']);}
$view=($u&&($_GET['view']??'')==='settings')?'settings':'faxes';
if($u&&($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['action']??'')!=='login'){
 if(!csrf_ok()){$error='Security token expired. Refresh and try again.';}
 else try{
  $action=(string)($_POST['action']??'');
  if($action==='send'||$action==='preview'){
   if(empty($u['can_send']))throw new RuntimeException('Your account is not permitted to send faxes.');
   $_SESSION['tffax_portal_user_name']=$u['user_name'];$_POST['identity_id']=(int)$u['default_identity_id'];
   if($action==='preview'){$m->servePortalOutboundPreview((int)$u['id'],$_POST,$_FILES);}
   $job=$m->submitOutbound($_POST,$_FILES,false);$notice='Fax queued for transmission.';
  }elseif($action==='preview_cover'){
   $m->servePortalCoverPreview((int)$u['id'],0,$_POST);
  }elseif($action==='test_fax'){
   $job=$m->sendPortalTestFax((int)$u['id']);$notice='Test fax #'.$job.' queued to HP Fax Test at 18884732963.';$view='settings';
  }elseif($action==='send_draft'){
   $id=(int)($_POST['id']??0);if(!$m->portalCanAccessJob((int)$u['id'],$id))throw new RuntimeException('You do not have access to that fax.');$m->sendDraft($id);$notice='Fax queued for transmission.';
  }elseif($action==='delete'){
   if(empty($u['can_delete']))throw new RuntimeException('Your account is not permitted to delete fax records.');$id=(int)($_POST['id']??0);if(!$m->portalCanAccessJob((int)$u['id'],$id))throw new RuntimeException('You do not have access to that fax.');$m->deleteJob($id);$notice='Fax record deleted.';
  }elseif($action==='save_preferences'){$m->updatePortalPreferences((int)$u['id'],$_POST);$u=$m->getPortalUser((int)$u['id']);$notice='Fax settings saved.';$view='settings';
  }elseif($action==='change_password'){$m->changePortalPassword((int)$u['id'],$_POST['current_password']??'',$_POST['new_password']??'',$_POST['confirm_password']??'');$notice='Password changed.';$view='settings';
  }elseif($action==='save_cover'){$m->savePortalCoverPage((int)$u['id'],$_POST);$notice='Cover page saved.';$view='settings';
  }elseif($action==='copy_cover'){$m->copyGlobalCoverPageToUser((int)$u['id'],(int)($_POST['id']??0));$notice='Cover page copied to My Cover Pages. You can edit your copy below.';$view='settings';
  }elseif($action==='delete_cover'){$m->deletePortalCoverPage((int)$u['id'],(int)($_POST['id']??0));$notice='Cover page deleted.';$view='settings';}
 }catch(Throwable $e){$error=$e->getMessage();}
}
$jobs=$u?$m->getPortalJobs((int)$u['id'],200):[];
$covers=$u?$m->getCoverPages(true,(int)$u['id'],true):[];
$inboundDids=$u?$m->getUserInboundDids((int)$u['id']):[];
$myCovers=$u?$m->getCoverPages(false,(int)$u['id'],false):[];
$globalCovers=$u?$m->getCoverPages(true,null,true):[];
$editCover=null;if($u&&!empty($_GET['edit_cover'])){$editCover=$m->getPortalCoverPage((int)$u['id'],(int)$_GET['edit_cover']);}
require __DIR__.'/portal-view.php';
