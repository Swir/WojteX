# Beta.1 release checks — 2026-10-24

This records the checks performed for the first public WojteX pre-release. It is not a production certification.

## Automated checks

- PHP 8.5.12, PHPUnit 11.5.57, SQLite: **178 tests, 1496 assertions — PASS**.
- Appearance behaviour: defaults, reload persistence, operating-system theme changes, reset, invalid stored data, blocked storage, legacy preference migration and cross-tab updates — PASS.
- Language/source integrity: **983 matching PL/EN keys**, placeholders and literal JavaScript translation references — PASS.
- PHP preflight: required extensions and Argon2id available.
- Composer lock refreshed without changing dependency versions; dependency advisory audit reported no known vulnerabilities at build time. This is not a complete application security audit.

The PHP run exposed a password-reset notification inheritance error. Beta.1 uses Laravel's existing locale property. Test-only fixes resolve helper method collisions, delegate the mocked rehash check to the real hasher, account for session revocation/rate limits, and inspect the correct email text sections. Search tests check exact result membership without assuming order for tied timestamps. No access-control middleware was disabled to obtain the passing result.

## Fresh installer ZIP

The dependency-inclusive archive was extracted into a new directory and served locally with PHP 8.5.12, production Composer dependencies and a fresh SQLite database. **25 real HTTP/file checks passed**: installer and CSRF token, owner-code installation, new administrator session, empty discussions and four categories, beta version/assets, PL/EN appearance labels, saved administrator theme defaults, topic creation, logout/login, guest denial for administration, private-path denial, installation lock and removal of the owner code. No authentication or CSRF middleware was disabled.

This trial found an export rule that omitted database migrations; it is corrected in beta.1, and the archive builder now rejects an export missing the initial user-table migration. The successful trial used the corrected ZIP. The final archive differs from that tested candidate only in this release-check document.

## Earlier hosting/UI verification of the included appearance changes

The dev.55 appearance changes were checked on PHP 8.5.12 / MariaDB 10.11.19: administrator defaults saved through the real web form; personal midnight/cyan/compact choices persisted after reload; reset restored forum defaults. Desktop 1440px and mobile 390px layouts, Polish/English appearance dialogs and light/rose preview were checked in Chrome. These checks used viewport resizing, not a physical touch device.

The preceding dev.54 installation passed 21 real HTTP/MySQL setup and basic function checks, including login, topic creation/reply/read/deletion, guest access boundaries and CSRF rejection. They are historical supporting evidence, not a claim that the complete beta.1 suite ran on MySQL.

## Limits

- The full automated suite here uses SQLite; real MySQL concurrency and row-lock behaviour have not been qualified.
- Email recovery tests use test transports. External SMTP delivery was not tested.
- No independent security/accessibility audit, high-load test or complete real-community MyBB migration was performed.
- The MyBB importer does not transfer every feature or permission. Use its documented scope and an isolated target database.
- Messages are not end-to-end encrypted. Do not submit private data to public bug reports.
- Appearance preferences are local to the browser; there is no cross-device account synchronization or ZIP theme manager.

Use beta.1 on a separate test installation with backups.
