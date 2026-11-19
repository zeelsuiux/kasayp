@echo off
title Kasyap Diamond ERP - Client Live Link
cls
echo =====================================================================
echo           KASYAP DIAMOND ERP - CLIENT LIVE LINK GENERATOR
echo =====================================================================
echo.
echo [1/2] Checking XAMPP Apache on Port 80...
netstat -ano | findstr :80 | findstr LISTENING >nul
if %errorlevel% neq 0 (
    echo [WARNING] Apache Port 80 chalu nathi! XAMPP ma Apache Start karo.
    echo.
) else (
    echo [OK] Apache Port 80 chalu che.
)
echo.
echo [2/2] Starting High-Speed Cloudflare Tunnel (HTTP/2)...
echo.
echo ---------------------------------------------------------------------
echo Niche aavti https://xxxx.trycloudflare.com vali link tamara client ne moklo.
echo AA WINDOW NE BANDH NA KARTA (Minimize kari devani).
echo ---------------------------------------------------------------------
echo.
"C:\Program Files (x86)\cloudflared\cloudflared.exe" tunnel --protocol http2 --edge-ip-version 4 --url http://localhost:80
pause
