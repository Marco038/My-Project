# BUKID CONNECT — Capstone System Blueprint

**Version:** 1.0 · **Stack (as implemented):** HTML5, CSS3, JavaScript (ES modules-free SPA), PHP 8+, MySQL/MariaDB · **Auth:** PHP sessions + CSRF token + bcrypt + email OTP (JWT optional roadmap below)

This document satisfies the **14 output sections** requested for BSIT capstone documentation and maps each area to the **actual repository** under `Bukid/`.

---

## 1. Complete System Architecture

**Pattern:** Single-page application (SPA) shell with **role-based navigation**, **JSON REST-style endpoints** (`auth.php`, `crops.php`, `orders.php`, `api.php`), **MySQL** persistence, **server-side session** for authentication, **event_log** + **audit_logs** for traceability, **notifications** table for in-app fan-out.

**Layers:**

| Layer | Responsibility |
|--------|----------------|
| Presentation | `html/en/index.html` (SPA shell), `css/main.css`, `js/shared/*`, `js/farmer/*`, `js/buyer/*`, `js/admin/*` |
| Application / API | `php/*.php` — action dispatch via `action` POST/GET parameter |
| Domain | Orders (inventory + transactions), visits, ratings, alerts, favorites |
| Data | `database.sql` — unified `users` + role enum; `categories` taxonomy; `crops`, `orders`, `transactions`, `event_logs`, etc. |

**Event-driven behaviour (implemented):**

- Order placed → inventory check (transaction) → notifications + `event_logs`.
- Visit requested → slot check → notify farmer + `event_logs`.
- Admin alert → optional **province targeting** → notifications to matching users + `event_logs`.
- Low stock after order → threshold notification + `inventory.low` event.
- Failed logins → lockout + `audit_logs`.
- Delivered order → `transactions` row + notifications (payment gateway **not** integrated; “payment confirmed” maps to **delivered + transaction record** for capstone scope).

**Cloud-ready:** Stateless PHP nodes require shared session storage (Redis) or sticky sessions; place MySQL on managed RDS; terminate TLS at reverse proxy; set `BUKID_DEV_OTP` false and configure SMTP.

---

## 2. Folder Structure

```
Bukid/
├── index.html                 → Redirect to html/en/index.html
├── css/main.css
├── docs/
│   ├── BUKID_CONNECT_CAPSTONE_BLUEPRINT.md   (this file)
│   └── BUKID_CONNECT_SYSTEM_SPECIFICATION.md (narrative spec, if present)
├── html/
│   ├── index.html             → Redirect to en/index.html
│   ├── en/index.html          → App shell (canonical SPA)
│   ├── en/body_fragment.txt   → Source for tools/build_html_index.py
│   ├── tl/index.html          → Tagalog stub / language entry
│   ├── farmer|buyer|admin/index.html → Role shortcuts (?join=…)
│   └── body_fragment.txt      → Legacy fallback for build tool
├── js/
│   ├── config.js
│   ├── shared/   (utils, navigation, app core)
│   ├── farmer/   farmer-pages.js
│   ├── buyer/    buyer-pages.js
│   └── admin/    admin-pages.js
├── php/
│   ├── includes/  db.php, csrf.php, notifications_helper.php, event_log.php
│   ├── auth.php, crops.php, orders.php, api.php, admin_export.php
├── tools/         Optional Python helpers
└── database.sql
```

---

## 3. Frontend UI Design

- **Theme:** Agricultural greens (`--green-*`), earth accent, DM Sans + Playfair Display.
- **Layout:** Collapsible sidebar (mobile), top bar, card-based dashboards, modals for orders/crops/alerts.
- **Role differentiation:** Distinct **nav definitions** per role (`navigation.js`); buyer-only **recommended crops** strip; farmer **analytics chart**; admin **reports CSV** + platform analytics.
- **Responsive:** `@media (max-width: 768px)` sidebar drawer; stacked forms; compact tables.
- **Feedback:** Toasts (`utils.js`), loading placeholders for dynamic selects, live polling for dashboard/marketplace/orders/notifications.

---

## 4. Backend Structure

| Endpoint | Primary actions |
|----------|-------------------|
| `auth.php` | `csrf`, `register`, `login`, `verify_otp`, `resend_otp`, `forgot_password_request`, `forgot_password_reset`, `check`, `logout`, `update_profile`, `change_password` |
| `crops.php` | `list_categories`, `list_units`, `get_crops`, `my_crops`, `add_crop`, `update_crop`, `delete_crop`, admin crop moderation |
| `orders.php` | `place_order`, `my_orders`, `update_order`, `cancel_order`, `my_transactions`, `all_orders` (admin) |
| `api.php` | Visits, messages, notifications, favorites, ratings, alerts (`post_alert`, `get_alerts`), `public_stats`, `farmer_analytics`, `admin_analytics`, audit/event logs, etc. |
| `admin_export.php` | Session CSV exports (admin) |

**RBAC:** Enforced per action (`requireLogin`, role checks, `requireAdmin`).

---

## 5. Database Schema (logical ERD summary)

**Core entities:**

- **users** — PK `id`; role `farmer|buyer|admin`; profile; `gov_id_verified`; OTP fields; lockout; `province` (used for alert targeting).
- **categories** — Controlled vocabulary for crop categories (+ merge with distinct values on `crops`).
- **crops** — FK `farmer_id` → `users`; inventory, price, harvest, status.
- **orders** — FKs buyer, farmer, crop; delivery fields; status workflow.
- **transactions** — One row per delivered settlement (capstone “payment recorded”).
- **farm_visits**, **messages**, **notifications**, **ratings**, **favorites**, **alerts** (`target_province` nullable), **audit_logs**, **event_logs**.

**Spec vs implementation:** Single `users` table instead of physical `farmers` / `buyers` tables — use views **`v_farmers`**, **`v_buyers`**. One line per crop in `orders` instead of `order_items` — acceptable simplification. `crop_images` merged into `crops.image` path field. `weather_alerts` / `pest_alerts` merged into **`alerts.type`**.

---

## 6. REST API Endpoints (conventions)

- **Method:** `GET` or `POST` with `action` parameter (FormData for POST).
- **CSRF:** All authenticated POSTs send `csrf_token` (from `GET auth.php?action=csrf` or `check` / `login` response).
- **Session:** Cookie `PHPSESSID`; `touch_session()` idle timeout in `db.php`.

**Representative catalogue:** see section 4; extend by grep `if ($action ===` in each PHP file.

---

## 7. Authentication Flow

1. Visitor loads app → `fetchCsrf()` → `GET auth.php?action=csrf` → session + token.
2. **Register** → bcrypt hash → OTP email → `verify_otp` → login allowed.
3. **Login** → verify active account → lockout check → bcrypt → optional OTP if email unverified → session vars + CSRF returned.
4. **Forgot password** → `forgot_password_request` (OTP) → `forgot_password_reset` (code + new password) → bcrypt update.
5. **Session check** → `GET auth.php?action=check` returns user + CSRF.
6. **Logout** → `POST auth.php` `logout` (CSRF) → destroy session.

**JWT roadmap:** Issue short-lived JWT after login for API-only clients; validate `Authorization: Bearer` in a thin front controller; keep session for browser SPA. Not required for capstone demo on XAMPP.

---

## 8. Dashboard Layouts

| Role | Key widgets |
|------|----------------|
| **Farmer** | Stats: listings, orders, pending, earnings, visit requests; alerts; recent orders; analytics page with **bar chart** + top crops. |
| **Buyer** | Order stats; **recommended crops** (from live `get_crops`); alerts (province-scoped); recent orders. |
| **Admin** | KPI cards; user/crop/order tools; broadcast alerts with optional province; audit & event logs; analytics; CSV reports. |

---

## 9. Functional Modules

- **Farmer:** Listings CRUD, order workflow, visits, chat, analytics, transactions, notifications, settings.
- **Buyer:** Marketplace search/filter, order modal/checkout, visits, favorites, ratings post-delivery, transactions, notifications.
- **Admin:** User verify/toggle, crop status, orders view, targeted alerts, exports, security logs.

---

## 10. Security Implementation

| Control | Implementation |
|---------|------------------|
| Passwords | `password_hash` / `password_verify` (bcrypt) |
| SQLi | Prepared statements throughout |
| XSS | `sanitize()` + `escapeHtml()` in UI |
| CSRF | `php/includes/csrf.php` + `csrf_token` on POST |
| Session fixation | Regenerate session ID on login (optional hardening) |
| Brute force | Failed login counter + 15-minute lockout |
| Audit | `audit_logs` + `event_logs` |
| HTTPS | Terminate at Apache/nginx; set secure cookie flags in production |
| OTP dev leak | `BUKID_DEV_OTP` in `db.php` — **false** in production |

---

## 11. Sample Code Structure

- **API call (client):** `api('orders.php', { action: 'place_order', crop_id, ... })` in `utils.js` (auto-appends CSRF).
- **Auth check (server):** `requireLogin();` then `$_SESSION['role']`.
- **Event:** `log_event($conn, 'order.placed', $buyerId, 'order', $orderId, [...]);`

---

## 12. ERD Description (textual)

`users (1) ──< (N) crops`  
`users (1) ──< (N) orders` as buyer or farmer  
`crops (1) ──< (N) orders`  
`orders (1) ── (0..1) transactions`  
`users (1) ──< (N) messages` (sender/receiver)  
`users (1) ──< (N) notifications`  
`users (1) ──< (N) ratings` (buyer → farmer)  
`users (1) ──< (N) farm_visits`  
`users (admin) (1) ──< (N) alerts`

---

## 13. User Flow (condensed)

**Buyer:** Register → OTP → Browse → Order (pickup/delivery) → Track status → Rate on delivery → Optional visit request / chat.  
**Farmer:** Register → OTP → List crops → Confirm/progress orders → Mark delivered → View earnings/analytics.  
**Admin:** Login → Verify farmers → Monitor listings/orders → Broadcast alert (national or province) → Export reports / read logs.

---

## 14. Navigation Structure

Defined in `js/shared/navigation.js`:

- **Farmer:** Dashboard, My Crops, Orders, Visits, Messages, Analytics, Transactions, Alerts, Notifications, Settings.
- **Buyer:** Dashboard, Marketplace, Orders, Favorites, Visits, Messages, Transactions, Alerts, Notifications, Settings.
- **Admin:** Dashboard, Users, Crop Listings, Transactions, Analytics, Reports, Marketplace, Broadcast Alerts, Audit Logs, Event Log, Notifications, Settings.

---

## Deployment checklist (production)

1. MySQL: import `database.sql`; run `ALTER` for `alerts.target_province` if upgrading old DB.  
2. `php/includes/db.php`: credentials, `BUKID_DEV_OTP = false`, SMTP for mail.  
3. Web server: HTTPS, `session.cookie_httponly`, `session.cookie_secure`, tight `uploads/` permissions if profile upload added later.  
4. Schedule external backups (mysqldump) — **not** exposed in admin UI for safety.

---

*This blueprint describes the shipped capstone codebase. Extend with Laravel/Express + JWT when course requirements mandate a framework migration.*
