<div class="tffax-panel">
  <h2>Automatic DID / Caller ID Routing</h2>
  <p>Point one or more <strong>Inbound Routes</strong> to <strong>Fax Platform → Automatic DID/CID Router</strong>. Fax Platform will preserve the original DID and Caller ID, evaluate the rules below in priority order, and deliver the call to the matching fax mailbox. Unmatched calls are received into the <strong>Unassigned Inbox</strong> instead of being discarded.</p>
  <div class="alert alert-info"><strong>Safe integration:</strong> this router uses only <code>tffax-*</code> dialplan contexts and the standard destination hook. It does not rewrite Core inbound routes, other modules' destinations, or shared dialplan contexts.</div>
</div>
<div class="row">
  <div class="col-md-5"><div class="tffax-panel"><h2>Add Routing Rule</h2>
    <form method="post" action="config.php?display=tffax&view=routing"><input type="hidden" name="tffax_action" value="save_route">
      <div class="row"><div class="col-sm-4 form-group"><label>Priority</label><input type="number" min="1" max="9999" class="form-control" name="priority" value="100"></div><div class="col-sm-8 form-group"><label>Mailbox</label><select class="form-control" name="destination_id" required><option value="">Select...</option><?php foreach($destinations as $d): if(!$d['enabled'])continue;?><option value="<?=$d['id']?>"><?=htmlspecialchars($d['name'])?></option><?php endforeach;?></select></div></div>
      <div class="form-group"><label>DID Pattern</label><input class="form-control" name="did_pattern" placeholder="16155551001"><p class="help-block">Exact DID or wildcard, e.g. <code>16155551*</code>. Leave blank or <code>*</code> for any DID.</p></div>
      <div class="form-group"><label>Caller ID Pattern</label><input class="form-control" name="cid_pattern" placeholder="*"><p class="help-block">Optional. Exact Caller ID or wildcard, e.g. <code>1800555*</code>. DID should normally be the primary routing key.</p></div>
      <div class="form-group"><label>Description</label><input class="form-control" name="description" placeholder="Accounting dedicated fax DID"></div>
      <div class="checkbox"><label><input type="checkbox" name="enabled" checked> Enabled</label></div>
      <button class="btn btn-primary">Save Rule</button>
    </form>
  </div></div>
  <div class="col-md-7"><div class="tffax-panel"><h2>Routing Rules</h2>
    <table class="table table-striped"><thead><tr><th>Pri</th><th>DID</th><th>Caller ID</th><th>Mailbox</th><th>Description</th><th></th></tr></thead><tbody>
    <?php if(!$routes):?><tr><td colspan="6" class="text-muted">No automatic routing rules configured. Direct mailbox destinations can still be selected from Inbound Routes.</td></tr><?php endif;?>
    <?php foreach($routes as $r):?><tr class="<?=$r['enabled']?'':'text-muted'?>"><td><?=$r['priority']?></td><td><code><?=htmlspecialchars($r['did_pattern']?:'*')?></code></td><td><code><?=htmlspecialchars($r['cid_pattern']?:'*')?></code></td><td><?=htmlspecialchars($r['destination_name']?:'Missing mailbox')?></td><td><?=htmlspecialchars($r['description'])?></td><td><form method="post" action="config.php?display=tffax&view=routing" onsubmit="return confirm('Delete this routing rule?');"><input type="hidden" name="tffax_action" value="delete_route"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn btn-xs btn-danger">Delete</button></form></td></tr><?php endforeach;?></tbody></table>
  </div></div>
</div>
