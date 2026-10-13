@echo off
setlocal EnableExtensions
chcp 65001 >nul
cd /d "C:\xampp\htdocs\ticket"
set "ITSM_CONFIG_PATH=C:\xampp\htdocs\ticket\config.php"
if not exist "C:\xampp\htdocs\ticket\storage\food_ticket_logs" mkdir "C:\xampp\htdocs\ticket\storage\food_ticket_logs" >nul 2>&1

:loop
set "DAY="
for /f %%I in ('powershell.exe -NoProfile -Command "Get-Date -Format yyyyMMdd"') do set "DAY=%%I"
if not defined DAY set "DAY=unknown"
set "LOG=C:\xampp\htdocs\ticket\storage\food_ticket_logs\worker-%DAY%.log"
echo [%date% %time%] START worker >> "%LOG%"
"C:\xampp\php\php.exe" -d display_errors=0 -d log_errors=1 "cron\food_ticket_worker.php" --loop --max-minutes=360 >> "%LOG%" 2>&1
set "RC=%ERRORLEVEL%"
echo [%date% %time%] EXIT %RC% >> "%LOG%"
rem RC=3: another worker instance is already running -> stop this wrapper (the 5-minute trigger retries later)
if "%RC%"=="3" exit /b 0
rem wait 10 seconds, then restart (ping is used because "timeout" fails without a console)
ping -n 11 127.0.0.1 >nul
goto loop
