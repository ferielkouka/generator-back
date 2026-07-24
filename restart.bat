@echo off
:loop
php artisan serve --port=8004
echo Redemarrage...
taskkill /F /IM php.exe
timeout /t 2
goto loop