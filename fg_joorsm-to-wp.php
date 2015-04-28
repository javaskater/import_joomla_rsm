<?php
/**
 * Plugin Name: My fg_joomla extension for the RSM
 * Plugin Uri:  http://test.rsmontreuil.fr
 * Description: l'extension du fg-joomla-to-wordpress pour gérer finement
 * l'importation des données Joomla du RSM
 * Version:     0.1
 * Author:      Jean-Pierre MENA
 *  */
define( 'JOORSM__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
require_once( JOORSM__PLUGIN_DIR . '/admin/menu.php' );
require_once( JOORSM__PLUGIN_DIR . '/import_docs.php');

RsmImportMenu::init();
if ( class_exists('fgj2wp', false) ) {
	class JooRsm extends fgj2wp {
		const REGEXPQUOTES = '["\\\\]+'; //on ne met pas \' car un contenu title ou alt peut l'inclure !!!!
		const NOT_REGEXPQUOTES = '[^"\\\\]+'; //http://stackoverflow.com/questions/22070140/preg-match-a-php-string-with-simple-or-double-quotes-escaped-inside
		const REGEXPSIMPLEQUOTES = '[\'\\\\]+';
		const NOT_REGEXPSIMPLEQUOTES = '[^\'\\\\]+';
		const REGEXPBACKSLASHES = '[\\\\]+';
		private $admin_menu;
		private $joo_images_directory;
		private $post_media = array(); //for each post the array of attachment posts of type images
		private $imported_users = array(); //Users I get From the Joomla database
		private $media_count = 0; //the total number of images imported !!!
		private $image_size_in_post;
		private $site_base_url;
		private $tab_path_icons;
		private $ICON_PATH="admin/icons.csv";
		public function __construct(){
			$this->image_size_in_post = "medium"; //I want all the image in posts to be medium size !!!!
			//ABSPATH contains already a / 
			$this->site_base_url = get_site_url(); //the url I want to fetch in the images links !!!!
			$this->joo_images_directory = ABSPATH . 'wp-content/uploads/images';
			$this->tab_path_icons=array();
			$handle = fopen(JOORSM__PLUGIN_DIR.$this->ICON_PATH, "r");
			while($buffer = fgets($handle, 4096)){
				array_push($this->tab_path_icons,trim($buffer));
			}
			fclose($handle);
			$docs_manager = new JooRsm_docs(ABSPATH . 'wp-content/uploads/download-manager-files',$this);
			$options = get_option('fgj2wp_options');
			$this->plugin_options = array();
			if ( is_array($options) ) {
				$this->plugin_options = array_merge($this->plugin_options, $options);
			}
			//add the users info columns added to the information brought back from the Joomla Post
			add_filter('fgj2wp_get_posts_add_extra_cols', array(&$this, 'add_info_user_and_stats_to_posts'));
			//Create WP USers with the same ID as Joo Users and store the generated Password in a usermeta
			add_action('fgj2wp_pre_import', array(&$this, 'import_joo_users_in_wp'),1);
			//Imports Joo Reossitry containers and files as DM Categories and Posts We let the parent import the main categories, before importing our ones !!!
			add_action('fgj2wp_pre_import_posts', array(&$docs_manager, 'import_joo_remository_in_wp_dm'),2);
			//If I delete all, I delete the imported Joomla users ... action=all only parameter
			add_action('fgj2wp_post_empty_database', array(&$this, 'delete_joo_users_in_wp'),1,1);
			//If I delete all, I delete the imported Joomla users ... action=all only parameter
			add_action('fgj2wp_post_empty_database', array(&$this, 'delete_joo_stats_in_wp'),2,1);
			//If I delete all, I delete the imported Docs to download ... action=all only parameter
			add_action('fgj2wp_post_empty_database', array(&$docs_manager, 'suppress_all_dm_data'),3,1);
			//I only find the rsm categories the others Post will get 1 as category
			add_filter('fgj2wp_get_categories', array(&$this, 'only_rsm_categories'));
			//the next function will be executed first for the tag fgj2wp_pre_insert_post and receives 2 parameters!!!
			//http://codex.wordpress.org/Function_Reference/add_filter
			add_filter('fgj2wp_pre_insert_post', array(&$this, 'create_attachment_from_existing_images'),1,2);
			//this function will be called after associate the Joomla USer with the WP article ...
			add_filter('fgj2wp_pre_insert_post', array(&$this, 'associate_joo_user_with_article'),2,2);
			//this function will be called after to replace the rsm Joomla Gallerie with the WP shortcode ...
			add_filter('fgj2wp_pre_insert_post', array(&$this, 'replace_joo_galleries'),3,2);
			//this function will be called after to replace the All Videos Joomla Shortcode with the WP embed shortcode (core) ...
			add_filter('fgj2wp_pre_insert_post', array(&$this, 'replace_joo_videos'),4,2);
			//this function will be called after to replace the Joo Google Maps Short code  with the WP shortcode ...
			add_filter('fgj2wp_pre_insert_post', array(&$this, 'replace_joo_maps_in_posts'),5,2);
			//this function will be called after to unescape the simple and double quotes !!!!
			add_filter('fgj2wp_pre_insert_post', array(&$this, 'unescape_wp_post_content_before_insertion'),6,2);
			//this function will be called after to replace the Joo Remository Short code  with the WPDM shortcode ...
			add_filter('fgj2wp_pre_insert_post', array(&$docs_manager, 'replace_joo_quickdown_links_in_posts'),7,2);
			//after the post has been inserted we take care of linking the attachments to the parent post !!!
			add_action('fgj2wp_post_insert_post', array(&$this, 'link_attachment_to_parent_post'),1,2);
			//after the post has been inserted we take care getting the users stats from Joomla !!!
			add_action('fgj2wp_post_insert_post', array(&$this, 'add_user_stats_from_joomla'),2,2);
		}
		public function add_info_user_and_stats_to_posts(){
			return ", p.created_by, p.created_by_alias, p.hits ";
		}
		
		public function delete_joo_users_in_wp($action){
			global $wpdb;
			$result = true;
			if($action == 'all'){
				$sql_joo_users = "select user_id FROM $wpdb->usermeta where meta_key='joomlaid' order by user_id desc;";
				$joo_wp_users = $wpdb->get_results($sql_joo_users);
				if(count($joo_wp_users) > 0){
					//now we supress the user himself
					foreach ( $joo_wp_users as $joo_wp_user ) {
						$id_to_delete = $joo_wp_user->user_id;
						//We suppress all the meta datas of the previously Joomla users
						$sql_meta_query = "DELETE FROM $wpdb->usermeta where user_id = $id_to_delete";
						$result &= $wpdb->query($sql_meta_query);
						$sql_delete_user = "DELETE FROM $wpdb->users where ID= $id_to_delete";
						$result &= $wpdb->query($sql_delete_user);
					}
				}
			}
			return $result;
		}
		
		public function delete_joo_stats_in_wp($action){
			global $wpdb;
			$result = true;
			$sql_delete_userstats_count = "";
			if($action == 'all'){
				$sql_delete_userstats_count = sprintf("DELETE FROM %s",$wpdb->get_blog_prefix()."userstats_count");
					
			}else{
				$start_id = intval(get_option('fgj2wp_start_id'));
				//the same kinf of delete statement as for comments
				$sql_delete_userstats_count = $wpdb->prepare("DELETE FROM ".$wpdb->get_blog_prefix()."userstats_count WHERE post_id IN (SELECT ID FROM ".$wpdb->get_blog_prefix()."posts WHERE post_type IN ('post', 'page', 'attachment', 'revision') OR post_status = 'trash' OR post_title = 'Brouillon auto' AND ID >= %d)", $start_id);
			}
			if (!$wpdb->query($sql_delete_userstats_count)){
				$this->display_admin_error(sprintf('la requete de nettoyage des stats:%s a eu un probleme', $sql_delete_userstats_count));
			}
			return $result;
		}
		
		public function import_joo_users_in_wp(){
			global $joomla_db;
			$joo_prefix = $this->plugin_options['prefix'];
			$joo_users = array();
			$sql = "SELECT id, name, username, email, password, usertype FROM " . $joo_prefix . "users WHERE block = 0 ";
			$query = $joomla_db->query($sql);
			if ( is_object($query) ) {
				foreach ( $query as $row ) {
					$joo_users[] = array(
							'id'       => $row['id'],
							'name'     => $row['name'],
							'username' => $row['username'],
							'email'    => $row['email'],
							'password' => $row['password'],
							'usertype' => $row['usertype']
					);
				}
			}
			$this->display_admin_notice(sprintf('%d joomla users found we must insert them...', count($joo_users)));
			$indx = 0;
			foreach ( $joo_users as $joomla_user ){
				$this->imported_users[$indx]['joo_id'] = $joomla_user['id'];
				$user_id = username_exists( $joomla_user['username'] );
				if ( $user_id )
				{
					$this->imported_users[$indx]['wp_id'] = $user_id;
					$this->display_admin_notice(sprintf('WARNING:  This username  (%s) already exists - it won\'t be added !!!',$joomla_user['username']));
				}
				else
				{
					if ( email_exists($joomla_user['email']) AND !empty($joomla_user['email']) ){
						$this->display_admin_notice(sprintf('WARNING:  This users email address (%s) already exists - User (%s) can not be added !!!',$joomla_user['email'],$joomla_user['username']));
					}else{
						$random_password = wp_generate_password( 12, false );
						if ( empty($joomla_user['email']) )
							$ret = wp_create_user( $joomla_user['username'], $random_password);
						else
							$ret = wp_create_user( $joomla_user['username'], $random_password, $joomla_user['email'] );
						$this->imported_users[$indx]['wp_id'] = $ret;
						//set user meta data joomlapass for first login
						add_user_meta( $ret, 'joomlapass', $joomla_user['password'] );
						add_user_meta( $ret, 'wpgeneratedpass', $random_password );
						add_user_meta( $ret, 'joomlaid', $joomla_user['id'] );
						//http://wordpress.stackexchange.com/questions/4725/how-to-change-a-users-role
						if($joomla_user['usertype'] == 'Publisher'){
							$u = new WP_User( $ret );
							//Remove role
							$u->remove_role( 'subscriber' );
							// Add role
							$u->add_role( 'author' );
						}elseif($joomla_user['usertype'] == 'Editor'){
							$u = new WP_User( $ret );
							//Remove role
							$u->remove_role( 'subscriber' );
							//Add role
							$u->add_role( 'editor' );
						}
					}
				}
				$indx++;
			}
		}
		
		public function only_rsm_categories($tab_categories){
			$tab_filtree = array();
			foreach ($tab_categories as $cat){
				$titre = $cat["title"];
				if(preg_match ( '/^rs\-montreuil:/' ,$titre)){
					$tab_filtree[] = $cat;
				}
			}
			return $tab_filtree;
		}
		
		public function create_attachment_from_existing_images($wp_post, $joo_post){
			$this->post_media = array();
			$new_wp_post = $wp_post; //Array copy
			$post_date = $wp_post["post_date"];
			$content = $wp_post["post_content"];
			$result = $this->import_existing_media($content, $post_date);
			$this->post_media = $result['media'];
			$this->media_count += $result['media_count'];
			if (sizeof($this->post_media) > 0){
				$content = $this->process_content($content, $this->post_media);
			}
			$new_wp_post["post_content"] = $content;
			return $new_wp_post;
		}
		
		/*
		 * Before importing the posts WP Users have been created out of Joo Users
		* there are in $this->users now it is time to associate the ex Joo USer with its article
		*/
		public function associate_joo_user_with_article($wp_post, $joo_post){
			$JooWPusers= $this->imported_users;
			$new_wp_post = $wp_post; //Array copy
			$id_joo_user = $joo_post['created_by'];
			foreach ($JooWPusers as $JooWPuser){
				//Find the Joo_User of $joo_post and associate its corresponding wp_user_id with $new_wp_post
				if($JooWPuser['joo_id'] == $id_joo_user){
					$id_wp_user = $JooWPuser['wp_id'];
					$new_wp_post['post_author'] = $id_wp_user;
					break;
				}
			}
			return $new_wp_post;
		}
		
		/*
		 * pre-processing the content
		* replaces all shortcodes {gallery width=100}stories/randosport/MesnieresAvril2014{/gallery}
		* with [JooGallery path='stories/randosport/MesnieresAvril2014']
		*/
		public function replace_joo_galleries($wp_post, $joo_post){
			$new_wp_post = $wp_post; //Array copy
			$content = $wp_post["post_content"];
			$pattern_joo_gallery = "/{gallery([^}]*)?}([a-zA-Z0-9\/\-]+){\/gallery}/mi"; //m for multiple lines
			$content = preg_replace_callback($pattern_joo_gallery, array($this, 'replace_one_joo_gallery'), $content);
			$new_wp_post["post_content"] = $content;
			return $new_wp_post;
		}
		
		/*
		 * pre-processing the content
		* replaces all shortcodes {youtube}yZrR1z6GoH4{/youtube}
		* with [embed width="600px"]http://www.youtube.com/watch?v=yZrR1z6GoH4[/embed]
		* The same for all other videos types !!!!
		*/
		public function replace_joo_videos($wp_post, $joo_post){
			$new_wp_post = $wp_post; //Array copy
			$content = $wp_post["post_content"];
			$pattern_joo_allvideo = "/{([^}]*)}([a-zA-Z0-9\/\-\_]+){\/([^}]*)}/mi"; //m for multiple lines
			$content = preg_replace_callback($pattern_joo_allvideo, array($this, 'replace_one_joo_allvideo'), $content);
			$new_wp_post["post_content"] = $content;
			return $new_wp_post;
		}
			
		private function replace_one_joo_gallery($found_joo_gallery_pattern){
			if(sizeof($found_joo_gallery_pattern) > 2){
				return "[JooGallery path='".$found_joo_gallery_pattern[2]."']";
			}else{ //we do nothing we return as is
				return $found_joo_gallery_pattern[0];
			}
		}
		
		private function replace_one_joo_allvideo($found_joo_allvideo_pattern){
			if(sizeof($found_joo_allvideo_pattern) > 3 && $found_joo_allvideo_pattern[1] == $found_joo_allvideo_pattern[3]){
				$video_type = $found_joo_allvideo_pattern[1];
				if($video_type != "gallery"){//We are not in an image Gallery
					$videoId = $found_joo_allvideo_pattern[2];
					$this->display_admin_notice(sprintf('trouve une video de type %s et de Id: %s', $video_type, $videoId));
					if($video_type == "youtube"){
						return "[embed width=\"600px\"]http://www.youtube.com/watch?v=".$videoId."[/embed]";
					}elseif ($video_type == "dailymotion"){
						return "[embed width=\"600px\"]http://www.dailymotion.com/video/".$videoId."[/embed]";
					}elseif ($video_type == "vimeo"){
						return "[embed width=\"600px\"]http://vimeo.com/".$videoId."[/embed]";
					}else{
						$this->display_admin_error(sprintf('trouve une video de type %s et de Id: %s non pris en charge', $video_type, $found_joo_allvideo_pattern[2]));
						return $found_joo_allvideo_pattern[0];
					}
					return "[AllVideoId='".$found_joo_allvideo_pattern[2]."']";
				}
			}else{ //we do nothing we return as is
				return $found_joo_allvideo_pattern[0];
			}
		}
		
		private function import_existing_media($content, $post_date) {
			$media = array();
			$media_count = 0;
			$pattern = '#<(img|a)(.*?)(src|href)='.self::REGEXPQUOTES.'('.self::NOT_REGEXPQUOTES.')'.self::REGEXPQUOTES.'(.*?)>#';
			if ( preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) > 0 ) {
				if ( is_array($matches) ) {
					foreach ($matches as $match ) {
						$filename = $match[4];
						$filename = str_replace("%20", " ", $filename); // for filenames with spaces
						$other_attributes = $match[2] . $match[5];
							
						$filetype = wp_check_filetype($filename);
						if ( empty($filetype['type']) || ($filetype['type'] == 'text/html') ) { // Unrecognized file type
							continue;
						}
							
						$new_filename=$filename;;
						$new_full_filename=$this->joo_images_directory."/".basename($new_filename);
						$existing_Joo_image = false;
						$post_name = "none";
						// Upload the file from the Joomla web site to WordPress upload dir
						if(preg_match('/^images\//', $filename)){//it is perhaps an image I got from the Joomla Image Filesystem
							$new_filename = preg_replace('#.*images/#', '', $new_filename);
							if (file_exists ( $this->joo_images_directory."/". $new_filename)){
								$post_name = str_replace('/', '_', $new_filename);
								$post_name = preg_replace('/\.[^.]+$/', '', $post_name);
								$new_full_filename=$this->joo_images_directory."/".$new_filename;
								$existing_Joo_image = true;
							}
						}
						if (!$existing_Joo_image){
							if (preg_match('/^http/', $filename) ) {
								if ( preg_match('#^' . $this->plugin_options['url'] . '#', $filename) // Local file
										|| ($this->plugin_options['import_external'] == 1) ) { // External file
									$old_filename = $filename;
								} else {
									continue;
								}
							} else {
								$old_filename = untrailingslashit($this->plugin_options['url']) . '/' . $filename;
							}
							$old_filename = str_replace(" ", "%20", $old_filename); // for filenames with spaces
							$date = strftime('%Y/%m', strtotime($post_date));
							$uploads = wp_upload_dir($date);
							$new_upload_dir = $uploads['path'];
		
							$new_filename = $filename;
							if ( $this->plugin_options['import_duplicates'] == 1 ) {
								// Images with duplicate names
								$new_filename = preg_replace('#.*images/stories/#', '', $new_filename);
								$new_filename = preg_replace('#.*media/k2#', 'k2', $new_filename);
								$new_filename = str_replace('http://', '', $new_filename);
								$new_filename = str_replace('/', '_', $new_filename);
							}
		
							$new_full_filename = $new_upload_dir . '/' . basename($new_filename);
		
							// print "Copy \"$old_filename\" => $new_full_filename<br />";
							if ( ! @$this->remote_copy_forrsm($old_filename, $new_full_filename) ) {
								$error = error_get_last();
								$error_message = $error['message'];
								$this->display_admin_error("Can't copy $old_filename to $new_full_filename : $error_message");
								continue;
							}
							$post_name = preg_replace('/\.[^.]+$/', '', basename($new_filename));
						}
							
							
							
						// If the attachment does not exist yet, insert it in the database
						$attachment = $this->get_attachment_from_name_forrsm($post_name);
						if ( !$attachment ) {
							$attachment_data = array(
									'post_date'			=> $post_date,
									'post_mime_type'	=> $filetype['type'],
									'post_name'			=> $post_name,
									'post_title'		=> $post_name,
									'post_status'		=> 'inherit',
									'post_content'		=> '',
							);
							$attach_id = wp_insert_attachment($attachment_data, $new_full_filename);
							$attachment = get_post($attach_id);
							$post_name = $attachment->post_name; // Get the real post name
							$media_count++;
						}
						$attach_id = $attachment->ID;
							
						$media[$filename] = array(
								'id'	=> $attach_id,
								'name'	=> $post_name,
						);
							
						if ( preg_match('/image/', $filetype['type']) ) { // Images
							// you must first include the image.php file
							// for the function wp_generate_attachment_metadata() to work
							require_once(ABSPATH . 'wp-admin/includes/image.php');
							$attach_data = wp_generate_attachment_metadata( $attach_id, $new_full_filename );
							wp_update_attachment_metadata( $attach_id, $attach_data );
								
							// Image Alt
							if (preg_match('#alt="(.*?)"#', $other_attributes, $alt_matches) ) {
								$image_alt = wp_strip_all_tags(stripslashes($alt_matches[1]), true);
								update_post_meta($attach_id, '_wp_attachment_image_alt', addslashes($image_alt)); // update_meta expects slashed
							}
						}
					}
				}
			}
			return array(
					'media'			=> $media,
					'media_count'	=> $media_count
			);
		}
		/**
		 * Process the post content
		 * a dû être recopiée ici  car appelle une fonction privée que je suis obligé de redéfinir ci dessous
		 * @param string $content Post content
		 * @param array $post_media Post medias
		 * @return string Processed post content
		 */
		public function process_content($content, $post_media) {
				
			if ( !empty($content) ) {
				$content = str_replace(array("\r", "\n"), array('', ' '), $content);
		
				// Replace page breaks
				$content = preg_replace("#<hr([^>]*?)class=\"system-pagebreak\"(.*?)/>#", "<!--nextpage-->", $content);
		
				// Replace media URLs with the new URLs
				$content = $this->process_content_media_links_redfini_rsm($content, $post_media);
		
				// For importing backslashes
				$content = addslashes($content);
			}
		
			return $content;
		}
		/**
		 * Check if the attachment exists in the database
		 * obligé de la copier icci ccar elle est définie comme privée dans la classe mère !!!
		 * @param string $name
		 * @return object Post
		 */
		protected function get_attachment_from_name($name) {
			$name = preg_replace('/\.[^.]+$/', '', basename($name));
			$r = array(
					'name'			=> $name,
					'post_type'		=> 'attachment',
					'numberposts'	=> 1,
			);
			$posts_array = get_posts($r);
			if ( is_array($posts_array) && (count($posts_array) > 0) ) {
				return $posts_array[0];
			}
			else {
				return false;
			}
		}
		/**
		 * Replace media URLs with the new URLs
		 * Je suis obligé de recopier cette fonction ici car l'auteur n'a pas pris en compte le contenu échappé !!!
		 * 
		 * @param string $content
		 *        	Post content
		 * @param array $post_media
		 *        	Post medias
		 * @return string Processed post content
		 */
		protected function process_content_media_links_redfini_rsm($content, $post_media) {
			$matches = array ();
			$matches_caption = array ();
			
			if (is_array ( $post_media )) {
				
				// Get the attachments attributes
				$attachments_found = false;
				foreach ( $post_media as $old_filename => &$media_var ) {
					$post_media_name = $media_var ['name'];
					$attachment = $this->get_attachment_from_name ( $post_media_name ); //TODO fonction à redéfinir ici aussi car privée ailleurs !!!!
					if ($attachment) {
						$media_var ['attachment_id'] = $attachment->ID;
						$media_var ['old_filename_without_spaces'] = str_replace ( " ", "%20", $old_filename ); // for filenames with spaces
						if (preg_match ( '/image/', $attachment->post_mime_type )) {
							// Image
							$image_src = wp_get_attachment_image_src ( $attachment->ID, 'full' );
							$media_var ['new_url'] = $image_src [0];
							$media_var ['width'] = $image_src [1];
							$media_var ['height'] = $image_src [2];
						} else {
							// Other media
							$media_var ['new_url'] = wp_get_attachment_url ( $attachment->ID );
						}
						$attachments_found = true;
					}
				}
				if ($attachments_found) {
					
					// Remove the links from the content
					$this->post_link_count = 0;
					$this->post_link = array ();
					$content = preg_replace_callback ( '#<(a) (.*?)(href)=(.*?)</a>#i', array (
							$this,
							'remove_links' 
					), $content );
					$content = preg_replace_callback ( '#<(img) (.*?)(src)=(.*?)>#i', array (
							$this,
							'remove_links' 
					), $content );
					
					// Process the stored medias links
					$first_image_removed = false;
					foreach ( $this->post_link as &$link ) {
						
						// Remove the first image from the content
						if (($this->plugin_options ['first_image'] == 'as_featured') && ! $first_image_removed && preg_match ( '#^<img#', $link ['old_link'] )) {
							$link ['new_link'] = '';
							$first_image_removed = true;
							continue;
						}
						$new_link = $link ['old_link'];
						$alignment = '';
						if (preg_match ( '/(align=' . self::REGEXPQUOTES . '|float: )(left|right)/', $new_link, $matches )) {
							$alignment = 'align' . $matches [2];
						}
						if (preg_match_all ( '#(src|href)=' . self::REGEXPQUOTES . '(.*?)' . self::REGEXPQUOTES . '#i', $new_link, $matches, PREG_SET_ORDER )) {
							$caption = '';
							foreach ( $matches as $match ) {
								$old_filename = str_replace ( '%20', ' ', $match [2] ); // For filenames with %20
								$link_type = ($match [1] == 'src') ? 'img' : 'a';
								if (array_key_exists ( $old_filename, $post_media )) {
									$media = $post_media [$old_filename];
									if (array_key_exists ( 'new_url', $media )) {
										if ((strpos ( $new_link, $old_filename ) > 0) || (strpos ( $new_link, $media ['old_filename_without_spaces'] ) > 0)) {
											$new_link = preg_replace ( '#(' . $old_filename . '|' . $media ['old_filename_without_spaces'] . ')#', $media ['new_url'], $new_link, 1 );
											
											if ($link_type == 'img') { // images only
											                             // Define the width and the height of the image if it isn't defined yet
												if ((strpos ( $new_link, 'width=' ) === false) && (strpos ( $new_link, 'height=' ) === false)) {
													$width_assertion = isset ( $media ['width'] ) ? ' width="' . $media ['width'] . '"' : '';
													$height_assertion = isset ( $media ['height'] ) ? ' height="' . $media ['height'] . '"' : '';
												} else {
													$width_assertion = '';
													$height_assertion = '';
												}
												
												// Caption shortcode
												if (preg_match ( '/class=' . self::REGEXPQUOTES . '.*caption.*?' . self::REGEXPQUOTES . '/', $link ['old_link'] )) {
													if (preg_match ( '/title="(.*?)"/', $link ['old_link'], $matches_caption )) {
														$caption_value = str_replace ( '%', '%%', $matches_caption [1] );
														$align_value = ($alignment != '') ? $alignment : 'alignnone';
														$caption = '[caption id="attachment_' . $media ['attachment_id'] . '" align="' . $align_value . '"' . $width_assertion . ']%s' . $caption_value . '[/caption]';
													}
												}
												
												$align_class = ($alignment != '') ? $alignment . ' ' : '';
												$new_link = preg_replace ( '#<img(.*?)( class=' . self::REGEXPQUOTES . '(.*?)' . self::REGEXPQUOTES . ')?(.*) />#', "<img$1 class=\"$3 " . $align_class . 'size-full wp-image-' . $media ['attachment_id'] . "\"$4" . $width_assertion . $height_assertion . ' />', $new_link );
											}
										}
									}
								}
							}
							
							// Add the caption
							if ($caption != '') {
								$new_link = sprintf ( $caption, $new_link );
							}
						}
						$link ['new_link'] = $new_link;
					}
					
					// Reinsert the converted medias links
					$content = preg_replace_callback ( '#__fg_link_(\d+)__#', array (
							$this,
							'restore_links' 
					), $content );
				}
			}
			return $content;
		}
		
		/**
		 * Remove all the links from the content and replace them with a specific tag
		 * I want to update the post_tile wih the alt!!!!
		 * @param array $matches Result of the preg_match
		 * @return string Replacement
		 */
		private function remove_links($matches) {
			$joo_link_or_image = $matches[0];
			$joo_link_pattern = "/<img(.*)src=".self::REGEXPQUOTES."(".self::NOT_REGEXPQUOTES.")".self::REGEXPQUOTES."(.*)(alt=".self::REGEXPQUOTES."(".self::NOT_REGEXPQUOTES.")".self::REGEXPQUOTES.")(.*)(title=".self::REGEXPQUOTES."(".self::NOT_REGEXPQUOTES.")".self::REGEXPQUOTES.")(.*)\/>/i";
			if(preg_match($joo_link_pattern,$matches[0],$joolink_matches)){
				$nb_joolinks_matches = sizeof($joolink_matches);
				if ($nb_joolinks_matches > 8){ //we have a title and an alt
					$this->post_link[] = array('old_link' => $joo_link_or_image,
							'old_title' => $joolink_matches[8],
							'old_alt' => $joolink_matches[5]);
				}elseif ($nb_joolinks_matches > 5) { //we only have an alt
					$this->post_link[] = array('old_link' => $joo_link_or_image,
							'old_alt' => $joolink_matches[5]);
				}else{
					$this->post_link[] = array('old_link' => $joo_link_or_image);
				}
			}else{ //We have title alone
				$joo_link_pattern = "/<img(.*)src=".self::REGEXPQUOTES."(".self::NOT_REGEXPQUOTES.")".self::REGEXPQUOTES."(.*)(title=".self::REGEXPQUOTES."(".self::NOT_REGEXPQUOTES.")".self::REGEXPQUOTES.")(.*)\/>/i";
				if(preg_match($joo_link_pattern,$matches[0],$joolink_matches)){
					$nb_joolinks_matches = sizeof($joolink_matches);
					if ($nb_joolinks_matches > 5){ //we have a title and an alt
						$this->post_link[] = array('old_link' => $joo_link_or_image,
								'old_title' => $joolink_matches[5],
								'old_alt' => $joolink_matches[5]);
					}else{
						$this->post_link[] = array('old_link' => $joo_link_or_image);
					}
				}else{ //no title, no alt !!!
					$this->post_link[] = array('old_link' => $joo_link_or_image);
				}
			}
			return '__fg_link_' . $this->post_link_count++ . '__';
		}
		/**
		 * Restore the links in the content and replace them with the new calculated link
		 *
		 * @param array $matches Result of the preg_match
		 * @return string Replacement the link with an a href around id except when an icon!!!
		 */
		private function restore_links($matches) {
			$link = $this->post_link[$matches[1]];
			$new_link = array_key_exists('new_link', $link)? $link['new_link'] : $link['old_link'];
			$joo_img_pattern = "/<img(.*?)src=(".self::REGEXPQUOTES.")([a-zA-Z0-9\-\_\:\/\ ]+).(bmp|gif|jpeg|jpg|png)(".self::REGEXPQUOTES.")(.*?)>/i";
			$image_joolink = $link["old_link"];
			$image_joolink_decoded=urldecode($image_joolink);
			if(preg_match($joo_img_pattern,$image_joolink_decoded,$joolink_matches)){
				$joo_image_path = $joolink_matches[3].".".$joolink_matches[4];
				$wp_link = preg_replace("/http:\/\/[^\/]+/", "[url]" , $new_link);//we replace the base ur with the [url] shortcode
				foreach ( $this->post_media as $old_filename => $media ) {
					if($old_filename == $joo_image_path){
						$post_media_name = $media['name'];
						$attachment = $this->get_attachment_from_name_forrsm($post_media_name);
						$update_media = false;
						if($link['old_title'] && sizeof($link['old_title']) > 0){
							$attachment->post_title = $link['old_title'];
							$update_media = true;
						}
						if($link['old_alt'] && sizeof($link['old_alt']) > 0){
							$attachment->post_excerpt = $link['old_alt'];
							$update_media = true;
						}
						if ($update_media){
							wp_update_post( $attachment );
						}
						//http://codex.wordpress.org/Function_Reference/get_post_meta
						$meta_values = get_post_meta($attachment->ID, "_wp_attachment_metadata", false );
						$wp_link_pattern = '/<img(.*?)class=('.self::REGEXPQUOTES.')('.self::NOT_REGEXPQUOTES.')('.self::REGEXPQUOTES.') src=('.self::REGEXPQUOTES.')('.self::NOT_REGEXPQUOTES.').(bmp|gif|jpeg|jpg|png)('.self::REGEXPQUOTES.')(.*?)>/i';
						$is_icon=false;
						if(preg_match($wp_link_pattern,$wp_link,$wp_link_matches)){
							$wp_full_img_link = $wp_link_matches[6].".".$wp_link_matches[7];
							foreach ($this->tab_path_icons as $icon_path){
								if(stristr($wp_full_img_link,$icon_path)){
									$is_icon=true;
									break;
								}
							}
						}
						if ($meta_values[0]["sizes"][$this->image_size_in_post] != null){
							$thumb_metas = $meta_values[0]["sizes"][$this->image_size_in_post];
							$new_class = preg_replace ( "/size\-[a-z]+/" , "size-".$this->image_size_in_post,$wp_link_matches[3]);
							if(!$is_icon){
								$new_class = $new_class." noticon";
							}
							$wp_thumb_img_link = preg_replace ( "/[^\/]+$/" , $meta_values[0]["sizes"]["medium"]["file"] ,$wp_full_img_link);
							$wp_image = "<img class=\"".$new_class."\" src=\"".$wp_thumb_img_link."\" alt=\"".$attachment->post_excerpt."\" title=\"".$attachment->post_title."\"";
							$wp_image .= " width=\"".$thumb_metas["width"]."\" height = \"".$thumb_metas["height"]."\" />";
							if(!$is_icon){//only if it is not an icon !!! (the Icon are used for links!!!)
								$new_link = "<a href=\"".$wp_full_img_link."\">".$wp_image."</a>";
							} else {//If I am an icon I do nothing I directly return $new Link with a small change
								$new_link = str_replace($this->site_base_url, "[url]", $new_link);
							}
						}else if ($is_icon){
							$new_link = str_replace(self::REGEXPQUOTES, '"', $wp_link);;
						}else{
							$new_link = str_replace($this->site_base_url, "[url]", $new_link);
						}
						break;
					}
				}
			}
			return $new_link;
		}
		/**
			 * Copied as is from fg-joomla-to-wordpress because
			 * defined as private there
			 * Copy a remote file
			 * in replacement of the copy function
			 * we  canot give the same name beaucause the other one is private
			 * @param string $url URL of the source file
			 * @param string $path destination file
			 * @return boolean
			 */
		private function remote_copy_forrsm($url, $path) {
				
			/*
			 * cwg enhancement: if destination already exists, just return true
			*  this allows rebuilding the wp media db without moving files
			*/
			if ( !$this->plugin_options['force_media_import'] && file_exists($path) && (filesize($path) > 0) ) {
				return true;
			}
				
			$response = wp_remote_get($url); // Uses WordPress HTTP API
				
			if ( is_wp_error($response) ) {
				trigger_error($response->get_error_message(), E_USER_WARNING);
				return false;
			} elseif ( $response['response']['code'] != 200 ) {
				trigger_error($response['response']['message'], E_USER_WARNING);
				return false;
			} else {
				file_put_contents($path, wp_remote_retrieve_body($response));
				return true;
			}
		}
		
		/**
		 * Check if the attachment exists in the database
		 * copied as is from the original plugin because defined there as private !!!
		 * the name has been changed because the original is private
		 * @param string $name
		 * @return object Post
		 */
		private function get_attachment_from_name_forrsm($name) {
			$name = preg_replace('/\.[^.]+$/', '', basename($name));
			$r = array(
					'name'			=> $name,
					'post_type'		=> 'attachment',
					'numberposts'	=> 1,
			);
			$posts_array = get_posts($r);
			if ( is_array($posts_array) && (count($posts_array) > 0) ) {
				return $posts_array[0];
			}
			else {
				return false;
			}
		}
		/*It is an action called after the post has been inserted ! It is called here
		 * again because in the main function there is no media to import
		* $new_post_id is the ID of the attachment
		* $post, is the post with the text, the parent post!!!
		*/
		public function link_attachment_to_parent_post($new_post_id, $post){
			$the_post_parent = get_post($new_post_id,ARRAY_A);
			if ( $new_post_id > 0 && $the_post_parent["post_parent"] == 0 && $the_post_parent["post_type"] == 'post' && sizeof($this->post_media) > 0) {
				// Add links between the post and its medias
				//$this->add_post_media($new_post_id, $the_post_parent, $this->post_media,false);
				/*
				 * I decide to habe systematically a Post Thumb image
				 */
				$this->add_post_media($new_post_id, $the_post_parent, $this->post_media,true);
				$this->post_media = array();
			}
		}
		/*It is an action called after the post has been inserted ! It is called here
		 * again because we want to get the Joomla clics stats in our WP Blog
		* We have previously activated the http://wordpress.org/plugins/user-stats/
		*/
		public function add_user_stats_from_joomla($new_post_id, $post){
			global $wpdb;
			$the_post_I_want_to_add_stats_to = get_post($new_post_id,ARRAY_A);
			$joo_post = $post;
			$nbe_clicks = $post["hits"];
			//http://codex.wordpress.org/Function_Reference/add_post_meta
			if (!add_post_meta($new_post_id, '_statz_count', $nbe_clicks, true)){
				$this->display_admin_error(sprintf('la metadonnee _statz_count pour le post de id: %d a pete correctement creee', $new_post_id));
			}
			//http://codex.wordpress.org/Class_Reference/wpdb && http://stackoverflow.com/questions/8566603/wordpress-wpdb-insert-mysql-now
			$userstats_request = $wpdb->prepare("INSERT INTO ".$wpdb->get_blog_prefix()."userstats_count (`date`,`post_id`,`count`) VALUES ('%s',%d,%d)", current_time('mysql', 1), $new_post_id, $nbe_clicks);
			if (!$wpdb->query($userstats_request)){
				$this->display_admin_error(sprintf('pour le post de id: %d la table %s a pas  pu etre mise a jour', $new_post_id, $wpdb->get_blog_prefix().'userstats_count'));
			}
			return 0;
		}
		/*It is a filter tor replace the Joomla Shrortcode for Google Maps in the
		 * WP shortcode for the http://wordpress.org/plugins/wp-flexible-map/ it the post content
		* It is called before we import the Category
		*/
		public function replace_joo_maps_in_posts($wp_post, $joo_post){
			$new_wp_post = $wp_post; //Array copy
			$content = $wp_post["post_content"];
			$pattern_joo_google_maps = '/{mosmap lat='.self::REGEXPSIMPLEQUOTES.'([0-9\.]+)'.self::REGEXPSIMPLEQUOTES.'\|lon='.self::REGEXPSIMPLEQUOTES.'([0-9\.]+)'.self::REGEXPSIMPLEQUOTES.'\|([^}]*)}/i';
			$content = preg_replace_callback($pattern_joo_google_maps, array($this, 'replace_one_joo_map_in_post'), $content);
			$new_wp_post["post_content"] = $content;
			return $new_wp_post;
		}
		protected function replace_one_joo_map_in_post($found_joo_googlemap_pattern){
			if(sizeof($found_joo_googlemap_pattern) > 3){
				$latitude = $found_joo_googlemap_pattern[1];
				$longitude = $found_joo_googlemap_pattern[2];
				$translated_map = "[flexiblemap center=\"".$latitude.",".$longitude."\"";
				$other_attributes_joostr = $found_joo_googlemap_pattern[3];
				$other_attributes = array("width"=>"100%", "height"=>"400px","zoom"=>9,"title"=>"Rendez-vous", "description"=>"---", "link"=>null);
				$pattern = '/lbxwidth='.self::REGEXPSIMPLEQUOTES.'([0-9]+px)'.self::REGEXPSIMPLEQUOTES.'/';
				$matches = array();
				if (preg_match($pattern, $other_attributes_joostr, $matches, PREG_OFFSET_CAPTURE)){
					$other_attributes["width"] = $matches[1][0];
				}
				$pattern = '/lbxheight='.self::REGEXPSIMPLEQUOTES.'([0-9]+px)'.self::REGEXPSIMPLEQUOTES.'/';
				if (preg_match($pattern, $other_attributes_joostr, $matches, PREG_OFFSET_CAPTURE)){
					$other_attributes["height"] = $matches[1][0];
				}
				$pattern = '/zoom='.self::REGEXPSIMPLEQUOTES.'([0-9]+)'.self::REGEXPSIMPLEQUOTES.'/';
				if (preg_match($pattern, $other_attributes_joostr, $matches, PREG_OFFSET_CAPTURE)){
					$other_attributes["zoom"] = $matches[1][0];
				}
				$pattern = '/text='.self::REGEXPSIMPLEQUOTES.'('.self::NOT_REGEXPSIMPLEQUOTES.')'.self::REGEXPSIMPLEQUOTES.'/';
				if (preg_match($pattern, $other_attributes_joostr, $matches, PREG_OFFSET_CAPTURE)){
					$this->translate_description_from_joo_to_wp($matches[1][0],$other_attributes);
				}
				foreach ($other_attributes as $the_wp_attribute=>$its_wp_value){
					$translated_map .= " ".$the_wp_attribute."=\"".$its_wp_value."\"";
				}
				$translated_map .= "]";
				return $translated_map;
			}else{ //we do nothing we return as is
				return $found_joo_googlemap_pattern[0];
			}
		}
		protected function translate_description_from_joo_to_wp($joo_description, &$attributes_for_wordpress){
			$pattern_link_more_info = '/<a href='.self::REGEXPQUOTES.'('.self::NOT_REGEXPQUOTES.')'.self::REGEXPQUOTES.'[^>]+>[^<]+<\/a>/mi';
			$description_without_tags = null;
			//http://php.net/manual/en/function.preg-replace.php see examples at the end
			$description_without_link = preg_replace ( $pattern_link_more_info , "" , $joo_description);
			if($description_without_link != null && count($description_without_link) > 0){
				$description_without_opening_tags = preg_replace ( "/<[a-z]+[^>]*>/im" , "" , $description_without_link);
				if($description_without_opening_tags != null && count($description_without_opening_tags) > 0){
					$description_without_tags = preg_replace ( "/<\/[a-z]+>/i" , "" , $description_without_opening_tags);
					if($description_without_tags != null && count($description_without_tags) > 0){
						$attributes_for_wordpress["description"] = $description_without_tags;
					}
				}
			}
			$found_links = array();
			if (preg_match($pattern_link_more_info, $joo_description, $found_links, PREG_OFFSET_CAPTURE) && count($found_links) > 1){
				$attributes_for_wordpress["link"] = $found_links[1][0];
			}
		}
		
		/*It is a filter tor unescape all the quotes and double quotes
		 * last thing to do before real insertion !!!!
		 */
		public function unescape_wp_post_content_before_insertion($wp_post, $joo_post){
			$new_wp_post = $wp_post; //Array copy
			$content = $wp_post["post_content"];
			$array_escaped_quotes = array('/('.self::REGEXPBACKSLASHES.')/');
			$array_unescaped_quotes = array("");
			$unescaped_content = preg_replace($array_escaped_quotes, $array_unescaped_quotes, $content);
			$new_wp_post["post_content"] = $unescaped_content;
			return $new_wp_post;
		}
	}
	
		
	if ( !function_exists( 'joorsmwp_load' ) ) {
		add_action( 'plugins_loaded', 'joorsmwp_load', 30 );
	
		function joorsmwp_load() {
			$joo_rsm_to_wp = new JooRsm();
		}
	}
}