@echo off
title Zoughaib Market POS
color 0A

echo.
echo  =========================================
echo   Market POS System - Starting...
echo  =========================================
echo.

:: ── Step 1: Start MySQL (check first, then try service, then direct) ──────────
"C:\xampp\mysql\bin\mysqladmin.exe" --user=root ping >nul 2>&1
if %ERRORLEVEL%==0 (
    echo  [OK] Database already running.
    goto START_APACHE
)

echo  [..] Starting database...
net start mysql >nul 2>&1
if %ERRORLEVEL%==0 goto WAIT_MYSQL

:: Not installed as a service — start directly
if exist "C:\xampp\mysql_start.bat" (
    start /min "MySQL" "C:\xampp\mysql_start.bat"
) else (
    start /min "MySQL" "C:\xampp\mysql\bin\mysqld.exe" "--defaults-file=C:\xampp\mysql\bin\my.ini"
)

:WAIT_MYSQL
set /a TRIES=0
:MYSQL_LOOP
set /a TRIES+=1
"C:\xampp\mysql\bin\mysqladmin.exe" --user=root ping >nul 2>&1
if %ERRORLEVEL%==0 (
    echo  [OK] Database is ready.
    goto START_APACHE
)
if %TRIES% GEQ 20 (
    echo  [!!] Database did not start. Check XAMPP installation.
    goto OPEN_BROWSER
)
timeout /t 2 /nobreak >nul
goto MYSQL_LOOP

:: ── Step 2: Start Apache ──────────────────────────────────────────────────────
:START_APACHE
echo  [..] Starting web server...
net start Apache2.4 >nul 2>&1
if %ERRORLEVEL%==0 (
    echo  [OK] Web server started.
    goto RUN_UPDATE
)

:: Already running or not a service — try direct
if exist "C:\xampp\apache_start.bat" (
    start /min "Apache" "C:\xampp\apache_start.bat"
) else (
    start /min "Apache" "C:\xampp\apache\bin\httpd.exe" -f "C:\xampp\apache\conf\httpd.conf"
)
timeout /t 3 /nobreak >nul
echo  [OK] Web server ready.

:: ── Step 3: Check for and apply updates ──────────────────────────────────────
:RUN_UPDATE
echo  [..] Checking for updates...
"C:\xampp\php\php.exe" "C:\xampp\htdocs\dahdouh\auto_update.php" >> "C:\xampp\htdocs\dahdouh\auto_update.log" 2>&1
echo  [OK] Update check done.

:: ── Step 4: Open POS in browser (kiosk-printing = no print dialog) ────────────
:OPEN_BROWSER
echo.
echo  =========================================
echo   Ready! Opening POS in browser...
echo  =========================================
echo.
timeout /t 1 /nobreak >nul

:: Force Chrome/Edge to use the Windows default printer with kiosk-printing
:: (without this, kiosk-printing uses the last saved destination which may be PDF)
reg add "HKCU\SOFTWARE\Policies\Google\Chrome"    /v "DefaultPrinterSelection" /t REG_SZ /d "{\"kind\":\"default\"}" /f >nul 2>&1
reg add "HKCU\SOFTWARE\Policies\Microsoft\Edge"   /v "DefaultPrinterSelection" /t REG_SZ /d "{\"kind\":\"default\"}" /f >nul 2>&1

set POS_URL=http://localhost/dahdouh/
set BROWSER=

if exist "%PROGRAMFILES%\Google\Chrome\Application\chrome.exe"         set "BROWSER=%PROGRAMFILES%\Google\Chrome\Application\chrome.exe"
if exist "%PROGRAMFILES(X86)%\Google\Chrome\Application\chrome.exe"   set "BROWSER=%PROGRAMFILES(X86)%\Google\Chrome\Application\chrome.exe"
if exist "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe"         set "BROWSER=%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe"
if not defined BROWSER (
    if exist "%PROGRAMFILES(X86)%\Microsoft\Edge\Application\msedge.exe" set "BROWSER=%PROGRAMFILES(X86)%\Microsoft\Edge\Application\msedge.exe"
    if exist "%PROGRAMFILES%\Microsoft\Edge\Application\msedge.exe"      set "BROWSER=%PROGRAMFILES%\Microsoft\Edge\Application\msedge.exe"
)

if defined BROWSER (
    start "" "%BROWSER%" --kiosk-printing "%POS_URL%"
) else (
    start "POS" /b "%POS_URL%"
)

timeout /t 2 /nobreak >nul
exit
