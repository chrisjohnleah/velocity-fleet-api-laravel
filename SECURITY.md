# Security Policy

## Supported versions

This project is in early development. Security fixes are applied to the latest release on the `main` branch.

## Reporting a vulnerability

Please **do not** report security vulnerabilities through public GitHub issues.

Instead, email **christopher.leah@happywebs.co.uk** with:

- a description of the vulnerability and its impact,
- steps to reproduce (a proof of concept if possible),
- any suggested remediation.

You'll receive an acknowledgement as soon as possible, and a fix or mitigation plan once the report has been triaged. Please give a reasonable window to address the issue before any public disclosure.

## Scope notes

This bridge persists API tokens (the access token and, for the refresh flow, the refresh token) in the database via the Eloquent token store. When reporting, please be mindful of anything touching token storage, the token-store contract, the seeding of tokens from config, or the encryption of token columns.

## Data protection (GDPR)

The v0.2 fleet platform can store vehicle/driver position history, which is **personal data** (and may constitute worker monitoring).

- **History is OFF by default.** It only persists when `velocity-fleet.history.enabled` is true.
- **PII is encrypted at rest** (`driver_name`, `vehicle_registration`, `mobile_phone`) using Laravel's `encrypted` cast (requires `APP_KEY`). Token columns are encrypted by default too; `velocity-fleet:doctor` checks this.
- **Retention is enforced** — `retention.positions_days` (default 90) prunes old rows.
- **Private devices are suppressed** from history ingestion.
- Before enabling history, complete a DPIA and ensure a lawful basis for any worker monitoring. Subject-access / erasure tooling is planned for a later release — until then, use the model + retention pruning directly.

Speeding/idling alerts are **heuristics** derived from poll cadence and should not be treated as authoritative for disciplinary purposes.
