# Dental Clinic API

REST API for a single-location dental clinic management system, built with **Laravel** and consumed by a separate Next.js frontend ([dental-clinic-front](https://github.com/shayan655/dental-clinic-front)).

## Features

- **Cookie-based SPA authentication** (Laravel Sanctum): httpOnly session cookie, CSRF protection, login rate limiting.
- **Five roles** (super admin, clinic manager, doctor, receptionist, patient), enforced on the server with policies. Hiding a button in the UI is never the only protection.
- **Patients**: self-registration, or created by the front desk. Staff can later create a login for a staff-created profile (generated temporary password, shown once).
- **Appointments**: free-slot search, "any available doctor" assignment, overlap protection with database row locking, opening-hours validation and a status workflow whose final states (completed, cancelled) are locked.
- **Medical records**: readable only by the treating doctors and the patient, editable only by the doctor who wrote the entry. Never deletable through the API.
- **Billing**: invoices are created automatically when an appointment is completed (price copied at that moment), manual invoices, mark as paid in person. There are no online payments.
- **Staff management**: create, edit and deactivate accounts (history is preserved), with a single active clinic manager.
- **Clinic settings**, weekly working hours and services (soft-deleted, so old appointments keep their service).
- **Role-specific dashboards**, a public **contact form** and a staff **inbox** for the messages.
- **170+ automated tests** covering every role against every rule, plus a GitHub Actions workflow.

## Tech stack

PHP 8.4 · Laravel 13 · MySQL · Laravel Sanctum (SPA mode) · PHPUnit · GitHub Actions

## Roles and permissions

| Capability | Super admin | Manager | Doctor | Receptionist | Patient |
|---|---|---|---|---|---|
| Staff accounts | ✅ all | ✅ doctors and receptionists | ❌ | ❌ | ❌ |
| Clinic settings and services | ✅ | ✅ | ❌ | ❌ | ❌ |
| Patient list | ✅ all | ✅ all | own patients only | ✅ all | ❌ (own profile only) |
| Create and edit patients | ✅ | ✅ | ❌ | ✅ | ❌ |
| Appointments (view) | ✅ all | ✅ all | own | ✅ all | own |
| Book an appointment | ✅ | ✅ | follow-ups for own patients | ✅ | for themselves |
| Reschedule and change status | ✅ | ✅ | ❌ | ✅ | cancel own open ones |
| Appointment notes | ✅ | ✅ | own | ✅ | ❌ |
| Medical records | ❌ | ❌ | read own patients, write own entries | ❌ | read own |
| Invoices | ✅ | ✅ | ❌ | ✅ | view own |
| Contact messages | ✅ (and delete) | ✅ (and delete) | ❌ | ✅ read | ❌ |

## Design decisions worth knowing

- **Clinical data is strictly doctor and patient.** Managers, receptionists and super admins get a `403` from the API, not just a hidden page.
- **No double booking.** Booking and rescheduling run in a transaction that locks the candidate doctors, and all slot logic lives in one service (`AppointmentScheduler`), shared by the availability endpoint and the booking itself.
- **Nothing important is deleted.** Services are soft-deleted, staff are deactivated (sessions are ended immediately), medical records and invoices have no delete endpoint.
- **Mass-assignment safety.** Roles, `is_active` and ownership columns are never fillable from request data.
- **Appointment times** are returned as clinic wall-clock time without a timezone suffix (`2026-11-16T10:00:00`), so they display the same in every browser.

## API overview

Public: `GET /api/services` · `GET /api/settings` · `GET /api/settings/hours` · `POST /api/contact`

Auth: `GET /sanctum/csrf-cookie` · `POST /api/register` · `POST /api/login` · `POST /api/logout` · `GET /api/user`

Authenticated (access depends on the role):

| Area | Endpoints |
|---|---|
| Patients | `GET/POST /api/patients` · `GET/PUT /api/patients/{id}` · `POST /api/patients/{id}/account` |
| Medical records | `GET/POST /api/patients/{id}/records` · `PUT /api/patients/{id}/records/{record}` |
| Appointments | `GET/POST /api/appointments` · `GET/PUT /api/appointments/{id}` · `GET /api/availability` |
| Invoices | `GET/POST /api/invoices` · `PUT /api/invoices/{id}` |
| Staff and doctors | `GET/POST /api/staff` · `PUT/DELETE /api/staff/{id}` (delete deactivates) · `GET /api/doctors` |
| Services and settings | `POST/PUT/DELETE /api/services` · `PUT /api/settings` · `PUT /api/settings/hours` |
| Inbox | `GET /api/contact-messages` · `GET /api/contact-messages/unread-count` · `PUT/DELETE /api/contact-messages/{id}` |
| Dashboards | `GET /api/dashboard/{manager,doctor,receptionist,patient}` |
| Profile | `PUT /api/profile` · `PUT /api/profile/password` |

## Getting started

### Requirements

PHP 8.4, Composer, MySQL, and [Laravel Herd](https://herd.laravel.com) (recommended: it makes the local domains below easy).

### Why local domains?

Sanctum's cookie mode needs the frontend and the API to share a root domain. Plain `localhost:3000` and `localhost:8000` do not work reliably, so the project uses `app.clinic.test` (frontend) and `api.clinic.test` (this API).

### Setup

```bash
git clone <this-repository-url> dental-clinic-backend
cd dental-clinic-backend
composer install
cp .env.example .env
php artisan key:generate
```

Create two empty MySQL databases: `dental_clinic_backend` (the app) and `dental_clinic_testing` (used only by the tests, which wipe it on every run).

Set these values in `.env` (plus your database username and password):

```dotenv
APP_URL=http://api.clinic.test
APP_TIMEZONE=Europe/Amsterdam

DB_DATABASE=dental_clinic_backend

SESSION_DOMAIN=.clinic.test
SESSION_SECURE_COOKIE=false
SANCTUM_STATEFUL_DOMAINS=app.clinic.test
FRONTEND_URL=http://app.clinic.test
```

Then:

```bash
php artisan migrate --seed
herd link api.clinic        # run inside the project folder: serves it at http://api.clinic.test
```

The frontend is served at `http://app.clinic.test`; see its README for the matching `herd proxy` command.

### Demo accounts

`php artisan db:seed` (development only, it does nothing in production) creates these accounts. The password for all of them is `password`.

| Role | Email |
|---|---|
| Super admin | `admin@clinic.test` |
| Clinic manager | `manager@clinic.test` |
| Doctor | `doctor@clinic.test`, `doctor2@clinic.test` |
| Receptionist | `reception@clinic.test` |
| Patient | `patient@clinic.test` |

It also adds sample services, appointments, medical records, invoices, and two staff-created patients without a login (`anna@example.com`, `tom@example.com`) to try the "Create login" flow.

## Tests

```bash
php artisan test
```

`phpunit.xml` points the tests at the `dental_clinic_testing` database, never at your development data. The suite runs automatically on every push through GitHub Actions (`.github/workflows/tests.yml`).

## Production notes

The seeder does nothing in production, so create the first account with:

```bash
php artisan clinic:create-admin
```

Before going live: `APP_ENV=production`, `APP_DEBUG=false`, HTTPS on both domains with `SESSION_SECURE_COOKIE=true`, a freshly generated `APP_KEY`, a dedicated MySQL user (never `root`), tested backups, and `php artisan optimize` on each deploy.

## Roadmap

Email notifications and reminders, online payments, a self-service password reset, per-doctor schedules, a medical-record audit trail and encryption of clinical text at rest.

## Author

Built by Shayan.