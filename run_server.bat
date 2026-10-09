@echo off
echo Starting PHP Development Server on http://127.0.0.1:8000
echo Using the configured database. Private project files are blocked by router.php.
php -S 127.0.0.1:8000 router.php
pause
