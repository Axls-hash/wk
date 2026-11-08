@echo off
cd /d %~dp0
set ALLOW_SQLITE=1
echo [wk_dl] local preview: DB=SQLite(app.db)  URL=http://localhost:8000
echo [wk_dl] (production deploy: run daili-go.exe directly, WITHOUT ALLOW_SQLITE)
echo.
daili-go.exe
echo.
echo [wk_dl] exited. read the error above if any.
pause
