# Mama Care — Lubaga Hospital MCH

PHP system for antenatal, maternity, and postnatal care at Lubaga Hospital. It follows the Uganda HMIS maternal register flow: one patient, pregnancy episodes, ANC visits, delivery, then postnatal follow-up.

## Requirements

- PHP 8.1+
- MariaDB 10.5+ or MySQL 8
- Apache with `mod_rewrite` (or equivalent)

## Setup

1. Copy `.env.example` to `.env`. Set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` to the values supplied by your database host. Keep `.env` private; it is ignored by Git.
2. Create an empty database using the same name as `DB_NAME`, with `utf8mb4` / `utf8mb4_unicode_ci`.
3. Select that database, then import `sql/schema.sql` followed by `sql/seed.sql`. The schema does not create or select a hard-coded database.
4. Create the initial administrator from an interactive terminal with `php create_admin.php`. Choose a unique password of at least 12 characters. The command only works while the `users` table is empty.
5. Point the web root at this folder.

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
- Live patient dump (`u454222977_lubaga.sql`) is not in git. It contains identifiable clinical data.

## Remaining gaps (not in this pass)

- Role-based screens: `requireRole()` exists, but most pages only check login.
- No user-admin UI (create / deactivate staff).
- `next_visit_date` is stored when entered; the dashboard still also infers a 28-day interval for overdue lists.
- No appointment calendar or SMS reminders.
- Partner HIV codes on the old dump were free text (`neg`, `good`) rather than HMIS codes.
- Twins / multiple births are still one baby row per delivery.

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
api.php                 JSON helpers
sql/                    schema, lookup/demo seeds, upgrade
```
