<?php $ea=$editAccount?:[]; $editing=!empty($ea['id']); ?>
<div class="tffax-panel">
  <h2>Fax Accounts</h2>
  <p class="text-muted">The normal setup is now one account = one user login + inbound mailbox + DID routing + fax permissions. Use Advanced Setup only for shared mailboxes, caller-ID routing, unusual wildcard rules, or other special cases.</p>
</div>
<div class="row">
  <div class="col-md-5"><div class="tffax-panel"><h2><?=$editing?'Edit':'Create'?> Fax Account</h2>
    <form method="post" action="config.php?display=tffax&view=accounts"><input type="hidden" name="tffax_action" value="save_account"><?php if($editing):?><input type="hidden" name="id" value="<?=(int)$ea['id']?>"><?php endif;?>
      <div class="form-group"><label>Login / Account Name</label><input class="form-control" name="user_name" required placeholder="frontdesk" value="<?=htmlspecialchars($ea['user_name']??'')?>"></div>
      <div class="form-group"><label>Email</label><input type="email" class="form-control" name="email" placeholder="frontdesk@example.com" value="<?=htmlspecialchars($ea['email']??'')?>"></div>
      <div class="form-group"><label>Inbound Fax DID(s)</label><textarea class="form-control" name="inbound_dids" rows="3" placeholder="16155551001&#10;16155551002"><?=htmlspecialchars($ea['inbound_dids']??'')?></textarea><p class="help-block">One per line or comma-separated. Exact DIDs are recommended; <code>*</code> and <code>?</code> wildcards are supported.</p></div>
      <div class="form-group"><label>Default Outbound Identity</label><select class="form-control" name="default_identity_id"><option value="">None</option><?php foreach($identities as $i):?><option value="<?=$i['id']?>" <?=((int)($ea['default_identity_id']??0)===(int)$i['id'])?'selected':''?>><?=htmlspecialchars($i['name'])?><?=!empty($i['fax_number'])?' — '.htmlspecialchars($i['fax_number']):''?></option><?php endforeach;?></select></div>
      <div class="form-group"><label>Portal Password</label><input type="password" class="form-control" name="portal_password" minlength="8" <?=$editing?'':'required'?> autocomplete="new-password"><p class="help-block"><?=$editing?'Leave blank to keep the current password.':'At least 8 characters.'?> Users log in at <code>/fax/</code>.</p></div>
      <div class="checkbox"><label><input type="checkbox" name="portal_enabled" <?=(!$editing||!empty($ea['portal_enabled']))?'checked':''?>> User portal login enabled</label></div>
      <div class="checkbox"><label><input type="checkbox" name="can_send" <?=(!$editing||!empty($ea['can_send']))?'checked':''?>> Can send faxes</label></div>
      <div class="checkbox"><label><input type="checkbox" name="can_receive" <?=(!$editing||!empty($ea['can_receive']))?'checked':''?>> Can receive faxes</label></div>
      <div class="checkbox"><label><input type="checkbox" name="can_delete" <?=!empty($ea['can_delete'])?'checked':''?>> Can delete own fax records</label></div>
      <div class="checkbox"><label><input type="checkbox" name="can_view_all" <?=!empty($ea['can_view_all'])?'checked':''?>> Can view all fax records (manager/admin)</label></div>
      <button class="btn btn-primary"><i class="fa <?=$editing?'fa-save':'fa-user-plus'?>"></i> <?=$editing?'Save Changes':'Create Fax Account'?></button><?php if($editing):?> <a class="btn btn-default" href="config.php?display=tffax&view=accounts">Cancel</a><?php endif;?>
    </form>
  </div></div>
  <div class="col-md-7"><div class="tffax-panel"><h2>Configured Accounts</h2>
    <table class="table table-striped"><thead><tr><th>Account</th><th>DID(s)</th><th>Mailbox</th><th>Identity</th><th>Portal</th><th></th></tr></thead><tbody>
    <?php if(!$accounts):?><tr><td colspan="6" class="text-muted">No fax accounts configured.</td></tr><?php endif;?>
    <?php foreach($accounts as $a):?><tr><td><strong><?=htmlspecialchars($a['user_name'])?></strong><div class="small text-muted"><?=htmlspecialchars($a['email'])?></div></td><td><code><?=htmlspecialchars($a['inbound_dids']?:'—')?></code></td><td><?=htmlspecialchars($a['mailbox_name']?:'—')?></td><td><?=htmlspecialchars($a['default_identity_name']?:'—')?></td><td><?=!empty($a['portal_enabled'])?'<span class="label label-success">Enabled</span>':'<span class="label label-default">Disabled</span>'?></td><td><a class="btn btn-xs btn-default" href="config.php?display=tffax&view=accounts&edit=<?=(int)$a['id']?>">Edit</a> <form method="post" action="config.php?display=tffax&view=accounts" style="display:inline" onsubmit="return confirm('Delete this fax account and its managed mailbox/routing? Historical fax records will be retained.');"><input type="hidden" name="tffax_action" value="delete_account"><input type="hidden" name="id" value="<?=(int)$a['id']?>"><button class="btn btn-xs btn-danger">Delete</button></form></td></tr><?php endforeach;?>
    </tbody></table>
  </div></div>
</div>
<div class="tffax-panel"><h3>Advanced Setup</h3><p>For shared mailboxes or special routing, the original tools remain available: <a href="config.php?display=tffax&view=destinations">Inbound Mailboxes</a>, <a href="config.php?display=tffax&view=users">Fax Users</a>, and <a href="config.php?display=tffax&view=routing">Advanced Routing</a>.</p></div>
