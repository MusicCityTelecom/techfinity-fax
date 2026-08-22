# Installation

## Prerequisites

Confirm Asterisk fax support and document conversion tools are present:

```bash
asterisk -rx 'module show like fax'
asterisk -rx 'core show application SendFAX'
asterisk -rx 'core show application ReceiveFAX'
command -v gs tiff2pdf tiffinfo
```

## Install with Module Admin

1. Download `tffax-16.0.2.tar.gz`.
2. In FreePBX open **Admin > Module Admin > Upload Modules**.
3. Upload the archive.
4. Install/enable **Fax Platform**.
5. Apply Config.
6. Open **Applications > Fax Platform > Diagnostics**.

The installer creates the `tffax_*` tables, `/var/spool/asterisk/tffax` directories, and `/fax/` portal wrapper.

## Upgrade

Upload the new tarball through Module Admin. The installer performs idempotent schema upgrades and preserves existing `tffax_*` data. Always back up the PBX and database before upgrading.

## Uninstall

Use Module Admin or `fwconsole ma uninstall tffax`. The current uninstall script intentionally does not drop fax history tables automatically. Back up or manually remove module-owned data only when you are certain it is no longer needed.
