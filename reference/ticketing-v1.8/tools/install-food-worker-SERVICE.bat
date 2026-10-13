@echo off
chcp 65001 >nul
setlocal EnableExtensions
title نصب سرویس Worker چاپ فیش غذا

net session >nul 2>&1
if errorlevel 1 (
  echo.
  echo  این فایل را با راست‌کلیک → Run as administrator اجرا کنید.
  echo.
  pause
  exit /b 1
)

cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-food-worker-SERVICE.ps1"
set ERR=%ERRORLEVEL%
echo.
if %ERR% neq 0 (echo نصب ناموفق. کد: %ERR%) else (echo نصب انجام شد.)
pause
exit /b %ERR%
