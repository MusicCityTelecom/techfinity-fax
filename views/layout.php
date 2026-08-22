<?php
$base='config.php?display=tffax';
$notice=$_SESSION['tffax_notice']??''; $error=$_SESSION['tffax_error']??'';
unset($_SESSION['tffax_notice'],$_SESSION['tffax_error']);
$brand=$module->getPlatformBranding();
$accent=isset($brand['accent'])&&preg_match('/^#[0-9a-fA-F]{6}$/',$brand['accent'])?$brand['accent']:'#0b6fa4';
$uiTheme=$module->getSetting('ui_theme','refined'); if(!in_array($uiTheme,['refined','classic'],true)){$uiTheme='refined';}
$adminLogo=($uiTheme==='refined'&&!empty($brand['compact_logo_url']))?$brand['compact_logo_url']:($brand['icon_logo_url']??'');
?>
<link rel="stylesheet" href="assets/tffax/css/tffax.css?ver=16.0.2">
<div class="tffax-shell ia-native-module theme-<?=htmlspecialchars($uiTheme,ENT_QUOTES,'UTF-8')?>" style="--ia-accent:<?=htmlspecialchars($accent,ENT_QUOTES,'UTF-8')?>">
  <div class="tffax-head">
    <div class="tffax-head-brand">
      <div><h1>Fax Platform</h1><p>Inbound and outbound multi-user fax management</p></div>
    </div>
    <div class="tffax-badge">v16.0.2</div>
  </div>
  <nav class="tffax-nav">
    <?php foreach(['dashboard'=>'Dashboard','send'=>'Send Fax','history'=>'Fax History','accounts'=>'Fax Accounts','identities'=>'Fax Identities','coverpages'=>'Cover Pages','settings'=>'Settings','diagnostics'=>'Diagnostics'] as $k=>$label): ?>
      <a class="<?= $view===$k?'active':'' ?>" href="<?= $base ?>&view=<?= $k ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if($notice): ?><div class="alert alert-success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
  <?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <div class="tffax-content"><?php include __DIR__.'/'.$view.'.php'; ?></div>
</div>
