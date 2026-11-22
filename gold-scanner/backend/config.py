"""Configuration loading. Reads config/config.json (falls back to config.example.json)."""
from __future__ import annotations

import ipaddress
import json
import os
from dataclasses import dataclass, field
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parent.parent
CONFIG_DIR = PROJECT_ROOT / "config"

# Canonical fields, in display order, with Arabic labels.
FIELDS: list[tuple[str, str]] = [
    ("barcode", "الباركود"),
    ("item_name", "اسم القطعة"),
    ("category", "الفئة"),
    ("karat", "العيار"),
    ("weight", "الوزن (جرام)"),
    ("making_charge", "المصنعية"),
    ("price", "السعر المخزّن (ريال)"),
    ("status", "الحالة"),
    ("branch", "الفرع"),
    ("notes", "ملاحظات"),
]


@dataclass
class Config:
    excel_path: Path
    sheets: list[str] = field(default_factory=list)
    header_row: int = 1
    columns: dict[str, list[str]] = field(default_factory=dict)
    include_unmapped_columns: bool = True
    case_insensitive_barcodes: bool = True
    host: str = "0.0.0.0"
    port: int = 8000
    access_key: str = ""
    allowed_networks: list[str] = field(default_factory=list)
    demo_mode: bool = False
    poll_interval_seconds: float = 3.0
    debounce_seconds: float = 1.0
    stable_wait_seconds: float = 0.7
    read_retries: int = 6
    retry_delay_seconds: float = 1.0
    scanner_suffix: str = "enter"  # enter | tab | none
    scanner_idle_submit_ms: int = 250
    upload_enabled: bool = False
    admin_key: str = ""
    max_upload_mb: float = 20
    backups_keep: int = 10
    ssl_certfile: str = ""
    ssl_keyfile: str = ""

    def networks(self):
        return [ipaddress.ip_network(n, strict=False) for n in self.allowed_networks]


def _resolve_path(p: str) -> Path:
    path = Path(os.path.expandvars(p))
    if not path.is_absolute():
        path = PROJECT_ROOT / path
    return path


def load_config(path: str | os.PathLike | None = None) -> Config:
    if path is None:
        path = os.environ.get("GOLD_SCANNER_CONFIG")
    if path is None:
        path = CONFIG_DIR / "config.json"
        if not Path(path).exists():
            path = CONFIG_DIR / "config.example.json"
    raw = json.loads(Path(path).read_text(encoding="utf-8"))
    cols = {}
    for key, val in (raw.get("columns") or {}).items():
        cols[key] = [val] if isinstance(val, str) else list(val)
    known = Config.__dataclass_fields__.keys()
    kwargs = {k: v for k, v in raw.items() if k in known and k not in ("excel_path", "columns")}
    cfg = Config(excel_path=_resolve_path(raw["excel_path"]), columns=cols, **kwargs)
    if cfg.scanner_suffix not in ("enter", "tab", "none"):
        cfg.scanner_suffix = "enter"
    return cfg
