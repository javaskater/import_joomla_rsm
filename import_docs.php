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
		
		/* Removes all dFiles in tthe Files Systems
		 * The data are regular Posts and terms already deleted by the 
		 * Parent Plugin!!!!
		 */
		public function suppress_all_dm_data($action){
			global $wpdb;
			$result = true;
			if($action == 'all'){ //All Posts / Post Meta  and terms relationships has been deleted by the parent plugin  
				//Delete the imported Files
				if (is_dir($this->download_directory)) {
					if ($dh = opendir($this->download_directory)) {
						while (($file = readdir($dh)) !== false) {
							if(!is_dir($file) && substr($file , 0 ,1 ) != '.'){
								$abspath = $this->download_directory."/".$file;
								if (!unlink($abspath)){
									$this->caller->display_admin_error(sprintf('probleme lors de la suppression du fichier physique %s', $abspath));
								}
							}
						}
						closedir($dh);
					}
				}
			} else {
				// WordPress post ID to start the deletion
				$start_id = intval(get_option('fgj2wp_start_id'));
				if ( $start_id != 0) {
					//Before deleting the PosstMeta Database Data, gest the path of the files to delete on the harddisk !!
					$sql_file_path_query = <<<SQL
-- Delete Post meta
SELECT meta_value FROM $wpdb->postmeta
WHERE meta_key LIKE '%file%' and post_id IN
	(
	SELECT ID FROM $wpdb->posts
	WHERE (post_type IN ('wpdmpro')
	OR post_status = 'trash'
	OR post_title = 'Brouillon auto')
	AND ID >= $start_id
	);
SQL;
					//https://codex.wordpress.org/Class_Reference/wpdb#Examples_3
					$fichiers_names = $wpdb->get_col($sql_file_path_query);
					if ( $fichiers_names )
					{
						echo "List of {$meta_key3_value}(s), sorted by {$meta_key1}, {$meta_key2}";
						foreach ( $fichiers_names as $file_path_serialized ){
							$file_path_array =  unserialize($file_path_serialized);
							if($file_path_array && len($file_path_array) > 0){
								$file_abs_path = $this->download_directory.$file_path_array[0];
								if (!unlink($file_abs_path)){
									$this->caller->display_admin_error(sprintf('probleme lors de la suppression du fichier physique %s', $file_abs_path));
								}
							}
						}
					}
					//The parent module has not deleted this new kind of Post !!!!
					$sql_queries[] = <<<SQL
-- Delete Comments meta
DELETE FROM $wpdb->commentmeta
WHERE comment_id IN
	(
	SELECT comment_ID FROM $wpdb->comments
	WHERE comment_post_ID IN
		(
		SELECT ID FROM $wpdb->posts
		WHERE (post_type IN ('wpdmpro')
		OR post_status = 'trash'
		OR post_title = 'Brouillon auto')
		AND ID >= $start_id
		)
	);
SQL;

					$sql_queries[] = <<<SQL
-- Delete Comments
DELETE FROM $wpdb->comments
WHERE comment_post_ID IN
	(
	SELECT ID FROM $wpdb->posts
	WHERE (post_type IN ('wpdmpro')
	OR post_status = 'trash'
	OR post_title = 'Brouillon auto')
	AND ID >= $start_id
	);
SQL;

					$sql_queries[] = <<<SQL
-- Delete Term relashionships
DELETE FROM $wpdb->term_relationships
WHERE `object_id` IN
	(
	SELECT ID FROM $wpdb->posts
	WHERE (post_type IN ('wpdmpro')
	OR post_status = 'trash'
	OR post_title = 'Brouillon auto')
	AND ID >= $start_id
	);
SQL;

					$sql_queries[] = <<<SQL
-- Delete Post meta
DELETE FROM $wpdb->postmeta
WHERE post_id IN
	(
	SELECT ID FROM $wpdb->posts
	WHERE (post_type IN ('wpdmpro')
	OR post_status = 'trash'
	OR post_title = 'Brouillon auto')
	AND ID >= $start_id
	);
SQL;

					$sql_queries[] = <<<SQL
-- Delete Posts
DELETE FROM $wpdb->posts
WHERE (post_type IN ('wpdmpro')
OR post_status = 'trash'
OR post_title = 'Brouillon auto')
AND ID >= $start_id;
SQL;
				}
			}
			
			// Execute SQL queries
			if ( count($sql_queries) > 0 ) {
				foreach ( $sql_queries as $sql ) {
					$result &= $wpdb->query($sql);
				}
			}
			
		}
	}
}