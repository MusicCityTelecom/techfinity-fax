# TechFinity Fax 16.0.1

This is the first public open-source release of TechFinity Fax.

## Installation artifact

Use `tffax-16.0.1.tar.gz` with **Admin > Module Admin > Upload Modules** on a supported FreePBX 16 or 17 installation. The complete source archive is provided as `tffax-16.0.1-src.zip`.

Verify downloads against `tffax-16.0.1-SHA256SUMS.txt`.

## Highlights

- Multi-user inbound and outbound fax workflow.
- Existing FreePBX User Management credentials are used for the fax user portal.
- DID/CID inbound routing, including common NANP DID normalization.
- User-specific fax permissions, mailboxes, identities, history and cover pages.
- Administrator-configurable inbound/success/failure email templates.
- Timezone-aware timestamps; default timezone is `America/Chicago`.
- Configurable fax-engine, T.38 and G.711 behavior.
- Diagnostics for required fax applications and document conversion utilities.
- Standard FreePBX/Bootstrap administration styling.
- No InnAware branding or dependencies.

## Requirements

- FreePBX 16 or 17.
- User Management (`userman`).
- Asterisk `res_fax` plus a fax technology resource such as `res_fax_spandsp`.
- Ghostscript and TIFF/PDF conversion utilities used by the document workflow.

## Checksums

```text
60462634b725d18564a343c90b9149f2cfa206b77a29bbe101eda2af1009105f  tffax-16.0.1.tar.gz
7ac1f7ce760d5e7910cfe71b1050fa952d64eeb9c27ac3432f232b884002b2e6  tffax-16.0.1-src.zip
```

## Project status

TechFinity Fax is an independent open-source project. FreePBX, Asterisk and Sangoma names and trademarks belong to their respective owners; no affiliation or endorsement is implied.
