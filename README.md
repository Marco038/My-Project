# BUKID CONNECT — Farmer-to-Buyer Marketplace

Web-based agricultural marketplace (Philippines). Stack: **PHP + MySQL**, **HTML**, **CSS**, **JavaScript** (no bundler). Full capstone specification: **`docs/BUKID_CONNECT_SYSTEM_SPECIFICATION.md`**.  
Architecture & deliverables (sections 1–14): **`docs/BUKID_CONNECT_CAPSTONE_BLUEPRINT.md`**.

## Quick start

1. Import `database.sql` in phpMyAdmin (creates DB `bukid_connect`, the `categories` table, seed users, and views `v_farmers` / `v_buyers`). Re-import or run new `CREATE`/`INSERT` blocks when the schema changes. **Category and unit** dropdowns are filled from the API, not from static HTML.
2. Adjust `php/includes/db.php` if MySQL credentials differ (`DB_NAME` must match).
3. Open **`http://localhost/Bukid/`** (or your folder name, e.g. `/BUKID/`) — you are redirected to **`html/en/index.html`** (English app).

## Project structure (by language & role)

```
Bukid/
├── index.html              ← Redirects to html/en/index.html
├── css/
│   └── main.css            ← All application styles
├── html/
│   ├── index.html          ← Redirect to en/index.html
│   ├── en/index.html       ← Main SPA (English)
│   ├── en/body_fragment.txt ← Primary source for tools/build_html_index.py
│   ├── body_fragment.txt   ← Legacy fallback for the build tool
│   ├── tl/                 ← Tagalog entry stub
│   ├── farmer/             ← Role shortcut + README
│   ├── buyer/
│   └── admin/
├── js/
│   ├── config.js           ← API base path + global state
│   ├── shared/
│   │   ├── utils.js        ← api(), toast, escapeHtml, modals, …
│   │   ├── navigation.js ← Sidebar + showPage()
│   │   └── app.js          ← Auth, dashboard, orders, visits, chat, checkout
│   ├── farmer/
│   │   └── farmer-pages.js
│   ├── buyer/
│   │   └── buyer-pages.js
│   └── admin/
│       └── admin-pages.js
├── php/
│   ├── includes/           ← Shared PHP (DB, notifications, event log)
│   ├── auth.php
│   ├── crops.php
│   ├── orders.php
│   ├── api.php
│   └── admin_export.php    ← Admin CSV downloads (Reports page)
├── tools/
│   ├── split_frontend.py   ← Re-split JS from js/_bundle_raw.js (optional workflow)
│   └── build_html_index.py ← Rebuild html/en/index.html from html/en/body_fragment.txt
└── database.sql            ← includes `categories` (marketplace taxonomy)
```

### Changing the API URL

The app reads `<meta name="apidir" content="../../php/"/>` in `html/en/index.html`. `js/config.js` resolves it with `new URL(..., location.href)` so API calls work from any subfolder. Adjust the meta value if your `php/` path differs.

### Regenerating split JS (advanced)

If you maintain a monolithic script as `js/_bundle_raw.js`, run:

`python tools/split_frontend.py`

Then fix any manual edits in the generated `js/shared/app.js` if needed.

## Default accounts

| Role   | Username        | Password   |
|--------|-----------------|------------|
| Admin  | admin           | Admin@123  |
| Farmer | juan_farm       | Farmer@123 |
| Farmer | pedro_highland  | Farmer@123 |
| Buyer  | maria_buyer     | Buyer@123  |
| Buyer  | carlos_resto    | Buyer@123  |

Set `BUKID_DEV_OTP` to `false` in `php/includes/db.php` for production; configure real SMTP for OTP email.
