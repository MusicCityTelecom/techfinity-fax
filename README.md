# TechFinity Fax

TechFinity Fax is an open-source multi-user fax platform module for FreePBX 16 and 17. It uses Asterisk `res_fax` / `res_fax_spandsp` for fax transport and FreePBX User Management for end-user authentication.

**Current public version:** 16.0.1  
**Author:** Robert Thomas Heggie (Tommy)  
**Email:** tommy@techfinity.tech  
**License:** GPL-3.0-or-later

## Release downloads

The first public release artifacts are stored under `releases/16.0.1/`:

- `tffax-16.0.1.tar.gz` — Module Admin installation package
- `tffax-16.0.1-src.zip` — complete source archive
- `tffax-16.0.1-SHA256SUMS.txt` — SHA-256 checksums

## User portal

After installation, users access the fax portal at:

```text
https://PBX-HOSTNAME-OR-IP/fax/
```

Users sign in with their existing **FreePBX User Management** username and password. TechFinity Fax does not create, store, verify, reset, or change a second fax password. Administrators grant fax access and permissions to an existing User Management account.

## Features

- User Management authentication
- Per-user fax authorization and permissions
- Inbound fax mailboxes with DID/CID routing
- Automatic DID normalization for common 10-digit, 11-digit and `+1` NANP formats
- Unassigned Inbox fallback for unmatched inbound fax calls
- Inbound fax email notifications and PDF delivery
- Outbound PDF/image fax submission
- Per-user outbound identity and caller-ID selection
- Fax history, preview and download
- Cover page templates
- Administrator-editable email templates for inbound, outbound success and outbound failure
- Timezone-aware display and notifications; default `America/Chicago`
- T.38 / G.711 fax transport policy controls
- Diagnostics and configurable fax-engine settings
- Default FreePBX/Bootstrap styling in the administration interface

## Requirements

- FreePBX 16 or 17
- FreePBX User Management (`userman`)
- Asterisk with `res_fax` and a fax technology module such as `res_fax_spandsp`
- Ghostscript and TIFF/PDF utilities used by the document conversion workflow

Before production use, verify:

```bash
asterisk -rx 'module show like fax'
asterisk -rx 'core show application SendFAX'
asterisk -rx 'core show application ReceiveFAX'
command -v gs tiff2pdf tiffinfo
```

## Installation through Module Admin

1. Download `releases/16.0.1/tffax-16.0.1.tar.gz`.
2. Open **Admin > Module Admin**.
3. Choose **Upload Modules** and upload the tarball.
4. Install/enable **TechFinity Fax**.
5. Click **Apply Config**.
6. Open **Applications > TechFinity Fax > Diagnostics** and resolve any failed checks before production use.

The module declares User Management as a module dependency. Its installer creates only `tffax_*` database tables, the module-owned fax spool, the module dialplan contexts, and the `/fax/` portal wrapper.

## Basic configuration

1. Create one or more **Fax Identities**.
2. Map existing User Management accounts under **Fax Users**.
3. Create **Inbound Mailboxes** and assign users.
4. Route a dedicated inbound DID directly to a mailbox, or point an inbound route to **TechFinity Fax > Automatic DID/CID Router** and create routing rules.
5. Configure **Settings**, including T.38 policy, rates, timezone and notification sender.
6. Customize notification text under **Email Templates**.
7. Apply configuration after routing, mailbox, identity, or fax-engine changes.

## Data and paths

- Module ID: `tffax`
- Database tables: `tffax_*`
- Default fax spool: `/var/spool/asterisk/tffax`
- User portal: `/fax/`
- Administration: `/admin/config.php?display=tffax`
- Dialplan contexts: `tffax-router`, `tffax-inbound`, `tffax-tx`

TechFinity Fax does not intentionally modify unrelated modules or their database tables.

## License and trademarks

Copyright (C) 2026 Robert Thomas Heggie / TechFinity Communications LLC.

This project is licensed under the GNU General Public License version 3 or, at your option, any later version. See `LICENSE` in the source package.

FreePBX and Sangoma are trademarks of their respective owners. TechFinity Fax is an independent open-source project and is not affiliated with or endorsed by Sangoma Technologies.
