[CmdletBinding()]
param([string]$PhpPath)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$workerPath = Join-Path $projectRoot 'cron\food_ticket_worker.php'

if ([string]::IsNullOrWhiteSpace($PhpPath)) {
    $xamppPhp = Join-Path $env:SystemDrive 'xampp\php\php.exe'
    if (Test-Path $xamppPhp) {
        $PhpPath = $xamppPhp
    } else {
        $phpCommand = Get-Command php.exe -ErrorAction SilentlyContinue
        if ($phpCommand) {
            $PhpPath = $phpCommand.Source
        }
    }
}

if ([string]::IsNullOrWhiteSpace($PhpPath) -or !(Test-Path $PhpPath)) {
    throw 'PHP CLI was not found. Pass the path to php.exe to the BAT file, for example: install_food_ticket_worker_windows.bat C:\xampp\php\php.exe'
}
$PhpPath = (Resolve-Path $PhpPath).Path
Write-Host "Using PHP CLI: $PhpPath"

function Invoke-Php {
    param([string[]]$Arguments)

    $previousPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& $PhpPath @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousPreference
    }
    [pscustomobject]@{
        ExitCode = $exitCode
        Output = @($output | ForEach-Object { [string] $_ })
    }
}

$iniCheck = Invoke-Php -Arguments @('--ini')
$iniCheck.Output | Where-Object { $_ -notmatch '(?:PHP )?Warning: Module "openssl" is already loaded' } | ForEach-Object { Write-Host $_ }
if ($iniCheck.ExitCode -ne 0) {
    throw 'Could not read the PHP configuration path.'
}

$moduleCheck = Invoke-Php -Arguments @('-m')
if ($moduleCheck.ExitCode -ne 0) {
    throw 'Could not read the PHP module list.'
}
$phpModules = @($moduleCheck.Output | ForEach-Object { $_.Trim() })
if ($moduleCheck.Output | Where-Object { $_ -match '(?:PHP )?Warning: Module "openssl" is already loaded' }) {
    Write-Host 'NOTE: openssl is loaded more than once in php.ini; it is available, so installation will continue.'
}
if ($phpModules -notcontains 'pdo_mysql') {
    throw 'The pdo_mysql PHP extension must be enabled for this php.exe.'
}

if ($phpModules -notcontains 'openssl') {
    throw 'The openssl PHP extension is required to decrypt the saved Access password. Enable it in the php.ini used by this php.exe.'
}

$execCheck = Invoke-Php -Arguments @('-r', "exit(function_exists('exec') ? 0 : 1);")
if ($execCheck.ExitCode -ne 0) {
    throw 'The PHP exec function must be enabled for the PowerShell fallback and Windows printing.'
}

if ($phpModules -notcontains 'odbc') {
    Write-Host 'PHP odbc is not enabled. Access reads and deletes will use PowerShell and Microsoft ACE.'
}

$lintCheck = Invoke-Php -Arguments @('-l', $workerPath)
$lintCheck.Output | Where-Object { $_ -notmatch '(?:PHP )?Warning: Module "openssl" is already loaded' } | ForEach-Object { Write-Host $_ }
if ($lintCheck.ExitCode -ne 0) {
    throw 'PHP worker syntax validation failed.'
}

$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$action = New-ScheduledTaskAction -Execute $PhpPath -Argument ('"{0}" --loop' -f $workerPath) -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $identity
$principal = New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero)
Register-ScheduledTask -TaskName 'Food Ticket PHP Worker' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Polls the attendance source table and prints food tickets.' -Force | Out-Null
Start-ScheduledTask -TaskName 'Food Ticket PHP Worker'

Write-Host 'Worker task installed and started for the current Windows account.'
Write-Host 'The account must remain signed in and have access to the Access files, MySQL, and printer.'
