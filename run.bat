@echo off
:: Check if MySQL is already running
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe" >NUL
if "%ERRORLEVEL%"=="1" (
    echo Starting MySQL Database Server...
    pushd C:\xampp
    start /B mysql\bin\mysqld.exe --defaults-file=mysql\bin\my.ini --standalone
    popd
)

:: Check if Apache is already running
tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I /N "httpd.exe" >NUL
if "%ERRORLEVEL%"=="1" (
    echo Starting Apache Web Server...
    pushd C:\xampp
    start /B apache\bin\httpd.exe
    popd
)

:: Open the website in default browser
start http://localhost/koral/index.php
exit
