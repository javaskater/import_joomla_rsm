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
		private $download_categories;
		private $download_files;
		private $site_url;
		private $author;
		private $caller;
		
		public function __construct($download_directory,$caller) {
			$this->wp_docs_categories_array = array();
			$this->download_directory = $download_directory;
			$this->site_url = get_site_url();
			$this->download_files = array();
			$this->download_categories = array();
			$this->caller = $caller; //The object that called me useful to get access to the Warning and log displays !!!
			$this->author = null; //WPUSer Object
			$user_query = new WP_User_Query( array ( 'orderby' => 'ID', 'order' => 'DESC' ) );
			// User Loop
			if ( ! empty( $user_query->results ) ) {
				foreach ( $user_query->results as $user ) {
					$user_id = $user->ID;
					if ($user->user_login == 'javaskater'){
						$this->author = &$user;
						break;
					}
				}
			}
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
		
		/* Gets the Joomla Remository Containers
		 * and import them as WP-Terms of taxonomy wpdmcategory 
		 * Gets the Joomla Remository Files and
		 * import them as Posts of type wpdmpro
		 * Store the two arrays as instance variables for shortcode substitution
		 * //TODO le connecter aux appels généraux !!!
		 */
		public function import_joo_remository_in_wp_dm(){
			global $joomla_db;
			global $wpdb;
			$joo_prefix = $this->caller->plugin_options['prefix'];
			$joo_remository_containers = array();
			$sql = "SELECT id, name, alias, description, filepath FROM " . $joo_prefix . "downloads_containers WHERE name NOT LIKE '%Exemple%'";
			$query = $joomla_db->query($sql);
			if ( is_object($query) ) {
				foreach ( $query as $row ) {
					$joo_remository_containers[] = array(
							'id'       => $row['id'],
							'parent_id'     => 0,
							'name' => $row['name'],
							'slug' => sanitize_title($row['name']),
							'alias'    => $row['alias'],
							'description' => $row['description'],
							'filepath' => $row['filepath']
					);
				}
			}
			$this->caller->display_admin_notice(sprintf('%d joomla containers found we must insert them...', count($joo_remository_containers)));
			/*
			 * See code line 806 of fg-joomla-to-wordpress.php (private function import_categories)
			 */
			$cat_count = 0;
			foreach ( $joo_remository_containers as $joo_rem_cont ){
					if ( get_category_by_slug($joo_rem_cont['slug']) ) {
						continue; // Do not import already imported category
					}
						
					// Insert the category https://codex.wordpress.org/Function_Reference/wp_insert_category
					$this->download_categories[] = array(
						'joo_cont' => $joo_rem_cont,
						'content' => array(
							'cat_name' 				=> $joo_rem_cont['name'],
							'category_description'	=> $joo_rem_cont['description'],
							'category_nicename'		=> $joo_rem_cont['slug'], // slug
							// TODO ajouter le type de catégory qui n'est pas catégorie .... 
							'taxonomy' => 'wpdmcategory'),
						'status' => array(
								'imported' => false,
								'term_id' => -1
						)
					);
					$to_import = &$this->download_categories[count($this->download_categories)-1];	
					if ( ($cat_id = wp_insert_category($to_import['content'], $wp_error)) !== false ) {
						$cat_count++;
						$to_import['status']['imported'] = true;
						$to_import['status']['term_id'] = $cat_id;
					} else {
						$this->caller->display_admin_error(sprintf('ERROR:  This Joomla Remository Container (%s) can not be added reason (%s) !!!',$joo_rem_cont['name'],$wp_error));
					}
			}
			$this->caller->display_admin_notice(sprintf('INFO:  (%d) out of (%d) Joomla Remository Containers have been translated into WP categories',$cat_count,count($this->download_categories)));
			
			/*
			 * Pour les fichiers unitaires on fait comme précédemment
			 */
			$joo_remository_docs = array();
			$sql = "SELECT id, containerid, realname, filepath, filetitle, description, publish_from, downloads FROM " . $joo_prefix . "downloads_files";
			$query = $joomla_db->query($sql);
			if ( is_object($query) ) {
				foreach ( $query as $row ) {
					$joo_remository_docs[] = array(
							'id'       => $row['id'],
							'containerid'     => $row['containerid'],
							'filename' => $row['realname'], //the filename itself  !!!
							'dirname' => $row['filepath'], //The directory path of the file int the REMOSITORY File system
							'filetitle'    => $row['filetitle'],
							'description' => $row['description'],
							'publish_from' => $row['publish_from'], //it must be a date obkect of the time the file has been submitted for Mysql it is a datetime object !!!
							'downloads' => $row['downloads']
					);
				}
			}
			$this->caller->display_admin_notice(sprintf('%d joomla remository files found we must insert them...', count($joo_remository_docs)));
			$posts_count = 0;
			foreach ( $joo_remository_docs as $joo_rem_doc ){
				//copy the physical file if exists:
				$meta_physicalfile_value = null;
				$filename = $joo_rem_doc['filename'];
				$ext = pathinfo($filename, PATHINFO_EXTENSION);
				$filename_bis = basename($filename,'.'.$ext).'.'.$joo_rem_doc['id'].'.'.$ext; //somteimes Remository used that kind of filename instaed the first one!!!
				$icon_dm = null;
				if ($ext == 'pdf'){ //TODO peut être mettre le raccourci [url]
					$icon_dm = $this->site_url.'/wp-content/plugins/download-manager/file-type-icons/pdf.png';
				} else if($ext == 'doc' || $ext == 'docx'){
					$icon_dm = $this->site_url.'/wp-content/plugins/download-manager/file-type-icons/docx.png';
				} else if ($ext == 'xls' || $ext == 'xlsx'){
					$icon_dm = $this->site_url.'/wp-content/plugins/download-manager/file-type-icons/xlsx_win.png';
				}
				$joo_remdir = $joo_rem_doc['dirname'];
				$dest_full_path = $this->download_directory."/".$filename;
				if(file_exists($joo_remdir."/".$filename)){ 
					copy($joo_remdir."/".$filename,$dest_full_path);
					$meta_physicalfile_array = array($filename); //it will be serialized by the update_post_meta itself !!!
				}else if(file_exists($joo_remdir."/".$filename_bis)){ 
					copy($joo_remdir."/".$filename_bis,$dest_full_path);
					$meta_physicalfile_array = array($filename); //it will be serialized by the update_post_meta itself !!!
				}else{
					$this->caller->display_admin_error(sprintf('ERROR:  file (%s) does not exist or cannot be copied to (%s) !!!',$full_rempath,$dest_full_path));
					$meta_physicalfile_value = null;
				}
				$post_categories = array();
				foreach ($this->download_categories as $cat){
					if ($cat['joo_cont']['id'] == $joo_rem_doc['containerid']){
						$post_categories[] = $cat['status']['term_id']; //TODO le 6 na pas été trouvé !!! vérifier que cat change à cahque ffois !!!!
					}
				}
				if (count($post_categories) == 0){
					$post_categories[] = 1; //1 means unclassified in the terms/categories table
				}
				$author_id = 1;
				if($this->author){
					$author_id = $this->author->ID;
				}
				// Insert the dm file which is a  Post of a specific type
				$insert_date = new DateTime($joo_rem_doc['publish_from']);
				$this->download_files[] = array(
					'joo_file' => $joo_rem_doc,
					'post' => array( //TOD à adapter
						'post_category'		=> $post_categories, //works only for standard categories
						'tags_input'	=> $post_categories, //works only for standard categories
						'post_author'		=> $author_id,
						'post_content'		=> $joo_rem_doc['description'],
						'post_date'			=> date('Y-m-d H:i:s',$insert_date->getTimestamp()),
						'post_status'		=> 'publish',
						'post_title'		=> $joo_rem_doc['filetitle'],
						'post_name'			=> sanitize_title($joo_rem_doc['filetitle']),
						'post_type'			=> 'wpdmpro',
					),
					'post_meta' => array(
						'_edit_last' => $author_id,
						'_edit_lock' => $insert_date->getTimestamp().':'.$author_id, //TODO Voir ss'il s'agit d'un timestamps + ID du user javaskater
						'_statz_count' => 1,
						'__wpdm_access' => array('guest'), //it will be serialized by the update_post_meta itself !!!
						'__wpdm_download_count' => $joo_rem_doc['downloads'], //TODO récupérer les download de la requête Joomla !!!
						'__wpdm_files' => $meta_physicalfile_array, //it will be serialized by the update_post_meta itself !!!
						'__wpdm_icon' => $icon_dm,
						'__wpdm_legacy_id' => null,
						'__wpdm_link_label' => null,
						'__wpdm_masterkey' => '552f96f089efe', //TODO esssayer de voir dans le code à quoi cela peut ccorrespondre
						'__wpdm_password' => null,
						'__wpdm_quota' => null,
					),
					'status' => array(
						'imported' => false,
						'post_id' => -1
					)
				);
				$to_import = &$this->download_files[count($this->download_files)-1];
				/*
				 * The part of code has been inspired by the lines 983 to 1009 of fd-joomla-to-wordpress.php !!!!
				 */
				// Insert the post	https://codex.wordpress.org/Function_Reference/wp_insert_post
				$new_post_id_or_wp_error = wp_insert_post($to_import['post'],true); //returns a WP_Error Object if there ids a problem with the insertion otherwise the new post_id
				// to check it http://wordpress.stackexchange.com/questions/11141/how-to-catch-what-to-do-with-a-wp-error-object
				if (!is_wp_error($new_post_id_or_wp_error) ){
					//Because we are not the native post type, we must use: https://codex.wordpress.org/Function_Reference/wp_set_object_terms#Setting_a_Post.27s_Categories
					$terms_ids = array_map( 'intval', $to_import['post']['post_category']);
					$terms_ids = array_unique( $terms_ids );
					//First Solution !!!
					$new_categories_or_wp_error = wp_set_object_terms($new_post_id_or_wp_error, $terms_ids, 'wpdmcategory');
					if (is_wp_error($new_categories_or_wp_error) ){
						$this->caller->display_admin_error(sprintf('ERROR: The categores (%s) for the Joomla Rem File (%s) or WP Post (%d) could not be added reason (%s) !!!',join(';',$terms_ids),$joo_rem_doc['realname'],$new_post_id_or_wp_error,join(" | ",$new_categories_or_wp_error->get_error_messages())));
					}
					//Other Solution We can do a traditional wpdb->query
					/*foreach ($terms_ids as $term_id){
						$inserted = $wpdb->query( $wpdb->prepare( 
							"
								INSERT INTO $wpdb->term_relationships
								( object_id, term_taxonomy_id, term_order )
								VALUES ( %d, %d, %d )
							", 
						        array(
								$new_post_id_or_wp_error, 
								$term_id, 
								0
							) 
						) );
						if (!$inserted ){
							$this->caller->display_admin_error(sprintf('ERROR: The category (%d) for the Joomla Rem File (%s) or WP Post (%d) could not be added reason (%s) !!!',$term_id,$joo_rem_doc['realname'],$new_post_id_or_wp_error,join(" | ",$new_categories_or_wp_error->get_error_messages())));
						}
						
					}*/
					foreach ($to_import['post_meta'] as $meta_key => $meta_value) {
						$new_meta_id_or_wp_error = update_post_meta($new_post_id_or_wp_error, $meta_key, $meta_value, true) || add_post_meta($new_post_id_or_wp_error, $meta_key, $meta_value);
						if (is_wp_error($new_meta_id_or_wp_error) ){
							$this->caller->display_admin_error(sprintf('ERROR: The Meta tuple (%s=>%s) for the Joomla Rem File (%s) can not be added reason (%s) !!!',$meta_key,$meta_value,$joo_rem_doc['realname'],join(" | ",$new_meta_id_or_wp_error->get_error_messages())));
						}
					}
					$to_import['status']['post_id'] = $new_post_id_or_wp_error;
					$to_import['status']['imported'] = true;
					$posts_count++;
				}else{
					$this->caller->display_admin_error(sprintf('ERROR:  This Joomla Remository file (%s) can not be added reason (%s) !!!',$joo_rem_doc['realname'],join(" | ",$new_post_id_or_wp_error->get_error_messages())));
				}
			}
			$this->caller->display_admin_notice(sprintf('INFO:  (%d) out of (%d) Joomla Remository Files have been translated into WP Posts',$posts_count,count($joo_remository_docs)));
		}
		
		/* filter translate in the content
		 * from {quickdown:39} to [wpdm_package id='422']
		 * where 39 is the id of the Joo_File and the 422 the id ot the corresponding WP_Post of wpdmpro type !!!
		 */
		public function replace_joo_quickdown_links_in_posts($wp_post, $joo_post){
			$new_wp_post = $wp_post; //Array copy
			$content = $wp_post["post_content"];
			$pattern_joo_quickdown = '/{quickdown:([0-9\.]+)}/i';
			$content = preg_replace_callback($pattern_joo_quickdown, array($this, 'replace_one_joo_quikdownlink_in_post'), $content);
			$new_wp_post["post_content"] = $content;
			return $new_wp_post;
		}
		protected function replace_one_joo_quikdownlink_in_post($found_pattern_joo_quickdown){
			$translated_code = null;
			if(sizeof($found_pattern_joo_quickdown) > 1){
				$joo_id_link = $found_pattern_joo_quickdown[1];
				foreach($this->download_files as $df){
					if($df['joo_file']['id'] == $joo_id_link){
						$translated_code = '[wpdm_package id=\''.$df['status']['post_id'].'\']';
						break;
					}
				}
			}
			if (!$translated_code){ //we do nothing we return as is
				$this->caller->display_admin_error(sprintf('ERROR:  This Joomla Remository file ID (%d) can not be translated into a wpdm package!!!',$joo_id_link));
				return $found_pattern_joo_quickdown[0];
			} else {
				return $translated_code;
			}
		}
	}
}