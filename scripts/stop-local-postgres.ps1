param(
    [string] $PgRoot = "C:\tmp\postgresql-17.5",
    [string] $DataDir = "C:\tmp\nahshon-smart-erp-pgdata"
)

$ErrorActionPreference = "Stop"
$pgCtl = Join-Path $PgRoot "bin\pg_ctl.exe"
if (-not (Test-Path -LiteralPath $pgCtl)) {
    throw "pg_ctl.exe not found at $PgRoot"
}
if (Test-Path -LiteralPath $DataDir) {
    & $pgCtl -D $DataDir stop -m fast
}
