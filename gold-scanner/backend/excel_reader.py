"""Read-only parsing of legacy .xls inventory files into an in-memory barcode index.

The file is read into memory as bytes and parsed from there, so the original
file is never opened for writing and is held open for the shortest time possible.
"""
from __future__ import annotations

import datetime as _dt
import re
from dataclasses import dataclass, field
from pathlib import Path

import xlrd

from .config import FIELDS, Config

BARCODE_RE = re.compile(r"^[0-9A-Za-z\-_.\/ ]{1,64}$")


class ExcelReadError(Exception):
    """Raised when the workbook cannot be read or fails validation."""


@dataclass
class Snapshot:
    records: dict[str, list[dict]]           # normalized key -> list of records
    duplicates: dict[str, list[dict]]        # key -> locations of duplicates
    total_rows: int
    sheets: list[str]
    warnings: list[str] = field(default_factory=list)
    fingerprint: dict[str, str] = field(default_factory=dict)  # key -> row hash for diffs


def normalize_key(barcode: str, case_insensitive: bool = True) -> str:
    key = str(barcode).strip()
    return key.upper() if case_insensitive else key


def cell_to_text(cell, datemode: int) -> str:
    """Convert a cell to a display string, keeping stored values intact."""
    ctype, value = cell.ctype, cell.value
    if ctype in (xlrd.XL_CELL_EMPTY, xlrd.XL_CELL_BLANK):
        return ""
    if ctype == xlrd.XL_CELL_TEXT:
        return str(value).strip()
    if ctype == xlrd.XL_CELL_NUMBER:
        if float(value).is_integer():
            return str(int(value))
        return ("%.6f" % float(value)).rstrip("0").rstrip(".")
    if ctype == xlrd.XL_CELL_DATE:
        try:
            d = xlrd.xldate_as_datetime(value, datemode)
            return d.date().isoformat() if d.time() == _dt.time(0) else d.isoformat(sep=" ")
        except Exception:
            return str(value)
    if ctype == xlrd.XL_CELL_BOOLEAN:
        return "TRUE" if value else "FALSE"
    if ctype == xlrd.XL_CELL_ERROR:
        return ""
    return str(value).strip()


def barcode_from_cell(cell, datemode: int) -> str:
    """Barcodes are always strings. Numeric cells lose '.0'; text cells keep leading zeros."""
    return cell_to_text(cell, datemode)


def _map_headers(headers: list[str], cfg: Config) -> tuple[dict[str, int], dict[int, str]]:
    norm = {h.strip().lower(): i for i, h in enumerate(headers) if h.strip()}
    mapped: dict[str, int] = {}
    for fkey, aliases in cfg.columns.items():
        for alias in aliases:
            idx = norm.get(alias.strip().lower())
            if idx is not None:
                mapped[fkey] = idx
                break
    used = set(mapped.values())
    extras = {i: h.strip() for i, h in enumerate(headers) if h.strip() and i not in used}
    return mapped, extras


def parse_workbook(data: bytes, cfg: Config) -> Snapshot:
    if not data:
        raise ExcelReadError("الملف فارغ (قد يكون قيد الحفظ)")
    try:
        book = xlrd.open_workbook(file_contents=data, on_demand=False)
    except Exception as e:  # xlrd raises many types for corrupt/partial files
        raise ExcelReadError(f"تعذر قراءة ملف Excel: {type(e).__name__}: {e}") from e

    names = book.sheet_names()
    if cfg.sheets:
        missing = [s for s in cfg.sheets if s not in names]
        if missing:
            raise ExcelReadError(f"أوراق العمل غير موجودة: {', '.join(missing)}")
        target = cfg.sheets
    else:
        target = names[:1]

    records: dict[str, list[dict]] = {}
    fingerprint: dict[str, str] = {}
    warnings: list[str] = []
    total = 0
    hdr = max(cfg.header_row, 1) - 1
    label_of = dict(FIELDS)

    for sname in target:
        sh = book.sheet_by_name(sname)
        if sh.nrows <= hdr:
            warnings.append(f"ورقة العمل '{sname}' لا تحتوي على بيانات")
            continue
        headers = [cell_to_text(c, book.datemode) for c in sh.row(hdr)]
        mapped, extras = _map_headers(headers, cfg)
        if "barcode" not in mapped:
            raise ExcelReadError(f"عمود الباركود غير موجود في ورقة '{sname}'. راجع إعداد columns.barcode")
        bcol = mapped["barcode"]
        for r in range(hdr + 1, sh.nrows):
            row = sh.row(r)
            if bcol >= len(row):
                continue
            barcode = barcode_from_cell(row[bcol], book.datemode)
            if not barcode:
                continue  # blank rows are skipped
            fields = {}
            for fkey, idx in mapped.items():
                fields[fkey] = cell_to_text(row[idx], book.datemode) if idx < len(row) else ""
            fields["barcode"] = barcode
            extra = []
            if cfg.include_unmapped_columns:
                for idx, h in extras.items():
                    v = cell_to_text(row[idx], book.datemode) if idx < len(row) else ""
                    if v:
                        extra.append({"label": h, "value": v})
            rec = {
                "fields": fields,
                "labels": {k: label_of.get(k, k) for k in fields},
                "extra": extra,
                "location": {"sheet": sname, "row": r + 1},
            }
            key = normalize_key(barcode, cfg.case_insensitive_barcodes)
            records.setdefault(key, []).append(rec)
            fingerprint[key] = fingerprint.get(key, "") + repr((fields, extra))
            total += 1

    if total == 0:
        # Protects against wiping the inventory with an empty/wrong file.
        raise ExcelReadError("الملف لا يحتوي على أي قطعة بباركود")
    duplicates = {k: [x["location"] for x in v] for k, v in records.items() if len(v) > 1}
    if duplicates:
        warnings.append(f"يوجد {len(duplicates)} باركود مكرر في الملف")
    return Snapshot(records, duplicates, total, list(target), warnings, fingerprint)


def read_file_bytes(path: Path) -> bytes:
    """Read-only, binary. Never opens the file for writing."""
    with open(path, "rb") as f:
        return f.read()


def diff_snapshots(old: Snapshot | None, new: Snapshot) -> dict[str, int]:
    if old is None:
        return {"added": len(new.records), "modified": 0, "deleted": 0}
    o, n = old.fingerprint, new.fingerprint
    return {
        "added": sum(1 for k in n if k not in o),
        "deleted": sum(1 for k in o if k not in n),
        "modified": sum(1 for k in n if k in o and o[k] != n[k]),
    }


def valid_barcode(s: str) -> bool:
    return bool(BARCODE_RE.match(s.strip())) if s else False
