<?php
/**
 * Plugin Name: Mon extension pour le RSM
 * Plugin Uri:  http://test.rsmontreuil.fr
 * Description: l'extension du fg-joomla-to-wordpress pour gérer finement
 * l'importation des données Joomla du RSM
 * Version:     0.1
 * Author:      Jean-Pierre MENA
 *  */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

if ( class_exists('fgj2wp', false) ) {
	
	if ( !class_exists('JooRsm', false) ) {
		class JooRsm extends fgj2wp {
			private $joo_images_directory;
			private $post_media = array(); //for each post the array of attachment posts of type images
			private $media_count = 0; //the total number of images imported !!!
			private $image_size_in_post;
			public function __construct(){
				$this->image_size_in_post = "medium"; //I want all the image in posts to be medium size !!!!
				//ABSPATH contains already a / 
				$this->joo_images_directory = ABSPATH . 'wp-content/uploads/images';
				$options = get_option('fgj2wp_options');
				$this->plugin_options = array();
				if ( is_array($options) ) {
					$this->plugin_options = array_merge($this->plugin_options, $options);
				}
				add_filter('fgj2wp_get_categories', array(&$this, 'only_rsm_categories'));
				//the next function will be executed first for the tag fgj2wp_pre_insert_post and receives 2 parameters!!!
				//http://codex.wordpress.org/Function_Reference/add_filter
				add_filter('fgj2wp_pre_insert_post', array(&$this, 'create_attachment_from_existing_images'),1,2);
				//after the post has been inserted we take care of linking the attachments to the parent post !!!
				add_action('fgj2wp_post_insert_post', array(&$this, 'link_attachment_to_parent_post'),1,2);
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
			
			private function import_existing_media($content, $post_date) {
				$media = array();
				$media_count = 0;
					
				if ( preg_match_all('#<(img|a)(.*?)(src|href)="(.*?)"(.*?)>#', $content, $matches, PREG_SET_ORDER) > 0 ) {
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
								if ( ! @$this->remote_copy($old_filename, $new_full_filename) ) {
									$error = error_get_last();
									$error_message = $error['message'];
									$this->display_admin_error("Can't copy $old_filename to $new_full_filename : $error_message");
									continue;
								}
								$post_name = preg_replace('/\.[^.]+$/', '', basename($new_filename));
							}
			
							
			
							// If the attachment does not exist yet, insert it in the database
							$attachment = $this->get_attachment_from_name($post_name);
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
			 * Remove all the links from the content and replace them with a specific tag
			 * I want to update the post_tile wih the alt!!!!
			 * @param array $matches Result of the preg_match
			 * @return string Replacement
			 */
			private function remove_links($matches) {
				$joo_link_or_image = $matches[0];
				$joo_link_pattern = "/<img(.*)src=\"([^\"]*)\"(.*)(alt=\"([^\"]*)\")(.*)(title=\"([^\"]*)\")(.*)\/>/i";
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
					$joo_link_pattern = "/<img(.*)src=\"([^\"]*)\"(.*)(title=\"([^\"]*)\")(.*)\/>/i";
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
				$pattern = "/<img(.*?)src=('|\")(.*?).(bmp|gif|jpeg|jpg|png)('|\")(.*?)>/i";
				$image_joolink = $link["old_link"];
				if(preg_match($pattern,$image_joolink,$joolink_matches)){
					$joo_image_path = $joolink_matches[3].".".$joolink_matches[4];
					$wp_link = preg_replace("/http:\/\/[^\/]+/", "[url]" , $new_link);//we replace the base ur with the [url] shortcode
					foreach ( $this->post_media as $old_filename => $media ) {
						if($old_filename == $joo_image_path){
							$post_media_name = $media['name'];
							$attachment = $this->get_attachment_from_name($post_media_name);
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
							$thumb_metas = $meta_values[0]["sizes"][$this->image_size_in_post];
							$wp_link_pattern = "/<img(.*?)class=('|\")(.*?)('|\") src=('|\")(.*?).(bmp|gif|jpeg|jpg|png)('|\")(.*?)>/i";
							if(preg_match($wp_link_pattern,$wp_link,$wp_link_matches)){
								$wp_full_img_link = $wp_link_matches[6].".".$wp_link_matches[7];
								if($thumb_metas["file"] != null && sizeof($thumb_metas["file"]) > 0 && $thumb_metas["file"] !=  $wp_full_img_link){//only if it is not an icon !!! (the Icon are used for links!!!)
									$new_class = preg_replace ( "/size\-[a-z]+/" , "size-".$this->image_size_in_post,$wp_link_matches[3]);
									$wp_thumb_img_link = preg_replace ( "/[^\/]+$/" , $meta_values[0]["sizes"]["medium"]["file"] ,$wp_full_img_link);
									$wp_image = "<img class=\"".$new_class."\" src=\"".$wp_thumb_img_link."\" alt=\"".$attachment->post_excerpt."\" title=\"".$attachment->post_title."\"";
									$wp_image .= " width=\"".$thumb_metas["width"]."\" height = \"".$thumb_metas["height"]."\" />";
									$new_link = "<a href=\"".$wp_full_img_link."\">".$wp_image."</a>";
								}
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
			 *
			 * @param string $url URL of the source file
			 * @param string $path destination file
			 * @return boolean
			 */
			private function remote_copy($url, $path) {
					
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
			 * @param string $name
			 * @return object Post
			 */
			private function get_attachment_from_name($name) {
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
					$this->add_post_media($new_post_id, $the_post_parent, $this->post_media,false);
					$this->post_media = array();
				}
			}
		}
	}
}
if ( !function_exists( 'joorsmwp_load' ) ) {
	add_action( 'plugins_loaded', 'joorsmwp_load', 20 );

	function joorsmwp_load() {
		$joo_rsm_to_wp = new JooRsm();
	}
}
?>
