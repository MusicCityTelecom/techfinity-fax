# TechFinity Fax for FreePBX 16

TechFinity Fax is an open-source multi-user fax management module for FreePBX 16. It uses Asterisk `res_fax` / `res_fax_spandsp` for fax transport and FreePBX User Management for user authentication.

## User portal

After installation, users access the fax portal at:

```text
https://PBX-HOSTNAME-OR-IP/fax/
```

For example, if the FreePBX administration interface is `https://pbx.example.com/admin/`, the fax portal is `https://pbx.example.com/fax/`. The module also displays the exact portal URL on **Applications > TechFinity Fax > Fax Accounts**.

Users sign in with their existing **FreePBX User Management** username and password. TechFinity Fax does not create, store, verify, reset, or change a second fax password. Administrators assign fax access and permissions to an existing User Management account.

Password changes and recovery remain under FreePBX User Management / UCP. Removing a fax account mapping does not delete the FreePBX user.

## Features

- FreePBX User Management authentication
- Per-user fax authorization and permissions
- Inbound fax mailboxes with DID/CID routing
- Inbound fax email notifications and PDF delivery
- Outbound PDF/image fax submission
- Per-user outbound identity and caller-ID selection
- Fax history, preview and download
- Personal and global cover pages
- Per-user sender/company profiles
- Test-fax workflow
- Timezone-aware display and notification timestamps
- Diagnostics and configurable fax engine settings

## Requirements

- FreePBX 16
- FreePBX User Management (`userman`)
- Asterisk with `res_fax` and a fax technology module such as `res_fax_spandsp`
- Ghostscript and TIFF/PDF utilities used by the module's document conversion workflow

## Installation

Install the module using the normal FreePBX module upload/install workflow, then run **Apply Config**. Configure fax identities, create Fax Accounts mapped to existing User Management users, and route inbound DIDs to the generated TechFinity Fax destinations.

The public portal wrapper is installed at `/var/www/html/fax/index.php` and loads the module application from `/var/www/html/admin/modules/tffax/portal.php`.

## Authentication design

Authentication is delegated to the installed FreePBX User Management module using its public module API. The fax module stores only the User Management numeric user ID, username cache, fax permissions, profile information, mailbox assignments and fax records.

Older 0.4.x installations are migrated by matching existing fax usernames to FreePBX User Management usernames. Legacy module password hashes, if present from an older installation, are not used by the v16 branch.

## Data and paths

- Module ID: `tffax`
- Database tables: `tffax_*`
- Default fax spool: `/var/spool/asterisk/tffax`
- User portal: `/fax/`
- Administration: `/admin/config.php?display=tffax`

## License

Copyright (C) 2026 TechFinity Inc. / Robert Thomas Heggie

This project is licensed under the GNU General Public License, version 3 or (at your option) any later version. See `LICENSE`.

FreePBX is a registered trademark of Sangoma Technologies, Inc. TechFinity Fax is an independent open-source project and is not affiliated with or endorsed by Sangoma Technologies.