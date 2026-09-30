@echo off
rem ------------------------------------------------------------------
rem  Education Hub - WhatsApp Group Sender: daily start
rem  Starts the database, the web app, the sending queue and (when
rem  installed) the WhatsApp Web worker, then opens the app.
rem  Keep this window open while sending. Close it to stop everything.
rem ------------------------------------------------------------------
title Education Hub
cd /d "%~dp0"

echo Starting the database (Docker)...
docker compose up -d --wait
if errorlevel 1 (
    echo.
    echo Docker Desktop is not running. Start Docker Desktop, wait until it is ready, then run start.bat again.
    pause
    exit /b 1
)

rem Groups interrupted by a restart are marked "Delivery unconfirmed" instead of being sent twice.
php artisan campaigns:recover
if errorlevel 1 (
    echo.
    echo PHP could not start the app. See the message above.
    pause
    exit /b 1
)

rem Use the built screens, not the development server.
if exist public\hot del public\hot
if not exist public\build\manifest.json (
    echo Building the screens, first start only...
    call npm run build
)

set SERVER="php artisan serve --host=127.0.0.1 --port=8010"
set QUEUE="php artisan queue:work --queue=whatsapp,default --tries=1 --timeout=0"
set NAMES=server,queue
set WORKER=
if exist playwright\worker.js (
    set WORKER="node playwright/worker.js"
    set NAMES=server,queue,whatsapp
)

rem Open the app in the browser once the server has had a moment to start.
start "" /b cmd /c "timeout /t 4 /nobreak >nul && start http://127.0.0.1:8010"

echo.
echo Education Hub is running at http://127.0.0.1:8010  (close this window to stop)
echo.
call npx concurrently --names=%NAMES% --kill-others %SERVER% %QUEUE% %WORKER%
