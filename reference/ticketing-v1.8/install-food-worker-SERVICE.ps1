#Requires -RunAsAdministrator
param(
    # -Interactive : اجرا فقط وقتی کاربر لاگین است (روش قدیمی؛ پس از ری‌استارت بدون لاگین کار نمی‌کند)
    [switch]$Interactive,
    # حساب اجراکنندهٔ worker؛ پیش‌فرض حساب فعلی (مثلاً DOMAIN\User)
    [string]$RunAs = '',
    # برای نصب بدون سؤال (اختیاری). ترجیحاً خالی بگذارید تا رمز پرسیده شود.
    [string]$PlainPassword = ''
)
$ErrorActionPreference = 'Stop'

$TaskName = 'FoodTicket-Worker'
$legacyTaskNames = @($TaskName, 'Food Ticket PHP Worker',
    [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String('VU5JUy1Gb29kVGlja2V0LVdvcmtlcg=='))) | Select-Object -Unique
$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

Write-Host ""
Write-Host "============================================"
Write-Host "  Food Ticket Worker - Install Service Task"
Write-Host "============================================"
Write-Host ("Project: " + $ProjectRoot)

$WorkerPhp = Join-Path $ProjectRoot 'cron\food_ticket_worker.php'
if (!(Test-Path $WorkerPhp)) {
    throw "cron\food_ticket_worker.php not found. Place scripts in app root (next to cron)."
}

$WrapperCmd = Join-Path $ProjectRoot 'run-food-worker-service.cmd'
$LogDir = Join-Path $ProjectRoot 'storage\food_ticket_logs'

$PhpExe = $env:FOOD_PHP
if ([string]::IsNullOrWhiteSpace($PhpExe) -or !(Test-Path $PhpExe)) {
    foreach ($c in @(
        (Join-Path $env:SystemDrive 'xampp\php\php.exe'),
        'C:\xampp\php\php.exe',
        'D:\xampp\php\php.exe',
        'C:\php\php.exe',
        'C:\laragon\bin\php\php.exe'
    )) {
        if (Test-Path $c) { $PhpExe = $c; break }
    }
}
if ([string]::IsNullOrWhiteSpace($PhpExe) -or !(Test-Path $PhpExe)) {
    $cmd = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($cmd) { $PhpExe = $cmd.Source }
}
if ([string]::IsNullOrWhiteSpace($PhpExe) -or !(Test-Path $PhpExe)) {
    throw "php.exe not found. Set FOOD_PHP=C:\xampp\php\php.exe then run again."
}
$PhpExe = (Resolve-Path $PhpExe).Path
Write-Host ("PHP: " + $PhpExe)

$ConfigPath = $env:ITSM_CONFIG_PATH
if ([string]::IsNullOrWhiteSpace($ConfigPath)) {
    $ConfigPath = Join-Path $ProjectRoot 'config.php'
} elseif (![System.IO.Path]::IsPathRooted($ConfigPath)) {
    $ConfigPath = Join-Path $ProjectRoot $ConfigPath
}
if (!(Test-Path -LiteralPath $ConfigPath -PathType Leaf)) {
    throw "Configuration file not found: $ConfigPath"
}
$ConfigPath = (Resolve-Path -LiteralPath $ConfigPath).Path
if ($ConfigPath.Contains('"')) { throw 'The configuration path cannot contain a double quote.' }
$env:ITSM_CONFIG_PATH = $ConfigPath
$env:ITSM_PROJECT_ROOT = $ProjectRoot
Write-Host ("Config: " + $ConfigPath)

function Invoke-Php {
    param([string[]]$Arguments)

    $previousPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& $PhpExe @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousPreference
    }
    [pscustomobject]@{
        ExitCode = $exitCode
        Output = @($output | ForEach-Object { [string] $_ })
    }
}

$moduleCheck = Invoke-Php -Arguments @('-m')
if ($moduleCheck.ExitCode -ne 0) { throw "Could not read the PHP module list (exit code $($moduleCheck.ExitCode))." }
$mods = @($moduleCheck.Output | ForEach-Object { $_.Trim() })
if ($moduleCheck.Output | Where-Object { $_ -match '(?:PHP )?Warning: Module "openssl" is already loaded' }) {
    Write-Host 'NOTE: openssl is loaded more than once in php.ini; trying to repair it (a backup is kept).'
    try {
        $iniInfo = Invoke-Php -Arguments @('--ini')
        $iniLine = $iniInfo.Output | Where-Object { $_ -match 'Loaded Configuration File:\s+(.+)$' } | Select-Object -First 1
        $iniPath = if ($iniLine -match 'Loaded Configuration File:\s+(.+)$') { $Matches[1].Trim() } else { '' }
        if ($iniPath -and (Test-Path -LiteralPath $iniPath)) {
            $iniLines = [System.IO.File]::ReadAllLines($iniPath)
            $pattern = '^\s*extension\s*=\s*"?(?:php_)?openssl(?:\.dll)?"?\s*(?:;.*)?$'
            $hits = @()
            for ($i = 0; $i -lt $iniLines.Length; $i++) { if ($iniLines[$i] -match $pattern) { $hits += $i } }
            if ($hits.Count -gt 1) {
                Copy-Item -LiteralPath $iniPath -Destination ($iniPath + '.bak-' + (Get-Date -Format 'yyyyMMddHHmmss')) -Force
                foreach ($idx in $hits[1..($hits.Count - 1)]) { $iniLines[$idx] = ';' + $iniLines[$idx] + '  ; duplicate disabled by food-worker installer' }
                [System.IO.File]::WriteAllLines($iniPath, $iniLines, (New-Object System.Text.UTF8Encoding $false))
                Write-Host ("php.ini repaired: " + $iniPath + " (" + ($hits.Count - 1) + " duplicate line(s) disabled). Restart Apache once to apply it there too.")
            } else {
                Write-Host ('NOTE: no duplicate extension=openssl line found in ' + $iniPath + '; check other .ini files (php --ini).')
            }
        }
    } catch {
        Write-Host ('WARNING: could not repair php.ini automatically: ' + $_.Exception.Message)
    }
}
if ($mods -notcontains 'pdo_mysql') { throw 'PHP extension pdo_mysql is required.' }
if ($mods -notcontains 'openssl') { throw 'PHP extension openssl is required.' }
$execCheck = Invoke-Php -Arguments @('-r', "exit(function_exists('exec') ? 0 : 1);")
if ($execCheck.ExitCode -ne 0) { throw 'PHP exec() must be enabled.' }
$lintCheck = Invoke-Php -Arguments @('-l', $WorkerPhp)
$lintCheck.Output | Where-Object { $_ -notmatch '(?:PHP )?Warning: Module "openssl" is already loaded' } | ForEach-Object { Write-Host $_ }
if ($lintCheck.ExitCode -ne 0) { throw 'Worker PHP syntax error.' }

$preflightPhp = Join-Path ([System.IO.Path]::GetTempPath()) ('food-ticket-preflight-' + [Guid]::NewGuid().ToString('N') + '.php')
$preflightCode = @'
<?php
$mode = (string) ($argv[1] ?? '');
try {
    if ($mode === 'mysql') {
        $configPath = (string) getenv('ITSM_CONFIG_PATH');
        $config = require $configPath;
        $database = $config['database'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($database['host'] ?? ''),
            (int) ($database['port'] ?? 3306),
            (string) ($database['name'] ?? ''),
            (string) ($database['charset'] ?? 'utf8mb4')
        );
        $pdo = new PDO($dsn, (string) ($database['user'] ?? ''), (string) ($database['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->query('SELECT 1');
        echo 'MYSQL_OK';
    } elseif ($mode === 'attendance') {
        $root = (string) getenv('ITSM_PROJECT_ROOT');
        require ($root . DIRECTORY_SEPARATOR . 'bootstrap.php');
        require ($root . DIRECTORY_SEPARATOR . 'food-ticket.php');
        $config = food_ticket_config(true);
        if (empty($config['enabled'])) {
            throw new RuntimeException('Food ticket processing is disabled in settings.');
        }
        $connection = food_ticket_odbc((string) $config['attendance_path'], function_exists('food_ticket_attendance_password') ? food_ticket_attendance_password($config) : (function_exists('food_ticket_attendance_factory_password') ? food_ticket_attendance_factory_password() : food_ticket_decrypt((string) ($config['attendance_password_enc'] ?? ''))));
        try {
            food_ticket_access_rows($connection, food_ticket_source_table(), 1);
        } finally {
            food_ticket_access_close($connection);
        }
        echo 'ATTENDANCE_SOURCE_TABLE_OK';
    } else {
        throw new RuntimeException('Unknown preflight check.');
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
'@
[System.IO.File]::WriteAllText($preflightPhp, $preflightCode, [System.Text.Encoding]::ASCII)
try {
    $databaseCheck = Invoke-Php -Arguments @($preflightPhp, 'mysql')
    if ($databaseCheck.ExitCode -ne 0) {
        $detail = ($databaseCheck.Output | Where-Object { $_ -notmatch '(?:PHP )?Warning: Module "openssl" is already loaded' }) -join ' '
        throw "MySQL connection failed using '$ConfigPath'. $detail"
    }
    Write-Host 'MySQL: connection OK.'

    $accessCheck = Invoke-Php -Arguments @($preflightPhp, 'attendance')
    if ($accessCheck.ExitCode -ne 0) {
        $detail = ($accessCheck.Output | Where-Object { $_ -notmatch '(?:PHP )?Warning: Module "openssl" is already loaded' }) -join ' '
        throw "Attendance source table connection failed. Check the saved Access password and app.key in '$ConfigPath'. $detail"
    }
    Write-Host 'Attendance source table: connection OK.'
} finally {
    Remove-Item -LiteralPath $preflightPhp -Force -ErrorAction SilentlyContinue
}

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $ProjectRoot 'storage') | Out-Null

$wrapperTemplate = @'
@echo off
setlocal EnableExtensions
chcp 65001 >nul
cd /d "__ROOT__"
set "ITSM_CONFIG_PATH=__CONFIG__"
if not exist "__LOGDIR__" mkdir "__LOGDIR__" >nul 2>&1

:loop
set "DAY="
for /f %%I in ('powershell.exe -NoProfile -Command "Get-Date -Format yyyyMMdd"') do set "DAY=%%I"
if not defined DAY set "DAY=unknown"
set "LOG=__LOGDIR__\worker-%DAY%.log"
echo [%date% %time%] START worker >> "%LOG%"
"__PHP__" -d display_errors=0 -d log_errors=1 "cron\food_ticket_worker.php" --loop --max-minutes=360 >> "%LOG%" 2>&1
set "RC=%ERRORLEVEL%"
echo [%date% %time%] EXIT %RC% >> "%LOG%"
rem RC=3: another worker instance is already running -> stop this wrapper (the 5-minute trigger retries later)
if "%RC%"=="3" exit /b 0
rem wait 10 seconds, then restart (ping is used because "timeout" fails without a console)
ping -n 11 127.0.0.1 >nul
goto loop
'@
$wrapperText = $wrapperTemplate.Replace('__ROOT__', $ProjectRoot).Replace('__CONFIG__', $ConfigPath).Replace('__LOGDIR__', $LogDir).Replace('__PHP__', $PhpExe)
$wrapperText = ($wrapperText -replace "`r?`n", "`r`n") + "`r`n"
[System.IO.File]::WriteAllText($WrapperCmd, $wrapperText, [System.Text.Encoding]::ASCII)
Write-Host ("Wrapper: " + $WrapperCmd)

$taskNamesToReplace = $legacyTaskNames
foreach ($oldTaskName in $taskNamesToReplace) {
    $existingTask = Get-ScheduledTask -TaskName $oldTaskName -ErrorAction SilentlyContinue
    if ($existingTask) {
        if ($existingTask.State -eq 'Running') {
            Stop-ScheduledTask -TaskName $oldTaskName -ErrorAction Stop
            for ($attempt = 0; $attempt -lt 10; $attempt++) {
                $existingTask = Get-ScheduledTask -TaskName $oldTaskName -ErrorAction SilentlyContinue
                if (!$existingTask -or $existingTask.State -ne 'Running') { break }
                Start-Sleep -Seconds 1
            }
            if ($existingTask -and $existingTask.State -eq 'Running') {
                throw "The previous worker task '$oldTaskName' is still running; stop it manually before reinstalling."
            }
        }
        Unregister-ScheduledTask -TaskName $oldTaskName -Confirm:$false -ErrorAction Stop
        Write-Host ("Removed previous task: " + $oldTaskName)
    } elseif ($oldTaskName -eq $TaskName) {
        Write-Host 'No existing scheduled task; creating a new one.'
    }
}

# هر php.exe یتیمی که هنوز worker را اجرا می‌کند (مثلاً بعد از Ctrl+C یا نصب قبلی) را ببند تا قفل آزاد شود.
try {
    $orphans = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -like '*food_ticket_worker.php*' })
    foreach ($o in $orphans) {
        Stop-Process -Id $o.ProcessId -Force -ErrorAction SilentlyContinue
        Write-Host ("Stopped orphan worker process PID " + $o.ProcessId)
    }
    if ($orphans.Count -gt 0) { Start-Sleep -Seconds 2 }
} catch {
    Write-Host ('NOTE: could not scan for orphan workers: ' + $_.Exception.Message)
}

$action = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument ('/c "' + $WrapperCmd + '"') -WorkingDirectory $ProjectRoot
# دو تریگر: (۱) هنگام بالا آمدن ویندوز  (۲) هر ۵ دقیقه یک بار — اگر worker به هر دلیل مرده باشد دوباره بالا می‌آید.
# MultipleInstances=IgnoreNew و قفل داخلی worker جلوی اجرای همزمان دو نمونه را می‌گیرند.
$triggerStartup = New-ScheduledTaskTrigger -AtStartup
$triggerRepeat = New-ScheduledTaskTrigger -Once -At ((Get-Date).AddMinutes(1)) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 3650)
$triggers = @($triggerStartup, $triggerRepeat)

$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -Hidden

# حساب اجرا: SYSTEM به فایل منبع تردد و چاپگر دسترسی ندارد، پس با حساب کاربری (مثلاً PSAdmin) اجرا می‌شود.
$runUser = $RunAs
if ([string]::IsNullOrWhiteSpace($runUser)) {
    $runUser = if ($env:USERNAME) { $env:USERDOMAIN + '\' + $env:USERNAME } else { 'SYSTEM' }
}
$logonDescription = ''

function ConvertTo-PlainText([System.Security.SecureString]$secure) {
    $ptr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { return [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) }
    finally { [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
}

$registered = $false
if (-not $Interactive -and $runUser -ne 'SYSTEM') {
    # حالت پیشنهادی: «Run whether user is logged on or not» — بدون نیاز به لاگین، با رمز ذخیره‌شده در ویندوز
    for ($try = 1; $try -le 3 -and -not $registered; $try++) {
        $plain = $PlainPassword
        if ([string]::IsNullOrEmpty($plain)) {
            $secure = Read-Host -AsSecureString ("Windows password for " + $runUser + " (attempt $try/3, empty = skip)")
            $plain = ConvertTo-PlainText $secure
        }
        if ([string]::IsNullOrEmpty($plain)) { break }
        try {
            Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $triggers -Settings $settings -User $runUser -Password $plain -RunLevel Highest -Description 'Food Ticket PHP Worker' -Force | Out-Null
            $registered = $true
            $logonDescription = 'Password (runs without login)'
            Write-Host ("Task account: " + $runUser + " (runs whether logged on or not)")
        } catch {
            Write-Host ('Could not register with that password: ' + $_.Exception.Message)
            $PlainPassword = ''
        } finally {
            $plain = $null
        }
    }
    if (-not $registered) {
        Write-Host 'WARNING: falling back to Interactive mode - the worker will only run while this user is logged in.'
    }
}
if (-not $registered) {
    try {
        $principal = New-ScheduledTaskPrincipal -UserId $runUser -LogonType Interactive -RunLevel Highest
        Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $triggers -Principal $principal -Settings $settings -Description 'Food Ticket PHP Worker' -Force | Out-Null
        $logonDescription = 'Interactive (only while logged in!)'
        Write-Host ("Task account: " + $runUser + " (Interactive)")
    } catch {
        $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
        Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $triggers -Principal $principal -Settings $settings -Description 'Food Ticket PHP Worker' -Force | Out-Null
        $runUser = 'SYSTEM'
        $logonDescription = 'ServiceAccount (SYSTEM - may not reach the source file/printer)'
        Write-Host 'Task account: SYSTEM (fallback)'
    }
}

Start-ScheduledTask -TaskName $TaskName -ErrorAction Stop
$task = $null
for ($attempt = 0; $attempt -lt 15; $attempt++) {
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction Stop
    if ($task.State -eq 'Running') { break }
    Start-Sleep -Seconds 1
}
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction Stop
$info = $task | Get-ScheduledTaskInfo
if ($task.State -ne 'Running') {
    throw "Scheduled task was created but the worker did not stay running. Last result=$($info.LastTaskResult). Check logs: $LogDir"
}

# صبر برای اولین heartbeat (نشانهٔ اینکه worker واقعاً چرخه اجرا کرده است)
$hb = Join-Path $ProjectRoot 'storage\food_worker.heartbeat'
$hbOk = $false
for ($attempt = 0; $attempt -lt 20; $attempt++) {
    if ((Test-Path -LiteralPath $hb) -and ((Get-Item -LiteralPath $hb).LastWriteTime -gt (Get-Date).AddSeconds(-30))) { $hbOk = $true; break }
    Start-Sleep -Seconds 1
}

Write-Host ""
Write-Host "--------------------------------------------"
Write-Host "  INSTALL OK"
Write-Host "--------------------------------------------"
Write-Host ("  Task     : " + $TaskName)
Write-Host ("  Account  : " + $runUser)
Write-Host ("  Logon    : " + $logonDescription)
Write-Host "  Trigger  : At Windows startup + every 5 min watchdog"
Write-Host "  Restart  : wrapper loop (10 s) + task restart (1 min)"
Write-Host ("  State    : " + $task.State + "  (Result 267009 = 'currently running', not an error)")
Write-Host ("  Heartbeat: " + $(if ($hbOk) { 'OK - worker is cycling' } else { 'NOT SEEN YET - run status-food-worker-SERVICE.bat in a minute' }))
Write-Host ("  Logs     : " + $LogDir)
Write-Host ("  LastRun  : " + $info.LastRunTime + "  Result=" + $info.LastTaskResult)
Write-Host ""
if ($logonDescription -like 'Interactive*') {
    Write-Host "WARNING: the worker stops when the user logs off / after a reboot without login."
    Write-Host "         Re-run the installer and enter the Windows password to fix this."
}
Write-Host "NOTE: use UNC paths (\\server\share) for Access files; mapped drive letters do not exist for background tasks."
Write-Host "NOTE: for printing prefer printer_mode = tcp_raw (port 9100); per-user Windows printers are not visible to background tasks."
Write-Host "Status: status-food-worker-SERVICE.bat"
Write-Host "Remove: uninstall-food-worker-SERVICE.bat"
Write-Host ""
