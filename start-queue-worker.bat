@echo off
echo Starting Laravel Queue Worker...
cd /d %~dp0

:loop
echo Starting queue worker...
php artisan queue:work --tries=3 --timeout=7200 --sleep=3 --max-jobs=1000
echo Queue worker exited. Restarting in 5 seconds...
timeout /t 5
goto loop
