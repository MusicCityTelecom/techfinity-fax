# Security Policy

## Supported release

Security fixes are currently targeted at the latest public TechFinity Fax release.

| Version | Supported |
| --- | --- |
| 16.0.1 | Yes |

## Reporting a vulnerability

Please do not publish a suspected security vulnerability as a public issue until reasonable time has been allowed for review and remediation.

Report security concerns to **tommy@techfinity.tech** with:

- affected TechFinity Fax version;
- FreePBX and Asterisk versions;
- a concise description of the issue and its impact;
- reproduction steps or proof-of-concept details where appropriate;
- relevant logs with credentials, tokens, fax content and personal information removed.

## Security boundaries

TechFinity Fax delegates interactive user authentication to FreePBX User Management. Administrators remain responsible for securing the PBX host, HTTPS configuration, User Management accounts, SIP trunks, firewall rules and operating-system access.

Fax documents can contain sensitive information. Protect the PBX filesystem, backups, database, email transport and the module-owned spool under `/var/spool/asterisk/tffax` accordingly.
