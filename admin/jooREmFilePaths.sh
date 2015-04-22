#!/bin/bash

#if you need to reload the non empty database mysql -ursm25 -prsm25 rsm25 < rsm25.sql does the job
#see http://stackoverflow.com/questions/9558867/how-to-fetch-field-from-mysql-query-result-in-bash
#see also man mysql commmand: s=>silent N=>skip column name output
JOO_MYSQL_ID=rsm25
WP_MYSQL_ID=wprsm
REMOSITORY_LOCAL_ROOT=/home/jpmena/workspace/RSM/2_5_RespRSM/remos_downloads

res=$(mysql -u${JOO_MYSQL_ID} -p${JOO_MYSQL_ID} ${JOO_MYSQL_ID} -s -N -e "SELECT id,filepath FROM jo25_downloads_files")
#echo "resultat:"$res
#http://stackoverflow.com/questions/10586153/split-string-into-an-array-in-bash
array=(${res// / })
prec=0
for i in "${!array[@]}"
do
	if [ $(( $i % 2)) -eq 1 ]
	then
		prec=$((i - 1))
		id_joo=${array[$prec]}
		path_joo_1and1=${array[i]}
		path_joo_local=$REMOSITORY_LOCAL_ROOT/$(expr match "$path_joo_1and1" '.*remos_downloads\/\(.*\)') #http://tldp.org/LDP/abs/html/string-manipulation.html
    	#echo "${id_joo}=>${path_joo_1and1}"
    	#echo "${id_joo}=>${path_joo_local}"
		sql_command="update jo25_downloads_files set filepath='${path_joo_local}' where id=${id_joo}"
		echo "on passe la commande: $sql_command à la base Joomla"
		res=$(mysql -u${JOO_MYSQL_ID} -p${JOO_MYSQL_ID} ${JOO_MYSQL_ID} -s -N -e "${sql_command}")
	fi
done