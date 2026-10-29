# Mama Care — Lubaga Hospital MCH

PHP system for antenatal, maternity, and postnatal care at Lubaga Hospital. It follows the Uganda HMIS maternal register flow: one patient, pregnancy episodes, ANC visits, delivery, then postnatal follow-up.

## Requirements

- PHP 8.1+
- MariaDB 10.5+ or MySQL 8
- Apache with `mod_rewrite` (or equivalent)

## Setup

1. Copy `.env.example` to `.env`. Set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` to the values supplied by your database host. Keep `.env` private; it is ignored by Git.
2. Create an empty database using the same name as `DB_NAME`, with `utf8mb4` / `utf8mb4_unicode_ci`.
3. For a self-contained import including an initial administrator, generate a private bundle with `php sql/build_install.php "$HOME/mama-care-install-private.sql"`. The script generates a unique 36-character password, stores only its PHP password hash in the SQL, and prints the password once. Keep the bundle and password private; the output is outside the repository and restricted to your user.
4. Alternatively, import the committed `sql/mama-care-install.sql`, which contains the complete schema and required lookup data but no account credentials. After import, create the initial administrator with `php create_admin.php` from an interactive terminal.
5. In either option, select a new, empty database in phpMyAdmin before importing. The SQL does not create or select a hard-coded database and must not be imported over an existing production database.
6. Point the web root at this folder and sign in. Change the initial password after first login.

The generated install bundle includes the full schema and lookup data used by maternal care, newborn follow-up, screening, referrals, appointments, reports, audit logging, commodities, and staffing. It contains no sample or patient records. The schema uses InnoDB, foreign keys, unique constraints, and indexes for the system's relationship and date-based queries; it does not add speculative partitioning or synthetic data.

`sql/mama-care-install.sql` is the tracked, credential-free source bundle. Refresh it after schema or lookup changes with `php sql/build_install.php --refresh-source`; review and commit that SQL change with the related schema/seed changes.

To create an administrator directly on a server where the app is already configured, `php create_admin.php` remains available for an empty `users` table.

For local demonstrations only, import `sql/demo_seed.sql` instead of `sql/seed.sql`. It includes sample clinical records and demo users, all using password `Admin123`:

| Username | Role |
| --- | --- |
| `admin` | admin |
| `dr_namukwaya` | doctor |
| `midwife_grace` | midwife |
| `reception_john` | receptionist |
| `manager_mary` | manager |

Never import `demo_seed.sql` into a live database. These demo credentials and records are not suitable for production.

## What this cleanup fixed

- Database credentials are read from `.env`; `.env.example` contains placeholders only and is safe to commit.
- Fresh installs use the selected database rather than a hard-coded `mama_care` database name.
- Lookup data is separate from demo users and sample patient records.
- Passwords now use `password_hash()` / `password_verify()`. Old MD5 hashes are verified once and upgraded on login.
- Foreign keys, unique visit/delivery keys, and `recorded_by` audit columns.
- Empty enum values (for example `referral_type = ''`) stored as `NULL`.
- The fresh schema accepts legacy role values; `super_admin` is recognized as the existing `admin` role without granting `staff_admin` the same privilege.
- Dashboard “Record Visit” used the patient id as `episode_id` — that link now uses the pregnancy episode.
- Excel export in reports wrote broken row tags (`32...6` instead of `<tr>...</tr>`).
- Clinical pages and reports are available to authenticated staff; staff-account and audit-log administration is limited to administrators.
- Appointment scheduling, status tracking, staff-account administration, and a paginated audit-log viewer are available from the navigation.
- Delivery records support up to eight newborns, and postnatal visits can be linked to the newborn receiving care. Delivery and perinatal reports count individual newborns while retaining compatibility with older single-baby records.
- A returning mother can be linked to her existing patient record and registered for a new pregnancy episode after a prior episode has ended. Each episode has its own client number, ANC history, and delivery; one active episode per mother is enforced by the application.
- First ANC and follow-up dates are stored as entered, invalid/future clinical dates are rejected, and estimated due dates are calculated from LMP. Dashboard follow-up uses an explicitly entered next visit or a scheduled appointment rather than assuming a fixed 28-day interval.
- Child encounters are recorded per newborn with dated growth, feeding, and developmental observations through 730 days after birth. Immunization events are separately structured by vaccine name, dose label, date, status, and optional lot number. Alongside pregnancy records, this supports continuity across approximately the first 1,000 days from conception without inferring an immunization schedule or diagnosing from growth values.
- Structured screening records capture the test, date, reported status, value/unit, and result notes. Abnormal/pending counts are surfaced for follow-up; the application does not apply clinical thresholds or generate diagnoses.
- Referrals can be linked to the mother, pregnancy episode, newborn, and a recorded screening result, with urgency, receiving destination, follow-up date, completion status, and outcome notes.
- Resource planning records commodity receipts/issues/adjustments and monthly cadre-level staffing capacity. Stock on hand is ledger-based, with transparent 90-day issue-rate indicators; workload counts reflect recorded encounters attributed to staff accounts.
- Report exports validate their requested report/date range and HTML-escape exported database values.
- Live patient dump (`u454222977_lubaga.sql`) is not in git. It contains identifiable clinical data.

## Known limitations

- Pregnancy dating currently uses LMP + 280 days; ultrasound-confirmed dating and a dating-confidence/source field should be added with the hospital’s approved clinical data standard.
- Appointments are scheduled and listed, but there is no calendar view or SMS reminder service.
- Partner HIV codes on the old dump were free text (`neg`, `good`) rather than HMIS codes.
- Existing delivery records remain readable as single-baby records; newborn rows are created for deliveries recorded after the multiple-birth workflow is deployed.
- Uganda MoH/HMIS and facility-approved protocols should be checked and versioned by clinical leadership before any screening result definitions, immunization schedules, pregnancy-contact cadence, or referral thresholds are treated as clinical rules. The current forms capture observations and workflow status but deliberately do not infer diagnoses or care schedules.
- Screening history and child immunization history use structured event records, but a clinically validated test catalog, controlled vaccine/dose vocabulary, growth-chart interpretation, and approved reminder schedule remain future work.
- Commodity planning is a basic, auditable signal from the locally recorded stock ledger and 90-day issue rate; it is not an automated procurement order or a forecast until completeness, lead times, and stock counts are validated. Staffing capacity and encounter totals are captured separately and need facility review before being used for workload decisions.

For an existing database, back it up before applying `sql/upgrade_existing.sql`. Review the migration against the exact database version and schema first; the fresh-install schema already contains these additions.

## Layout

```
config.php              shared bootstrap, DB, helpers
create_admin.php        one-time CLI bootstrap for the first administrator
login.php / logout.php
index.php               dashboard
patients.php            registry
register_patient.php    ANC first visit
anc_visit.php           ANC follow-up
delivery_admission.php  maternity / delivery
postnatal_visit.php     PNC
patient_profile.php     full journey
search_global.php
reports.php
appointments.php         appointment scheduling and status tracking
child_followup.php       per-newborn child health follow-up through 730 days
screenings.php           structured maternal/newborn screening register
referrals.php            referral and completion tracking
resource_planning.php    commodity ledger, staffing capacity, and workload
users.php                admin-only staff account management
audit_log.php            admin-only activity log
system_check.php       admin-only database schema and runtime check
api.php                 JSON helpers
sql/                    schema, lookup/demo seeds, upgrade
```

## Deployment health checks

Administrators can open **System Check** from the navigation to verify that the database engine version, feature tables, and required columns are present. It reads schema metadata only and does not display patient data or credentials. A missing table or column can explain HTTP 500 responses after a partial or incomplete upgrade; do not rerun the full upgrade file to repair one missing item because it is not safe to rerun.

If System Check reports that the schema is ready but a page still returns HTTP 500, use the hosting control panel's PHP error log to find the fatal error for that request and share only the relevant, sanitized error message and affected page URL. Do not enable public error display on a live site or share `.env` values, patient data, or full server logs.
