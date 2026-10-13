param(
    [string] $ComputerName = ''
)

$ErrorActionPreference = 'Stop'
$utf8Encoding = New-Object -TypeName System.Text.UTF8Encoding -ArgumentList $false
[Console]::OutputEncoding = $utf8Encoding
$script:CimSession = $null

function Get-InventoryRows {
    param(
        [Parameter(Mandatory = $true)][string] $ClassName,
        [string] $Namespace = 'root/cimv2',
        [string[]] $Properties = @('*'),
        [string] $Filter = ''
    )

    $options = @{
        ClassName = $ClassName
        Namespace = $Namespace
        OperationTimeoutSec = 30
        ErrorAction = 'Stop'
    }
    if (-not [string]::IsNullOrWhiteSpace($Filter)) {
        $options.Filter = $Filter
    }
    if ($script:CimSession) {
        $options.CimSession = $script:CimSession
    }
    $items = Get-CimInstance @options
    if ($Properties -and $Properties -notcontains '*') {
        $items = $items | Select-Object -Property $Properties
    }
    return @($items)
}

function Get-OptionalInventoryRows {
    param(
        [Parameter(Mandatory = $true)][string] $ClassName,
        [string] $Namespace = 'root/cimv2',
        [string[]] $Properties = @('*'),
        [string] $Filter = ''
    )
    try {
        return @(Get-InventoryRows -ClassName $ClassName -Namespace $Namespace -Properties $Properties -Filter $Filter)
    } catch {
        return @()
    }
}

function Invoke-RegistryMethod {
    param(
        [Parameter(Mandatory = $true)][string] $MethodName,
        [Parameter(Mandatory = $true)][hashtable] $Arguments
    )
    $options = @{
        Namespace = 'root/default'
        ClassName = 'StdRegProv'
        MethodName = $MethodName
        Arguments = $Arguments
        ErrorAction = 'Stop'
    }
    if ($script:CimSession) {
        $options.CimSession = $script:CimSession
    }
    return Invoke-CimMethod @options
}

function Get-InstalledSoftware {
    $software = @()
    $clock = [System.Diagnostics.Stopwatch]::StartNew()
    $hklm = [uint32] 2147483650
    $paths = @(
        'SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall',
        'SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall'
    )
    foreach ($path in $paths) {
        try {
            $keys = Invoke-RegistryMethod -MethodName 'EnumKey' -Arguments @{ hDefKey = $hklm; sSubKeyName = $path }
            foreach ($key in @($keys.sNames)) {
                if ($software.Count -ge 500 -or $clock.Elapsed.TotalSeconds -gt 45) { break }
                $subKey = $path + '\' + [string] $key
                $nameResult = Invoke-RegistryMethod -MethodName 'GetStringValue' -Arguments @{ hDefKey = $hklm; sSubKeyName = $subKey; sValueName = 'DisplayName' }
                $name = [string] $nameResult.sValue
                if ([string]::IsNullOrWhiteSpace($name)) { continue }
                $versionResult = Invoke-RegistryMethod -MethodName 'GetStringValue' -Arguments @{ hDefKey = $hklm; sSubKeyName = $subKey; sValueName = 'DisplayVersion' }
                $publisherResult = Invoke-RegistryMethod -MethodName 'GetStringValue' -Arguments @{ hDefKey = $hklm; sSubKeyName = $subKey; sValueName = 'Publisher' }
                $software += [pscustomobject]@{
                    name = $name.Trim()
                    version = [string] $versionResult.sValue
                    publisher = [string] $publisherResult.sValue
                }
            }
        } catch {
            continue
        }
    }
    return @($software)
}

try {
    if (-not [string]::IsNullOrWhiteSpace($ComputerName)) {
        $scanUser = [Environment]::GetEnvironmentVariable('ITSM_SCAN_USER')
        $scanPass = [Environment]::GetEnvironmentVariable('ITSM_SCAN_PASS')
        $credential = $null
        if (-not [string]::IsNullOrWhiteSpace($scanUser) -and $null -ne $scanPass) {
            $securePassword = ConvertTo-SecureString $scanPass -AsPlainText -Force
            $credential = New-Object -TypeName System.Management.Automation.PSCredential -ArgumentList $scanUser, $securePassword
        }
        $sessionErrors = @()
        # ترتیب پروتکل‌ها از متغیر محیطی ITSM_SCAN_PROTOCOLS خوانده می‌شود (مثلاً «Wsman,Dcom»)
        # تا اگر DCOM روی شبکه بسته است، مسیر WinRM اول امتحان شود.
        $protocolEnv = [Environment]::GetEnvironmentVariable('ITSM_SCAN_PROTOCOLS')
        $protocolList = @()
        if (-not [string]::IsNullOrWhiteSpace($protocolEnv)) {
            $protocolList = @($protocolEnv -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ -in @('Dcom', 'Wsman') })
        }
        if ($protocolList.Count -eq 0) { $protocolList = @('Dcom', 'Wsman') }
        foreach ($protocol in $protocolList) {
            $candidate = $null
            try {
                $sessionOptions = New-CimSessionOption -Protocol $protocol
                $sessionArgs = @{ ComputerName = $ComputerName; SessionOption = $sessionOptions; OperationTimeoutSec = 30; ErrorAction = 'Stop' }
                if ($credential) { $sessionArgs.Credential = $credential }
                $candidate = New-CimSession @sessionArgs
                # New-CimSession is lazy: run a tiny query to be sure the session really works
                $null = Get-CimInstance -CimSession $candidate -ClassName Win32_ComputerSystem -OperationTimeoutSec 20 -ErrorAction Stop
                $script:CimSession = $candidate
                break
            } catch {
                $sessionErrors += ($protocol + ': ' + $_.Exception.Message)
                if ($candidate) { Remove-CimSession -CimSession $candidate -ErrorAction SilentlyContinue; $candidate = $null }
            }
        }
        if (-not $script:CimSession) {
            throw ('ALL_PROTOCOLS_FAILED: ' + ($sessionErrors -join ' | '))
        }
    }

    $computer = Get-InventoryRows -ClassName 'Win32_ComputerSystem' -Properties @('Manufacturer', 'Model', 'UserName', 'TotalPhysicalMemory', 'Domain', 'Name')
    $bios = Get-OptionalInventoryRows -ClassName 'Win32_BIOS' -Properties @('SerialNumber', 'Manufacturer', 'SMBIOSBIOSVersion', 'ReleaseDate')
    $processor = Get-OptionalInventoryRows -ClassName 'Win32_Processor' -Properties @('Name', 'NumberOfCores', 'NumberOfLogicalProcessors', 'ProcessorId', 'MaxClockSpeed', 'Manufacturer')
    $os = Get-OptionalInventoryRows -ClassName 'Win32_OperatingSystem' -Properties @('Caption', 'Version', 'BuildNumber', 'OSArchitecture', 'SerialNumber', 'InstallDate', 'WindowsDirectory', 'SystemDrive', 'LastBootUpTime')
    $motherboard = Get-OptionalInventoryRows -ClassName 'Win32_BaseBoard' -Properties @('Manufacturer', 'Product', 'SerialNumber', 'Version')
    $memory = Get-OptionalInventoryRows -ClassName 'Win32_PhysicalMemory' -Properties @('BankLabel', 'Capacity', 'Manufacturer', 'PartNumber', 'SerialNumber', 'Speed')
    $physicalDisks = Get-OptionalInventoryRows -ClassName 'Win32_DiskDrive' -Properties @('Index', 'Model', 'Size', 'SerialNumber', 'MediaType', 'InterfaceType')
    $graphics = Get-OptionalInventoryRows -ClassName 'Win32_VideoController' -Properties @('Name', 'AdapterRAM', 'DriverVersion', 'VideoModeDescription')
    $sound = Get-OptionalInventoryRows -ClassName 'Win32_SoundDevice' -Properties @('Name', 'Manufacturer', 'PNPDeviceID')
    $opticalDrives = Get-OptionalInventoryRows -ClassName 'Win32_CDROMDrive' -Properties @('Caption', 'Drive', 'Manufacturer')
    $networkAdapters = Get-OptionalInventoryRows -ClassName 'Win32_NetworkAdapter' -Properties @('Name', 'MACAddress', 'AdapterType', 'Speed', 'NetConnectionStatus', 'PhysicalAdapter', 'Manufacturer')
    $modems = Get-OptionalInventoryRows -ClassName 'Win32_POTSModem' -Properties @('Name', 'Manufacturer', 'Status')
    $users = Get-OptionalInventoryRows -ClassName 'Win32_UserProfile' -Properties @('LocalPath', 'SID', 'LastUseTime', 'Loaded')
    $disks = Get-OptionalInventoryRows -ClassName 'Win32_LogicalDisk' -Properties @('DeviceID', 'VolumeName', 'FileSystem', 'Size', 'FreeSpace', 'DriveType') | Where-Object { [int] $_.DriveType -eq 3 }
    $network = Get-OptionalInventoryRows -ClassName 'Win32_NetworkAdapterConfiguration' -Properties @('Description', 'MACAddress', 'IPAddress', 'IPSubnet', 'DefaultIPGateway', 'DNSDomain') | Where-Object { $_.IPAddress -or $_.MACAddress }
    $printers = Get-OptionalInventoryRows -ClassName 'Win32_Printer' -Properties @('Name', 'DriverName', 'PortName', 'Default', 'Network', 'Shared')
    $pnp = Get-OptionalInventoryRows -ClassName 'Win32_PnPEntity' -Properties @('Name', 'Manufacturer', 'PNPClass', 'DeviceID', 'Status') -Filter "PNPClass='Image' OR PNPClass='Monitor' OR PNPClass='Keyboard' OR PNPClass='Mouse'"
    $scanners = @($pnp | Where-Object { $_.PNPClass -eq 'Image' })
    $monitorsPnp = @($pnp | Where-Object { $_.PNPClass -eq 'Monitor' })
    $keyboards = @($pnp | Where-Object { $_.PNPClass -eq 'Keyboard' })
    $mice = @($pnp | Where-Object { $_.PNPClass -eq 'Mouse' })
    $monitors = Get-OptionalInventoryRows -ClassName 'Win32_DesktopMonitor' -Properties @('Name', 'MonitorManufacturer', 'MonitorType', 'ScreenHeight', 'ScreenWidth')
    $antivirus = Get-OptionalInventoryRows -ClassName 'AntiVirusProduct' -Namespace 'root/SecurityCenter2' -Properties @('displayName', 'productState', 'pathToSignedProductExe')
    $software = Get-InstalledSoftware

    if ($computer.Count -eq 0) {
        throw 'NO_COMPUTER_INFO: target returned no computer information (check WMI/DCOM access and firewall).'
    }
    $result = [ordered]@{
        computer = @($computer)
        bios = @($bios)
        processor = @($processor)
        os = @($os)
        motherboard = @($motherboard)
        memory_modules = @($memory)
        physical_disks = @($physicalDisks)
        graphics = @($graphics)
        sound = @($sound)
        optical_drives = @($opticalDrives)
        network_adapters = @($networkAdapters)
        modems = @($modems)
        os_users = @($users)
        disks = @($disks)
        network = @($network)
        printers = @($printers)
        scanners = @($scanners)
        monitors = @($monitors)
        monitors_pnp = @($monitorsPnp)
        keyboards = @($keyboards)
        mice = @($mice)
        antivirus = @($antivirus)
        software = @($software)
    }
    $result | ConvertTo-Json -Depth 8 -Compress
} catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
} finally {
    if ($script:CimSession) {
        Remove-CimSession -CimSession $script:CimSession -ErrorAction SilentlyContinue
    }
}
