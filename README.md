# Mama Care — Lubaga Hospital MCH

PHP system for antenatal, maternity, and postnatal care at Lubaga Hospital. It follows the Uganda HMIS maternal register flow: one patient, pregnancy episodes, ANC visits, delivery, then postnatal follow-up.

## Requirements

- PHP 8.1+
- MariaDB 10.5+ or MySQL 8
- Apache with `mod_rewrite` (or equivalent)

## Setup

1. Copy `.env.example` to `.env` and set the database credentials.
2. Create the database:
   - **New install:** import `sql/schema.sql`, then `sql/seed.sql`.
   - **Existing Lubaga database:** run `sql/upgrade_existing.sql` before deploying this code. It adds foreign keys, `recorded_by`, `next_visit_date`, and unique constraints.
3. Point the web root at this folder.

Demo users (seed only) all use password `Admin123`:

| Username | Role |
| --- | --- |
| `admin` | admin |
| `dr_namukwaya` | doctor |
| `midwife_grace` | midwife |
| `reception_john` | receptionist |
| `manager_mary` | manager |

Change these passwords after first login. Do not use the seed users in production.

## What this cleanup fixed

- Database credentials moved out of `config.php` into `.env` (not committed).
- Passwords now use `password_hash()` / `password_verify()`. Old MD5 hashes are verified once and upgraded on login.
- Foreign keys, unique visit/delivery keys, and `recorded_by` audit columns.
- Empty enum values (for example `referral_type = ''`) stored as `NULL`.
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
sql/                    schema, seed, upgrade
```
