# Updating a test installation

These steps cover a test installation of WojteX beta.1 upgrading to beta.2. Test on an isolated copy with backups.

1. Back up the database, `.env`, private storage, installation lock, custom CSS, language packs and extensions. Verify that the backup can be restored. Stop writes while creating a consistent backup.
2. Extract the new package into a separate private directory. Use a separate database and disable outgoing mail in this copy.
3. Preserve the original `APP_KEY` and installation lock when restoring an existing installation. Do not generate a new key, rerun the installer or use database reset commands.
4. Apply application files and bundled dependencies, keeping your own assets/extensions separately. For hosting layouts with a separate public directory, also update the public files and verify the entry-point path.
5. Run `php artisan migrate --force` and `php artisan optimize:clear` against the isolated target copy. The migrations add followed topics, post edit revisions, attachment upload keys and indexes for topic lists, bookmarks and unread messages. Allow time for index creation on larger databases.
6. Check login, permissions, topics, messages, uploads, languages and appearance. Confirm the previous backup can still be restored.

Technical identifiers such as `config/veyra.php`, the legacy session cookie and older class names remain for compatibility. Do not rename them manually. Existing `.env` values and forum names are not automatically overwritten by the public package.

Run upgrades manually. Never use `migrate:fresh`, `db:wipe` or a fresh installer to update an existing forum.
