@echo off
REM Registers a Windows Task Scheduler task that starts Gold Scanner when this user logs on.
cd /d "%~dp0"
schtasks /Create /F /TN "GoldScanner" /SC ONLOGON /RL LIMITED /TR "\"%~dp0start.bat\""
if %errorlevel%==0 (echo Task "GoldScanner" created.) else (echo Failed - try running as Administrator.)
pause
