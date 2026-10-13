[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string] $BackupRoot,
    [string] $DbHost = '127.0.0.1',
    [int] $DbPort = 3306,
    [string] $DbName = 'persian_ticketing',
    [string] $DbUser = 'itsm_backup',
    [string] $MySqlDumpPath = '',
    [int] $RetentionDays = 30
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$backupPath = [System.IO.Path]::GetFullPath($BackupRoot)
$projectPrefix = $projectRoot.TrimEnd([char[]]@('\', '/')) + [System.IO.Path]::DirectorySeparatorChar
if ($backupPath.Equals($projectRoot, [System.StringComparison]::OrdinalIgnoreCase) -or $backupPath.StartsWith($projectPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'برای جلوگیری از انتشار عمومی، BackupRoot را خارج از پوشه وب‌سایت انتخاب کنید.'
}
if ($DbName -notmatch '^[A-Za-z0-9_]+$' -or $DbUser -notmatch '^[A-Za-z0-9_.@-]+$') {
    throw 'نام پایگاه‌داده یا کاربر دیتابیس معتبر نیست.'
}
if ($DbHost -notmatch '^[A-Za-z0-9.:-]+$') {
    throw 'نام میزبان MySQL معتبر نیست.'
}
if ($DbPort -lt 1 -or $DbPort -gt 65535) {
    throw 'پورت MySQL معتبر نیست.'
}

if ([string]::IsNullOrWhiteSpace($MySqlDumpPath)) {
    $command = Get-Command mysqldump -ErrorAction SilentlyContinue
    if (-not $command) {
        throw 'mysqldump پیدا نشد؛ مسیر آن را با MySqlDumpPath مشخص کنید.'
    }
    $MySqlDumpPath = $command.Source
}
if (-not (Test-Path -LiteralPath $MySqlDumpPath -PathType Leaf)) {
    throw 'فایل mysqldump در مسیر مشخص‌شده پیدا نشد.'
}
New-Item -ItemType Directory -Path $backupPath -Force | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss-fff'
$dumpFile = Join-Path $backupPath "persian-ticketing-$stamp.sql"
$uploadsZip = Join-Path $backupPath "persian-ticketing-uploads-$stamp.zip"
$defaultsFile = Join-Path ([System.IO.Path]::GetTempPath()) ('itsm-mysql-' + [guid]::NewGuid().ToString('N') + '.cnf')
$utf8NoBom = [System.Text.UTF8Encoding]::new($false)

$dbPassword = [Environment]::GetEnvironmentVariable('ITSM_DB_PASSWORD')
if ([string]::IsNullOrWhiteSpace($dbPassword)) {
    $securePassword = Read-Host 'رمز کاربر اختصاصی دیتابیس' -AsSecureString
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    try {
        $dbPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
        $securePassword.Dispose()
    }
}

$quotedPassword = '"' + $dbPassword.Replace('\', '\\').Replace('"', '\"').Replace("`r", '\r').Replace("`n", '\n') + '"'
$defaults = "[client]`r`nhost=$DbHost`r`nport=$DbPort`r`nuser=$DbUser`r`npassword=$quotedPassword`r`n"
$backupComplete = $false
try {
    [System.IO.File]::WriteAllText($defaultsFile, $defaults, $utf8NoBom)
    $dumpArgs = @(
        "--defaults-extra-file=$defaultsFile",
        '--single-transaction',
        '--routines',
        '--events',
        '--triggers',
        "--result-file=$dumpFile",
        $DbName
    )
    & $MySqlDumpPath @dumpArgs
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $dumpFile -PathType Leaf) -or (Get-Item -LiteralPath $dumpFile).Length -eq 0) {
        throw 'mysqldump نسخه پشتیبان دیتابیس را کامل نکرد.'
    }

    $uploadPaths = @(
        (Join-Path $projectRoot 'storage/uploads'),
        (Join-Path $projectRoot 'assets/uploads')
    ) | Where-Object { Test-Path -LiteralPath $_ -PathType Container }
    if ($uploadPaths.Count -gt 0) {
        Compress-Archive -Path $uploadPaths -DestinationPath $uploadsZip -CompressionLevel Optimal -Force
        Write-Output "پیوست‌ها: $uploadsZip"
    } else {
        Write-Output 'پوشه پیوست‌ها هنوز خالی است؛ فقط نسخه دیتابیس ساخته شد.'
    }
    $backupComplete = $true

    if ($RetentionDays -gt 0) {
        $cutoff = (Get-Date).AddDays(-$RetentionDays)
        Get-ChildItem -LiteralPath $backupPath -File -Filter 'persian-ticketing-*' |
            Where-Object { $_.LastWriteTime -lt $cutoff } |
            Remove-Item -Force
    }
    Write-Output "پایگاه‌داده: $dumpFile"
} finally {
    Remove-Item -LiteralPath $defaultsFile -Force -ErrorAction SilentlyContinue
    if (-not $backupComplete) {
        Remove-Item -LiteralPath $dumpFile -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $uploadsZip -Force -ErrorAction SilentlyContinue
    }
    $dbPassword = ''
}
