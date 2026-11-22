# ماسح الذهب — Arar Dahab Gold Scanner

Barcode lookup for a gold jewelry store's Excel inventory (Arabic RTL UI, works with Honeywell keyboard-wedge scanners and phone cameras).

| Folder | Version | Use it when |
|---|---|---|
| [`gold-scanner/`](gold-scanner/) | Python / FastAPI | Running on the store's Windows PC. Excel is read live from disk and auto-syncs on save. |
| [`gold-scanner-laravel/`](gold-scanner-laravel/) | PHP / Laravel 12 | Hosting on a server (cPanel/VPS). Excel is uploaded from the UI or replaced via FTP. |

Each folder has its own README with setup, configuration, deployment and security notes.

> The bundled inventory files contain **fictional demo data** only.
