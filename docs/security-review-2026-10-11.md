# Security review — 11 October 2026

Reviewed PHP entry points, shared authorization, tenant references, imports/uploads, rich content, question/grid writes, print data and private-file rules. Regression tests use disposable databases.

## Implemented and checked

- Re-check packages, live role/status, tenant references and teaching ownership on every request, including question edits/imports, AJAX reads and paper/grid creation.
- Block removed quick-create endpoints. Subject/class creation remains administrator-only; ledger viewing never grants extra marks-editing rights.
- CSRF protection covers writes and logout; GET logout only confirms. Routine changes require current administrator password, run transactionally and archive previous schedules.
- Reject array/non-integer identifiers, invalid topic relationships, array uploads and oversized/invalid grid payloads. Existing image MIME/size checks and disabled fresh-install admin remain.
- Strict HttpOnly, SameSite, cookie-only sessions, login ID regeneration and live account checks. Login limits cover user/IP pairs, aggregate account attempts and aggregate network attempts.
- Keep rich HTML sanitization. Encode embedded JSON/onclick values. Catalogue chapters use DOM text, not HTML; TeX previews and Nepali MathJax repairs use textContent. Imports reject unmatched classes/subjects rather than choosing the first entry. Database details are logged, not returned. Uncaught web exceptions return generic 500 responses.
- Add nosniff, frame denial, same-origin referrer and private/no-store headers. Baseline CSP blocks objects, framing, external form actions and base URL injection. This is NOT a strict script CSP: the legacy UI still has inline scripts and external CDNs.
- Protect backup/database/journal variants and private source/config files in Apache and the local router. FTP runtime exclusions and serialized, non-destructive deployment are unchanged.

## Hosting/operator requirements

- Use HTTPS and current PHP/database/OS/web-server security updates. Enable ERP_TRUST_PROXY=1 only behind a trusted proxy that strips client-supplied forwarding headers. Set ERP_ALLOWED_HOSTS to exact allowed host names/ports.
- Verify Apache honors .htaccess; other servers need equivalent deny rules. Restrict filesystem permissions and keep live backups outside the public root where possible.
- Use unique passwords, least-privilege database/FTP credentials, rotation, monitored logs, offsite backups and network-level rate limiting. Login limits alone do not stop distributed bots.
- The deployment preserves config/config.php. New security helpers also load through authentication/database code, so hosting configuration need not be overwritten. Configure environment options separately.
- Stronger follow-ups include MFA, nonce-based strict script CSP with removal of inline handlers, self-hosted/pinned third-party assets, infrastructure assessment, document malware scanning and independent authenticated production penetration testing.
- No production attack scan or hosting changes were performed. No claim is made that all vulnerabilities have been eliminated.

Guidance: [OWASP authorization](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html), [file upload](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html), and [CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html). Per-request authorization is server-enforced; CSP supplements safe rendering.
