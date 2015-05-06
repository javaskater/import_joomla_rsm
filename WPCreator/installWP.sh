#!/usr/bin/env bash

function install_from_wp_website {
		url=$1
		cible=$2
		archive_a_recuperer=$(basename $url)
		if [[ ! -f "$DOWNLOAD/$archive_a_recuperer" ]]; then
			#CURL_PROXY_OPTION="--proxy dgproxy.appli.dgi:8080"
			curl_exe=$(which curl)
			$curl_exe $CURL_PROXY_OPTION $url -o "$DOWNLOAD/$archive_a_recuperer"
		fi
		if [[ -f "$DOWNLOAD/$archive_a_recuperer" ]]; then
			echo "j'installe $archive_a_recuperer vers $cible"
			tar_exe=$(which tar)
			unzip_exe=$(which unzip)
			case "$archive_a_recuperer" in
				*.tar.gz|*.tgz)  $tar_exe xf "$DOWNLOAD/$archive_a_recuperer" -C $cible;;
				*.zip)     $unzip_exe -qq "$DOWNLOAD/$archive_a_recuperer" -d $cible;;
				*)      echo "$archive_a_recuperer a une extension non prise en charge. abandon" 
						exit 1;;
			esac
		fi
}

#trouvé sur http://superuser.com/questions/518347/equivalent-to-tars-strip-components-1-in-unzip
unzip-strip() (
    local zip=$1
    local dest=${2:-.}
    unzip_exe=$(which unzip)
    local temp=$(mktemp -d) && $unzip_exe -qq -d "$temp" "$zip" && mkdir -p "$dest" &&
    shopt -s dotglob && local f=("$temp"/*) &&
    if (( ${#f[@]} == 1 )) && [[ -d "${f[0]}" ]] ; then
        mv "$temp"/*/* "$dest"
    else
        mv "$temp"/* "$dest"
    fi && rmdir "$temp"/* "$temp"
)

function install_from_gitHub {
	url=$1
	cible=$2
	nom_plugin=$(basename ${url} .git)
	git_exe=$(which git)
	rep_actuel=$(pwd)
	echo "on change pour $cible"
	cd $cible
	echo "on clone ${url} pour créer ${cible}/${nom_plugin}"
	$git_exe clone --depth=1 ${url} && rm -rf ${nom_plugin}/.git
	echo "on revient ver $rep_actuel"
	cd $rep_actuel
}

function main {
	export DOWNLOAD="/home/jpmena/workspace/RSM/test/tmp"
	export WP_HOME="/home/jpmena/workspace/RSM"
	export WP_URL="https://fr.wordpress.org/wordpress-4.2.1-fr_FR.zip"
	export THEMES_LIST_CSV="listeThemes.csv"
	export THEMES_GITHUBLIST_CSV="listeGitThemes.csv"
	export PLUGINS_LIST_CSV="listePlugins.csv"
	export PLUGINS_GITHUBLIST_CSV="listeGitPlugins.csv"
	export WD=$(pwd)
	
	
	#supprimer une installation précédente de WP si présente
	if [[ -d "$WP_HOME/wordpress" ]]; then
		rm -rf "$WP_HOME/wordpress"
	fi
	
	mkdir -p $DOWNLOAD
	mkdir -p $WP_HOME
	
	#Télécharger et installer la dernière version de WP en français
	install_from_wp_website $WP_URL $WP_HOME
	
	#Télécharger et installer les thèmes WP
	while read -r theme
	do
		install_from_wp_website $theme "$WP_HOME/wordpress/wp-content/themes"
	done < "$WD/$THEMES_LIST_CSV"
	#Télécharger et installer les Plugins WP
	while read -r plugin
	do
		install_from_wp_website $plugin "$WP_HOME/wordpress/wp-content/plugins"
	done < "$WD/$PLUGINS_LIST_CSV"
	
	#Télécharger et installer les thèmes GitHub
	while read -r theme
	do
		install_from_gitHub $theme "$WP_HOME/wordpress/wp-content/themes"
	done < "$WD/$THEMES_GITHUBLIST_CSV"
	
	#Télécharger et installer les Plugins GitHub
	while read -r plugin
	do
		install_from_gitHub $plugin "$WP_HOME/wordpress/wp-content/plugins"
	done < "$WD/$PLUGINS_GITHUBLIST_CSV"
	
	#patcher le fichier de configuration de Wordpress
	cd "${WP_HOME}/wordpress"
	if [[ -f "${WD}/wp-config.LOCAL.patch" ]]; then 
		cp -pv wp-config-sample.php wp-config.LOCAL.php
		patch < "${WD}/wp-config.LOCAL.patch"
		cp -pv wp-config.LOCAL.php wp-config.php
	fi
	if [[ -f "${WD}/wp-config.TESTAND.patch" ]]; then 
		cp -pv wp-config-sample.php wp-config.TESTAND.php
		patch < "${WD}/wp-config.TESTAND.patch"
	fi
	if [[ -f "${WD}/wp-config.AND.patch" ]]; then 
		cp -pv wp-config-sample.php wp-config.AND.php
		patch < "${WD}/wp-config.AND.patch"
	fi
	cd $WD
	rm -rf $DOWNLOAD
}

log_file="$(basename $0)_$(date +"%d-%m-%Y_%T").log"
main 2>&1 | tee $log_file
