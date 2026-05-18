# UGNAYAN Web System

This workspace is organized around one shared MySQL database connection:

- Database config: `config/database.php`
- Full schema for modules 1 to 10: `database/ugnayan_database_modules_1_to_10.sql`
- App entry redirect: `index.php`

## Setup

1. Open phpMyAdmin or your MySQL client.
2. Import `database/ugnayan_database_modules_1_to_10.sql`.
3. Confirm the database name is `ugnayan_db`.
4. If your MySQL username or password is not `root` with a blank password, update `config/database.php`.
5. Open `index.php` from your local PHP server. It redirects signed-out users to Module 1 login and signed-in users to their role-based portal.

## Default Admin

- Email: `admin@ugnayan.com`
- Password: `admin123`

## Notes

Module 1 uses the resident verification flow. Modules 2 to 10 are available through the shared left-side navigation and show live counts from their own database tables.
