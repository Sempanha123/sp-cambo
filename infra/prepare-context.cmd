@echo off
if exist backend-public rmdir /s /q backend-public
xcopy /e /i /q ..\backend\public backend-public >nul
if errorlevel 1 exit /b 1
echo Prepared infra\backend-public for the Nginx image.
