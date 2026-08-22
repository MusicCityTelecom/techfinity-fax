<div class="tffax-cards">
<?php foreach(['inbound'=>'Inbound Faxes','outbound'=>'Outbound Faxes','queued'=>'Active / Queued','failed'=>'Failed'] as $k=>$l): ?><div class="tffax-card"><span><?=htmlspecialchars($l)?></span><strong><?= (int)$stats[$k] ?></strong></div><?php endforeach; ?>
</div>
<div class="tffax-panel"><div class="panel-title"><h2>Recent Fax Activity</h2><a class="btn btn-default" href="config.php?display=tffax&view=history">View All</a></div><?php $jobs=$jobs; include __DIR__.'/jobs_table.php'; ?></div>
