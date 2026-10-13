@echo off
setlocal EnableExtensions
title Uninstall Food Ticket Worker Service
cd /d "%~dp0"

net session >nul 2>&1
if errorlevel 1 (
  echo ERROR: Run as Administrator.
  pause
  exit /b 1
)

set "TASK_NAME=FoodTicket-Worker"
echo Stopping and removing task: %TASK_NAME%
schtasks /End /TN "%TASK_NAME%" >nul 2>&1
powershell.exe -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Where-Object { $_.CommandLine -like '*food_ticket_worker.php*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }" >nul 2>&1
schtasks /Delete /TN "%TASK_NAME%" /F >nul 2>&1
if errorlevel 1 (
  echo Task not found or could not be removed.
) else (
  echo Task removed.
)
echo.
pause
exit /b 0
