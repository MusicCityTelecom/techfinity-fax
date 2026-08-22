<div class="row"><div class="col-lg-5"><div class="tffax-panel"><h2>Cover Pages</h2><p class="text-muted">Templates are rendered to PDF and prepended to the fax document.</p>
<?php foreach($coverpages as $c): ?><div class="tffax-cover-row"><strong><?=htmlspecialchars($c['name'])?></strong> <span class="label label-<?=$c['enabled']?'success':'default'?>"><?=$c['enabled']?'Enabled':'Disabled'?></span><pre><?=htmlspecialchars($c['template_html'])?></pre><form method="post" action="config.php?display=tffax&view=coverpages" onsubmit="return confirm('Delete this cover page?');"><input type="hidden" name="tffax_action" value="delete_coverpage"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn btn-xs btn-danger">Delete</button></form></div><?php endforeach; ?>
</div></div><div class="col-lg-7"><div class="tffax-panel"><h2>Add Cover Page</h2><form method="post" action="config.php?display=tffax&view=coverpages"><input type="hidden" name="tffax_action" value="save_coverpage"><div class="form-group"><label>Name</label><input class="form-control" name="name" required></div><div class="form-group"><label>Template</label><textarea class="form-control" name="template_html" rows="18" required>{{from_name}}
{{from_company}}
Fax: {{from_number}}

TO: {{to_name}}
{{to_company}}
Fax: {{to_number}}

SUBJECT: {{subject}}
DATE: {{date}}

{{message}}</textarea><p class="help-block">Available variables: {{from_name}}, {{from_company}}, {{from_number}}, {{to_name}}, {{to_company}}, {{to_number}}, {{subject}}, {{date}}, {{message}}</p></div><label><input type="checkbox" name="enabled" checked> Enabled</label><br><br><button class="btn btn-primary">Save Cover Page</button></form></div></div></div>
