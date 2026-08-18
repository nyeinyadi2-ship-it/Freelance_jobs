@echo off
echo Starting FreelanceHub WebSocket Chat Server...
echo Make sure you ran: composer install --no-dev
echo.
php %~dp0\start_server.php 8080
pause
