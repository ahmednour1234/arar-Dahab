import os
import sys
import time
from pathlib import Path

import pytest
import xlwt
from fastapi.testclient import TestClient

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))
sys.path.insert(0, str(ROOT / "scripts"))

from backend.config import load_config  # noqa: E402
from backend.excel_reader import ExcelReadError, parse_workbook, read_file_bytes  # noqa: E402
from backend.main import create_app  # noqa: E402
from backend.sync import SyncService  # noqa: E402
from generate_sample_xls import build_rows, write_xls  # noqa: E402

KEY = "test-key"


@pytest.fixture
def cfg(tmp_path):
    xls = tmp_path / "inventory.xls"
    write_xls(xls)
    c = load_config(ROOT / "config" / "config.example.json")
    c.excel_path = xls
    c.access_key = KEY
    c.demo_mode = True
    c.upload_enabled = False
    c.poll_interval_seconds = 0.3
    c.debounce_seconds = 0.2
    c.stable_wait_seconds = 0.1
    c.read_retries = 2
    c.retry_delay_seconds = 0.1
    return c


def parse(cfg):
    return parse_workbook(read_file_bytes(cfg.excel_path), cfg)


# ---------------- parsing ----------------
def test_parse_sample(cfg):
    s = parse(cfg)
    assert s.total_rows == len(build_rows()) >= 30
    rec = s.records["6280001001"][0]["fields"]
    assert set(rec) >= {"barcode", "item_name", "category", "karat", "weight", "making_charge",
                        "price", "status", "branch", "notes"}


def test_leading_zeros_and_numeric_and_alnum(cfg):
    s = parse(cfg)
    assert s.records["00123456"][0]["fields"]["barcode"] == "00123456"
    assert "123456" not in s.records
    # numeric cell 123456789.0 -> "123456789"
    assert s.records["123456789"][0]["fields"]["barcode"] == "123456789"
    assert "GR-2024-A7" in s.records


def test_duplicates_detected(cfg):
    s = parse(cfg)
    assert list(s.duplicates) == ["DUP-5555"]
    assert len(s.duplicates["DUP-5555"]) == 2
    assert any("مكرر" in w for w in s.warnings)


def test_column_mapping_arabic_headers(tmp_path, cfg):
    p = tmp_path / "ar.xls"
    wb = xlwt.Workbook(encoding="utf-8")
    ws = wb.add_sheet("مخزون")
    for c, h in enumerate(["ملاحظات", "الباركود", "اسم القطعة", "العيار", "رقم المورد"]):
        ws.write(0, c, h)
    ws.write(1, 0, "ملاحظة")
    ws.write(1, 1, "0007")
    ws.write(1, 2, "خاتم")
    ws.write(1, 3, 21)
    ws.write(1, 4, "S-9")
    wb.save(str(p))
    cfg.excel_path = p
    s = parse(cfg)
    r = s.records["0007"][0]
    assert r["fields"]["item_name"] == "خاتم" and r["fields"]["karat"] == "21"
    assert r["extra"] == [{"label": "رقم المورد", "value": "S-9"}]


def test_multiple_sheets(tmp_path, cfg):
    p = tmp_path / "multi.xls"
    wb = xlwt.Workbook(encoding="utf-8")
    for name, code in (("A", "111"), ("B", "222")):
        ws = wb.add_sheet(name)
        ws.write(0, 0, "Barcode"); ws.write(0, 1, "Item Name")
        ws.write(1, 0, code); ws.write(1, 1, "x")
    wb.save(str(p))
    cfg.excel_path = p
    cfg.sheets = ["A", "B"]
    s = parse(cfg)
    assert set(s.records) == {"111", "222"}
    cfg.sheets = ["Missing"]
    with pytest.raises(ExcelReadError):
        parse(cfg)


def test_missing_barcode_column(tmp_path, cfg):
    p = tmp_path / "bad.xls"
    wb = xlwt.Workbook(); ws = wb.add_sheet("S"); ws.write(0, 0, "Foo"); wb.save(str(p))
    cfg.excel_path = p
    with pytest.raises(ExcelReadError):
        parse(cfg)


def test_corrupt_or_partial_file_rejected(tmp_path, cfg):
    data = cfg.excel_path.read_bytes()
    with pytest.raises(ExcelReadError):
        parse_workbook(data[: len(data) // 2], cfg)
    with pytest.raises(ExcelReadError):
        parse_workbook(b"", cfg)


def test_never_writes_excel(cfg):
    before = (cfg.excel_path.stat().st_mtime_ns, cfg.excel_path.read_bytes())
    svc = SyncService(cfg)
    svc.reload("startup")
    assert (cfg.excel_path.stat().st_mtime_ns, cfg.excel_path.read_bytes()) == before


# ---------------- sync ----------------
def test_failed_reload_keeps_snapshot(cfg):
    svc = SyncService(cfg)
    assert svc.reload("startup")
    old = svc.snapshot
    cfg.excel_path.write_bytes(b"garbage")
    assert not svc.reload("change")
    assert svc.snapshot is old
    st = svc.status()
    assert st["state"] == "stale" and st["last_error"]
    os.remove(cfg.excel_path)
    assert not svc.reload("change")
    assert svc.snapshot is old


def wait_for(fn, timeout=10):
    end = time.time() + timeout
    while time.time() < end:
        if fn():
            return True
        time.sleep(0.1)
    return False


def test_auto_reload_modify_add_delete(cfg):
    svc = SyncService(cfg)
    svc.start(background=True)
    try:
        rows = build_rows()
        rows[0][6] = 7777.77                      # modify price
        rows.append(["NEW-1", "قطعة جديدة", "خواتم", 21, 1, 1, 1, "متوفر", "x", ""])  # add
        deleted = rows.pop(1)[0]                  # delete
        time.sleep(0.05)
        write_xls(cfg.excel_path, rows)
        assert wait_for(lambda: "NEW-1" in svc.snapshot.records)
        s = svc.snapshot
        assert s.records[rows[0][0]][0]["fields"]["price"] == "7777.77"
        assert deleted not in s.records
        assert svc.status()["last_change"] == {"added": 1, "modified": 1, "deleted": 1}
    finally:
        svc.stop()


# ---------------- API ----------------
@pytest.fixture
def client(cfg):
    app = create_app(cfg, start_sync=False)
    app.state.sync.reload("startup")
    return TestClient(app)


H = {"X-Access-Key": KEY}


def test_api_requires_key(client):
    assert client.get("/api/item/00123456").status_code == 401
    assert client.get("/api/health").status_code == 401
    assert client.get("/api/config/status").status_code == 200


def test_api_lookup(client):
    r = client.get("/api/item/00123456", headers=H)
    assert r.status_code == 200
    assert r.json()["item"]["fields"]["barcode"] == "00123456"
    assert client.get("/api/item/gr-2024-a7", headers=H).status_code == 200  # case-insensitive
    assert client.get("/api/item/123456789", headers=H).status_code == 200


def test_api_not_found_duplicate_invalid(client):
    r = client.get("/api/item/999999", headers=H)
    assert r.status_code == 404 and r.json()["detail"] == "القطعة غير موجودة في ملف المخزون"
    r = client.get("/api/item/DUP-5555", headers=H)
    assert r.status_code == 409 and r.json()["count"] == 2
    assert client.get("/api/item/%3Cscript%3E", headers=H).status_code == 400


def test_api_503_when_never_loaded(cfg):
    cfg.excel_path = cfg.excel_path.parent / "nope.xls"
    cfg.read_retries = 1
    app = create_app(cfg, start_sync=False)
    app.state.sync.reload("startup")
    c = TestClient(app)
    assert c.get("/api/item/123", headers=H).status_code == 503
    h = c.get("/api/health", headers=H).json()
    assert h["excel"]["state"] == "error"


def test_api_health_and_demo(client):
    h = client.get("/api/health", headers=H).json()
    assert h["server"] == "ok" and h["excel"]["state"] == "ok" and h["excel"]["records"] >= 30
    body = str(client.get("/api/config/status").json()) + str(h)
    assert "inventory.xls" not in body and ":\\" not in body  # no paths exposed
    d = client.get("/api/demo/barcodes", headers=H).json()["barcodes"]
    assert any(b["barcode"] == "00123456" for b in d)


def test_demo_disabled(cfg):
    cfg.demo_mode = False
    app = create_app(cfg, start_sync=False)
    app.state.sync.reload("startup")
    assert TestClient(app).get("/api/demo/barcodes", headers=H).status_code == 404


def test_frontend_served(client):
    r = client.get("/")
    assert r.status_code == 200 and 'dir="rtl"' in r.text
    js = client.get("/static/app.js")
    assert js.status_code == 200 and "javascript" in js.headers["content-type"]
    assert "text/css" in client.get("/static/styles.css").headers["content-type"]


# ---------------- upload ----------------
ADMIN = {"X-Access-Key": KEY, "X-Admin-Key": "adm"}


@pytest.fixture
def up_client(cfg):
    cfg.upload_enabled = True
    cfg.admin_key = "adm"
    app = create_app(cfg, start_sync=False)
    app.state.sync.reload("startup")
    return TestClient(app), cfg


def test_upload_replaces_data_and_keeps_backup(up_client, tmp_path):
    c, cfg = up_client
    rows = build_rows()
    rows.append(["UP-1", "قطعة مرفوعة", "خواتم", 21, 2, 2, 2, "متوفر", "x", ""])
    newf = tmp_path / "new.xls"
    write_xls(newf, rows)
    r = c.post("/api/admin/upload", content=newf.read_bytes(), headers=ADMIN)
    assert r.status_code == 200, r.text
    assert r.json()["records"] == len(rows)
    assert c.get("/api/item/UP-1", headers=H).status_code == 200
    assert cfg.excel_path.read_bytes() == newf.read_bytes()
    assert len(list((cfg.excel_path.parent / "backups").glob("*.xls"))) == 1


def test_upload_rejects_bad_files_and_keys(up_client):
    c, cfg = up_client
    before = cfg.excel_path.read_bytes()
    good = before
    assert c.post("/api/admin/upload", content=good, headers={"X-Access-Key": KEY, "X-Admin-Key": "x"}).status_code == 401
    assert c.post("/api/admin/upload", content=b"PK\x03\x04 xlsx", headers=ADMIN).status_code == 400
    assert c.post("/api/admin/upload", content=good[:len(good) // 2], headers=ADMIN).status_code == 400
    assert cfg.excel_path.read_bytes() == before  # untouched after rejected uploads
    assert c.get("/api/item/00123456", headers=H).status_code == 200


def test_upload_disabled(client):
    assert client.post("/api/admin/upload", content=b"x", headers=ADMIN).status_code == 404
