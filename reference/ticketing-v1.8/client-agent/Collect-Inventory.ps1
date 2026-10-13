[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][uri] $Endpoint,
    [string] $AssetTag = '',
    [switch] $AllowInsecureHttp
)

$ErrorActionPreference = 'Stop'
if ($Endpoint.Scheme -ne 'https' -and -not $AllowInsecureHttp) {
    throw 'برای ارسال امن از HTTPS استفاده کنید؛ HTTP فقط با AllowInsecureHttp مجاز است.'
}
function Get-InventoryRows {
    param(
        [Parameter(Mandatory = $true)][string] $ClassName,
        [string] $Namespace = 'root/cimv2',
        [string] $Filter = ''
    )
    try {
        $options = @{ ClassName = $ClassName; Namespace = $Namespace; ErrorAction = 'Stop' }
        if ($Filter) { $options.Filter = $Filter }
        return @(Get-CimInstance @options)
    } catch {
        return @()
    }
}

$computer = Get-InventoryRows -ClassName 'Win32_ComputerSystem' |
    Select-Object Manufacturer, Model, UserName, TotalPhysicalMemory, Domain, Name -First 1
if (-not $computer) {
    throw 'اطلاعات رایانه دریافت نشد؛ اجرای اسکریپت روی Windows پشتیبانی‌شده لازم است.'
}
$bios = Get-InventoryRows -ClassName 'Win32_BIOS' |
    Select-Object SerialNumber, Manufacturer, SMBIOSBIOSVersion, ReleaseDate -First 1
$os = Get-InventoryRows -ClassName 'Win32_OperatingSystem' |
    Select-Object Caption, Version, BuildNumber, OSArchitecture, SerialNumber, InstallDate, WindowsDirectory, SystemDrive, LastBootUpTime -First 1
$processor = Get-InventoryRows -ClassName 'Win32_Processor' |
    Select-Object Name, NumberOfCores, NumberOfLogicalProcessors, ProcessorId, MaxClockSpeed, Manufacturer -First 1
$motherboard = Get-InventoryRows -ClassName 'Win32_BaseBoard' |
    Select-Object Manufacturer, Product, SerialNumber, Version -First 1
$memoryModules = Get-InventoryRows -ClassName 'Win32_PhysicalMemory' |
    Select-Object BankLabel, Capacity, Manufacturer, PartNumber, SerialNumber, Speed
$physicalDisks = Get-InventoryRows -ClassName 'Win32_DiskDrive' |
    Select-Object Index, Model, Size, SerialNumber, MediaType, InterfaceType
$graphics = Get-InventoryRows -ClassName 'Win32_VideoController' |
    Select-Object Name, AdapterRAM, DriverVersion, VideoModeDescription
$network = Get-InventoryRows -ClassName 'Win32_NetworkAdapterConfiguration' -Filter 'IPEnabled = TRUE'
$network = @($network | Where-Object { $_.IPAddress -and $_.MACAddress })
$primaryNetwork = $network | Select-Object -First 1
$logicalDisks = Get-InventoryRows -ClassName 'Win32_LogicalDisk' -Filter 'DriveType = 3'
$printers = Get-InventoryRows -ClassName 'Win32_Printer'
$pnp = Get-InventoryRows -ClassName 'Win32_PnPEntity'
$software = @(
    Get-ItemProperty -Path @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
    ) -ErrorAction SilentlyContinue |
        Where-Object { -not [string]::IsNullOrWhiteSpace([string] $_.DisplayName) } |
        Select-Object -First 500 @{ Name = 'name'; Expression = { $_.DisplayName } },
            @{ Name = 'version'; Expression = { $_.DisplayVersion } },
            @{ Name = 'publisher'; Expression = { $_.Publisher } }
)
$antivirus = Get-InventoryRows -ClassName 'AntiVirusProduct' -Namespace 'root/SecurityCenter2'
$hostname = [string] $computer.Name
$serial = [string] $bios.SerialNumber
$memoryMb = [int64] [math]::Round(([int64] $computer.TotalPhysicalMemory) / 1MB)
$antivirusNames = @($antivirus | ForEach-Object { [string] $_.displayName } | Where-Object { $_ } | Select-Object -Unique)

$payload = [ordered]@{
    asset_tag = $AssetTag
    hostname = $hostname
    serial_number = $serial
    computer_type = 'Windows client'
    manufacturer = [string] $computer.Manufacturer
    model = [string] $computer.Model
    operating_system = [string] $os.Caption
    os_architecture = [string] $os.OSArchitecture
    os_serial = [string] $os.SerialNumber
    ip_address = [string] ($primaryNetwork.IPAddress | Where-Object { $_ -notmatch '^169\.254\.' } | Select-Object -First 1)
    mac_address = [string] $primaryNetwork.MACAddress
    cpu = [string] $processor.Name
    memory_mb = $memoryMb
    domain_username = [string] $env:USERNAME
    antivirus = ($antivirusNames -join ', ')
    disks = @($logicalDisks | Select-Object DeviceID, VolumeName, FileSystem, Size, FreeSpace, DriveType)
    software = $software
    peripherals = @{
        printers = @($printers | Select-Object Name, DriverName, PortName, Default, Network, Shared)
        scanners = @($pnp | Where-Object { $_.PNPClass -eq 'Image' } | Select-Object Name, Manufacturer, PNPClass, DeviceID, Status)
        monitors = @($pnp | Where-Object { $_.PNPClass -eq 'Monitor' } | Select-Object Name, Manufacturer, PNPClass, DeviceID, Status)
        keyboards = @($pnp | Where-Object { $_.PNPClass -eq 'Keyboard' } | Select-Object Name, Manufacturer, PNPClass, DeviceID, Status)
        mice = @($pnp | Where-Object { $_.PNPClass -eq 'Mouse' } | Select-Object Name, Manufacturer, PNPClass, DeviceID, Status)
    }
    hardware = @{
        manufacturer = [string] $computer.Manufacturer
        model = [string] $computer.Model
        user = [string] $computer.UserName
        bios = $bios
        processor = $processor
        operating_system = $os
        motherboard = $motherboard
        memory_modules = $memoryModules
        physical_disks = $physicalDisks
        graphics = $graphics
        network_adapters = @($network | Select-Object Description, MACAddress, IPAddress, IPSubnet, DefaultIPGateway, DNSDomain)
        logical_disks = $logicalDisks
        antivirus = $antivirus
        collected_at = (Get-Date).ToString('o')
    }
    collected_at = (Get-Date).ToString('o')
}

$json = $payload | ConvertTo-Json -Depth 10 -Compress
if ([System.Text.Encoding]::UTF8.GetByteCount($json) -gt 4MB) {
    throw 'حجم اطلاعات بیش از محدودیت ۴ مگابایت است.'
}
$token = [Environment]::GetEnvironmentVariable('ITSM_INVENTORY_TOKEN')
if ([string]::IsNullOrWhiteSpace($token)) {
    $secureToken = Read-Host 'توکن Inventory' -AsSecureString
    $tokenPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
    try {
        $token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($tokenPointer)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($tokenPointer)
        $secureToken.Dispose()
    }
}
if ([string]::IsNullOrWhiteSpace($token)) {
    throw 'توکن Inventory الزامی است.'
}
$headers = @{ 'X-Inventory-Token' = $token }
try {
    $response = Invoke-RestMethod -Uri $Endpoint -Method Post -Headers $headers -ContentType 'application/json; charset=utf-8' -Body $json
} finally {
    $token = ''
    $headers = $null
}
if (-not $response.ok) {
    throw 'سرور اطلاعات Inventory را نپذیرفت.'
}
Write-Output ("ثبت Inventory انجام شد: {0} (شناسه {1})" -f $response.asset_tag, $response.asset_id)
