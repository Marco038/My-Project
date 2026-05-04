# HTML layout

| Path | Purpose |
|------|---------|
| **`en/index.html`** | Main SPA (English). Canonical app entry. |
| **`tl/index.html`** | Tagalog hub / pointer to English (duplicate UI here when translating). |
| **`farmer/`, `buyer/`, `admin/`** | Short role entry pages → redirect to `en/index.html?join=…`. |
| **`index.html`** | Redirects to `en/index.html`. |

Assets: `../../css/`, `../../js/`, `../../php/` from `html/en/`.
