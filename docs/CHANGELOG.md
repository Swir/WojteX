# Changelog

## Unreleased

- Preview formatting in the editor before publishing topics, replies and edits. The preview uses the same Markdown or BBCode renderer as published posts.
- Follow discussions and filter your list to topics with new replies. Jump to the first unread reply and keep your reading position as you scroll. Follow access uses the same category permissions as the forum.
- Mark an entire followed topic as read directly from the list. Replies received after marking appear as new again.
- Filter followed topics by category. The selected category stays in the address when switching between all followed topics and those with new replies.
- Save or remove a bookmark directly from the topic list. The saved collection refreshes when a bookmark is removed.
- Save a draft of a new topic or a reply in the current browser. Restore text after reloading, discard it explicitly, or choose which version to save when another tab changes the draft. Attachments are selected again when reopening a draft.
- Copy a direct link from any post. The link finds the post's current page and keeps the topic's normal access checks.
- Added an index for the latest discussion feed and reused Markdown rendering setup within a topic page.
- After posting a reply, open the page containing it directly. Posting refreshes category counters through a smaller response and leaves the shoutbox input intact.
- The discussion list can load before sidebar statistics and activity. Automatic sidebar loads run when the sidebar is visible. Startup reuses the translations already in the page when the requested language matches.
- Updated home page and category cards, with a directory for all visible categories and direct access to subcategories from their parent.
- Quick search from the header, `/` or `Ctrl/⌘+K`. Jump to categories, preview matching topic titles, or search titles and post contents together. Suggestions use live forum permissions and work in Polish and English.
- Updated Laravel to 13.44.1 and the affected Symfony components to 7.4.24 to address the advisories reported by Composer audit.
- Discussion pages use one visible topic heading, readable 16px post text, a focused reading column, a reply shortcut and expandable moderator actions.
- Wider topic/edit composer with larger fields and formatting controls, a sticky header/footer, and optional attachments in an expandable section.
- New topics opened from a discussion inherit its category. Publishing starts at the top of the new topic.
- Polish/English reply and moderation labels. Verified locally through real browser topic creation, replies and post editing; checked the composer/discussion at 390px and desktop widths. Existing appearance checks and 985-key language/source checks pass. Test-host deployment verified without adding test posts there.

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
