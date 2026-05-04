## PHP layout

| Path | Role |
|------|------|
| `includes/db.php` | MySQL connection, `sanitize()`, `touch_session()` |
| `includes/csrf.php` | Session CSRF token; all state-changing POSTs expect `csrf_token` |
| `includes/notifications_helper.php` | `notify_user()` |
| `includes/event_log.php` | `log_event()` |
| `auth.php`, `crops.php`, `orders.php`, `api.php` | HTTP JSON endpoints (shared; authorization by `role` inside each script) |
| `admin_export.php` | Admin-only CSV export (`?type=orders|users|crops|transactions|visits`; session cookie) |

Role-specific **folders** for PHP are optional; the API stays role-aware in one place to match the BSIT capstone scope.
