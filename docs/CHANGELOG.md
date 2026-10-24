# Changelog

## 0.1.0-beta.1 — 2026-10-24

First public WojteX testing pre-release, based on the Veyra alpha.3-dev.54 prototype and the WojteX dev.55 appearance update.

- WojteX naming, WX vector logo/favicon and new release documentation.
- Personal appearance panel available on desktop/mobile and in Polish/English.
- Graphite, midnight, light, device-following and forum-default modes.
- Six accents, comfortable/compact spacing, immediate preview and reset.
- Browser-local preference persistence, migration of previous light/dark choice, cross-tab updates and safe fallback when storage is unavailable.
- Expanded administrator theme/accent choices. Saving defaults no longer resets personal appearance.
- Existing forum, messages, moderation, groups, languages, modular shoutbox/rankings and experimental MyBB import carried forward.
- Dependency-inclusive installer archive and SHA-256 checksum.
- Fixed a PHP inheritance error in password-reset notifications by using Laravel's existing locale property.
- Repaired test fixture method collisions and test expectations for revoked sessions, rate limits, email text and unordered search membership. The complete PHP/SQLite suite now runs successfully.

This release does not claim production readiness, end-to-end encrypted messages, a complete MyBB migration or automatic upgrades. SMTP delivery and high-load/concurrency behaviour require further validation. See [RELEASE_CHECKS.md](RELEASE_CHECKS.md).
