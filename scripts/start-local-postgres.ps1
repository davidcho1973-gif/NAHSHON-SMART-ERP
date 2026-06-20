param(
    [string] $PgRoot = "C:\tmp\postgresql-17.5",
    [string] $DataDir = "C:\tmp\nahshon-smart-erp-pgdata",
    [string] $Database = "nahshon_smart_erp",
    [string] $User = "postgres",
    [string] $Password = "postgres",
    [int] $Port = 5432
)

$ErrorActionPreference = "Stop"
$bin = Join-Path $PgRoot "bin"
$initdb = Join-Path $bin "initdb.exe"
$pgCtl = Join-Path $bin "pg_ctl.exe"
$pgIsReady = Join-Path $bin "pg_isready.exe"
$createdb = Join-Path $bin "createdb.exe"
$psql = Join-Path $bin "psql.exe"
$logFile = "C:\tmp\nahshon-smart-erp-postgres.log"

if (-not (Test-Path -LiteralPath $initdb)) {
    throw "PostgreSQL binaries not found at $PgRoot. Install or extract PostgreSQL portable binaries first."
}

if (-not (Test-Path -LiteralPath $DataDir)) {
    $pwFile = Join-Path $env:TEMP ("nahshon-pgpass-" + [guid]::NewGuid().ToString() + ".txt")
    $Password | Set-Content -Encoding ASCII -NoNewline -Path $pwFile
    & $initdb -D $DataDir -U $User --encoding=UTF8 --locale=C -A scram-sha-256 --pwfile=$pwFile
    Remove-Item -LiteralPath $pwFile -Force
}

$ready = & $pgIsReady -h 127.0.0.1 -p $Port -U $User 2>$null
if ($LASTEXITCODE -ne 0) {
    & $pgCtl -D $DataDir -o "-p $Port" -l $logFile start
    Start-Sleep -Seconds 2
}

$env:PGPASSWORD = $Password
$dbExists = @(& $psql -h 127.0.0.1 -p $Port -U $User -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='$Database'") -join ""
if ($dbExists.Trim() -ne "1") {
    & $createdb -h 127.0.0.1 -p $Port -U $User $Database
}

& $pgIsReady -h 127.0.0.1 -p $Port -U $User
Write-Host "PostgreSQL ready: $Database on 127.0.0.1:$Port"
