# Installation and Upgrade Guide

## Preflight

Confirm the target is FreePBX 16 or 17, User Management is installed, Asterisk has `res_fax` plus a fax technology module, and `gs`, `tiff2pdf`, and `tiffinfo` are available.

```bash
fwconsole --version
fwconsole ma list | grep userman
asterisk -rx 'module show like fax'
asterisk -rx 'core show application SendFAX'
asterisk -rx 'core show application ReceiveFAX'
command -v gs tiff2pdf tiffinfo
```

## Module Admin installation

1. Download `releases/16.0.1/tffax-16.0.1.tar.gz`.
2. Open **Admin > Module Admin > Upload Modules**.
3. Upload the package.
4. Install and enable **TechFinity Fax**.
5. Click **Apply Config**.
6. Open **Applications > TechFinity Fax > Diagnostics** and resolve any failed checks before production use.

## Post-install configuration

Create a fax identity, map existing User Management accounts under Fax Users, create inbound mailboxes, then route dedicated fax DIDs to either a mailbox or the Automatic DID/CID Router.

The user portal is installed at `/fax/` and uses existing User Management credentials.

## Upgrade safety

The installer uses idempotent table creation and additive schema changes. Back up the `tffax_*` tables and `/var/spool/asterisk/tffax` before major upgrades. Uninstall intentionally preserves fax data rather than destroying archived records.
