# Secure Configuration

This directory stays **out of the web root** and contains only private runtime
configuration. Everything here is ignored by git except for these docs and the
`.example.php` templates.

Keep the workflow below so secrets never leave the server:

1. Copy the relevant `*.example.php` file to its real name (drop the `.example`).
2. Fill in the values locally on the server or in your `.local` overrides.
3. Never commit the real files. The `.gitignore` in the repo blocks them already.

Files you may want to provide:

- `admin-credentials.php` — defines `ADMIN_USERNAME` / `ADMIN_PASSWORD`.
- `db-credentials.php` — defines `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `DB_NAME`.
- `sfm-secrets.php` — optional array for extra shared secrets.

Rotation checklist lives in `docs/deployment-secrets.md`.

If new secrets are required, create a matching `*.example.php` so teammates know
which keys to supply without exposing real values.

Run `php secure/scripts/secrets_guard.php` anytime you modify ignore rules or
add new secret files; it will fail fast if something risky slips into git.
