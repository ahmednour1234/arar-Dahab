@echo off
REM Run as Administrator. Allows port 8000 ONLY from the local subnet on Private networks.
netsh advfirewall firewall add rule name="Gold Scanner 8000" dir=in action=allow protocol=TCP localport=8000 remoteip=localsubnet profile=private
pause
