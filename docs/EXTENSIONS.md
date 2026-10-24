# Extensions — WojteX 0.1.0-alpha.1

WojteX contains an initial, working server-side extension contract. It discovers local manifests, lets an administrator enable or disable installed modules, loads an optional PHP entry file and exposes three application events. The implementation is `app/Services/ExtensionRegistry.php`; this document describes the current code, not a proposed future marketplace.

**Custom extensions run as trusted PHP code with the same process and database privileges as WojteX itself. There is no sandbox, per-extension permission enforcement, signature verification or marketplace.** The administrator must trust the author and review the code before installing it. A manifest’s `api` field is a compatibility marker, not a security boundary.

## Included modules

| ID | Purpose | Current implementation |
| --- | --- | --- |
| `welcome` | Home-page welcome panel. | Built-in feature controlled by its database state; manifest only. |
| `shoutbox` | Community messages and moderation. | Built-in feature controlled by its database state; manifest only. |
| `rankings` | Ranking from eligible posts and reactions. | Built-in feature controlled by its database state; manifest only. |
| `welcome-message` | Example custom extension greeting newly registered users. | Manifest and PHP entry file; disabled until explicitly enabled. |

The standard installer enables `welcome`, `shoutbox` and `rankings`. A newly copied extension with no `plugin_states` row is disabled by default.

## File and manifest contract

Each extension lives directly under `extensions/`, with a directory name identical to its manifest ID. For example:

| File | Purpose |
| --- | --- |
| `extensions/thread-log/plugin.json` | Metadata, API version and optional entry filename. |
| `extensions/thread-log/plugin.php` | Trusted PHP entry file returning a callable. |

Example `plugin.json`:

```json
{
  "id": "thread-log",
  "name": "Thread creation log",
  "description": "Records created topic IDs in the application log.",
  "version": "0.1.0",
  "api": 1,
  "entry": "plugin.php"
}
```

Recognized fields:

| Field | Contract |
| --- | --- |
| `id` | Required string matching `^[a-z][a-z0-9-]{1,50}$`: 2–51 lowercase ASCII letters/digits/hyphens, beginning with a letter. It must exactly match the directory name. |
| `name` | Required display name. Use a plain text string. |
| `version` | Required display version. Use a string such as `0.1.0`; the current registry does not resolve or compare semantic version ranges. |
| `description` | Optional plain text string; defaults to empty. |
| `api` | Required JSON integer `1`. Other API versions are not loaded. |
| `entry` | Optional PHP filename directly inside the extension directory, such as `plugin.php`. Allowed pattern: `^[a-zA-Z0-9_-]+\.php$`. Paths/subdirectories are not accepted. |

The registry scans `extensions/*/plugin.json`. Invalid JSON, an ID/directory mismatch, missing required metadata or an unsupported API marker cause the manifest to be skipped. It does not implement dependency resolution, minimum-core-version checks, upgrade scripts or an installation wizard. Additional metadata is not part of this initial contract.

The public bootstrap exposes the recognized catalog metadata and enabled state, with `entry` removed. **Do not put credentials, private configuration or other secrets in manifest metadata.**

## Entry point and application lifecycle

After WojteX is installed, `AppServiceProvider` calls `ExtensionRegistry::boot()`. For each enabled extension, the registry resolves its entry file and requires it. If that file returns a callable, the callable is executed immediately to register listeners or perform other bootstrap work.

Keep bootstrap work short. It runs during application startup, normally for each HTTP request in the shipped deployment model. It is not a once-only installer. Do not create database tables, send messages or perform expensive network requests unconditionally in the entry function.

Example `plugin.php` matching the manifest above:

```php
<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

return static function (): void {
    Event::listen('veyra.thread.created', static function (int $threadId, int $authorId): void {
        Log::info('Topic created', [
            'thread_id' => $threadId,
            'author_id' => $authorId,
        ]);
    });
};
```

The callable returns no application response. Laravel facades and application classes are available through the normal application autoloader. There is no separate extension service container or isolated database connection. Additional classes require your own namespace/autoload arrangement; the registry only includes the declared entry file.

## Events actually emitted

The string event names below are dispatched by the current controllers using Laravel’s event dispatcher. Payload elements are positional listener arguments.

| Event | Listener signature | When it runs |
| --- | --- | --- |
| `veyra.user.registered` | `function (int $userId): void` | After a successful ordinary registration has saved the user and established the session. |
| `veyra.thread.created` | `function (int $threadId, int $authorId): void` | After the transaction creating a topic and its first post has committed. |
| `veyra.post.created` | `function (int $postId, int $authorId): void` | After a reply and its topic timestamp update have committed. |

The initial post of a newly created topic triggers `veyra.thread.created`; it does **not** additionally trigger `veyra.post.created`. The importer writes through its own transaction and does **not** emit these events for imported accounts, topics or posts.

There are currently no WojteX extension events for login, logout, edits, deletion, moderation, reactions, attachments or shoutbox messages. Listen only to events you have verified in the code. Listener execution is synchronous in the request; there is no queue/retry/delivery guarantee attached to these hooks. Since creation has already committed, a listener error does not undo the new user/topic/reply and may still fail the HTTP response. Account for this when designing side effects and retries.

## Install a custom extension manually

1. Review the manifest and all PHP files in a staging copy of the forum. Keep a usable backup before testing code that changes data.
2. Copy the extension directory into `extensions/`. Its basename must exactly match `plugin.json`’s `id`. Do not modify core application files to register it.
3. Check PHP syntax with the server’s PHP version, for example:

   ```sh
   php -l extensions/thread-log/plugin.php
   ```

4. Sign in as the administrator and open **Panel administratora → Moduły**. Reload the page to discover newly copied metadata.
5. Enable the installed extension with its switch. The state is saved in `plugin_states`. The code loads on the next application bootstrap; under the standard request model this means the next request.
6. Perform the action that the extension handles, then verify the actual output and application log. For `thread-log`, create a topic and inspect the configured Laravel log, normally `storage/logs/laravel.log`.

The control endpoint is `PATCH /api/admin/plugins/{id}` with `{"enabled": true}` or `{"enabled": false}`. It requires the normal administrator session and CSRF protection. It only changes the state of a manifest already present on the server. **Uploading ZIP packages from the panel is not implemented.**

## Try the included `welcome-message` sample

The package already contains:

- `extensions/welcome-message/plugin.json`
- `extensions/welcome-message/plugin.php`

In **Moduły**, keep **Shoutbox** enabled and enable **Powitanie nowych osób**. Register a new test account in a separate browser session. The listener writes one shout attributed to that new account:

> Dołączam do społeczności. Cześć wszystkim! 👋

That is the sample’s actual behavior: it is an automatic message from the new user account, not a separate bot account. If the shoutbox module is disabled, the listener returns without writing. Imported users and login to existing accounts do not trigger the greeting. There is currently no sample-specific settings form; change the trusted sample code to alter the text or behavior.

Disable the sample after testing if the community does not want automatic greetings. Existing greeting messages remain in the database and can be moderated normally.

## Disable, remove and recover

To stop an extension, disable its switch in **Moduły**. For a custom extension, remove its directory after disabling if you no longer want the files installed. The registry does not automatically uninstall tables, remove previously created data or clean up the saved state row.

Boot-time loading has limited error recovery: an exception thrown while requiring an entry file or invoking its returned bootstrap callable is reported and the extension’s state is set to disabled. This is **not a general safe mode**. It does not sandbox malicious code, recover from every fatal process failure, or wrap callbacks that execute later during event dispatch.

If a broken custom extension prevents access to the panel, use trusted server/file access to move that extension directory outside `extensions/`, then retry a fresh request. Inspect the log and repair it in staging before restoring it. For built-in manifest-only modules, deleting a manifest is not a supported way to switch the feature off: their controllers consult the saved database state, so disable them through the panel first.

## Current boundaries

This alpha provides local discovery, enable/disable state, trusted PHP entry loading and the three events above. It has no online extension catalog, dependency manager, package signature checks, ZIP uploader, permission sandbox, per-extension settings schema, automatic schema migrations, uninstall hooks, guaranteed update compatibility or comprehensive safe-mode switch. The API is initial and may change during alpha development. Validate each extension against the exact release you deploy.
