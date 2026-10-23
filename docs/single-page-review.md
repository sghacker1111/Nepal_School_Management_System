# Independent review — 2026-10-17

Scope: the approved single-page/clean-URL migration in the isolated migration
branch; no production writes, no schema or accounting-calculation refactor.
Reviewer: independent GPT-6.1 Sol, as approved by the owner. All implementation
was completed natively in the primary task; this was the final independent audit.

Four reproducible issues were found and fixed:

| Finding | Fix and regression |
| --- | --- |
| New edits during a slow save could be marked saved and replaced | Compare against the submitted form snapshot; retain newer edits as unsaved for both 200 and 204 responses |
| Import validation left the button/busy latch disabled | The app form controller owns pending state for enhanced forms; corrected imports can retry after 422/419 without reloading |
| A validation error under a different account/school retained private old content | Compare response context before the draft-retention branch; reuse shell clearing and authorized rebuild |
| Previewing a bill reset its unsaved state | Preserve unsaved state after preview remount; previews never mean an invoice was saved |

The reviewer reran its integrated preview reproduction: dirty state remains true,
navigation requests confirmation, and cancel preserves the draft. Its final focused
rerun passed 30/30 with no remaining concrete findings. These are disposable local
DOM/HTTP checks, not physical print, real-browser or MySQL production certification.

Primary-task final checks additionally cover native print/export submit buttons,
URL-object CSRF wrapping, narrow-list school-context checks, no browser-storage
school HTML, fixed adapter/library loading and deployment exclusions. Full regression
results and the remaining browser gate are recorded in frontend-modernization.md.
