@echo off
setlocal EnableExtensions
chcp 65001 >nul
title Food Ticket Worker Status
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0status-food-worker-SERVICE.ps1"
echo.
pause
exit /b 0
