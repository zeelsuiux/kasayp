$ErrorActionPreference = "SilentlyContinue"
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "       KASYAP DIAMOND ERP - LIVE CLIENT LINK RUNNER       " -ForegroundColor Yellow
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host ""

# Check Apache
$apacheRunning = Get-NetTCPConnection -State Listen -LocalPort 80 -ErrorAction SilentlyContinue
if (-not $apacheRunning) {
    Write-Host "[WARNING] XAMPP Apache is NOT running on port 80!" -ForegroundColor Red
    Write-Host "Please start Apache from XAMPP Control Panel." -ForegroundColor Yellow
    Write-Host ""
} else {
    Write-Host "[OK] XAMPP Apache is running." -ForegroundColor Green
}

$cloudflared = "C:\xampp\cloudflared.exe"
if (-not (Test-Path $cloudflared)) {
    Write-Host "[ERROR] cloudflared.exe not found at $cloudflared" -ForegroundColor Red
    exit 1
}

$logFile = "$PSScriptRoot\tunnel.log"
if (Test-Path $logFile) { Remove-Item $logFile -Force }

Write-Host "Connecting to Cloudflare... Please wait a few seconds..." -ForegroundColor Cyan

$process = Start-Process -FilePath $cloudflared -ArgumentList "tunnel --url http://localhost:80 --protocol http2" -RedirectStandardError $logFile -RedirectStandardOutput "$PSScriptRoot\tunnel_out.log" -PassThru -NoNewWindow

$foundUrl = $null
$attempts = 0
while ($attempts -lt 25) {
    Start-Sleep -Seconds 1
    $attempts++
    if (Test-Path $logFile) {
        $line = Get-Content $logFile | Select-String "trycloudflare.com" | Select-Object -Last 1
        if ($line -match "https://[a-zA-Z0-9\.\-_]+\.trycloudflare\.com") {
            $foundUrl = $matches[0]
            break
        }
    }
}

if ($foundUrl) {
    $projectUrl = "$foundUrl/projects/kasyap/"
    Clear-Host
    Write-Host "==========================================================" -ForegroundColor Green
    Write-Host "           LIVE CLIENT LINK IS ACTIVE AND RUNNING!        " -ForegroundColor Yellow
    Write-Host "==========================================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "👉 Client Share Link:" -ForegroundColor Cyan
    Write-Host "   $projectUrl" -ForegroundColor White -BackgroundColor DarkGreen
    Write-Host ""
    
    # Copy to clipboard
    try {
        Set-Clipboard -Value $projectUrl
        Write-Host "📋 [SUCCESS] Link has been copied to your clipboard!" -ForegroundColor Green
        Write-Host "             Just paste (Ctrl + V) to WhatsApp or email." -ForegroundColor Gray
    } catch {}

    # Save to desktop for reference
    $desktopLink = [Environment]::GetFolderPath("Desktop") + "\Kasyap_Live_Link.txt"
    "KASYAP DIAMOND ERP - ACTIVE CLIENT LINK`r`n`r`n$projectUrl`r`n`r`nGenerated At: $(Get-Date)" | Out-File -FilePath $desktopLink -Encoding utf8
    Write-Host "💾 Saved on Desktop: Kasyap_Live_Link.txt" -ForegroundColor Gray
    Write-Host ""
    Write-Host "----------------------------------------------------------" -ForegroundColor DarkGray
    Write-Host "⚠️  DO NOT CLOSE THIS WINDOW while the client is testing!" -ForegroundColor Yellow
    Write-Host "    Press Ctrl + C or close this window to stop the link." -ForegroundColor DarkGray
    Write-Host "----------------------------------------------------------" -ForegroundColor DarkGray

    # Keep alive loop
    $process.WaitForExit()
} else {
    Write-Host "[ERROR] Could not generate link. Please check your internet connection." -ForegroundColor Red
}
