@echo off
echo Starting Laravel Queue Worker...
cd /d %~dp0
php artisan queue:work --tries=3 --timeout=3600 --sleep=3 --max-jobs=1000
pause