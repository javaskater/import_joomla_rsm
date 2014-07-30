<?php
/**
 * JPMEna Getting the Docs from Joomla (Remositor)
 * To Joomla (DownLoad Manager
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

if ( !class_exists('JooRsm_docs', false) ) {
	class JooRsm_docs {
		//the array of Categories found in the Joomla Remository Manager
		private $wp_docs_categories_array;
		//the directory where the Files are to be found in DownLoad Manager
		private $download_directory;
		private $caller;
		
		public function __construct($download_directory,$caller) {
			$this->wp_docs_categories_array = array();
			$this->download_directory = $download_directory;
			$this->caller = $caller; //The object that called me useful to get access to the Warning and log displays !!!
		}
		
		/* Removes all data specific to 
		 * the DownLoad Manager in the DataBase
		 * And in the FileSystem
		 */
		public function suppress_all_ahm_data($action){
			global $wpdb;
			$result = true;
			$sql_delete_userstats_count = "";
			if($action == 'all'){
				//emty the ahm_files table
				$sql_delete_userstats_count = sprintf("DELETE FROM `%s`","ahm_files");
				$del_sql = $wpdb->query($sql_delete_userstats_count);
				if ($del_sql){
					if($wpdb->last_error){
						$this->caller->display_admin_error(sprintf('la requete de nettoyage de la table des fichiers joints:%s a eu un probleme %s', $sql_delete_userstats_count,$wpdb->last_error));
					}else{
						$this->caller->display_admin_notice(sprintf('la requete de nettoyage de la table des fichiers joints:%s a rien fait, la table etait deja vide',$sql_delete_userstats_count));
					}
				}
				//Delete the Option 
				if(!delete_option("ahm_files")){
					$this->caller->display_admin_error("impossible de supprimer l'option ahm_files qui contient les catégories");
				} //recreates the option if necessary !! (If the DownLoad Manager needs it !!!!)
				//Delete the imported Files
				if (is_dir($this->download_directory)) {
					if ($dh = opendir($this->download_directory)) {
						while (($file = readdir($dh)) !== false) {
							if(!is_dir($file) && substr($file , 0 ,1 ) != '.'){
								$abspath = $this->download_directory."/".$file;
								if (!unlink($abspath)){
									$this->caller->display_admin_error(sprintf('probleme lors de la suppression du fichier physique %s', $file));
								}
							}
						}
						closedir($dh);
					}
				}
			}
		}
	}
}