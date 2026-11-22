# ماسح الذهب — Gold Jewelry Barcode Scanner (Local MVP)

A local web app that runs on the store's Windows PC. Employees scan a barcode on the Honeywell Android scanner (or type it on any browser) and see the item's details, read straight from the store's existing Excel `.xls` file.

- **Excel is the single source of truth.** The app opens the file **read-only** and never writes to it. Nobody has to upload or import anything: when someone saves the file, the app reloads it on its own.
- **No cloud, no database, no internet.** Everything stays on the PC and the store's Wi‑Fi.

> ⚠ The bundled `data/inventory.xls` holds **fictional demo data**. Prices and weights are made up.

---

## 1. Project structure

```
gold-scanner/
├─ backend/
│  ├─ config.py          # loads config/config.json
│  ├─ excel_reader.py    # read-only .xls parsing, barcode normalization, duplicates, diffs
│  ├─ sync.py            # watchdog + polling, debounce, stability check, retry, atomic swap
│  └─ main.py            # FastAPI app, API, LAN allowlist, access key, static frontend
├─ frontend/             # index.html, styles.css, app.js (Arabic RTL, vanilla JS, offline)
├─ data/inventory.xls    # demo legacy XLS (36 rows)
├─ config/
│  ├─ config.example.json
│  └─ config.json        # your live config (created from the example)
├─ scripts/generate_sample_xls.py
├─ tests/test_app.py
├─ requirements.txt / requirements-dev.txt
├─ start.bat             # first run creates .venv and installs dependencies
├─ install_autostart.bat / uninstall_autostart.bat
└─ open_firewall.bat
```

## 2. Install and start (Windows)

1. Install **Python 3.11 or newer** from python.org. Tick **"Add python.exe to PATH"**.
2. Copy the `gold-scanner` folder to the PC, for example `C:\GoldScanner`.
3. Double-click **`start.bat`**. The first run creates `.venv` and installs the dependencies; this step needs internet once. After that, the app runs fully offline.
4. Open **http://localhost:8000** on the PC.
5. Enter the access key from `config/config.json` (`access_key`; the demo value is `change-me-1234`).

Manual alternative:
```bat
cd C:\GoldScanner
py -3 -m venv .venv
.venv\Scripts\pip install -r requirements.txt
.venv\Scripts\python -m backend.main
```

Development and tests:
```bat
.venv\Scripts\pip install -r requirements-dev.txt
.venv\Scripts\python -m pytest -q
.venv\Scripts\python scripts\generate_sample_xls.py      # regenerate the demo XLS
```

## 3. Configuration (`config/config.json`)

| Key | Meaning |
|---|---|
| `excel_path` | Absolute path to the real file, e.g. `"C:\\GoldScanner\\data\\inventory.xls"` (in JSON, write each backslash twice). A relative path is resolved from the project folder. |
| `sheets` | `[]` reads the first sheet only. `["Sheet1","Sheet2"]` reads those sheets. |
| `header_row` | The 1-based row number that holds the column headers. |
| `columns` | Maps each field to a list of accepted header names (Arabic or English, not case-sensitive). Change this to match the real file. The app does not need to be rebuilt. |
| `include_unmapped_columns` | Shows any extra Excel columns on the result screen as well. |
| `case_insensitive_barcodes` | `gr-1` matches `GR-1`. |
| `host` / `port` | `0.0.0.0` allows LAN access. `127.0.0.1` limits access to this PC only. |
| `access_key` | A key every device must enter once. **Change it.** Leaving it empty turns the key off (not recommended). |
| `allowed_networks` | The client IP ranges allowed to connect (CIDR). Narrow this to your subnet, e.g. `["127.0.0.1/32","192.168.1.0/24"]`. |
| `demo_mode` | Shows the "للتجربة فقط" sample-barcode panel. **Set to `false` for production.** |
| `poll_interval_seconds`, `debounce_seconds`, `stable_wait_seconds`, `read_retries`, `retry_delay_seconds` | Sync timing. |
| `scanner_suffix` | `enter` (default), `tab`, or `none`. With `none`, the search starts automatically once input stops for `scanner_idle_submit_ms`. |

Restart the server after you change the config.

### Switching to the client's real Excel file
1. Put the real file somewhere on the PC and point `excel_path` at it.
2. Open the file and note the exact header names. Add them to the matching lists under `columns`. Only `barcode` is required; columns that are not mapped still appear as extra details.
3. If the headers are not in row 1, set `header_row`. If the items span several sheets, list them in `sheets`.
4. Restart the server. Then check `/api/health` and look at the status banner.

## 4. How synchronization works
1. The file loads when the server starts, and the app builds an in-memory barcode index.
2. **watchdog** watches the file's folder. Excel saves through a temp file and a rename, and the watch catches both. A **poll** every 3 s acts as a fallback.
3. Change events are debounced. The app then waits until the file's size and modification time stop changing, reads the bytes, and checks that the file did not change during the read.
4. The bytes are parsed and validated (the barcode column must exist and the workbook must be valid). Only then is the active index **swapped atomically**.
5. If a read fails (file locked, half-written, deleted, or corrupt), the previous snapshot stays active. The UI shows **"بيانات قديمة"** with the last good load time, and every result carries a "may be outdated" note. Retries continue automatically.
6. Duplicate barcodes are reported in the status banner. A lookup on a duplicate returns **HTTP 409** with the sheet and row of each copy, and never returns a random record.

## 5. API

The access key goes in the `X-Access-Key` header. Every endpoint except `/api/config/status` requires it.

| Endpoint | Result |
|---|---|
| `GET /api/health` | Server status, Excel state (`ok` / `stale` / `error` / `loading`), record count, last success, last error, last change (+added ~modified −deleted) |
| `GET /api/item/{barcode}` | 200 item · 404 not found · 409 duplicate · 503 no snapshot ever loaded · 400 invalid barcode |
| `GET /api/config/status` | Safe UI settings only (no paths, no key) |
| `GET /api/demo/barcodes` | Sample barcodes (only when `demo_mode` is on) |

## 6. Making the PC reachable from the scanner

1. **Reserve the PC's IP address.** In the Wi‑Fi router's admin page, find *DHCP reservation* / *Address reservation* and bind the PC's MAC address to a fixed IP (e.g. `192.168.1.100`). To find the current IP, run `ipconfig` and read the *IPv4 Address* line.
2. **Set the network to Private.** Go to Settings → Network & Internet → Wi‑Fi (or Ethernet) → *Private network*.
3. **Open the firewall for the LAN only.** Right-click `open_firewall.bat` → *Run as administrator*. It adds:
   `netsh advfirewall firewall add rule name="Gold Scanner 8000" dir=in action=allow protocol=TCP localport=8000 remoteip=localsubnet profile=private`
   If Windows asks the first time `python.exe` runs, allow it on **Private** networks only.
4. **Start automatically.** Run `install_autostart.bat`. It creates a Task Scheduler task that runs `start.bat` at logon. For the task to start, the PC must be on and a user must be logged in.

## 7. Connecting the Honeywell scanner
1. Connect the scanner to the **same Wi‑Fi** as the PC.
2. Open Chrome on the scanner and go to **`http://192.168.1.100:8000`** (use the PC's reserved IP). You can also open `http://192.168.1.100:8000/?key=YOUR_KEY` once. The key is then saved on the device and removed from the address bar. Bookmark the page or add it to the home screen.
3. **Set up keyboard-wedge output.** Open the scanner settings app (on most Honeywell models: *Settings → Honeywell Settings → Scanning → Internal Scanner → Default profile → Data Processing Settings*):
   - **Wedge**: enabled, method *Keyboard* / *Standard*.
   - **Suffix**: `Enter` (`\n`). This matches `scanner_suffix: "enter"`. If the device can only send Tab, set `scanner_suffix` to `"tab"`. If it sends no suffix at all, use `"none"`.
   - Menu names differ between Honeywell models and Android versions. Check the model's user guide if needed.
4. The barcode field gets focus automatically and uses `inputmode="none"`, so Android's soft keyboard stays hidden during scanning. To type a barcode by hand, tap the ⌨ icon in the field.
5. When a result is showing, scan the next item straight away. The app starts the new lookup on its own. You can also tap **"مسح قطعة جديدة"**.

> The app uses **keyboard-wedge input only**. It does not call the Honeywell scanning SDK or DataWedge intents.

## 8. Demo acceptance test script

Sample barcodes: `6280001001` … `6280001031`, `00123456` (leading zeros stored as text), `GR-2024-A7` (alphanumeric), `123456789` (stored as a number in Excel), `DUP-5555` (duplicate on purpose), `999999999` (not found).

| # | Steps |
|---|---|
| 1 | Run `start.bat`, open http://localhost:8000, and scan or type `6280001001`. The details appear. |
| 2 | Enter `999999999`. The page shows "القطعة غير موجودة في ملف المخزون". |
| 3 | Open `data\inventory.xls` in Excel, change the **Price** of `6280001001`, and press Ctrl+S. Within about 3–5 s, the header time updates. Scan again to see the new price. |
| 4 | Add a row with a new barcode (e.g. `NEW-100`) and save. It becomes searchable within a few seconds. |
| 5 | Delete a row and save. A search for that barcode now returns "not found". |
| 6 | Rename or move `inventory.xls` (or replace it with a broken file). The header turns amber ("بيانات قديمة"), a warning banner appears, and searches still return the last good data with an "outdated" note. Restore the file, and the status returns to green on its own. |
| 7 | On an Android phone or scanner on the same Wi‑Fi, open `http://<PC-IP>:8000`. |
| 8 | In Chrome DevTools, use device mode at a 320–360 px width, or use the scanner itself. Check that the layout is RTL and nothing overflows. |
| 9 | Look up `00123456`, `GR-2024-A7`, `gr-2024-a7`, and `123456789`. All are found. A search for `123456` is not found, because the leading zeros are preserved. |
| 10 | Look up `DUP-5555`. The page shows an explicit duplicate error with the sheet and row of each copy. The banner also reports one duplicate barcode. |

> Note on Excel and `.xls`: if Excel suggests converting the file to `.xlsx` when you save, choose **Keep current format**. Otherwise Excel writes a different file and the configured `.xls` will not change.

## 9. Security checklist (production)
- [ ] Change `access_key` to a long random value, and share it only with staff devices.
- [ ] Set `demo_mode: false`.
- [ ] Narrow `allowed_networks` to the store subnet (e.g. `192.168.1.0/24`). Add individual device IPs if they are reserved.
- [ ] Limit the firewall rule to `localsubnet` and the *Private* profile. **Never** port-forward 8000 on the router.
- [ ] Use WPA2/WPA3 Wi‑Fi with a strong password and a separate **guest** network for customers. Being on the Wi‑Fi does not make a device trustworthy, so the key and the allowlist are still needed.
- [ ] Keep Windows updated, use a Windows account with a password, and lock the PC when nobody is at it.
- [ ] Logs contain counts and errors only. Item data and prices are never logged.
- [ ] The traffic is plain HTTP on the LAN. That is acceptable for a demo; for higher assurance, put a local TLS reverse proxy in front.
- [ ] Back up the Excel file regularly. The app never modifies it, but people do.

## 10. Limitations and assumptions
- The real Excel layout is not known yet. Column mapping is configurable, and only the barcode column is required.
- Reads `.xls` (BIFF) only, using xlrd 2.x. `.xlsx` is not supported; adding openpyxl would be a small change.
- Excel formulas are read as the **cached values Excel saved**. Prices are shown exactly as stored, and nothing is calculated from live gold rates.
- Numbers are shown as stored, without currency rounding. A barcode stored as an Excel *number* cannot keep leading zeros (Excel itself drops them), so store those barcodes as text.
- Access control is a shared key plus an IP allowlist. That suits a demo or a small store, but it is not per-user authentication.
- Honeywell keyboard-wedge setup could not be tested here because no device was available. Menu paths vary by model.

---

## 11. Scanning with a phone camera

The scan screen has a **"مسح بكاميرا الجوال"** button. It uses Chrome's built-in `BarcodeDetector` when the phone has it, and otherwise the bundled ZXing library (`frontend/vendor/zxing.min.js`), which works offline. It reads Code128, EAN-13/8, UPC, Code39/93, ITF, Codabar, QR and DataMatrix.

**Browsers allow the camera only over HTTPS** (or on `localhost`). To enable HTTPS:
```bat
.venv\Scripts\pip install cryptography
.venv\Scripts\python scripts\make_cert.py            REM add the PC IP if it is missing, e.g. 192.168.1.100
```
Then set the following in `config/config.json` and restart the server:
```json
"ssl_certfile": "config/cert.pem",
"ssl_keyfile": "config/key.pem"
```
Open `https://<PC-IP>:8000`. The certificate is self-signed, so the phone shows a warning the first time. Tap *Advanced → Proceed*. For a public server, use a real certificate (e.g. Let's Encrypt, or Caddy/nginx in front of the app) and remove that warning.

## 12. Uploading Excel (server deployments)

When the app runs on a remote server instead of the store PC, there is no Excel file to watch locally. For that case, an **upload button (⬆) in the header** opens a screen to upload a new `.xls`:

- The upload needs the normal access key **and** a separate `admin_key`.
- The new file is **validated first**: it must be a real `.xls` and include the barcode column. If validation fails, the current data stays as it is.
- The previous file is copied to `data/backups/` first. The newest `backups_keep` copies are kept.
- The new file replaces the old one atomically, and the data reloads at once.

Config: `upload_enabled`, `admin_key` (**change it**), `max_upload_mb`, `backups_keep`.
On the store PC, where Excel is edited in place, you can set `upload_enabled: false`; automatic file watching keeps working either way.
Note: upload is the only feature that writes the data file. It writes only the file it replaces, and only after a backup.
