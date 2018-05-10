#!/bin/bash
echo -n "Enter the file name of the Holy Lance you want (Default: holy_lance): "
read file_name || file_name='holy_lance'
file_name"=${file_name:=holy_lance}"
echo -n "Enter the password of the Holy Lance you want (No password is required by default): "
read pw
php build -n $file_name -p $pw