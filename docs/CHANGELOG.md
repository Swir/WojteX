# Changelog

## Unreleased

- Wrap long lines in individual code blocks in discussions and editor previews. Copied code retains its original spacing and line breaks.
- Search the text of private messages across mailbox pages. The phrase stays in the current mailbox view when changing folders.
- Choose a post text size in Appearance. The setting is saved in your browser and applies to discussions and editor previews.
- Sort saved topics by when you bookmarked them. Search and category filters still work with this order.
- Search titles and posts in followed topics. Search works with the category and new replies filters, and stays in the address when you switch tabs or pages.
- Drag a file into the attachment picker when creating a topic or adding an attachment to a post. The file is checked before you send it.
- Open attached PNG, JPEG, GIF and WebP images in a preview. Images load only when requested, and the original download remains available.
- Copy code directly from posts and the editor preview. If clipboard access is unavailable, the code is selected for manual copying.
- The mobile menu has a close button, keeps keyboard focus inside while open, and restores focus when dismissed.
- Enter a page number to jump directly to it in long discussions and topic lists.
- Category and discussion pages show the path through parent categories, with links back to each level and the category directory.
- Filter the category directory by category name, description or parent category. The filter accepts searches without accents and stays in the address.
- See unread private message counts in navigation and a marker on the mobile menu. The background check runs only in visible tabs outside the mailbox.
- The post editor detects edits saved after it opened. Load the current published text for comparison while keeping your draft in the editor.
- Restore unfinished post edits from a browser draft. If the published post has changed, the editor flags the difference and lets you discard the draft to restore the published text.
- Use Ctrl or Command with B, I or K for bold, italic and links in post editors. When linking selected text, type the URL immediately to replace the example address.
- Quote a selected passage or a full post. Quotes include a link back to the source and are appended to the current reply draft.
- Search within a discussion across all of its pages. Results show text excerpts and link straight to the matching posts.
- Jump to the latest post from a discussion or its activity timestamp on topic lists. The view opens on the final page and focuses that post.
- See a selected attachment’s name and size, remove it before sending, and catch unsupported extensions or files over 5 MiB before publishing.
- Mark unread private messages across all mailbox pages in one action. Messages newer than the last mailbox refresh stay unread.
- Reply from a received private message or write again from a sent message. The recipient is preselected and the main composer keeps its draft.
- Filter private messages by unread, received or sent. Switching folders keeps the message you are composing.
- Search topic titles and posts in your bookmarks, and narrow the results by category. The filters stay in the address when sorting or paging through the collection.
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
