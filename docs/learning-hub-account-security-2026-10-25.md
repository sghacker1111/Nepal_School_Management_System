# Learning Hub and account security

## Study notes and local OCR

Add Study Note now offers editable Class, Subject, Chapter and optional Topic selectors. Subjects follow the school's active offerings and the teacher's assignments. Changing a parent selection clears child choices and reloads the permitted curriculum; late responses cannot overwrite a newer selection. The page filters also choose a valid subject after a class change instead of retaining an empty subject ID.

Notes have a heading and a rich body for reading materials, formulas, tables and images. They use the same formatting, Source code and Mathematics in TeX tools as Exam Questions, without question types, marks or cognitive rules. Existing note IDs, content and local/shared references are preserved during ordinary edits. Failed validation retains a draft for correction.

The OCR scanner also uses that shared editor. Recognition remains Tesseract running in the browser; there is no GPT connection or paid AI service. The existing OCR engine and language files may download before local processing. Auto-format preserves text line boundaries and escapes recognized text before creating question paragraphs.

## Sign-in protection

- Five consecutive incorrect sign-ins suspend the account for 30 minutes.
- Attempts during suspension do not consume retries or extend the suspension.
- After the wait, two further incorrect sign-ins lock the account until recovery.
- A successful sign-in before the next lock clears the failure count.
- Counts belong to the account ID, persist in the database, and apply across browser sessions and IP addresses. Existing network throttling remains an additional control.
- Locking and unlocking revoke existing sessions. Account access/password edits cannot clear the lock.

In **User management**, expand **Unlock account**, verify the user's identity, and enter the acting administrator's password. School admins may unlock lower-role users only in their own school. Only the main admin may unlock school admins. The main admin cannot use this control to unlock themselves or another main admin.

The main admin dashboard prompts for setup when no backup codes exist. The account menu's **Account security** page lets the main admin generate ten backup codes after confirming their current password. Save these offline immediately; they are only shown in the generation response. Generating a new set invalidates every unused old code. Each code is 128 random bits, and only its SHA-256 hash is stored. This is password recovery, not a second authentication factor.

The login page links to **Administrator account recovery**. An unused code plus a new matching password resets the main admin password, consumes the code, clears the lock and revokes all sessions. Recovery requires a normal sign-in afterwards. Recovery failures have independent account/network limits and generic responses. Codes and passwords are never written to audit logs.

Security state and code hashes use namespaced rows in the existing `app_meta` table. Each security mutation locks the user row in a transaction. No schema upgrade or school data rewrite is needed. Existing sessions created before this release must sign in again because the credential fingerprint now includes the revocation epoch. Password-confirmation controls for sensitive actions retain their separate throttling and refuse already locked accounts.

The policy and recovery implementation were checked against the [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html) and [Forgot Password Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html).

## Verification

Synthetic service/HTTP tests cover both lockout stages, CSRF, independent sessions, cross-school denial, peer-admin denial, password-edit bypass rejection, single-use codes, regeneration, recovery throttling and session invalidation. Parallel-process checks cover failure counting and code consumption. The HTTP security suite also runs against a disposable native MySQL 8 database.

Note tests exercise add/edit and catalog authorization, rich content/formula preservation, optional topics and draft recovery. DOM checks exercise changing selectors and stale requests; real PHP-rendered Learning Hub and OCR pages mount the shared editor with TeX and Source code tools. The release gate includes these checks alongside existing security, question authoring and frontend lifecycle suites.

Local validation completed: 81 service assertions, 76 HTTP security requests on SQLite and native MySQL, parallel lockout/recovery checks on both engines, 25 rendered learning-page mounts, the security release gate, frontend lifecycle checks and deployment safeguards. Live authenticated account operations are intentionally verified using disposable fixtures rather than locking real school users.
