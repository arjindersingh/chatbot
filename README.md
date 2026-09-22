# AskPilot

A basic WordPress FAQ chatbot based on `pmd.md`, using HTML/CSS/JavaScript, PHP, MySQL through `$wpdb`, REST endpoints, and native WordPress administration. No AI service or API key required.

## Install / update

1. Upload `dist/askpilot.zip` in **Plugins → Add New → Upload Plugin**, then activate it (or replace the installed version).
2. Open **AskPilot** in the dashboard and add questions and plain-text answers.
3. Customize the title, welcome message, and fallback answer. The chat button appears automatically on the public website.
4. Open **AskPilot → Queries** to see visitors and select **View full record** for their chat history.

Requires WordPress 6.0+ and PHP 7.4+. Activate separately per site; network-wide activation is not supported. Your theme must call standard WordPress enqueue/footer hooks. Version 0.4.0 automatically creates the record tables when updating an existing installation and preserves existing FAQs/settings.

## Visitor flow

1. Enter a required mobile number and email. Both are validated; the form explains that contact details and chat activity are saved.
2. Type a phrase and select **Search**. Matching question text contains the normalized phrase (case, punctuation, and repeated whitespace are ignored). For example, “opening hours” lists both “What are your opening hours?” and “Do opening hours change on holidays?”. Partial words also match; word order is preserved. Keywords and fuzzy matching are not used in this version.
3. Click a matching question to fetch and display its answer. Search responses contain question IDs and titles only. Unmatched searches show a helpful message and the configured fallback.
4. Search again or choose another question. Closing/reopening the widget keeps the current chat; reloading starts a new chat. Sessions expire after 24 hours and prompt for contact details again.

Mobile validation accepts 7–15 digits with an optional leading +, spaces, parentheses, and hyphens. Validation checks format, not ownership. The widget supports keyboard navigation, mobile layouts, request timeouts, and retry states.

## Saved queries

- The database stores each chat's email, mobile number, and start time, plus the welcome message, every successful search phrase, matching question list, no-match responses, and selected questions/answers. Selecting a question deleted since the search is also recorded.
- Each event has its own timestamp and belongs to its visitor's chat. Answers link to their originating search. Snapshots preserve the question/answer content even after FAQs change or are deleted.
- **AskPilot → Queries** lists chats newest first, 20 per page. Full records show chronological activity, 50 events per page, using the WordPress site timezone with seconds.
- Access requires `manage_options`. No public transcript-listing endpoint exists. The browser holds a random session token in memory; only its hash is stored in the database. Contact details are sent on start and are not repeated with every query.
- Records reflect server-processed activity, not a guarantee that a response reached the browser. Network failures before a request reaches the server cannot be recorded. Retrying after a lost response may create duplicate events.
- Records, contacts, settings, and FAQs remain after deactivation/deletion. This initial version has no retention cleanup, export, or deletion UI. No third-party AI requests or analytics are made.

## Endpoints and safeguards

All endpoints use POST under `/wp-json/askpilot/v1`:

- `/start`: `{ "email": "person@example.com", "mobile": "+15551234567" }` saves contact details and returns `session` and `welcome`.
- `/message`: `{ "session": "TOKEN", "message": "opening hours" }` returns `questions`, `search_id`, and `fallback`.
- `/answer`: `{ "session": "TOKEN", "search_id": 123, "id": 7 }` returns the selected `question` and `reply`. The question must belong to that session's search results.

Administrator writes require permissions and a WordPress nonce. Database values use WordPress helpers/prepared queries; the widget renders text safely. Start/search/answer requests share a best-effort limit of 20 requests per minute per hashed direct peer IP. Shared IPs/reverse proxies share the limit; this is not atomic abuse protection. Database failures are reported instead of silently serving unrecorded searches/answers.

This version is intended for a small FAQ collection and reads all question titles per search. AI, integrations, and advanced customization can be added later.

## Checks

```sh
php -l askpilot/askpilot.php
php -l askpilot/includes/records.php
php -l askpilot/includes/class-bsc-matcher.php
php tests/matcher-test.php
php tests/contact-test.php
php tests/queries-test.php
node --check askpilot/assets/chatbot.js
```

Workflow tests use an in-memory WordPress/database test double. Live WordPress/MySQL and browser testing remain necessary: update/activate; add/edit/delete FAQs; submit contact details; search and select a result; try a no-match phrase; verify the complete record and site timezone under Queries; check pagination and access permissions; disable the widget. Verify previous records survive FAQ edits.

Implementation references: [WordPress custom tables](https://developer.wordpress.org/plugins/creating-tables-with-plugins/) and [REST endpoints](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/).
