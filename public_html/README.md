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

