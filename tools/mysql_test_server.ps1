param(
    [Parameter(Mandatory=$true)][ValidateSet('Start','Stop')][string]$Action,
    [Parameter(Mandatory=$true)][string]$StateDirectory
)
$ErrorActionPreference = 'Stop'
$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')
$full = [IO.Path]::GetFullPath($StateDirectory).TrimEnd('\')
if ((Split-Path -Parent $full).TrimEnd('\') -ne $tempRoot -or
    (Split-Path -Leaf $full) -notmatch '^erp-mysql-server-[a-f0-9]{32}$') {
    throw 'State directory must be a new uniquely named direct child of the OS temporary directory.'
}
$statePath = Join-Path $full 'state.json'
if ($Action -eq 'Start') {
    if (Test-Path -LiteralPath $full) { throw 'State directory already exists; refusing to reuse it.' }
    $server = 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqld.exe'
    $client = 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqladmin.exe'
    $base = 'C:\Program Files\MySQL\MySQL Server 8.0'
    if (-not (Test-Path -LiteralPath $server) -or -not (Test-Path -LiteralPath $client)) { throw 'A local MySQL 8.0 server binary is unavailable.' }
    New-Item -ItemType Directory -Path $full | Out-Null
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    & icacls.exe $full '/inheritance:r' '/grant:r' ($identity+':(OI)(CI)F') | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not protect the temporary MySQL state directory.' }
    $entropy = [byte[]]::new(24); $rng=[Security.Cryptography.RandomNumberGenerator]::Create(); $rng.GetBytes($entropy); $rng.Dispose()
    $suffix = [BitConverter]::ToString($entropy).Replace('-','').ToLowerInvariant()
    $db = 'erp_migration_test_' + $suffix.Substring(0,12)
    $user = 'erp_test_' + $suffix.Substring(12,10)
    $password = $suffix.Substring(22,24) + 'Z9!'
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0); $listener.Start()
    $port = ([Net.IPEndPoint]$listener.LocalEndpoint).Port; $listener.Stop()
    $data = Join-Path $full 'data'; $log = Join-Path $full 'mysql.log'; $init = Join-Path $full 'initialize.sql'; $clientConfig = Join-Path $full 'client.cnf'
    $initSql = "CREATE DATABASE ``$db`` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;`nCREATE USER '$user'@'127.0.0.1' IDENTIFIED BY '$password';`nGRANT ALL PRIVILEGES ON ``$db``.* TO '$user'@'127.0.0.1';`nGRANT SHUTDOWN ON *.* TO '$user'@'127.0.0.1';"
    [IO.File]::WriteAllText($init,$initSql,[Text.UTF8Encoding]::new($false))
    [IO.File]::WriteAllText($clientConfig,"[client]`nhost=127.0.0.1`nport=$port`nuser=$user`npassword=$password`nprotocol=tcp`n",[Text.UTF8Encoding]::new($false))
    $initArgs = '--no-defaults --initialize-insecure --basedir="' + $base + '" --datadir="' + $data + '"'
    $initProc = Start-Process -FilePath $server -ArgumentList $initArgs -WindowStyle Hidden -Wait -PassThru -RedirectStandardOutput (Join-Path $full 'initialize.out') -RedirectStandardError (Join-Path $full 'initialize.err')
    if ($initProc.ExitCode -ne 0) { throw 'Private MySQL data directory initialization failed; inspect the private server log.' }
    $args = @('--no-defaults','--standalone','--console',('--basedir="'+$base+'"'),('--datadir="'+$data+'"'),"--port=$port",'--bind-address=127.0.0.1','--mysqlx=0','--skip-name-resolve',"--init-file=$init")
    $proc = Start-Process -FilePath $server -ArgumentList $args -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $full 'server.out') -RedirectStandardError (Join-Path $full 'server.err')
    $ready = $false
    for ($i=0; $i -lt 60; $i++) {
        Start-Sleep -Milliseconds 500
        if ($proc.HasExited) { throw 'Private MySQL process exited during startup; inspect the private server log.' }
        & $client "--defaults-extra-file=$clientConfig" ping *> $null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    }
    if (-not $ready) { throw 'Private MySQL did not become ready; its private state and log were retained.' }
    Remove-Item -LiteralPath $init -Force
    $serverProc = Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'mysqld.exe' -and $_.ExecutablePath -eq $server -and $_.CommandLine -like "*$data*" } | Sort-Object ProcessId -Descending | Select-Object -First 1
    if (-not $serverProc) { throw 'The owned MySQL server process could not be identified safely.' }
    $state = [ordered]@{ pid=[int]$serverProc.ProcessId; launcher_pid=$proc.Id; executable=$server; datadir=$data; port=$port; host='127.0.0.1'; database=$db; username=$user; password=$password; client_config=$clientConfig; version='8.0.45'; created_utc=[DateTime]::UtcNow.ToString('o') }
    [IO.File]::WriteAllText($statePath,($state | ConvertTo-Json -Compress),[Text.UTF8Encoding]::new($false))
    [ordered]@{ host='127.0.0.1'; port=$port; database=$db; username=$user; engine='MySQL 8.0.45'; state_directory=$full } | ConvertTo-Json -Compress
    exit 0
}
if (-not (Test-Path -LiteralPath $statePath)) { throw 'Owned server state file is missing; refusing to stop any process.' }
$state = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
$processes = @(Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'mysqld.exe' -and $_.ExecutablePath -eq $state.executable -and $_.CommandLine -like "*$($state.datadir)*" })
if (-not $processes -or -not ($processes.ProcessId -contains [int]$state.pid)) { throw 'Recorded PID does not match this private MySQL process; no process was stopped.' }
$client = 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqladmin.exe'
if (-not (Test-Path -LiteralPath $state.client_config) -or -not (Test-Path -LiteralPath $client)) { throw 'Private shutdown credentials or client are unavailable; no unrelated process was stopped.' }
& $client "--defaults-extra-file=$($state.client_config)" shutdown *> $null
if ($LASTEXITCODE -ne 0) { throw 'The owned MySQL process did not accept a graceful private shutdown request.' }
for ($i=0; $i -lt 60; $i++) {
    Start-Sleep -Milliseconds 200
    $remaining = @(Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'mysqld.exe' -and $_.ExecutablePath -eq $state.executable -and $_.CommandLine -like "*$($state.datadir)*" })
    if (-not $remaining) { 'Stopped owned temporary MySQL process.'; exit 0 }
}
throw 'Owned temporary MySQL process did not stop promptly.'
