# Workspace navigation fixes

- The top-bar menu button now hides or shows the desktop sidebar and expands the work area. Only this display preference is saved locally. The mobile drawer remains independent.
- Feature search includes add/import screens, marks entry, ledgers, paper groups, notes, OCR, account recovery settings, and other internal destinations. Server-generated shortcuts inherit the visible parent menu's package scope and apply role/read-only restrictions. Search never indexes student or question records.
- Failed partial GET navigation automatically opens the requested full page when no draft or unresolved save exists. A draft created while a request is pending prevents recovery from leaving the page. POST requests are never replayed. Question-list fragment failures use the same safe full-GET recovery.
- Background catalog/editor initialization no longer creates false unsaved-change warnings. Actual input, rich-editor changes, dynamic allocations, pending saves, and uncertain saves remain protected. Generated paper previews are read-only output, so displaying one alone does not create an unfinished draft.
- Hidden archive confirmations no longer trigger global warning toasts on page load.
- Sign-out confirmation is centered on the login gradient and retains POST/CSRF protection.

Verification uses disposable school fixtures, including role-scoped shortcut destinations, native logout security, real shipped Alpine/HTMX/MathJax initialization, editor changes, and navigation failures. Local browser testing reproduced the original generator error and confirmed direct full-page loading works; browser control was subsequently disabled in Work settings. No production school records were changed during verification.
