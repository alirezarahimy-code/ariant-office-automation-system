@echo off
setlocal
set "PHP_EXE=%~1"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install_food_ticket_worker.ps1" -PhpPath "%PHP_EXE%"
if errorlevel 1 exit /b 1
exit /b 0
