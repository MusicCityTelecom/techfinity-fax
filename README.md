# Fax Platform for FreePBX

Fax Platform is an open-source multi-user fax management module for FreePBX 16 and 17. It provides inbound and outbound faxing through Asterisk `res_fax`, DID/CID-based routing, per-user fax accounts, email delivery and notifications, cover pages, history, diagnostics, and a standalone `/fax/` user portal.

**Version:** 16.0.2  
**Author:** Tommy Heggie  
**Email:** tommy@techfinity.tech  
**License:** GPL-3.0-or-later

## Features

- Multi-user fax accounts and permissions
- Standalone `/fax/` user portal
- Dedicated inbound fax mailboxes
- Automatic DID/CID routing with unassigned-inbox fallback
- Inbound fax email delivery with PDF attachment
- Outbound fax submission and status tracking
- Cover-page-only faxing
- Editable global and personal cover-page templates
- Live outbound and cover-page preview
- Per-user sender/company/contact information
- Per-user outbound fax number / caller-ID request
- Email notifications for inbound, success, and failure events
- Administrator-editable notification templates
- Configurable timezone; default `America/Chicago`
- T.38/G.711 fax transport policy controls
- Fax diagnostics and dependency checks
- Classic and Refined interface themes

## Requirements

- FreePBX 16 or 17
- Asterisk with `res_fax`
- A fax technology module such as `res_fax_spandsp`
- Ghostscript (`gs`)
- TIFF utilities including `tiff2pdf` and `tiffinfo`
- A working outbound route/trunk for fax destinations
- Inbound DIDs routed to the module for inbound fax reception

Verify the fax engine before production use:

```bash
asterisk -rx 'module show like fax'
asterisk -rx 'core show application SendFAX'
asterisk -rx 'core show application ReceiveFAX'
command -v gs tiff2pdf tiffinfo
```

## Installation

### Module Admin

1. Download the release tarball `tffax-16.0.2.tar.gz`.
2. Open **Admin > Module Admin**.
3. Select **Upload Modules** and upload the tarball.
4. Install and enable **Fax Platform**.
5. Click **Apply Config**.
6. Open **Applications > Fax Platform > Diagnostics** and resolve any failed checks.

### CLI

```bash
fwconsole ma downloadinstall /path/to/tffax-16.0.2.tar.gz
fwconsole reload
```

If your FreePBX build does not accept a local path with `downloadinstall`, upload/extract the module to the FreePBX modules directory and use the normal Module Admin installation workflow.

## Basic configuration

1. Create one or more **Fax Identities**.
2. Create a **Fax Account** for each user. Account creation can also create the user's managed inbound mailbox and DID routing in one step.
3. For shared or advanced configurations, use **Inbound Mailboxes**, **Fax Users**, and **Advanced Routing**.
4. Point a FreePBX inbound route to either a dedicated Fax Platform mailbox or **Automatic DID/CID Router**.
5. Configure outbound settings, timezone, transport policy, and notification sender under **Settings**.
6. Customize cover pages and email notification templates as needed.
7. Apply configuration after routing, mailbox, identity, or fax-engine changes.

## User portal

After installation, the portal is available at:

```text
https://PBX-HOSTNAME-OR-IP/fax/
```

Fax portal credentials are managed by Fax Platform administrators. Users can manage their sender profile, notification preferences, personal cover pages, and portal password from the portal settings page.

## Data owned by the module

- Module ID: `tffax`
- Database tables: `tffax_*`
- Default spool: `/var/spool/asterisk/tffax`
- Public portal: `/fax/`
- Administration: `/admin/config.php?display=tffax`
- Dialplan contexts: `tffax-router`, `tffax-inbound`, `tffax-tx`

The module is designed to avoid modifying unrelated module tables or configuration.

## Security

- Restrict PBX administration and the fax portal to trusted networks or a properly secured remote-access path.
- Use TLS for the web interface.
- Use strong portal passwords.
- Treat stored faxes as potentially sensitive documents and protect backups accordingly.
- Keep FreePBX, Asterisk, PHP, the operating system, and this module updated.
- Review the module's Diagnostics page after upgrades.

## License

Copyright (C) 2026 Tommy Heggie.

This project is licensed under the GNU General Public License version 3 or, at your option, any later version. See `LICENSE`.

FreePBX and Sangoma are trademarks of their respective owners. This project is independent and is not affiliated with or endorsed by Sangoma Technologies.
