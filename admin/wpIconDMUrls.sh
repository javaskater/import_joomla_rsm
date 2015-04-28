#!/bin/bash

#if you need to reload the non empty database mysql -uwprsm -pwprsm wprsm < wprsm.sql does the job
#see http://stackoverflow.com/questions/9558867/how-to-fetch-field-from-mysql-query-result-in-bash
#see also man mysql commmand: s=>silent N=>skip column name output
LOCAL_URL_BASE="wprsm.1and1"
REMOTE_URL_BASE="ecole.rsmontreuil.fr"
WP_MYSQL_ID=wprsm

res=$(mysql -u${WP_MYSQL_ID} -p${WP_MYSQL_ID} ${WP_MYSQL_ID} -s -N -e "SELECT ID FROM rsm_wp_posts")
#echo "resultat:"$res
#http://stackoverflow.com/questions/10586153/split-string-into-an-array-in-bash
array=(${res// / })
for i in "${!array[@]}"
do
	wp_post_id=${array[i]}
	res2=$(mysql -u${WP_MYSQL_ID} -p${WP_MYSQL_ID} ${WP_MYSQL_ID} -s -N -e "SELECT meta_value FROM rsm_wp_postmeta WHERE post_id=${wp_post_id} AND meta_key='__wpdm_icon'")
	array2=(${res2// / })
	for j in "${!array2[@]}"
	do
		metavaluelocal=${array2[j]}
		metavalueremote=${metavaluelocal/${LOCAL_URL_BASE}/${REMOTE_URL_BASE}} #http://tldp.org/LDP/abs/html/string-manipulation.html
		#echo "${wp_post_id}=>${metavaluelocal}"
		#echo "${wp_post_id}=>${metavalueremote}"
		sql_command="update rsm_wp_postmeta set meta_value='${metavalueremote}' where post_id=${wp_post_id} AND meta_key='__wpdm_icon'"
		echo "on passe la commande: $sql_command à la base Joomla"
		res3=$(mysql -u${WP_MYSQL_ID} -p${WP_MYSQL_ID} ${WP_MYSQL_ID} -s -N -e "${sql_command}")
	done
done
