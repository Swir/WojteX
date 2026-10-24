# Development

Use an isolated checkout with no live configuration or user data.

```sh
composer install
php vendor/bin/phpunit --no-progress
node tests/appearance.mjs
python tools/verify_source.py
```

PHPUnit uses SQLite in memory. It does not prove MySQL row-lock behaviour or SMTP delivery. See release checks for the exact tested scope.

The frontend uses plain CSS and JavaScript; there is no frontend dependency installation or build step. `public/assets/wojtex-appearance.js` owns personal browser preferences. Theme defaults are validated by `AdminController` and returned through bootstrap. Translation strings are data-only JSON in `lang/pl` and `lang/en`.

## Build an installer archive

Start from a clean export of the desired Git commit, outside your development checkout. Install only production dependencies there:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
python tools/build_release.py /absolute/path/to/output
```

The builder creates `WojteX-<version>-install.zip` with a `wojtex-forum/` root and a SHA-256 file. It excludes Git metadata, local configuration, databases, logs, caches, tokens and uploads. It requires `vendor/autoload.php`. Test the resulting ZIP after extracting it into another clean directory. Do not build from a live installation.

PHP dependencies retain their upstream licences. Do not commit `vendor/`; the lock file specifies versions. `composer.json` declares the project's existing MIT licence.
