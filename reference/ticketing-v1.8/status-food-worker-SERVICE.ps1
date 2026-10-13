# Food Ticket Worker - status report
$ErrorActionPreference = 'Continue'
try { [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding $false } catch {}
$OutputEncoding = New-Object System.Text.UTF8Encoding $false

$TaskName = 'FoodTicket-Worker'
$legacyTaskNames = @($TaskName, 'Food Ticket PHP Worker',
    [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String('VU5JUy1Gb29kVGlja2V0LVdvcmtlcg=='))) | Select-Object -Unique
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$LogDir = Join-Path $Root 'storage\food_ticket_logs'
$Heartbeat = Join-Path $Root 'storage\food_worker.heartbeat'
$problems = @()

function Say($text, $color = 'Gray') { Write-Host $text -ForegroundColor $color }

Say ''
Say '=== Scheduled Task ===' Cyan
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
# سازگاری: اگر تسک با نام قدیمی نصب شده باشد، همان پیدا می‌شود
if (-not $task) {
    foreach ($legacy in $legacyTaskNames) {
        if ($legacy -eq $TaskName) { continue }
        $candidate = Get-ScheduledTask -TaskName $legacy -ErrorAction SilentlyContinue
        if ($candidate) { $task = $candidate; $TaskName = $legacy; break }
    }
}
if (-not $task) {
    Say 'Task is NOT installed. Run install-food-worker-SERVICE.bat as Administrator.' Red
    $problems += 'task not installed'
} else {
    $info = $task | Get-ScheduledTaskInfo
    $logon = [string]$task.Principal.LogonType
    Say ('State      : ' + $task.State)
    Say ('Account    : ' + $task.Principal.UserId)
    Say ('Logon type : ' + $logon)
    Say ('Triggers   : ' + (($task.Triggers | ForEach-Object { $_.CimClass.CimClassName -replace 'MSFT_Task|Trigger','' }) -join ', '))
    Say ('Last run   : ' + $info.LastRunTime)
    $meaning = switch ($info.LastTaskResult) {
        0        { 'success' }
        267009   { 'currently running (this is NOT an error)' }
        267011   { 'task has not run yet' }
        267014   { 'task was terminated (stopped by user/system)' }
        2147942402 { 'file not found - check wrapper/php paths' }
        default  { 'see https://learn.microsoft.com/windows/win32/taskschd/task-scheduler-error-and-success-constants' }
    }
    Say ('Last result: ' + $info.LastTaskResult + '  -> ' + $meaning)
    if ($task.State -ne 'Running') { $problems += 'task is not running'; Say 'Task is NOT running.' Red }
    if ($logon -eq 'Interactive') {
        $problems += 'Interactive logon: worker will not start after reboot without login'
        Say 'WARNING: Interactive logon - worker stops when the user logs off and does not start after reboot without login.' Yellow
        Say '         Re-run install-food-worker-SERVICE.bat and enter the Windows password.' Yellow
    }
}

Say ''
Say '=== Worker processes ===' Cyan
$procs = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -like '*food_ticket_worker.php*' })
if ($procs.Count -eq 0) {
    Say 'No food_ticket_worker.php process found.' Red
    $problems += 'no worker process'
} else {
    foreach ($p in $procs) {
        Say ('PID ' + $p.ProcessId + '  started ' + $p.CreationDate + '  mem ' + [math]::Round($p.WorkingSetSize / 1MB, 1) + ' MB')
    }
    if ($procs.Count -gt 1) {
        $problems += 'more than one worker process'
        Say 'WARNING: more than one worker is running. Run restart-food-worker-SERVICE.bat.' Yellow
    }
}

Say ''
Say '=== Heartbeat ===' Cyan
if (Test-Path -LiteralPath $Heartbeat) {
    $age = [int]((Get-Date) - (Get-Item -LiteralPath $Heartbeat).LastWriteTime).TotalSeconds
    Say ('Last cycle ' + $age + ' s ago  (' + (Get-Content -LiteralPath $Heartbeat -TotalCount 1) + ')')
    if ($age -gt 90) { $problems += 'heartbeat is stale'; Say 'Heartbeat is STALE - the worker is stuck or stopped.' Red }
} else {
    Say 'No heartbeat file yet.' Yellow
    $problems += 'no heartbeat'
}

Say ''
Say '=== Latest log ===' Cyan
$f = $null
if (Test-Path -LiteralPath $LogDir) {
    $f = Get-ChildItem -Path $LogDir -Filter 'worker-*.log' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
}
if ($f) {
    Say ('File: ' + $f.FullName)
    Get-Content -LiteralPath $f.FullName -Tail 15 -Encoding UTF8 | ForEach-Object { Write-Host $_ }
    $errs = @(Get-Content -LiteralPath $f.FullName -Tail 400 -Encoding UTF8 | Where-Object { $_ -match '\[batch\]|\[cycle\]|\[queue\]|\[food-engine\]|Fatal|db-reconnect' } | Select-Object -Last 5)
    if ($errs.Count -gt 0) {
        Say ''
        Say '--- recent error lines ---' Yellow
        $errs | ForEach-Object { Write-Host $_ }
    }
} else {
    Say 'No worker-*.log yet.' Yellow
}

Say ''
if ($problems.Count -eq 0) {
    Say 'RESULT: worker is healthy.' Green
} else {
    Say ('RESULT: ' + $problems.Count + ' problem(s): ' + ($problems -join '; ')) Red
}
Say ''
