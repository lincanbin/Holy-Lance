@echo off
set/p param=Enter the file name of the Lance Holy you want: 
php build -n %param%
pause