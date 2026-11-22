@echo off
chcp 65001 >nul
title Gold Scanner - ماسح الذهب
cd /d "%~dp0"

if not exist ".venv\Scripts\python.exe" (
  echo [1/2] Creating Python virtual environment...
  where py >nul 2>nul && (py -3 -m venv .venv) || (python -m venv .venv)
  if not exist ".venv\Scripts\python.exe" (
    echo ERROR: Python 3.11+ not found. Install from python.org and tick "Add python.exe to PATH".
    pause
    exit /b 1
  )
  echo [2/2] Installing dependencies...
  ".venv\Scripts\python.exe" -m pip install --upgrade pip >nul
  ".venv\Scripts\python.exe" -m pip install -r requirements.txt || (pause & exit /b 1)
)

if not exist "config\config.json" copy "config\config.example.json" "config\config.json" >nul

echo Starting Gold Scanner... (close this window to stop)
".venv\Scripts\python.exe" -m backend.main
pause
