@echo off
setlocal EnableExtensions
title Install Food Ticket Worker Service
cd /d "%~dp0"

net session >nul 2>&1
if errorlevel 1 (
  echo.
  echo  ERROR: Run this file as Administrator.
  echo  Right-click -^> Run as administrator
  echo.
  pause
  exit /b 1
)

if not exist "%~dp0install-food-worker-SERVICE.ps1" (
  echo ERROR: install-food-worker-SERVICE.ps1 not found in:
  echo   %~dp0
  pause
  exit /b 1
)

if not exist "%~dp0cron\food_ticket_worker.php" (
  echo ERROR: cron\food_ticket_worker.php not found.
  echo Put these files in the root of persian-ticketing next to the cron folder.
  pause
  exit /b 1
)

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-food-worker-SERVICE.ps1"
set ERR=%ERRORLEVEL%
echo.
if %ERR% neq 0 (
  echo Install FAILED. Exit code: %ERR%
) else (
  echo Install OK.
)
echo.
pause
exit /b %ERR%
