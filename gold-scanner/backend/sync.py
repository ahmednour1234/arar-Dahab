"""Excel synchronization service.

- watchdog watches the file's folder (Excel saves via temp file + rename)
- a polling thread is the fallback (network drives, missed events) and also
  retries automatically while the last read failed
- events are debounced, the file must be stable (size+mtime unchanged) before
  reading, reads are retried, and the active snapshot is swapped atomically
  only after a successful parse. On failure the previous snapshot is kept.
"""
from __future__ import annotations

import logging
import threading
import time
from datetime import datetime
from pathlib import Path

from .config import Config
from .excel_reader import ExcelReadError, Snapshot, diff_snapshots, parse_workbook, read_file_bytes

log = logging.getLogger("gold_scanner.sync")

try:
    from watchdog.events import FileSystemEventHandler
    from watchdog.observers import Observer
except Exception:  # pragma: no cover
    Observer = None
    FileSystemEventHandler = object


def _now() -> str:
    return datetime.now().astimezone().isoformat(timespec="seconds")


class _Handler(FileSystemEventHandler):
    def __init__(self, service: "SyncService"):
        self.service = service

    def on_any_event(self, event):
        paths = [getattr(event, "src_path", ""), getattr(event, "dest_path", "")]
        target = self.service.path.name.lower()
        if any(p and Path(p).name.lower() == target for p in paths):
            self.service.trigger("watchdog")


class SyncService:
    def __init__(self, cfg: Config):
        self.cfg = cfg
        self.path = Path(cfg.excel_path)
        self._snapshot: Snapshot | None = None
        self._lock = threading.Lock()          # guards status fields
        self._reload_lock = threading.Lock()   # one reload at a time
        self._event = threading.Event()
        self._stop = threading.Event()
        self._threads: list[threading.Thread] = []
        self._observer = None
        self._last_sig = None
        self.last_success: str | None = None
        self.last_attempt: str | None = None
        self.last_error: str | None = None
        self.last_change: dict | None = None
        self.load_count = 0
        self.watchdog_active = False

    # ---------- public state ----------
    @property
    def snapshot(self) -> Snapshot | None:
        return self._snapshot  # single reference read is atomic

    def status(self) -> dict:
        snap = self._snapshot
        with self._lock:
            err = self.last_error
            if snap is None:
                state = "error" if err else "loading"
            else:
                state = "stale" if err else "ok"
            return {
                "state": state,
                "records": snap.total_rows if snap else 0,
                "unique_barcodes": len(snap.records) if snap else 0,
                "duplicate_barcodes": len(snap.duplicates) if snap else 0,
                "warnings": list(snap.warnings) if snap else [],
                "last_success": self.last_success,
                "last_attempt": self.last_attempt,
                "last_error": err,
                "last_change": self.last_change,
                "watchdog_active": self.watchdog_active,
                "load_count": self.load_count,
            }

    # ---------- lifecycle ----------
    def start(self, background: bool = True):
        self.reload(reason="startup")
        if not background:
            return
        self._threads = [
            threading.Thread(target=self._worker, name="excel-reload", daemon=True),
            threading.Thread(target=self._poller, name="excel-poll", daemon=True),
        ]
        for t in self._threads:
            t.start()
        self._start_watchdog()

    def stop(self):
        self._stop.set()
        self._event.set()
        if self._observer:
            try:
                self._observer.stop()
                self._observer.join(timeout=2)
            except Exception:
                pass

    def _start_watchdog(self):
        if Observer is None or not self.path.parent.exists():
            log.warning("watchdog unavailable; relying on polling")
            return
        try:
            self._observer = Observer()
            self._observer.schedule(_Handler(self), str(self.path.parent), recursive=False)
            self._observer.daemon = True
            self._observer.start()
            self.watchdog_active = True
        except Exception as e:
            log.warning("watchdog failed (%s); relying on polling", e)

    def trigger(self, source: str):
        log.debug("change signal from %s", source)
        self._event.set()

    # ---------- threads ----------
    def _worker(self):
        while not self._stop.is_set():
            self._event.wait()
            if self._stop.is_set():
                return
            # debounce: keep waiting while new events arrive
            while True:
                self._event.clear()
                if not self._event.wait(self.cfg.debounce_seconds):
                    break
                if self._stop.is_set():
                    return
            self.reload(reason="change")

    def _signature(self):
        try:
            st = self.path.stat()
            return (st.st_size, st.st_mtime_ns)
        except OSError:
            return None

    def _poller(self):
        while not self._stop.wait(self.cfg.poll_interval_seconds):
            sig = self._signature()
            with self._lock:
                failing = self.last_error is not None
            if sig != self._last_sig or failing:
                self.trigger("poll")

    # ---------- reload ----------
    def _wait_stable(self) -> tuple | None:
        """Wait until size+mtime stop changing (file fully written)."""
        prev = self._signature()
        for _ in range(20):
            time.sleep(self.cfg.stable_wait_seconds)
            cur = self._signature()
            if cur is not None and cur == prev:
                return cur
            prev = cur
        return prev

    def reload(self, reason: str = "manual") -> bool:
        with self._reload_lock:
            with self._lock:
                self.last_attempt = _now()
            last_exc: Exception | None = None
            for attempt in range(1, max(1, self.cfg.read_retries) + 1):
                try:
                    if not self.path.exists():
                        raise ExcelReadError("ملف Excel غير موجود أو غير متاح حالياً")
                    sig = self._wait_stable() if reason != "startup" or attempt > 1 else self._signature()
                    data = read_file_bytes(self.path)
                    if self._signature() != sig:
                        raise ExcelReadError("الملف تغيّر أثناء القراءة")
                    snap = parse_workbook(data, self.cfg)
                    change = diff_snapshots(self._snapshot, snap)
                    self._snapshot = snap  # atomic swap
                    with self._lock:
                        self._last_sig = sig
                        self.last_success = _now()
                        self.last_error = None
                        if any(change.values()) or self.last_change is None:
                            self.last_change = change
                        self.load_count += 1
                    log.info("Excel loaded (%s): %d rows, +%d ~%d -%d, %d duplicate barcodes",
                             reason, snap.total_rows, change["added"], change["modified"],
                             change["deleted"], len(snap.duplicates))
                    return True
                except (OSError, ExcelReadError) as e:
                    last_exc = e
                    log.warning("Excel read attempt %d failed: %s", attempt, e)
                    if self._stop.is_set():
                        break
                    time.sleep(self.cfg.retry_delay_seconds)
            msg = str(last_exc) if isinstance(last_exc, ExcelReadError) else f"تعذر الوصول إلى ملف Excel ({type(last_exc).__name__})"
            with self._lock:
                self.last_error = msg
                self._last_sig = self._signature()
            log.error("Excel reload failed; keeping previous snapshot: %s", msg)
            return False
