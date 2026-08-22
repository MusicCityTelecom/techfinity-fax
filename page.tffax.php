<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$request = $_REQUEST;
$view = isset($request['view']) ? preg_replace('/[^a-z_]/','', $request['view']) : 'dashboard';
$allowed = ['dashboard','send','history','accounts','identities','coverpages','destinations','users','routing','settings','diagnostics'];
if (!in_array($view, $allowed, true)) { $view = 'dashboard'; }
$mod = FreePBX::Tffax();
if (!empty($_GET['tffax_file']) && !empty($_GET['id'])) { $mod->serveJobFile((int)$_GET['id'], $_GET['tffax_file']==='download'); }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $mod->processPost($_POST, $_FILES);
}
echo $mod->render($view, $_REQUEST);
