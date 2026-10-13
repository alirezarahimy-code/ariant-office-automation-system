@echo off
setlocal EnableExtensions
chcp 65001 >nul
title Restart Food Ticket Worker
cd /d "%~dp0"

net session >nul 2>&1
if errorlevel 1 (
  echo ERROR: Run as Administrator.
  pause
  exit /b 1
)

set "TASK_NAME=FoodTicket-Worker"
echo Stopping task...
schtasks /End /TN "%TASK_NAME%" >nul 2>&1
echo Stopping leftover worker processes...
powershell.exe -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Where-Object { $_.CommandLine -like '*food_ticket_worker.php*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }"
ping -n 4 127.0.0.1 >nul
echo Starting task...
schtasks /Run /TN "%TASK_NAME%"
echo.
echo Done. Check status with status-food-worker-SERVICE.bat in about 20 seconds.
pause
exit /b 0
