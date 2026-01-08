# Phase 1 Multi-Tenant Reporting System (Plain PHP)

This is a **framework-free** Phase 1 foundation for a multi-tenant reporting system built with **plain PHP 8+**, **MySQL (PDO)**, **HTML/CSS**, and **vanilla JavaScript**.

Phase 1 uses **deterministic mock analytics** (no Google APIs). Reports are generated manually by an ADMIN and stored as **JSON snapshots** in the database.

## Upload / Deployment (shared hosting / cPanel)

- Upload the entire contents of this `public_html/` folder into your hosting account’s `public_html/` directory.
- Ensure your hosting uses **PHP 8+**.

## Database setup (phpMyAdmin)

1. Create a new MySQL database (example: `reporting_system`).
2. Import `sql/schema.sql`
3. Import `sql/seed.sql`

## Configure DB connection

Edit:

- `includes/config.php`

Set:

- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

If you upload into a subfolder instead of the document root, also set:

- `BASE_PATH` (example: `/reports`)

## Default admin credentials

- Email: `admin@example.com`
- Password: `admin123`

There is also a sample user:

- Email: `user@example.com`
- Password: `user123`

### Important note about seeded passwords

On shared hosting you may not have CLI access to generate password hashes. The seed file stores passwords using a one-time marker format (`LEGACY:<password>`). On the **first successful login**, the application automatically converts that account to a secure `password_hash()` value.

After first login, the stored password is a normal hash and works like any other account.

## First-run checklist

1. Visit `index.php` in your browser and log in as `admin@example.com`.
2. Go to **Projects** and create your first real project (or edit the example).
3. Go to **Assignments** and assign users to projects (USER accounts only see assigned projects).
4. (Optional) Go to **Notes** and add a monthly work summary.
5. Go to **Generate** (single project) or **Generate All** (all projects) to create a monthly snapshot.
6. Go to **Dashboard** and click **View report**.

## Removing seed data (recommended for production)

After confirming the system works:

- Delete the sample user and example project from the Admin UI, or remove them from the database:
  - `DELETE FROM user_project WHERE user_id IN (SELECT id FROM users WHERE email='user@example.com');`
  - `DELETE FROM users WHERE email='user@example.com';`
  - `DELETE FROM projects WHERE name='Example Project';`
- Change the default admin password in **Admin → Users** (edit admin user and set a new password).

## What Phase 1 includes

- Session-based login (`password_hash` / `password_verify`)
- ADMIN / USER roles
- Projects
- User–project assignments (multi-tenant access control)
- Manual monthly report generation and archive
- Report view with Chart.js (CDN)
- CSRF protection for all POST forms
- Prepared statements everywhere
- Output escaping (`htmlspecialchars`)
- Basic security headers:
  - `X-Frame-Options`
  - `X-Content-Type-Options`
  - `Referrer-Policy`

## What Phase 1 intentionally does NOT include

- GA4/GSC API calls (planned for later phases)
- Cron jobs / background workers (everything is manual and immediate)

## Phase 2: GA4 setup (Visitors Overview only)

Phase 2 keeps the Phase 1 structure, but can optionally pull **real GA4 Visitors Overview** data using a **Google Cloud Service Account JSON key**.

### 1) Google Cloud: enable Analytics Data API

- In Google Cloud Console, enable **Google Analytics Data API** for your project.

### 2) Create a Service Account and download JSON

- Create a Service Account in Google Cloud and download its **JSON key**.

### 3) Upload `service-account.json` (NOT publicly accessible)

Upload the JSON key to:

- `public_html/includes/keys/service-account.json`

That folder ships with an `.htaccess` that denies web access. Do **not** upload the key anywhere else (and never commit it to git).

Token caching uses:

- `public_html/includes/cache/` (must be writable by PHP)

### 4) Configure `GOOGLE_SA_KEY_PATH`

Edit:

- `includes/config.php`

Set:

- `GA4_ENABLED` to `true`
- `GOOGLE_SA_KEY_PATH` to the key location

Defaults already point to:

- `public_html/includes/keys/service-account.json`

### 5) Add the Service Account email to your GA4 property

In Google Analytics (Admin → Property access management):

- Add the **service account email** (`client_email` in the JSON)
- Grant at least **Viewer** (or Analyst)

### 6) Set `ga4_property_id` per project

In the app:

- Admin → Projects → set **GA4 Property ID** (digits only) for each project

### 7) Generate report

- Admin → Generate (single project) or Generate All

If GA4 fails, the report still generates using mock data and is marked **PARTIAL** with a visible GA4 error message.

### One-time DB migration (adds PARTIAL status)

If you already imported Phase 1 schema, update the `monthly_reports.status` enum to include `PARTIAL`:

```sql
ALTER TABLE monthly_reports
  MODIFY status ENUM('READY','PARTIAL','GENERATING','ERROR') NOT NULL DEFAULT 'GENERATING';
```

