#!/bin/bash
echo "Enter the file name of the Holy Lance you want (Default: holy_lance): \n"
read file_name
file_name="${file_name:=holy_lance}"
echo "Enter the password of the Holy Lance you want (No password is required by default): \n"
read pw
pw="${pw:=}"
php build -n $file_name -p $pw