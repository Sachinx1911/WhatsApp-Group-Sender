@echo off
rem ------------------------------------------------------------------
rem  Education Hub - WhatsApp Group Sender: one-click start
rem
rem  Double-click this file. It starts everything the app needs:
rem    1. Docker Desktop (if it is not running) and the MySQL database
rem    2. Database updates and recovery of interrupted campaigns
rem    3. The web app, the sending queue and the WhatsApp Web worker
rem  then opens http://127.0.0.1:8010 in your browser.
rem
rem  Keep this window open while sending. Close it to stop everything.
rem ------------------------------------------------------------------
title Education Hub
cd /d "%~dp0"
setlocal EnableDelayedExpansion

echo.
echo  ==========================================
echo   Education Hub - WhatsApp Group Sender
echo  ==========================================
echo.

rem ---------- 1. Tools --------------------------------------------------
where php >nul 2>nul
if errorlevel 1 (
    rem PHP installed with winget is not always on PATH in a fresh window.
    for /d %%D in ("%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8*") do set "PATH=%%~D;!PATH!"
)
where php >nul 2>nul
if errorlevel 1 (
    echo  [X] PHP was not found. Install it with:  winget install PHP.PHP.8.4
    goto :fail
)

where node >nul 2>nul
if errorlevel 1 (
    echo  [X] Node.js was not found. Install it from https://nodejs.org and run this file again.
    goto :fail
)

where docker >nul 2>nul
if errorlevel 1 (
    echo  [X] Docker Desktop was not found. Install it from https://www.docker.com/products/docker-desktop/
    goto :fail
)

if not exist "%USERPROFILE%\bin" mkdir "%USERPROFILE%\bin" >nul 2>nul
set "PATH=%USERPROFILE%\bin;%PATH%"

rem ---------- 2. Configuration -----------------------------------------
if not exist .env (
    copy .env.example .env >nul
    echo  [i] .env was created from .env.example.
    echo      Open .env and set DB_PASSWORD, DB_ROOT_PASSWORD, ADMIN_PASSWORD and
    echo      WHATSAPP_WORKER_TOKEN, then run start.bat again.
    goto :fail
)
findstr /c:"DB_PASSWORD=change-me" .env >nul && (
    echo  [X] DB_PASSWORD in .env is still the example value. Set a real one and run again.
    goto :fail
)

rem ---------- 3. Docker Desktop + MySQL --------------------------------
echo  [1/5] Database (Docker)...
docker info >nul 2>nul
if not errorlevel 1 goto :dockerready

echo        Docker Desktop is not running - starting it. This can take a minute...
set "DOCKER_EXE="
if exist "%LOCALAPPDATA%\Programs\DockerDesktop\Docker Desktop.exe" set "DOCKER_EXE=%LOCALAPPDATA%\Programs\DockerDesktop\Docker Desktop.exe"
if exist "%ProgramFiles%\Docker\Docker\Docker Desktop.exe" set "DOCKER_EXE=%ProgramFiles%\Docker\Docker\Docker Desktop.exe"
if "%DOCKER_EXE%"=="" (
    echo  [X] Docker Desktop.exe was not found. Start Docker Desktop yourself, then run start.bat again.
    goto :fail
)
start "" "%DOCKER_EXE%"

rem Wait up to ~3 minutes for the engine.
set /a TRIES=0
:waitdocker
timeout /t 5 /nobreak >nul
docker info >nul 2>nul
if not errorlevel 1 goto :dockerready
set /a TRIES+=1
<nul set /p "=."
if %TRIES% lss 36 goto :waitdocker
echo.
echo  [X] Docker Desktop did not start in time. Open it, wait for "Engine running", then run start.bat again.
goto :fail

:dockerready
docker compose up -d --wait
if errorlevel 1 (
    echo  [X] The MySQL container could not start. See the message above.
    goto :fail
)

rem ---------- 4. Dependencies (first run or after an update) -----------
echo  [2/5] Dependencies...
if not exist vendor\autoload.php (
    echo        Installing PHP packages, first start only...
    where composer >nul 2>nul
    if errorlevel 1 (
        echo  [X] Composer was not found. Install it from https://getcomposer.org and run again.
        goto :fail
    )
    call composer install --no-interaction --no-dev --optimize-autoloader
    if errorlevel 1 goto :fail
)
findstr /c:"APP_KEY=base64:" .env >nul || php artisan key:generate --force
if not exist node_modules (
    echo        Installing frontend packages, first start only...
    call npm install
    if errorlevel 1 goto :fail
)
if not exist public\build\manifest.json (
    echo        Building the screens, first start only...
    call npm run build
    if errorlevel 1 goto :fail
)
if exist public\hot del public\hot
if not exist playwright\node_modules (
    echo        Installing the WhatsApp worker and Chromium, first start only...
    call npm install --prefix playwright
    if errorlevel 1 goto :fail
)
if not exist playwright\.env (
    copy playwright\.env.example playwright\.env >nul
    rem Keep the worker token identical to the Laravel one.
    for /f "tokens=1,* delims==" %%A in ('findstr /b "WHATSAPP_WORKER_TOKEN=" .env') do (
        powershell -NoProfile -Command "(Get-Content 'playwright\.env') -replace '^WHATSAPP_WORKER_TOKEN=.*', 'WHATSAPP_WORKER_TOKEN=%%B' | Set-Content 'playwright\.env' -Encoding ascii"
    )
)

rem ---------- 5. Database updates + recovery ---------------------------
echo  [3/5] Database updates...
php artisan migrate --force --no-interaction
if errorlevel 1 (
    echo  [X] The database could not be updated. See the message above.
    goto :fail
)
php artisan optimize:clear >nul
php artisan db:seed --class=AdminUserSeeder --force --no-interaction >nul 2>nul
php artisan db:seed --class=CategorySeeder --force --no-interaction >nul 2>nul

echo  [4/5] Recovering interrupted campaigns...
rem Groups interrupted by a restart are marked "Delivery unconfirmed" instead of being sent twice.
php artisan campaigns:recover
if errorlevel 1 (
    echo  [X] PHP could not start the app. See the message above.
    goto :fail
)

rem ---------- 6. Run everything ----------------------------------------
echo  [5/5] Starting the app, the sending queue and the WhatsApp worker...
set SERVER="php artisan serve --host=127.0.0.1 --port=8010"
set QUEUE="php artisan queue:work --queue=whatsapp,default --tries=1 --timeout=0"
set WORKER="node playwright/worker.js"

rem Open the app in the browser once the server has had a moment to start.
start "" /b cmd /c "timeout /t 4 /nobreak >nul && start http://127.0.0.1:8010"

echo.
echo  Education Hub is running at http://127.0.0.1:8010
echo  Keep this window and the WhatsApp (Chromium) window open while sending.
echo  Close this window to stop everything.
echo.
call npx concurrently --names=server,queue,whatsapp -c "#93c5fd,#c4b5fd,#86efac" --kill-others %SERVER% %QUEUE% %WORKER%
goto :eof

:fail
echo.
pause
exit /b 1
