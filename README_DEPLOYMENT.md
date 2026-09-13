# N&D Financial System - Hostinger Premium package

This build uses a static browser interface with a PHP 8.2/MySQL API, so Node.js is not required on the server.

1. Create a MySQL database and user in hPanel.
2. Copy `app_config/config.example.php` to `app_config/config.php` and enter the database credentials and administrator password hash.
3. Upload `public_html` to the selected domain's document root.
4. Keep `app_config` beside `public_html`, not inside it.
5. Open the site. The API creates the schema and imports the 64 reconciled August 2026 workbook records automatically.

Requirements: PHP 8.2+, PDO MySQL, mbstring, MySQL 8 or compatible MariaDB, and HTTPS.

Money is stored as integer centavos. Every edit, void, category addition, import, and month closing is audited. Closed months cannot be changed.
