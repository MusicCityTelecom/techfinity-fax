<?php
// Traditional module hook remains for broad compatibility with host releases that
// call module get_config hooks from functions.inc.php.
function tffax_get_config($engine) {
    global $ext;
    if (strtolower((string)$engine) !== 'asterisk') { return; }
    if (!isset($ext) || !is_object($ext)) { return; }
    $m = FreePBX::Tffax();

    // These contexts are entirely owned by Fax Platform.  Mark them no-custom
    // so generated dialplan does not warn about non-existent *-custom contexts.
    if (method_exists($ext,'set_no_custom')) { foreach (['tffax-router','tffax-inbound','tffax-tx'] as $c) { $ext->set_no_custom($c); } }

    $routes = $m->getRoutes();
    $destinations = $m->getDestinations();
    $spool = $m->getSetting('spool_dir', '/var/spool/asterisk/tffax');
    $tffaxBinDir = defined('AMPWEBROOT') ? rtrim(AMPWEBROOT,'/').'/admin/modules/tffax/bin' : '/var/www/html/admin/modules/tffax/bin';

    // Automatic DID/CID router. FreePBX routes the call here as an ordinary
    // module destination. Capture the original inbound metadata before we jump.
    $ext->add('tffax-router', 's', '', new ext_noop('Fax Platform automatic DID/CID router'));
    $ext->add('tffax-router', 's', '', new ext_set('TFFAX_DID', '${IF($["${FROM_DID}"!=""]?${FROM_DID}:${EXTEN})}'));
    $ext->add('tffax-router', 's', '', new ext_set('TFFAX_CID', '${CALLERID(num)}'));
    foreach ($routes as $r) {
        if (empty($r['enabled'])) { continue; }
        $cond = $m->routeExpression($r['did_pattern'], $r['cid_pattern']);
        $ext->add('tffax-router', 's', '', new ext_gotoif($cond, 'tffax-inbound,'.(int)$r['destination_id'].',1'));
    }
    // Never discard an unmatched fax. Store it in the virtual Unassigned Inbox.
    $ext->add('tffax-router', 's', '', new ext_goto('1', '0', 'tffax-inbound'));

    // Destination 0 is a virtual unassigned mailbox and always exists.
    $destinationRows = array_merge([['id'=>0,'identity_id'=>null,'enabled'=>1]], $destinations);
    foreach ($destinationRows as $d) {
        if ((int)$d['id'] !== 0 && empty($d['enabled'])) { continue; }
        $id = (int)$d['id'];
        $ctx = 'tffax-inbound';
        $tif = $spool . '/incoming/${UNIQUEID}.tif';
        $stationId = $id === 0 ? $m->getSetting('local_station_id','') : $m->getIdentityStationId($d['identity_id']);
        $ext->add($ctx, (string)$id, '', new ext_noop('Fax Platform inbound mailbox '.$id));
        $ext->add($ctx, (string)$id, '', new ext_set('TFFAX_DEST_ID', (string)$id));
        $ext->add($ctx, (string)$id, '', new ext_set('TFFAX_DID', '${IF($["${TFFAX_DID}"!=""]?${TFFAX_DID}:${FROM_DID})}'));
        $ext->add($ctx, (string)$id, '', new ext_set('TFFAX_CID', '${IF($["${TFFAX_CID}"!=""]?${TFFAX_CID}:${CALLERID(num)})}'));
        $ext->add($ctx, (string)$id, '', new ext_set('TFFAX_RX_FILE', $tif));
        $ext->add($ctx, (string)$id, '', new ext_set('FAXOPT(localstationid)', $m->escapeDialplan($stationId)));
        $ext->add($ctx, (string)$id, '', new ext_set('FAXOPT(ecm)', $m->getSetting('ecm','yes')));
        $ext->add($ctx, (string)$id, '', new ext_set('FAXOPT(minrate)', $m->getSetting('min_rate','4800')));
        $ext->add($ctx, (string)$id, '', new ext_set('FAXOPT(maxrate)', $m->getSetting('max_rate','14400')));
        $ext->add($ctx, (string)$id, '', new ext_receivefax('${TFFAX_RX_FILE}'));
        $ext->add($ctx, (string)$id, '', new ext_hangup());
    }
    $ext->add('tffax-inbound', 'h', '', new ext_noop('Fax Platform finalize inbound mailbox ${TFFAX_DEST_ID}'));
    $ext->add('tffax-inbound', 'h', '', new ext_system('/usr/bin/php -q '.escapeshellarg($tffaxBinDir.'/inbound.php').' --dest "${TFFAX_DEST_ID}" --file "${TFFAX_RX_FILE}" --did "${TFFAX_DID}" --caller "${TFFAX_CID}" --remote "${FAXOPT(remotestationid)}" --pages "${FAXOPT(pages)}" --rate "${FAXOPT(rate)}" --resolution "${FAXOPT(resolution)}" --ecm "${FAXOPT(ecm)}" --status "${FAXSTATUS}" --statusstr "${FAXOPT(statusstr)}" --error "${FAXERROR}"'));

    $mode = strtolower($m->getSetting('t38_mode','auto'));
    if ($mode === 'audio') { $sendOptions = 'F'; }
    elseif ($mode === 't38') { $sendOptions = 'z'; }
    elseif ($mode === 'prefer') { $sendOptions = 'zf'; }
    else { $sendOptions = 'f'; }

    $ext->add('tffax-tx', 's', '', new ext_noop('Fax Platform outbound job ${TFFAX_JOB_ID}'));
    $ext->add('tffax-tx', 's', '', new ext_set('FAXOPT(localstationid)', '${TFFAX_STATION_ID}'));
    $ext->add('tffax-tx', 's', '', new ext_set('FAXOPT(headerinfo)', '${TFFAX_HEADER}'));
    $ext->add('tffax-tx', 's', '', new ext_set('FAXOPT(ecm)', $m->getSetting('ecm','yes')));
    $ext->add('tffax-tx', 's', '', new ext_set('FAXOPT(minrate)', $m->getSetting('min_rate','4800')));
    $ext->add('tffax-tx', 's', '', new ext_set('FAXOPT(maxrate)', $m->getSetting('max_rate','14400')));
    $ext->add('tffax-tx', 's', '', new ext_system('/usr/bin/php -q '.escapeshellarg($tffaxBinDir.'/outbound-state.php').' --job "${TFFAX_JOB_ID}" --status sending'));
    $ext->add('tffax-tx', 's', '', new ext_sendfax('${TFFAX_FILE},'.$sendOptions));
    $ext->add('tffax-tx', 's', '', new ext_hangup());
    $ext->add('tffax-tx', 'h', '', new ext_noop('Fax Platform finalize outbound job ${TFFAX_JOB_ID}'));
    $ext->add('tffax-tx', 'h', '', new ext_system('/usr/bin/php -q '.escapeshellarg($tffaxBinDir.'/outbound-result.php').' --job "${TFFAX_JOB_ID}" --status "${FAXSTATUS}" --statusstr "${FAXOPT(statusstr)}" --error "${FAXERROR}" --remote "${FAXOPT(remotestationid)}" --pages "${FAXOPT(pages)}" --rate "${FAXOPT(rate)}" --resolution "${FAXOPT(resolution)}" --mode "'.$mode.'" --ecm "${FAXOPT(ecm)}"'));
}
function tffax_hookGet_config($engine) { return tffax_get_config($engine); }
