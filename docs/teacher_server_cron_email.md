# 老師伺服器 Cron 與 Email 設定

## Cron job

正式部署後，請在老師伺服器設定每 5 分鐘執行一次：

```cron
*/5 * * * * cd /workspace/www && php tasks/run_cron.php >> storage/cron.log 2>&1
```

如果伺服器網站目錄不是 `/workspace/www`，改成實際專案根目錄即可。

## Email SMTP

`config/integrations.local.php` 可覆蓋正式寄信設定：

```php
<?php
return [
    'app' => [
        'public_base_url' => 'http://web-D1481815.140.134.26.116.nip.io/public',
    ],
    'notifications' => [
        'email' => [
            'driver' => 'smtp',
            'from_address' => 'your-account@example.com',
            'from_name' => '線上點燈系統',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_username' => 'your-account@example.com',
            'smtp_password' => 'your-smtp-password',
            'smtp_encryption' => 'tls',
        ],
    ],
];
```

常見選擇：

- Gmail：需要使用應用程式密碼，不可直接使用 Google 帳號密碼。
- 學校信箱：需確認是否允許外部 SMTP 登入。
- Mailtrap / Brevo / SendGrid：適合展示與測試，需申請 SMTP 帳密。

未填 SMTP 前，系統可建立通知紀錄與重送紀錄，但不會真正寄出 Email。
