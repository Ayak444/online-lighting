# Deployment Guide

## Remote Layout

Upload the contents of this `online-lighting` folder into the Apache web root.

Expected public URLs:

- Home: `http://web-D1481815.140.134.26.116.nip.io/`
- Member login: `http://web-D1481815.140.134.26.116.nip.io/public/login.php`
- Member register: `http://web-D1481815.140.134.26.116.nip.io/public/register.php`
- Admin login: `http://web-D1481815.140.134.26.116.nip.io/admin/login.php`

## Database

Import SQL in this order:

1. `sql/schema.sql`
2. `sql/seed.sql`

Default admin account:

- Email: `admin@example.com`
- Password: `password`

## Database Config

Create `config/db.local.php` on the remote server:

```php
<?php
declare(strict_types=1);

return [
    'host' => 'localhost',
    'port' => '3306',
    'name' => 'D1481815',
    'user' => 'D1481815',
    'pass' => 'YOUR_MYSQL_PASSWORD',
];
```

## Smoke Test

1. Open the home page.
2. Register a member account.
3. Confirm the new row exists in `users`.
4. Log out and log in again.
5. Log in to admin with `admin@example.com`.
6. Confirm non-admin accounts cannot open `/admin/dashboard.php`.

