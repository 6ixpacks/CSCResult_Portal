@echo off
REM ============================================================
REM  CSCResultPortal - Performance test using ApacheBench (ab.exe)
REM  File: tests\run-benchmarks.bat   (version 3)
REM
REM  Measures how fast the portal's pages respond on THIS computer.
REM  Results are saved in tests\benchmark-results\ (one .txt per test
REM  plus a short summary.txt).
REM
REM  GETTING THE COOKIES RIGHT (this is where it went wrong before):
REM   - Log in as the STUDENT in one browser (e.g. Chrome).
REM   - Log in as the LECTURER in a DIFFERENT browser, or a Private /
REM     Incognito window. Do NOT log out of either one. Logging out
REM     destroys the session and makes the cookie useless.
REM   - In each: F12 > Application > Cookies > http://localhost, copy the
REM     VALUE of CSCPORTAL_SESSION (just the value, not the name).
REM   - Run this file and paste them when asked, within a few minutes
REM     (cookies stop working after 30 minutes).
REM   - Do not use the portal while the test runs.
REM ============================================================
setlocal EnableDelayedExpansion

set "AB=C:\xampp\apache\bin\ab.exe"
set "BASE=http://127.0.0.1/CSCResultPortal"
set "OUT=%~dp0benchmark-results"

if not exist "%AB%" (
    echo ab.exe was not found at %AB%
    echo Edit the AB line at the top of this file to match your XAMPP folder.
    pause & exit /b 1
)

echo.
echo ============================================================
echo  You will be asked for two cookie values and one number.
echo  Paste ONLY the value (a long mix of letters and numbers).
echo  Right-click in this window to paste, then press Enter.
echo ============================================================
echo.
set /p "STUDENT_COOKIE=1) Paste the STUDENT cookie value:  "
set /p "LECTURER_COOKIE=2) Paste the LECTURER cookie value: "
set /p "ASSIGNMENT_ID=3) Type the ASSIGNMENT_ID (a number): "

call :clean STUDENT_COOKIE
call :clean LECTURER_COOKIE
call :clean ASSIGNMENT_ID

if "!STUDENT_COOKIE!"=="" (
    echo No student cookie was entered. Run the file again.
    pause & exit /b 1
)
if "!LECTURER_COOKIE!"=="" (
    echo No lecturer cookie was entered. Run the file again.
    pause & exit /b 1
)
if "!ASSIGNMENT_ID!"=="" (
    echo No assignment id was entered. Run the file again.
    pause & exit /b 1
)

echo Checking the computer is ready...
set "TW=0"
for /f %%n in ('netstat -an ^| find /c "TIME_WAIT"') do set "TW=%%n"
if !TW! GTR 2500 (
    echo.
    echo   Windows still has !TW! recently-used network connections open from an earlier test.
    echo   Running now would make the portal fail to reach its database and spoil the results.
    echo   Please wait 5 minutes ^(or restart the computer^) and run this file again.
    pause & exit /b 1
)

echo Checking that your login cookies are accepted by the portal...
call :checkcookie "Student"  "%STUDENT_COOKIE%"  "%BASE%/pages/student/dashboard.php"
if errorlevel 1 exit /b 1
call :checkcookie "Lecturer" "%LECTURER_COOKIE%" "%BASE%/pages/lecturer/enter-scores.php?assignment_id=%ASSIGNMENT_ID%"
if errorlevel 1 exit /b 1
echo   Both cookies are valid.

if exist "%OUT%" rmdir /s /q "%OUT%"
mkdir "%OUT%"

echo.
echo Running tests. This takes a few minutes...

REM          file name              label                              requests  concurrency  cookie                 url                                   extra
call :bench  01-student-dashboard   "Student dashboard"                200       1            "%STUDENT_COOKIE%"     "%BASE%/pages/student/dashboard.php"
call :bench  02-student-results     "Student My Results"               200       1            "%STUDENT_COOKIE%"     "%BASE%/pages/student/my-results.php"
call :bench  03-student-register    "Student Register Courses"         200       1            "%STUDENT_COOKIE%"     "%BASE%/pages/student/register-courses.php"
call :bench  04-lecturer-scores     "Lecturer Enter Scores"            200       1            "%LECTURER_COOKIE%"    "%BASE%/pages/lecturer/enter-scores.php?assignment_id=%ASSIGNMENT_ID%"
call :bench  05-grade-report        "Official Grade Report"            50        1            "%LECTURER_COOKIE%"    "%BASE%/pages/shared/grade-report.php?assignment_id=%ASSIGNMENT_ID%"
REM Heavy login-page tests last, using keep-alive (-k) like a real browser does
call :bench  06-login-page-c10      "Login page, 10 users at once"     2000      10           NONE                   "%BASE%/index.php"  -k
call :bench  07-login-page-c50      "Login page, 50 users at once"     3000      50           NONE                   "%BASE%/index.php"  -k
call :bench  08-login-page-c100     "Login page, 100 users at once"    3000      100          NONE                   "%BASE%/index.php"  -k
call :bench  09-login-page-c150     "Login page, 150 users at once"    3000      150          NONE                   "%BASE%/index.php"  -k

REM ---------- Build a short summary ----------
set "SUM=%OUT%\summary.txt"
echo CSCResultPortal benchmark summary> "%SUM%"
echo Run on %DATE% %TIME%>> "%SUM%"
for %%f in ("%OUT%\0*.txt") do (
    echo.>> "%SUM%"
    echo ==== %%~nxf>> "%SUM%"
    findstr /C:"Concurrency Level" /C:"Complete requests" /C:"Failed requests" /C:"Non-2xx" /C:"Document Length" /C:"Requests per second" /C:"Time per request" /C:" 50%%" /C:" 95%%" /C:" 99%%" /C:"100%%" "%%f" >> "%SUM%"
)

echo.
echo Finished. Open this file and send me its contents:
echo    %SUM%
echo.
pause
exit /b 0


REM ------------------------------------------------------------
:clean
REM Trims spaces and removes a "CSCPORTAL_SESSION=" prefix if it was pasted by mistake
set "_v=!%~1!"
for /f "tokens=1" %%a in ("!_v!") do set "_v=%%a"
set "_v=!_v:CSCPORTAL_SESSION=!"
if "!_v:~0,1!"=="=" set "_v=!_v:~1!"
set "%~1=!_v!"
exit /b


REM ------------------------------------------------------------
:checkcookie
REM %1 = label   %2 = cookie value   %3 = page address
set "CODE=" & set "SIZE="
for /f "tokens=1,2 delims=:" %%a in ('curl.exe -s -o nul -w "%%{http_code}:%%{size_download}" -H "Cookie: CSCPORTAL_SESSION=%~2" "%~3"') do (
    set "CODE=%%a"
    set "SIZE=%%b"
)
if "!CODE!"=="503" (
    echo.
    echo   The portal answered 503: it could not reach its database.
    echo   Make sure MySQL is running in XAMPP, wait 5 minutes, then run this file again.
    pause
    exit /b 1
)
if not "!CODE!"=="200" (
    echo.
    echo   The %~1 cookie was REJECTED ^(the portal answered !CODE!^).
    echo   It has expired, the session was logged out, or it was copied wrongly.
    echo   Log in again WITHOUT logging out, copy a fresh VALUE, and run this file again.
    pause
    exit /b 1
)
if !SIZE! LSS 2000 (
    echo.
    echo   The %~1 cookie was accepted, but the page was only !SIZE! bytes - that is an error message,
    echo   not the real page. For the lecturer, check that ASSIGNMENT_ID belongs to that lecturer.
    pause
    exit /b 1
)
exit /b 0


REM ------------------------------------------------------------
:bench
REM %1 = file name   %2 = label   %3 = requests   %4 = concurrency
REM %5 = cookie value or NONE   %6 = url   %7 = optional extra ab option (-k)
echo.
echo   %~2 ...
if /I "%~5"=="NONE" (
    "%AB%" -n 5 -c 1 "%~6" >nul 2>&1
    "%AB%" -n %~3 -c %~4 %~7 "%~6" > "%OUT%\%~1.txt" 2>&1
) else (
    "%AB%" -n 5 -c 1 -C "CSCPORTAL_SESSION=%~5" "%~6" >nul 2>&1
    "%AB%" -n %~3 -c %~4 %~7 -C "CSCPORTAL_SESSION=%~5" "%~6" > "%OUT%\%~1.txt" 2>&1
)
findstr /C:"Non-2xx" "%OUT%\%~1.txt" >nul && echo     WARNING: some responses were not "200 OK" - do not use this result.
exit /b
