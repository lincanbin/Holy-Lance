@echo off
set/p param=Enter the file name of the Lance Holy you want(Default: holy_lance): 
if "%param%" equ "" set "param=holy_lance"
set/p pw=Enter the password of the Lance Holy you want(No password is required by default): 
php build -n %param% -p %pw%
pause