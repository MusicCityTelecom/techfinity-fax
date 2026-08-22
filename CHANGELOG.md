# Changelog

All notable changes to TechFinity Fax are documented here.

## 16.0.1 — 2026-08-21

First public open-source release.

- Rebased the project as a generic TechFinity Fax module with no InnAware branding.
- Targeted standard FreePBX 16 and 17 installations.
- Uses FreePBX User Management for end-user authentication instead of a separate fax password store.
- Added per-user fax authorization, inbound mailboxes, outbound faxing, history, document preview/download and cover pages.
- Added Automatic DID/CID Router with DID normalization for common NANP number forms.
- Added unmatched inbound fallback handling.
- Added administrator-editable notification templates for inbound fax, outbound success and outbound failure.
- Added timezone-aware display/notification timestamps with `America/Chicago` as the default timezone.
- Added configurable T.38/G.711 fax transport settings and diagnostics.
- Updated administration pages to use standard FreePBX/Bootstrap styling rather than product-specific styling.
- Added `/fax/` user portal integration.
- Added installation, security, contribution and release documentation.
