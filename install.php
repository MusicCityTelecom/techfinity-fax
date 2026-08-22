<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$hostApp = FreePBX::Create();
// Do not replace the host framework legacy database handle used by module installation.
$tffaxDb = $hostApp->Database;
$sql = [
"CREATE TABLE IF NOT EXISTS tffax_settings (`key` VARCHAR(64) NOT NULL, `value` TEXT NULL, PRIMARY KEY (`key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS tffax_identities (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL, `fax_number` VARCHAR(32) NOT NULL DEFAULT '', `station_id` VARCHAR(64) NOT NULL DEFAULT '', `header_text` VARCHAR(128) NOT NULL DEFAULT '', `email` VARCHAR(190) NOT NULL DEFAULT '', `outbound_cid` VARCHAR(64) NOT NULL DEFAULT '', `enabled` TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (`id`), KEY `enabled` (`enabled`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS tffax_destinations (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL, `identity_id` INT UNSIGNED NULL, `email_to` TEXT NULL, `attach_format` VARCHAR(8) NOT NULL DEFAULT 'pdf', `keep_copy` TINYINT(1) NOT NULL DEFAULT 1, `enabled` TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (`id`), KEY `identity_id` (`identity_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS tffax_jobs (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `uuid` CHAR(36) NOT NULL, `direction` ENUM('inbound','outbound') NOT NULL, `destination_id` INT UNSIGNED NULL, `identity_id` INT UNSIGNED NULL, `user_name` VARCHAR(100) NOT NULL DEFAULT '', `source_number` VARCHAR(64) NOT NULL DEFAULT '', `destination_number` VARCHAR(64) NOT NULL DEFAULT '', `recipient_name` VARCHAR(128) NOT NULL DEFAULT '', `recipient_company` VARCHAR(128) NOT NULL DEFAULT '', `subject` VARCHAR(190) NOT NULL DEFAULT '', `notes` TEXT NULL, `coverpage_id` INT UNSIGNED NULL, `remote_station_id` VARCHAR(128) NOT NULL DEFAULT '', `local_station_id` VARCHAR(128) NOT NULL DEFAULT '', `status` VARCHAR(32) NOT NULL DEFAULT 'queued', `status_code` VARCHAR(64) NOT NULL DEFAULT '', `status_text` TEXT NULL, `pages` INT UNSIGNED NOT NULL DEFAULT 0, `attempts` INT UNSIGNED NOT NULL DEFAULT 0, `max_attempts` INT UNSIGNED NOT NULL DEFAULT 3, `transport` VARCHAR(32) NOT NULL DEFAULT '', `ecm` VARCHAR(16) NOT NULL DEFAULT '', `bitrate` VARCHAR(16) NOT NULL DEFAULT '', `resolution` VARCHAR(32) NOT NULL DEFAULT '', `document_path` TEXT NULL, `cover_path` TEXT NULL, `tiff_path` TEXT NULL, `pdf_path` TEXT NULL, `created_at` DATETIME NOT NULL, `started_at` DATETIME NULL, `completed_at` DATETIME NULL, PRIMARY KEY (`id`), UNIQUE KEY `uuid` (`uuid`), KEY `status` (`status`), KEY `created_at` (`created_at`), KEY `direction` (`direction`), KEY `user_name` (`user_name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS tffax_events (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `job_id` BIGINT UNSIGNED NOT NULL, `event_type` VARCHAR(64) NOT NULL, `message` TEXT NULL, `details` LONGTEXT NULL, `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), KEY `job_id` (`job_id`), KEY `created_at` (`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS tffax_users (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `user_name` VARCHAR(100) NOT NULL, `can_send` TINYINT(1) NOT NULL DEFAULT 1, `can_receive` TINYINT(1) NOT NULL DEFAULT 1, `can_view_all` TINYINT(1) NOT NULL DEFAULT 0, `can_delete` TINYINT(1) NOT NULL DEFAULT 0, `default_identity_id` INT UNSIGNED NULL, `email` VARCHAR(190) NOT NULL DEFAULT '', PRIMARY KEY (`id`), UNIQUE KEY `user_name` (`user_name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS tffax_coverpages (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL, `template_html` LONGTEXT NULL, `enabled` TINYINT(1) NOT NULL DEFAULT 1, `owner_user_id` INT UNSIGNED NULL, PRIMARY KEY (`id`), KEY `owner_user_id` (`owner_user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS tffax_destination_users (`destination_id` INT UNSIGNED NOT NULL, `user_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`destination_id`,`user_id`), KEY `user_id` (`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS tffax_routes (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `priority` INT UNSIGNED NOT NULL DEFAULT 100, `did_pattern` VARCHAR(64) NOT NULL DEFAULT '', `cid_pattern` VARCHAR(64) NOT NULL DEFAULT '', `destination_id` INT UNSIGNED NOT NULL, `description` VARCHAR(190) NOT NULL DEFAULT '', `enabled` TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (`id`), KEY `priority` (`priority`), KEY `destination_id` (`destination_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];
foreach ($sql as $q) { $tffaxDb->exec($q); }
// Idempotent upgrades from earlier Fax Platform builds.
$tffaxColumns = [
 'recipient_name' => "ALTER TABLE tffax_jobs ADD COLUMN recipient_name VARCHAR(128) NOT NULL DEFAULT '' AFTER destination_number",
 'recipient_company' => "ALTER TABLE tffax_jobs ADD COLUMN recipient_company VARCHAR(128) NOT NULL DEFAULT '' AFTER recipient_name",
 'subject' => "ALTER TABLE tffax_jobs ADD COLUMN subject VARCHAR(190) NOT NULL DEFAULT '' AFTER recipient_company",
 'notes' => "ALTER TABLE tffax_jobs ADD COLUMN notes TEXT NULL AFTER subject",
 'coverpage_id' => "ALTER TABLE tffax_jobs ADD COLUMN coverpage_id INT UNSIGNED NULL AFTER notes",
 'cover_path' => "ALTER TABLE tffax_jobs ADD COLUMN cover_path TEXT NULL AFTER document_path"
];
foreach ($tffaxColumns as $col=>$alter) {
  $stc=$tffaxDb->prepare("SHOW COLUMNS FROM tffax_jobs LIKE ?"); $stc->execute([$col]);
  if (!$stc->fetch()) { $tffaxDb->exec($alter); }
}
try { $tffaxDb->exec("ALTER TABLE tffax_jobs ADD KEY user_name (user_name)"); } catch (\Throwable $e) {}
// 0.3.0 unified Fax Account + standalone user portal upgrades.
$tffaxUserCols = [
 'password_hash' => "ALTER TABLE tffax_users ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER email",
 'portal_enabled' => "ALTER TABLE tffax_users ADD COLUMN portal_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash",
 'primary_destination_id' => "ALTER TABLE tffax_users ADD COLUMN primary_destination_id INT UNSIGNED NULL AFTER default_identity_id"
];
foreach ($tffaxUserCols as $col=>$alter) { $stc=$tffaxDb->prepare("SHOW COLUMNS FROM tffax_users LIKE ?"); $stc->execute([$col]); if(!$stc->fetch()){$tffaxDb->exec($alter);} }
// 0.4.0 user portal preferences and personal cover pages.
$tffaxUserPrefs = [
 'notify_inbound' => "ALTER TABLE tffax_users ADD COLUMN notify_inbound TINYINT(1) NOT NULL DEFAULT 1 AFTER portal_enabled",
 'notify_success' => "ALTER TABLE tffax_users ADD COLUMN notify_success TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_inbound",
 'notify_failure' => "ALTER TABLE tffax_users ADD COLUMN notify_failure TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_success",
 'preview_before_send' => "ALTER TABLE tffax_users ADD COLUMN preview_before_send TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_failure"
];
foreach ($tffaxUserPrefs as $col=>$alter) { $stc=$tffaxDb->prepare("SHOW COLUMNS FROM tffax_users LIKE ?"); $stc->execute([$col]); if(!$stc->fetch()){$tffaxDb->exec($alter);} }
// 0.4.1 per-user sender/company profile. preview_before_send is retained only for backwards compatibility; preview is now always available as an explicit action.
$tffaxProfileCols = [
 'full_name' => "ALTER TABLE tffax_users ADD COLUMN full_name VARCHAR(128) NOT NULL DEFAULT '' AFTER email",
 'company_name' => "ALTER TABLE tffax_users ADD COLUMN company_name VARCHAR(190) NOT NULL DEFAULT '' AFTER full_name",
 'phone_number' => "ALTER TABLE tffax_users ADD COLUMN phone_number VARCHAR(64) NOT NULL DEFAULT '' AFTER company_name",
 'fax_number' => "ALTER TABLE tffax_users ADD COLUMN fax_number VARCHAR(64) NOT NULL DEFAULT '' AFTER phone_number",
 'address1' => "ALTER TABLE tffax_users ADD COLUMN address1 VARCHAR(190) NOT NULL DEFAULT '' AFTER fax_number",
 'address2' => "ALTER TABLE tffax_users ADD COLUMN address2 VARCHAR(190) NOT NULL DEFAULT '' AFTER address1",
 'city' => "ALTER TABLE tffax_users ADD COLUMN city VARCHAR(100) NOT NULL DEFAULT '' AFTER address2",
 'state' => "ALTER TABLE tffax_users ADD COLUMN state VARCHAR(64) NOT NULL DEFAULT '' AFTER city",
 'postal_code' => "ALTER TABLE tffax_users ADD COLUMN postal_code VARCHAR(32) NOT NULL DEFAULT '' AFTER state",
 'website' => "ALTER TABLE tffax_users ADD COLUMN website VARCHAR(190) NOT NULL DEFAULT '' AFTER postal_code"
];
foreach ($tffaxProfileCols as $col=>$alter) { $stc=$tffaxDb->prepare("SHOW COLUMNS FROM tffax_users LIKE ?"); $stc->execute([$col]); if(!$stc->fetch()){$tffaxDb->exec($alter);} }
$stc=$tffaxDb->prepare("SHOW COLUMNS FROM tffax_coverpages LIKE ?");$stc->execute(['template_style']);if(!$stc->fetch()){$tffaxDb->exec("ALTER TABLE tffax_coverpages ADD COLUMN template_style VARCHAR(32) NOT NULL DEFAULT 'professional' AFTER template_html");}
$stc=$tffaxDb->prepare("SHOW COLUMNS FROM tffax_coverpages LIKE ?");$stc->execute(['owner_user_id']);if(!$stc->fetch()){$tffaxDb->exec("ALTER TABLE tffax_coverpages ADD COLUMN owner_user_id INT UNSIGNED NULL AFTER enabled, ADD KEY owner_user_id (owner_user_id)");}
// Older unified accounts copied the user email into destination.email_to. Clear only exact account-owned duplicates so the user's notify_inbound preference controls delivery.
try{$tffaxDb->exec("UPDATE tffax_destinations d JOIN tffax_users u ON u.primary_destination_id=d.id SET d.email_to='' WHERE d.email_to<>'' AND d.email_to=u.email");}catch(\Throwable $e){}
$stc=$tffaxDb->prepare("SHOW COLUMNS FROM tffax_routes LIKE ?");$stc->execute(['managed_by_account']);if(!$stc->fetch()){$tffaxDb->exec("ALTER TABLE tffax_routes ADD COLUMN managed_by_account TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled");}

$defaults = [
 'spool_dir' => '/var/spool/asterisk/tffax',
 'local_station_id' => '', 'header_text' => 'Fax Platform', 'ecm' => 'yes',
 'min_rate' => '4800', 'max_rate' => '14400', 'max_attempts' => '3',
 'retain_days' => '365', 'outbound_context' => 'from-internal', 't38_mode' => 'auto',
 'email_from' => '', 'timezone' => 'America/Chicago', 'ui_theme' => 'refined',
 'email_tpl_inbound_subject' => 'Inbound fax from {{sender}}',
 'email_tpl_inbound_body' => "A new inbound fax was received.\n\nFrom: {{from_number}}\nTo DID: {{to_number}}\nMailbox: {{destination_name}}\nPages: {{pages}}\nRemote station: {{remote_station_id}}\nReceived: {{received_at}}\n",
 'email_tpl_success_subject' => 'Fax sent successfully to {{to_number}}',
 'email_tpl_success_body' => "Your fax was transmitted successfully.\n\nTo: {{to_number}}\nStatus: {{status_text}}\nPages: {{pages}}\nRemote station: {{remote_station_id}}\nCompleted: {{completed_at}}\nJob: #{{job_id}}\n",
 'email_tpl_failure_subject' => 'Fax failed to {{to_number}}',
 'email_tpl_failure_body' => "Your fax transmission failed.\n\nTo: {{to_number}}\nStatus: {{status_text}}\nPages: {{pages}}\nRemote station: {{remote_station_id}}\nCompleted: {{completed_at}}\nJob: #{{job_id}}\n"
];
$st = $tffaxDb->prepare("INSERT IGNORE INTO tffax_settings (`key`,`value`) VALUES (?,?)");
foreach ($defaults as $k=>$v) { $st->execute([$k,$v]); }
// Upgrade the original plain 0.4.0 global template into the new Professional preset when possible.
$tffaxMigratedLegacyCover=0;
try{
  $pro=$tffaxDb->prepare("SELECT id FROM tffax_coverpages WHERE owner_user_id IS NULL AND name='Professional' LIMIT 1");$pro->execute();
  if(!$pro->fetchColumn()){
    $std=$tffaxDb->prepare("SELECT id FROM tffax_coverpages WHERE owner_user_id IS NULL AND name='Standard Cover Page' LIMIT 1");$std->execute();$stdId=(int)$std->fetchColumn();
    if($stdId>0){$tffaxDb->prepare("UPDATE tffax_coverpages SET name='Professional',template_style='professional' WHERE id=?")->execute([$stdId]);$tffaxMigratedLegacyCover=$stdId;}
  }
}catch(\Throwable $e){}
// Built-in editable cover-page presets. Add missing presets on upgrade without disturbing user-created templates.
$tffaxCoverPresets = [
 ['Professional','professional',"TO: {{to_name}}\nCOMPANY: {{to_company}}\nFAX: {{to_number}}\n\nFROM: {{from_name}}\nCOMPANY: {{from_company}}\nPHONE: {{from_phone}}\nFAX: {{from_fax}}\nEMAIL: {{from_email}}\nADDRESS: {{from_address}}\nWEBSITE: {{from_website}}\n\nSUBJECT: {{subject}}\nDATE: {{date}}\n\nMESSAGE:\n{{message}}"],
 ['Classic Business','classic',"TO: {{to_name}}\nFAX: {{to_number}}\nCOMPANY: {{to_company}}\n\nFROM: {{from_name}}\nFAX: {{from_fax}}\nPHONE: {{from_phone}}\nCOMPANY: {{from_company}}\n\nRE: {{subject}}\nDATE: {{date}}\n\nCOMMENTS:\n{{message}}"],
 ['Clean & Simple','minimal',"TO: {{to_name}} · {{to_company}}\nFAX: {{to_number}}\n\nFROM: {{from_name}} · {{from_company}}\nPHONE: {{from_phone}} · FAX: {{from_fax}}\nEMAIL: {{from_email}}\n\nSUBJECT: {{subject}}\nDATE: {{date}}\n\n{{message}}"]
];
foreach($tffaxCoverPresets as $preset){
  [$pn,$ps,$pt]=$preset;
  $chk=$tffaxDb->prepare("SELECT id FROM tffax_coverpages WHERE owner_user_id IS NULL AND name=? LIMIT 1");$chk->execute([$pn]);
  if(!$chk->fetchColumn()){$cp=$tffaxDb->prepare("INSERT INTO tffax_coverpages (name,template_html,template_style,enabled,owner_user_id) VALUES (?,?,?,1,NULL)");$cp->execute([$pn,$pt,$ps]);}
  elseif($pn==='Professional' && $tffaxMigratedLegacyCover>0){$tffaxDb->prepare("UPDATE tffax_coverpages SET template_html=?,template_style='professional' WHERE id=?")->execute([$pt,$tffaxMigratedLegacyCover]);}
}
$spool = '/var/spool/asterisk/tffax';
foreach (['incoming','outgoing','queue','done','failed','tmp'] as $d) {
  $p = $spool.'/'.$d;
  if (!is_dir($p)) { @mkdir($p, 0770, true); }
  @chown($p, 'asterisk'); @chgrp($p, 'asterisk'); @chmod($p, 0770);
}


// 0.3.1: Publish the standalone fax portal outside /admin so the web administration layer
// admin-directory access controls do not block non-admin users. This mirrors
// a standalone portal deployment pattern.
$tffaxPublicDir = '/var/www/html/fax';
if (!is_dir($tffaxPublicDir)) { @mkdir($tffaxPublicDir, 0755, true); }
$tffaxPortalWrapper = <<<'PHP'
<?php
// Fax Platform standalone portal front controller.
// The application itself remains inside the module and is loaded here
// so /fax/ is reachable without traversing the protected /admin/modules path.
require '/var/www/html/admin/modules/tffax/portal.php';
PHP;
@file_put_contents($tffaxPublicDir.'/index.php', $tffaxPortalWrapper."\n");
@chmod($tffaxPublicDir, 0755);
@chmod($tffaxPublicDir.'/index.php', 0644);
