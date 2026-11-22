@echo off
REM Runs start-sync.bat automatically at every logon (Windows Task Scheduler).
REM The sync window stays visible so staff can see it is running.
cd /d "%~dp0"
schtasks /Create /F /TN "GoldScannerSync" /SC ONLOGON /RL LIMITED /TR "\"%~dp0start-sync.bat\""
if %errorlevel%==0 (echo Task "GoldScannerSync" created. It starts at next logon.) else (echo Failed.)
pause
