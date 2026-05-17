# Architecture Baseline

This project follows `功能架構設計圖.docx` as the source of truth.

## ER Model To Tables

| ER entity | Database table | Notes |
| --- | --- | --- |
| 使用者帳戶 | `users` | Includes password, email/phone/Line/Google login fields, name, birthday, birth clock time, derived lunar birthday, zodiac, annual reminder. |
| 第三方登入身分 | `auth_identities` | Stores Google / LINE linked identities so one member can bind more than one provider. |
| OAuth 驗證狀態 | `oauth_states` | Short-lived state records for login and account-link callbacks. |
| 手機登入驗證碼 | `phone_login_codes` | One-time phone login verification codes with expiration and attempt count. |
| 眷屬與祈福對象 | `dependents` | One user can own many dependents/prayer targets; one `本人` record is synced from member data. |
| 問題回饋 | `feedbacks` | User Q&A, bug reports, admin reply status. |
| 權限角色 | `roles`, `user_roles` | RBAC and multi-role identity. |
| 資訊展示 | `articles` | Announcements, blog, temple history, deity intro, lantern intro. |
| 燈種 | `lantern_types` | Uses `type_id`; stores name, suggestion, price, image, active state. |
| 實體燈位 | `lamp_positions` | Physical coordinates and status: available, maintenance, occupied. |
| 年度燈期設定 | `lamp_service_periods` | Shared yearly registration, opening, and expiration dates used by checkout, lamp positions, and reminder jobs. |
| 年度流年規則 | `annual_flow_rules` | Current-year rule table for smart renewal recommendations by yearly flow logic. |
| 年度流年表內容 | `annual_flow_entries` | Current-year public flow table with zodiac, age list, notices, appendix, and suggested lanterns. |
| 購物車 | `carts` | One cart per user. |
| 購物車明細 | `cart_items` | Cart, lantern type, and prayer target relationship. |
| 訂單 | `orders` | Total amount, order status, review status. |
| 點燈訂單明細 | `order_items` | Uses `detail_id`; order, target, lantern type, lamp position, expiration date. |
| 金流對帳紀錄 | `payments` | Payment method, status, transaction/webhook payload. |
| 電子發票 | `invoices` | One invoice/receipt per order. |
| 操作軌跡紀錄 | `audit_logs` | Admin and system operation logs with IP and timestamp. |
| 批次排程任務 | `scheduled_jobs` | Expiration reminders and event broadcasts. |
| 推播通知中心 | `notifications` | Email, SMS, Line, system notifications and e-receipt messages. |
| 會員通知偏好 | `user_notification_preferences` | Stores per-channel opt-in settings for Email, SMS, LINE, and system notifications. |
| 通知投遞紀錄 | `notification_deliveries` | Stores actual delivery attempts, provider response, retries, and skipped reasons. |
| 統計資料 | `statistics` | Monthly revenue, total lantern count, count by lantern type. |

## Required Main Flow

1. User registers or logs in.
2. User creates personal and dependent/prayer target data.
3. System derives lunar birthday, zodiac, and birth-hour branch from Gregorian birthday and clock time.
4. User views announcements, temple culture content, lantern suggestions, and the visual lamp wall.
5. User first selects a prayer target in the lantern hall; the system evaluates the target's derived birth profile against annual rules and marks recommended lanterns before the user chooses final items.
6. User adds selected lanterns into cart and creates order/payment records. The order inherits the active yearly lamp period so all users in the same service year share one expiration date.
7. In demo mode, the member can skip real payment from the order page; the system marks the payment as mock-confirmed, issues an invoice, approves the order, assigns available lamp positions, and records the audit trail.
8. On renewal, the system re-evaluates the prayer target against the current year's flow rules, proposes lantern types, and lets the user edit the final selection before cart checkout.
9. The public flow-table page lists only the active current-year flow table, including zodiac, ages, notices, appendix, and suggested lanterns.
10. Admin reviews payment, assigns physical lamp position, and updates order status.
11. System creates invoice/receipt and notification record.
12. Delivery worker sends Email, SMS, LINE, or system notifications and stores provider results.
13. Admin views reports and operation logs.
