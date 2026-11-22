@echo off
title Gold Scanner Sync
cd /d "%~dp0"
if not exist gold-sync.config.json (
  copy gold-sync.config.example.json gold-sync.config.json >nul
  echo Created gold-sync.config.json - edit server_url, keys and paths, then run this again.
  notepad gold-sync.config.json
  pause
  exit /b 1
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0gold-sync.ps1"
pause
