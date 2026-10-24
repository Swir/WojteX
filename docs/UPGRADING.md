# Updating a test installation

Beta.1 is the first public WojteX release. Test upgrades on an isolated copy before using any valuable data. It is not an instruction to migrate a live MyBB community.

1. Back up the database, `.env`, private storage, installation lock, custom CSS, language packs and extensions. Verify that the backup can be restored. Stop writes while creating a consistent backup.
2. Extract the new package into a separate private directory. Use a separate database and disable outgoing mail in this copy.
3. Preserve the original `APP_KEY` and installation lock when restoring an existing installation. Do not generate a new key, rerun the installer or use database reset commands.
4. Apply application files and bundled dependencies, keeping your own assets/extensions separately. For hosting layouts with a separate public directory, also update the public files and verify the entry-point path.
5. Run `php artisan migrate --force` and `php artisan optimize:clear` only against the isolated target copy. The appearance changes from dev.55 introduce no database migrations; older prototypes may require migrations already in this release.
6. Check login, permissions, topics, messages, uploads, languages and appearance. Confirm the previous backup can still be restored.

Technical identifiers such as `config/veyra.php`, the legacy session cookie and older class names remain for compatibility. Do not rename them manually. Existing `.env` values and forum names are not automatically overwritten by the public package.

There is no automatic updater in beta.1. Never use `migrate:fresh`, `db:wipe` or a fresh installer to update an existing forum.
