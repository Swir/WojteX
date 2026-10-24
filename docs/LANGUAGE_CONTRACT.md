# Alpha.2 localization implementation contract

Public implementation contract for WojteX Forum 0.1.0-alpha.2.

## Catalog files and keys

Built-in locales: `pl`, `en`. Metadata: `lang/{code}/manifest.json` with `schema:1`, `locale`, `name`, `native_name`, `version` (string). Plain-text, flat catalogs are split between `lang/{code}/ui.json`, `install.json`, `server.json`, `import.json`. Each is an object mapping keys to strings. UI keys begin `ui.`, installer/seed/demo keys begin `install.`, `seed.` or `demo.`, server keys begin `server.`, `validation.` or `extension.`. Parameters use Laravel syntax `:name`, never HTML. Built-in EN and PL key sets must match. Plural UI entries use `.one`, `.few`, `.many`, `.other` with English equivalents for all categories; select via Intl.PluralRules(active locale), fallback `.other`. Escape translations just like user input when putting them in HTML.

`App\Services\LanguageRegistry` exposes:

- `catalog(): array` => `[{'code','name','native_name','version','builtin','coverage','missing_count'}]`.
- `has(string $locale): bool`, `defaultLocale(): string` (stored `default_locale`, otherwise `pl`).
- `messages(string $locale): array` => complete flat translated map, fallback English, including UI/server/installer.
- `activate(string $locale): void`: sets Laravel app locale and registers maps for `__('key', ['name'=>value])`.
- `payload(?string $locale=null): array` => `{locale, fallback_locale:'en', languages:catalog, messages}`.
- `export(string $locale): array` => single uploadable JSON pack `{schema:1,locale,name,native_name,version,messages}`.
- `install(string $json, bool $replace=false): array`: validates data-only pack, stores atomically outside webroot, returns installed catalog entry. No overwriting built-in en/pl. Partial custom packs allowed, missing messages fall back to English and coverage is shown.

API:

- `GET /api/bootstrap` gains `i18n: payload()`.
- All frontend fetches send `X-WojteX-Locale: chosenCode` when available. Middleware resolves valid explicit locale -> session -> forum default. Installer GET supports `?lang=en` / `?lang=pl` and POST `locale` field; chosen locale saved in session. Invalid locale hints are ignored safely, actual locale mutation endpoints validate.
- `POST /api/locale` with `{locale}`: public, installed, CSRF protected. Saves session preference. Response `{i18n:payload()}`. Page reload afterward is okay; server shell and JS must show chosen language. Guest preference also works.
- `GET /api/admin/languages`: admin-only `{languages,default_locale}`.
- `GET /api/admin/languages/template?locale=en`: admin-only downloadable single JSON pack, defaults to English.
- `POST /api/admin/languages`: admin-only multipart field `file` (JSON max1MiB), optional `replace:1`. Response `{language,languages}`. Validation errors are conventional JSON422. Invalid locale/path, malformed/HTML/code content, unknown keys, mismatched placeholders and builtin overwrite are rejected. Import may be partial with English fallback.
- Existing `PATCH /api/admin/settings` accepts `default_locale` from installed catalog. Settings and admin show payload include default_locale; admin page can fetch languages API. Existing alpha.1 installations need no schema migration for locales.

Installer:

- Default `setup_mode=fresh` creates one administrator and four initial empty categories, no topics/posts/shouts/import activity unless demo is explicitly selected. Add visible independent choices Fresh forum / Prepare migration, default fresh.
- Migration mode `setup_mode=migrate` prepares a new separate installation and can redirect to `/` (then user opens migration tab); never reads/modifies old forum in installer, and demo must be false for migration mode.
- Save chosen `default_locale`, `setup_mode`; initialize welcome/tagline/category text in install language. Content is not auto-translated when viewing locale changes. Demo content if selected must follow installation language.
- Existing installation remains protected against reinstallation takeover. No destructive reset of an occupied database.

Testing: `php vendor/bin/phpunit`. Behavior tests cover fresh/no migration, EN/PL, locale persistence/default/fallback, new pack installation, malformed packs, path validation, role permissions and enforced CSRF.
