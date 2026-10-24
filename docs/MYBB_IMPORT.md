# MyBB import — WojteX 0.1.0-alpha.1

This alpha contains a real, transactional importer for a **versioned JSON export of standard MyBB 1.8.x data**. It is deliberately limited to users, forum hierarchy, published threads and published posts. It has been exercised against the bundled synthetic MyBB 1.8.41 fixture with SQLite; a live MyBB/MySQL export and a real community migration still require qualification.

## What transfers

- Usernames, email addresses and account creation dates.
- Supported standard MyBB password hashes and salts, for conversion at the first successful login. Source bans, non-viewable groups, awaiting-activation users and currently posting-suspended users are blocked by the exporter.
- Parent/child forum hierarchy, descriptions and ordering.
- Thread authors, subjects, dates, pinned/locked state and view counts.
- Published post authors, original text, original BBCode and creation/edit dates.
- Guest author names become blocked archive accounts. They cannot sign in.
- Old/new identifiers are recorded in `import_maps` for later reconciliation and redirect work.

All imported users have the `member` role, including source administrators and moderators. Existing WojteX administrators retain their accounts. **Every imported category is private**, including its descendants. Only existing WojteX administrators/moderators can inspect it until an administrator deliberately changes its visibility. Original MyBB group and forum permissions are not copied.

### Features outside this alpha

Attachments, avatars, private messages, custom profile fields, polls, reactions/reputation, subscriptions, notifications, custom groups/ACLs, plugins, themes and custom MyCode are **not imported**. The exporter counts several omitted tables, and the report displays these counts. Original BBCode is retained; the current renderer handles a conservative subset. Unknown tags stay visible as text. Old URL redirects are not installed automatically; `import_maps` is the data foundation, not a completed redirect service.

The exporter excludes and counts unapproved/deleted posts, unapproved/deleted threads and redirect placeholder threads. The importer rejects an independently supplied record with `visible != 1`; it does not convert it to public content.

This version performs a bounded, single-transaction import. It does **not** claim resumable batches, live delta synchronization or support for arbitrary SQL dumps. An unchanged snapshot can be submitted again without duplicates. A changed snapshot with the same `source_id` is rejected. Use a fresh staging installation to rehearse a newer snapshot; do not relabel the source to evade this check.

## 1. Export a consistent copy of MyBB

Use a copy of the source database and a database account that has **SELECT only** access. The exporter also requests a read-only transaction and repeatable-read isolation. A transaction does not make MyISAM tables consistent: the source must already be a frozen/consistent backup. No source account is created or modified by the tool.

Requirements: PHP 8.3+, the `pdo_mysql` extension, UTF-8 source data and a standard MyBB 1.8 schema. Run the command from the extracted WojteX project root. The exporter is a standalone PHP script and does not require Composer dependencies on the source machine; you may copy only `tools/export-mybb.php` to that private environment. It reads MySQL/MariaDB, not SQLite source databases. The destination WojteX installation must already be installed and have its own administrator account.

Set these environment variables privately:

| Variable | Meaning |
| --- | --- |
| `MYBB_DB_HOST` | Host containing the copied database. |
| `MYBB_DB_PORT` | Optional; defaults to `3306`. |
| `MYBB_DB_NAME` | Copied database name. |
| `MYBB_DB_USER` | SELECT-only database user. |
| `MYBB_DB_PASSWORD` | Database password. Do not place it in command arguments or screenshots. |
| `MYBB_TABLE_PREFIX` | Optional; defaults to `mybb_`. |
| `MYBB_SOURCE_ID` | Stable source identity, 3–64 ASCII letters/digits/dot/underscore/hyphen, for example `community-archive`. Keep it unchanged between exports of the same source. |
| `MYBB_VERSION` | Actual source version shown by MyBB, such as `1.8.41`. This is operator-supplied; the exporter does not claim automatic version detection. |
| `MYBB_SOURCE_URL` | Optional HTTP(S) URL without embedded credentials. Used as metadata only; never fetched by the importer. |

Example for Bash on Linux/macOS, replacing the database names and the version with the actual source values. The password is entered without echo and is not written as a literal in command history:

```bash
export MYBB_DB_HOST=127.0.0.1
export MYBB_DB_PORT=3306
export MYBB_DB_NAME=forum_backup
export MYBB_DB_USER=forum_reader
export MYBB_TABLE_PREFIX=mybb_
export MYBB_SOURCE_ID=community-archive
export MYBB_VERSION=1.8.41
read -r -s -p 'MyBB database password: ' MYBB_DB_PASSWORD
export MYBB_DB_PASSWORD
php tools/export-mybb.php /private/backups/mybb-export.json
unset MYBB_DB_PASSWORD
```

Create the private output directory beforehand. Its path is an example, not a required directory supplied by the package. On Windows, set the same variables in the process environment using a private terminal/session, and choose a directory with appropriate account access controls. Do not paste the environment values into a support ticket. Exit code `0` means the file was written; a nonzero code means the export failed. The exporter does not overwrite an existing file or emit a deliberately truncated snapshot.

For the built-in usage summary:

```sh
php tools/export-mybb.php --help
```

Use a path **outside every web root**. Existing files are not overwritten. On Unix, the newly created file uses restrictive permissions through `umask(0077)`; on Windows, check the directory/file ACLs. It contains email addresses and legacy password hashes, so keep it private. The exporter outputs counts only, never credentials or hashes.

## 2. Preview in WojteX

Sign in using the administrator account created by the WojteX installer. Open **Panel administratora → Migracja**, select the JSON produced by the exporter, and click **Przeanalizuj plik**. Do not choose a `.sql` backup. For a rehearsal using only bundled synthetic data, you can select `tests/Fixtures/mybb-small.json` instead.

The endpoints are:

- `POST /api/admin/imports/mybb/preview`: multipart field `file`.
- `POST /api/admin/imports/mybb`: multipart fields `file` and `confirm_private=1`.

Both require an authenticated, non-banned administrator plus the normal session CSRF token. The browser UI sends the session and CSRF token automatically. If calling the API directly, send the multipart body with the same-origin session and the `X-CSRF-TOKEN` returned by `/api/bootstrap`; never put the JSON or its hashes into a URL. Preview does not write data. Review the included counts, unsupported-password count, omitted records, scope warnings and private-category policy.

Validation rejects missing references, duplicate identifiers, duplicate email addresses, invalid UTF-8, forum hierarchy cycles, unsupported versions, invalid timestamps, malformed JSON, SQL text and source URLs containing credentials. Existing WojteX email addresses are matched case-insensitively and cause a conflict; accounts are never silently merged.

## 3. Run and verify

Read the full report, select the confirmation that accepts the scope and private destination categories, then click **Wykonaj import**. Changing the selected file clears the preview and requires a fresh analysis.

After scope confirmation, the importer validates again and writes inside a transaction. A per-source lock row serializes competing imports, and unique mappings/fingerprints guard against duplication. An error rolls back imported users, categories, threads, posts and tracking records. The completed report contains counts and warnings only, not password hashes.

Verify the result on staging:

1. Compare the expected record counts and sample author/date/body relationships.
2. Open imported content as an administrator; verify hierarchy and original formatting.
3. Browse as a guest and ordinary user, including direct topic links. Imported private content must remain inaccessible.
4. Verify a supported imported account can log in and upgrades its legacy password. Check banned accounts remain blocked.
5. Check custom MyCode and omitted features against the source. Keep the original backup until the missing mappings are implemented and validated.
6. Review category visibility in **Panel administratora → Działy**. Both a child and its private ancestors must be made public before ordinary users can see the child. Change visibility only after reviewing the intended access. There is no automatic reconstruction of source ACLs.

An imported user may successfully log in while still being unable to see the imported private topics; that is the intended quarantine behavior. Do not confuse successful authentication with completed access-control migration.

The upload is parsed from PHP’s temporary upload file; the importer does not create a second permanent JSON archive. The original exported file and any copies on the operator’s machine remain the operator’s responsibility. Keep a protected original backup, and remove unnecessary export copies after the verified migration according to the forum’s retention policy.

Before an actual cutover, freeze writes on the original forum and make a consistent final snapshot. This alpha is for test migrations; there is no zero-downtime/delta migration facility.

## Password handling

The supported standard MyBB 1.8 hash is:

```php
md5(md5($salt) . md5($plaintextPassword))
```

The real login route verifies that value with a constant-time comparison. After a successful login it writes a new **Argon2id** password hash using the application's configured costs, then clears `legacy_password_hash` and `legacy_password_salt`. No plaintext password is stored. An incorrect legacy password leaves the stored credentials unchanged. The importer accepts only a 32-character hexadecimal hash, a nonempty bounded salt and the `mybb-md5` scheme. Custom password plugins/SSO are not supported. Unsupported formats result in blocked imported accounts and an explicit warning; this alpha does not have a complete SMTP account-recovery flow.

Primary reference: [MyBB 1.8 login documentation](https://docs.mybb.com/1.8/faq/login-problems/). General migration rehearsal reference: [MyBB Merge System instructions](https://docs.mybb.com/1.8/merge/running/).

## Limits

| Bound | Limit |
| --- | ---: |
| JSON file | 16 MiB |
| Users, including synthesized guest accounts | 10,000 |
| Forums/categories | 2,000 |
| Threads | 20,000 |
| Posts | 50,000 |
| Combined records | 60,000 |
| Post text | 100,000 Unicode characters |

The web server and PHP upload/body/memory/time limits may be lower. Adjust them deliberately for the staging server; do not upload a truncated export. Larger migrations need a subsequent streaming/batched importer and load testing.

## Fixture and verification

`tests/Fixtures/mybb-small.json` contains synthetic accounts, two categories, one topic and two posts. It is safe test content; it is not an export of any real user's forum. Its test-only password is `Migration-test-2026!`.

Run from a developer checkout with the Composer development dependencies installed:

```sh
composer install
php vendor/phpunit/phpunit/phpunit --filter MybbImportTest
```

A runtime package prepared with `--no-dev` may omit PHPUnit; this does not prevent the administrator UI from importing the synthetic fixture.

The suite covers preview without writes, integrity of mappings, private quarantine, no privilege escalation, idempotence, changed-source rejection, case-insensitive account conflicts, source bans, unsupported password formats, guest attribution, rejected unpublished content and a forced database failure with complete rollback.


### Recorded result — 22 October 2026

**19 tests, 111 assertions passed** on PHP 8.5.12, PHPUnit 11.5.57 and SQLite. The final three tests exercise the actual `/api/auth/login` route after import: successful source-password conversion to Argon2id with both legacy fields removed; an incorrect source password leaving all credentials unchanged; and successful login for a valid imported email longer than 190 characters. Unit-test Argon2id costs are lowered solely to keep this suite fast; production uses the application configuration.
