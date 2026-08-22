<div class="row">
  <div class="col-md-5">
    <div class="tffax-panel">
      <h2>Add Fax User</h2>
      <p class="text-muted">Fax Platform keeps its own permission map. This does not create, delete, or modify accounts in the system account manager or any other custom module.</p>
      <form method="post" action="config.php?display=tffax&view=users">
        <input type="hidden" name="tffax_action" value="save_user">
        <div class="form-group"><label>User Name</label><input class="form-control" name="user_name" required placeholder="frontdesk"></div>
        <div class="form-group"><label>Email</label><input type="email" class="form-control" name="email" placeholder="frontdesk@example.com"></div>
        <div class="form-group"><label>Default Fax Identity</label><select class="form-control" name="default_identity_id"><option value="">None</option><?php foreach($identities as $i):?><option value="<?=$i['id']?>"><?=htmlspecialchars($i['name'])?></option><?php endforeach;?></select></div>
        <div class="checkbox"><label><input type="checkbox" name="can_send" checked> Can send fax</label></div>
        <div class="checkbox"><label><input type="checkbox" name="can_receive" checked> Can receive/own inbound fax</label></div>
        <div class="checkbox"><label><input type="checkbox" name="can_view_all"> Can view all fax mailboxes</label></div>
        <div class="checkbox"><label><input type="checkbox" name="can_delete"> Can delete fax records</label></div>
        <button class="btn btn-primary">Save Fax User</button>
      </form>
    </div>
  </div>
  <div class="col-md-7">
    <div class="tffax-panel">
      <h2>Fax Users</h2>
      <table class="table table-striped"><thead><tr><th>User</th><th>Email</th><th>Permissions</th><th>Default Identity</th><th></th></tr></thead><tbody>
      <?php if(!$users):?><tr><td colspan="5" class="text-muted">No Fax Platform users configured yet.</td></tr><?php endif;?>
      <?php foreach($users as $u):?><tr>
        <td><?=htmlspecialchars($u['user_name'])?></td><td><?=htmlspecialchars($u['email'])?></td>
        <td><span class="small"><?php $perms=[]; if($u['can_send'])$perms[]='Send'; if($u['can_receive'])$perms[]='Receive'; if($u['can_view_all'])$perms[]='View all'; if($u['can_delete'])$perms[]='Delete'; echo htmlspecialchars(implode(', ',$perms)?:'None'); ?></span></td>
        <td><?=htmlspecialchars($u['default_identity_name']?:'—')?></td>
        <td><form method="post" action="config.php?display=tffax&view=users" onsubmit="return confirm('Remove this user from Fax Platform? This will not delete the system account itself.');"><input type="hidden" name="tffax_action" value="delete_user"><input type="hidden" name="id" value="<?=$u['id']?>"><button class="btn btn-xs btn-danger">Delete</button></form></td>
      </tr><?php endforeach;?></tbody></table>
    </div>
  </div>
</div>
