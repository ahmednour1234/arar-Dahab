# Gold Scanner - local sync agent
# Watches Excel files (or folders) on this PC and uploads them to the Gold Scanner
# server automatically after every save. Nothing to install: uses Windows PowerShell.
#
# Usage:  powershell -ExecutionPolicy Bypass -File gold-sync.ps1 [-Once]
#   -Once   upload changed files one time and exit (for testing / Task Scheduler)

param([switch]$Once)

$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$configPath = Join-Path $here 'gold-sync.config.json'
$statePath = Join-Path $here 'gold-sync.state.json'
$logPath = Join-Path $here 'gold-sync.log'

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function Write-Log([string]$msg, [string]$level = 'INFO') {
    $line = '{0} [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $level, $msg
    Write-Host $line
    try {
        Add-Content -Path $logPath -Value $line -Encoding UTF8
        if ((Get-Item $logPath).Length -gt 2MB) { Move-Item $logPath "$logPath.old" -Force }
    } catch {}
}

if (-not (Test-Path $configPath)) {
    Write-Log "Config not found: $configPath (copy gold-sync.config.example.json to gold-sync.config.json)" 'ERROR'
    exit 1
}
$cfg = Get-Content $configPath -Raw -Encoding UTF8 | ConvertFrom-Json
$server = ([string]$cfg.server_url).TrimEnd('/')
$poll = [int]$cfg.poll_seconds; if ($poll -lt 2) { $poll = 5 }

# state: path -> "length|lastWriteTicks" of the last successful upload
$state = @{}
if (Test-Path $statePath) {
    try {
        (Get-Content $statePath -Raw -Encoding UTF8 | ConvertFrom-Json).PSObject.Properties | ForEach-Object { $state[$_.Name] = $_.Value }
    } catch { Write-Log 'State file unreadable, starting fresh' 'WARN' }
}
function Save-State {
    $obj = New-Object PSObject
    foreach ($k in $state.Keys) { $obj | Add-Member -NotePropertyName $k -NotePropertyValue $state[$k] }
    $obj | ConvertTo-Json | Set-Content -Path $statePath -Encoding UTF8
}

# Expand configured entries (files or folders) into Excel file paths
function Get-WatchedFiles {
    $out = @()
    foreach ($entry in $cfg.paths) {
        $p = [Environment]::ExpandEnvironmentVariables([string]$entry)
        if (Test-Path -LiteralPath $p -PathType Container) {
            $out += Get-ChildItem -LiteralPath $p -File | Where-Object {
                $_.Extension -match '^\.xlsx?$' -and -not $_.Name.StartsWith('~$') -and -not $_.Name.StartsWith('.')
            } | ForEach-Object { $_.FullName }
        } elseif (Test-Path -LiteralPath $p -PathType Leaf) {
            $out += (Get-Item -LiteralPath $p).FullName
        }
    }
    return $out | Sort-Object -Unique
}

function Get-Sig([string]$path) {
    $i = Get-Item -LiteralPath $path
    return '{0}|{1}' -f $i.Length, $i.LastWriteTimeUtc.Ticks
}

# Copy while Excel may still have the file open (FileShare.ReadWrite)
function Copy-Shared([string]$src, [string]$dst) {
    $in = [IO.File]::Open($src, 'Open', 'Read', 'ReadWrite')
    try {
        $out = [IO.File]::Create($dst)
        try { $in.CopyTo($out) } finally { $out.Close() }
    } finally { $in.Close() }
}

function Send-File([string]$path) {
    $name = [IO.Path]::GetFileName($path)
    $tmp = Join-Path $env:TEMP ('gold-sync-' + [guid]::NewGuid().ToString('N') + [IO.Path]::GetExtension($path))
    try {
        Copy-Shared $path $tmp
        $headers = @{
            'X-Access-Key' = [string]$cfg.access_key
            'X-Admin-Key'  = [string]$cfg.admin_key
            'X-Filename'   = [Uri]::EscapeDataString($name)
        }
        $resp = Invoke-WebRequest -Uri "$server/api/admin/upload" -Method Post -InFile $tmp `
            -ContentType 'application/octet-stream' -Headers $headers -UseBasicParsing -TimeoutSec 120
        $body = $resp.Content | ConvertFrom-Json
        Write-Log ("Uploaded '{0}': {1} items{2}" -f $name, $body.records, $(if ($body.duplicate_barcodes) { ", $($body.duplicate_barcodes) duplicate barcodes" } else { '' }))
        return $true
    } catch {
        $detail = $_.Exception.Message
        try {
            $r = $_.Exception.Response
            if ($r) {
                $reader = New-Object IO.StreamReader($r.GetResponseStream())
                $j = $reader.ReadToEnd() | ConvertFrom-Json
                $detail = "HTTP $([int]$r.StatusCode): $($j.detail)"
            }
        } catch {}
        Write-Log "Upload failed for '$name': $detail" 'ERROR'
        return $false
    } finally {
        Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
    }
}

Write-Log "Gold Scanner sync agent started. Server: $server  Poll: ${poll}s"
$files = Get-WatchedFiles
if (-not $files) { Write-Log 'No Excel files found in configured paths yet - will keep checking.' 'WARN' }
else { Write-Log ("Watching {0} file(s): {1}" -f $files.Count, (($files | ForEach-Object { [IO.Path]::GetFileName($_) }) -join ', ')) }

$pending = @{}   # path -> sig seen last round (wait until stable before uploading)
while ($true) {
    foreach ($f in Get-WatchedFiles) {
        try { $sig = Get-Sig $f } catch { continue }
        if ($state[$f] -eq $sig) { $pending.Remove($f); continue }
        if ($Once -or $pending[$f] -eq $sig) {
            # unchanged since last check -> Excel finished saving
            if (Send-File $f) { $state[$f] = $sig; Save-State }
            $pending.Remove($f)
        } else {
            $pending[$f] = $sig
        }
    }
    if ($Once) { break }
    Start-Sleep -Seconds $poll
}
