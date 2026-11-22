"""FastAPI application: barcode lookup API + static Arabic frontend."""
from __future__ import annotations

import ipaddress
import logging
import mimetypes
import secrets
from contextlib import asynccontextmanager
from datetime import datetime

from fastapi import Depends, FastAPI, HTTPException, Request
from fastapi.responses import FileResponse, JSONResponse
from fastapi.staticfiles import StaticFiles

from .config import FIELDS, PROJECT_ROOT, Config, load_config
from fastapi.concurrency import run_in_threadpool

from .excel_reader import ExcelReadError, normalize_key, parse_workbook, valid_barcode
from .sync import SyncService

log = logging.getLogger("gold_scanner")
FRONTEND = PROJECT_ROOT / "frontend"
VERSION = "1.0.0"

# Windows registry often maps .js to text/plain, which browsers refuse to execute
# (we send X-Content-Type-Options: nosniff). Force correct types.
mimetypes.add_type("application/javascript", ".js")
mimetypes.add_type("text/css", ".css")
mimetypes.add_type("image/svg+xml", ".svg")


def save_uploaded_file(cfg: Config, data: bytes) -> None:
    """Back up the current file, then atomically replace it with the uploaded one."""
    import os
    import shutil

    target = cfg.excel_path
    target.parent.mkdir(parents=True, exist_ok=True)
    if target.exists():
        bdir = target.parent / "backups"
        bdir.mkdir(exist_ok=True)
        stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
        shutil.copy2(target, bdir / f"{target.stem}-{stamp}{target.suffix}")
        olds = sorted(bdir.glob(f"{target.stem}-*{target.suffix}"))
        for old in olds[: max(0, len(olds) - cfg.backups_keep)]:
            old.unlink(missing_ok=True)
    tmp = target.with_name(f".{target.name}.uploading")
    tmp.write_bytes(data)
    os.replace(tmp, target)


def create_app(cfg: Config | None = None, start_sync: bool = True) -> FastAPI:
    cfg = cfg or load_config()
    service = SyncService(cfg)
    networks = cfg.networks()

    @asynccontextmanager
    async def lifespan(app: FastAPI):
        if start_sync:
            service.start(background=True)
        yield
        service.stop()

    app = FastAPI(title="Gold Scanner", version=VERSION, lifespan=lifespan,
                  docs_url=None, redoc_url=None, openapi_url=None)
    app.state.cfg = cfg
    app.state.sync = service

    @app.middleware("http")
    async def lan_only(request: Request, call_next):
        host = request.client.host if request.client else ""
        if networks and host not in ("testclient",):
            try:
                ip = ipaddress.ip_address(host)
                if ip.version == 6 and ip.ipv4_mapped:
                    ip = ip.ipv4_mapped
                allowed = any(ip in n for n in networks)
            except ValueError:
                allowed = False
            if not allowed:
                log.warning("Blocked request from %s", host)
                return JSONResponse({"detail": "forbidden"}, status_code=403)
        response = await call_next(request)
        response.headers["Cache-Control"] = "no-store"
        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["Referrer-Policy"] = "no-referrer"
        return response

    def require_key(request: Request):
        if not cfg.access_key:
            return
        supplied = request.headers.get("X-Access-Key", "")
        if not secrets.compare_digest(supplied.encode(), cfg.access_key.encode()):
            raise HTTPException(status_code=401, detail="مفتاح الدخول غير صحيح")

    # ---------- public (no inventory data) ----------
    @app.get("/api/config/status")
    def config_status():
        return {
            "app_name": "ماسح الذهب",
            "version": VERSION,
            "auth_required": bool(cfg.access_key),
            "demo_mode": cfg.demo_mode,
            "scanner_suffix": cfg.scanner_suffix,
            "scanner_idle_submit_ms": cfg.scanner_idle_submit_ms,
            "poll_interval_seconds": cfg.poll_interval_seconds,
            "fields": [{"key": k, "label": l} for k, l in FIELDS],
            "upload_enabled": cfg.upload_enabled,
        }

    # ---------- protected ----------
    @app.get("/api/health", dependencies=[Depends(require_key)])
    def health():
        st = service.status()
        return {"server": "ok", "server_time": datetime.now().astimezone().isoformat(timespec="seconds"),
                "excel": st}

    @app.get("/api/item/{barcode}", dependencies=[Depends(require_key)])
    def get_item(barcode: str):
        if not valid_barcode(barcode):
            raise HTTPException(status_code=400, detail="صيغة الباركود غير صالحة")
        snap = service.snapshot
        if snap is None:
            raise HTTPException(status_code=503, detail="لم يتم تحميل ملف المخزون بعد")
        st = service.status()
        key = normalize_key(barcode, cfg.case_insensitive_barcodes)
        recs = snap.records.get(key)
        meta = {"data_state": st["state"], "last_success": st["last_success"], "last_error": st["last_error"]}
        if not recs:
            return JSONResponse(status_code=404, content={
                "detail": "القطعة غير موجودة في ملف المخزون", "barcode": barcode.strip(), **meta})
        if len(recs) > 1:
            return JSONResponse(status_code=409, content={
                "detail": "باركود مكرر في ملف المخزون — لا يمكن تحديد القطعة بدقة",
                "barcode": barcode.strip(), "count": len(recs),
                "locations": [r["location"] for r in recs], **meta})
        r = recs[0]
        return {"item": {"fields": r["fields"], "labels": r["labels"], "extra": r["extra"],
                         "location": r["location"]}, **meta}

    # ---------- admin: upload a new Excel file (for server deployments) ----------
    def require_admin(request: Request):
        if not cfg.upload_enabled:
            raise HTTPException(status_code=404, detail="رفع الملفات غير مفعّل")
        supplied = request.headers.get("X-Admin-Key", "")
        if not cfg.admin_key or not secrets.compare_digest(supplied.encode(), cfg.admin_key.encode()):
            raise HTTPException(status_code=401, detail="مفتاح المسؤول غير صحيح")

    @app.post("/api/admin/upload", dependencies=[Depends(require_key), Depends(require_admin)])
    async def upload_excel(request: Request):
        limit = int(cfg.max_upload_mb * 1024 * 1024)
        data = bytearray()
        async for chunk in request.stream():
            data += chunk
            if len(data) > limit:
                raise HTTPException(status_code=413, detail=f"الملف أكبر من {cfg.max_upload_mb:g} ميجابايت")
        data = bytes(data)
        if data[:8] != b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1":
            raise HTTPException(status_code=400, detail="الملف ليس بصيغة Excel ‎.xls (احفظه من Excel بصيغة Excel 97-2003)")
        try:
            snap = parse_workbook(data, cfg)  # validate BEFORE touching the active file
        except ExcelReadError as e:
            raise HTTPException(status_code=400, detail=str(e))
        try:
            save_uploaded_file(cfg, data)
        except OSError as e:
            log.error("upload save failed: %s", type(e).__name__)
            raise HTTPException(status_code=500, detail="تعذر حفظ الملف على الخادم")
        await run_in_threadpool(service.reload, "upload")
        log.info("Excel uploaded: %d rows", snap.total_rows)
        return {"ok": True, "records": snap.total_rows, "duplicate_barcodes": len(snap.duplicates),
                "warnings": snap.warnings, "excel": service.status()}

    @app.get("/api/demo/barcodes", dependencies=[Depends(require_key)])
    def demo_barcodes():
        if not cfg.demo_mode:
            raise HTTPException(status_code=404, detail="غير متاح")
        snap = service.snapshot
        out = []
        if snap:
            singles = [r[0]["fields"] for k, r in snap.records.items() if len(r) == 1]
            picks = singles[:6] + [f for f in singles if f["barcode"].startswith("0")][:1]
            picks += [f for f in singles if not f["barcode"].isdigit()][:1]
            seen = set()
            for f in picks:
                if f["barcode"] not in seen:
                    seen.add(f["barcode"])
                    out.append({"barcode": f["barcode"], "name": f.get("item_name", "")})
            for k, r in list(snap.records.items()):
                if len(r) > 1:
                    out.append({"barcode": r[0]["fields"]["barcode"], "name": "باركود مكرر (تجربة)"})
                    break
        out.append({"barcode": "999999999", "name": "غير موجود (تجربة)"})
        return {"barcodes": out}

    # ---------- frontend ----------
    @app.get("/", include_in_schema=False)
    def index():
        return FileResponse(FRONTEND / "index.html")

    app.mount("/static", StaticFiles(directory=FRONTEND), name="static")
    return app


def run():
    import uvicorn
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    cfg = load_config()
    app = create_app(cfg)
    log.info("Excel file: %s", cfg.excel_path.name)
    ssl = {}
    if cfg.ssl_certfile and cfg.ssl_keyfile:
        from .config import _resolve_path
        ssl = {"ssl_certfile": str(_resolve_path(cfg.ssl_certfile)), "ssl_keyfile": str(_resolve_path(cfg.ssl_keyfile))}
    scheme = "https" if ssl else "http"
    log.info("Open %s://localhost:%d  (LAN: %s://<this-PC-IP>:%d)", scheme, cfg.port, scheme, cfg.port)
    uvicorn.run(app, host=cfg.host, port=cfg.port, log_level="warning", **ssl)


if __name__ == "__main__":
    run()
