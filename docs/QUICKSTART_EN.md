# Install WojteX 0.1.0-beta.1

Use a separate directory and a new, empty database. This is a testing pre-release.

1. Download `WojteX-0.1.0-beta.1-install.zip` from GitHub Releases and extract the complete `wojtex-forum` directory. It includes PHP dependencies in `vendor/`. PHP itself is not bundled. GitHub's automatic source archives require `composer install --no-dev --optimize-autoloader` instead.
2. Use PHP 8.3+ with Argon2id and the extensions listed in README. Run `php tools/check.php` if shell access is available.
3. Keep the application outside the web root. Set the domain's document root to the application's **`public/`** directory. Apache needs rewrite support and `.htaccess`; Nginx needs an equivalent front-controller rule for `public/index.php`.
4. Run `php tools/prepare.php`. It generates `.env`, a random application key and a private owner code. Without shell access, the first web request performs preparation; read the code from `storage/install-token` using your hosting file manager.
5. Set `APP_URL=https://your-forum.example`, `APP_ENV=production`, `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=true` for HTTPS. Never expose `.env` or the owner code publicly.
6. SQLite is the default. For MySQL/MariaDB, create a dedicated empty database and user, then configure before installation:

```dotenv
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_new_database
DB_USERNAME=your_database_user
DB_PASSWORD="your_own_password"
```

7. PHP needs write access to `storage/`, `bootstrap/cache/` and, for SQLite, `database/`. Initial preparation also needs to create `.env` in the project root. Do not use world-writable permissions.
8. Visit `/install`, select English, choose **Fresh forum**, and supply the owner code, forum name and your own administrator credentials. Leave sample data unchecked for a blank community.
9. The installer creates one administrator and four empty categories, removes the owner code and locks installation. Configure registration, categories and default appearance in the administration panel.

If your host cannot change document roots, place **only the contents of `public/`** in the domain directory and adapt its `index.php` paths to the private application. This requires host-specific setup. Confirm that `.env`, `storage/` and `vendor/` are not accessible over HTTP.

## Local evaluation

```sh
php tools/check.php
php tools/prepare.php
php -S 127.0.0.1:8080 -t public public/router.php
```

Open `http://127.0.0.1:8080/install`. The built-in server is for local testing only.

## Appearance and mail

The Appearance button offers graphite, midnight, light and device-following modes, six accents and comfortable/compact spacing. Personal preferences are saved **in this browser**, not synchronized across devices. Forum default uses the administrator's settings.

No SMTP account is included. The default log transport deliberately disables email password recovery. Configure your own SMTP and HTTPS URL before testing recovery; see [PASSWORD_RECOVERY.md](PASSWORD_RECOVERY.md).

Back up the database, `.env` with its original `APP_KEY`, private storage and custom extensions before any upgrade. Never include credentials, database dumps or private messages in public GitHub issues.
