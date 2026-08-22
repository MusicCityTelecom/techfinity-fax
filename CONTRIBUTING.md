# Contributing to TechFinity Fax

Contributions that improve interoperability, security, installation safety, fax reliability, documentation or compatibility with supported FreePBX releases are welcome.

## Development rules

Keep changes limited to the `tffax` module and its own documentation/build files. Do not modify unrelated FreePBX modules, database tables or generated PBX files directly.

Before submitting a change:

1. Run PHP syntax validation on every changed PHP file.
2. Test installation or upgrade through Module Admin on a representative FreePBX 16 or 17 system.
3. Run Apply Config and confirm the generated Asterisk dialplan loads without module-created errors.
4. Test inbound fax routing, outbound faxing and User Management authentication when those areas are affected.
5. Do not commit passwords, SIP credentials, API tokens, private fax documents or customer information.
6. Update documentation and `CHANGELOG.md` when behavior changes.

## Coding guidance

- Preserve the module ID `tffax` for upgrade compatibility.
- Keep schema changes additive and upgrade-safe whenever possible.
- Use FreePBX APIs rather than modifying another module's files.
- Escape output and validate untrusted input.
- Keep filesystem writes inside module-owned paths unless installation of the `/fax/` portal wrapper requires otherwise.
- Maintain compatibility with existing installations and archived fax records.

## License

By contributing, you agree that your contribution may be distributed under the project's GPL-3.0-or-later license.
