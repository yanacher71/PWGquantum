# Cable QR access

Public QR labels encode https://pwgquantum.com/c/<32 lowercase hex characters>.
Each cable gets bin2hex(random_bytes(16)), stored in pwg_cables.public_token with
a case-sensitive UNIQUE index. Serial numbers stay unchanged and can still be
printed beside the QR. Anyone holding a QR link can view that cable and its PDF.

## Database and rollout

Only pwg_cables needs a token column/index. Batch pages remain internal admin
records, so pwg_batches needs no new field.

1. Back up the database. Run migrations/20261027_cable_public_tokens.sql once
   in phpMyAdmin. This PR does not apply production SQL.
2. Copy tools/backfill-cable-tokens.php to the host and run through SSH:
   php tools/backfill-cable-tokens.php /absolute/path/to/pwg-db-config.php
   It fills NULL tokens only, preserves existing tokens, retries collisions,
   and can be rerun safely. It does not print tokens. HTTP execution is disabled.
3. Confirm SELECT COUNT(*) FROM pwg_cables WHERE public_token IS NULL returns 0.
4. Deploy PHP files, .htaccess, and assets/vendor/qrcode.js together.
   Rewrite rules use Apache 2.4 END; verify on Hostinger staging.
5. Sign in to /admin/cables.php and use Find Cable by Serial Number,
   Public QR Link, or Print QR Label.

New cable records get tokens; edits keep them. During rollout NULL is allowed,
but those records have no public access. SQL imports must generate secure tokens
or be followed by the backfill. Do not use UUID(), RAND(), serial hashes or one
token shared by a batch.

Existing serial-only printed QR labels must be replaced. Publicly redirecting
serial links to token links would restore the enumeration flaw.

## Access boundaries

- Cable pages and PDFs both resolve the token through the database.
- Direct cable.php?token= and report.php?token= use identical authorization.
- Legacy /c/SERIAL, /c/SERIAL/report, and ?id=SERIAL require the existing
  pwg_admin session and a configured admin password. Logged-out requests get 404.
- A bad token never falls back to serial lookup, even for admins.
- batch.php requires admin login; batches no longer expose cable lists publicly.
- Upload/update APIs keep API-key authentication and serial inputs, but return
  token-based report URLs. Run backfill before resuming report jobs; missing
  tokens return 409.
- Report buttons use the guarded local PDF handler, rather than stored external
  document URLs. Ensure PDFs exist in the private pwg-reports/SERIAL.pdf
  directory before deploying. External copies and downloaded reports cannot be
  revoked by these site changes.
- Customer pages, PDFs and cable labels send no-store/no-referrer headers.
- Cable QR generation is local; tokens are not sent to an external QR service.
  Batch QR labels continue to require admin access.

Tokens do not expire so printed labels remain valid. To revoke one QR, generate a
new random_bytes(16) token for that cable and reprint the label. Avoid logging
tokens in analytics or publishing them in listings.

## Validation

Run php tests/cable-access.php and PHP syntax checks. The supplied GitHub workflow
runs these on the PR; they have not been executed locally because the workspace
became unavailable. Local QR SVG generation and whitespace checks passed before
workspace loss.

Before production, test against staging MySQL and Apache:
- Token A opens cable A and PDF A; B opens B.
- Anonymous serial lookups and serial-only PDFs return no cable data.
- Token A with serial B still selects A, never B.
- Missing/malformed/nonexistent tokens do not disclose records.
- Logged-in admin serial lookup and internal batch pages work.
- New cables have distinct tokens; editing preserves them.
- Running backfill twice preserves all issued QR links.
- Token QR labels scan correctly.
- Customer PDF jobs return token links and guarded PDFs remain available.

No PR merge, production deployment or database mutation is performed here.
Database migration and Apache/MySQL integration validation remain deployment steps.

## Vendored dependency

assets/vendor/qrcode.js: Kazuhiko Arase qrcode-generator, MIT.
Pinned Git blob df13f829bf41f36b82f0ed85751ed3b4c39cfeb8.
The license is stored alongside the file.
