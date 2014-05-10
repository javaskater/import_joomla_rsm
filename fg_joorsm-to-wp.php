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
			public function __construct(){
				$this->joo_images_directory = ABSPATH . '/wp-content/joo_images';
				add_filter('fgj2wp_get_categories', array(&$this, 'only_rsm_categories'));
				//the next function will be executed first for the tag fgj2wp_pre_insert_post and receives 2 parameters!!!
				//http://codex.wordpress.org/Function_Reference/add_filter
				add_filter('fgj2wp_pre_insert_post', array(&$this, 'create_attachment_from_existing_images'),1,2);
				//add_action('fgj2wp_post_insert_post', array(&$this, 'supprimer_post_non_montreuil'));
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
				$new_wp_post = $wp_post; //Array copy
				$post_date = $wp_post["post_date"];
				$content = $wp_post["post_content"];
				$result = $this->import_existing_media($content, $post_date);
				$post_media = $result['media'];
				$media_count += $result['media_count'];
				$content = $this->process_content($content, $post_media);
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
