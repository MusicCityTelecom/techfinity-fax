<?php
namespace FreePBX\modules;
use BMO;
use FreePBX_Helpers;

require_once __DIR__.'/TffaxTrait1.php';
require_once __DIR__.'/TffaxTrait2.php';
require_once __DIR__.'/TffaxTrait3.php';
require_once __DIR__.'/TffaxTrait4.php';
require_once __DIR__.'/TffaxTrait5.php';

class Tffax extends FreePBX_Helpers implements BMO {
    use TffaxTrait1, TffaxTrait2, TffaxTrait3, TffaxTrait4, TffaxTrait5;

    private $hostApp;
    private $db;
    private $astman;

    // Process module forms from page.tffax.php instead of relying on the
    // Host config-page init hook.  This is important for multipart/form-data
    // uploads and also avoids colliding with the host's own generic `action`
    // request parameter.
}
