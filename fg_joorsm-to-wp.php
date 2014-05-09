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
			public function __construct(){
				add_filter('fgj2wp_get_categories', array(&$this, 'gerer_categories'));
				//add_action('fgj2wp_post_insert_post', array(&$this, 'supprimer_post_non_montreuil'));
			}
			function gerer_categories($tab_categories){
				$tab_filtree = array();
				foreach ($tab_categories as $cat){
					$titre = $cat["title"];
					if(preg_match ( '/^rs\-montreuil:/' ,$titre)){
						$tab_filtree[] = $cat;
					}
				}
				return $tab_filtree;
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
