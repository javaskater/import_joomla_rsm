<?php
class RsmImportMenu {
	private static $initiated = false;
	
	public static function init() {
		if ( ! self::$initiated ) {
			self::init_hooks();
		}
	}
	/**
	 * Initializes WordPress hooks see Askimet Class
	 */
	private static function init_hooks() {
		self::$initiated = true;
		add_action( 'admin_menu', array('RsmImportMenu', 'fgj2wp_admin_menu'));
	}

	
	public static function fgj2wp_admin_menu(){
		// Create top-level menu item
		add_menu_page( 'Gestion de l\'import RSM',
		'Import RSM', get_option('wpdm_access_level','manage_options'),
		'importrsm_main_menu', array('RsmImportMenu','visualize_imported_users'),
		plugins_url( 'images/rsm.ico', __FILE__ ) );
		// Create a sub-menu under the top-level menu
		/*add_submenu_page( 'importrsm_main_menu',
		'Menu utilisateurs', 'Menu gestion des nouveaux utilisateurs',
		get_option('wpdm_access_level','manage_options'), 'ch3mlm-sub-menu',
		array('RsmImportMenu','visualize_imported_users') );*/
	}
	/*
	 * See page 90 of the CookBook and http://codex.wordpress.org/Function_Reference/add_menu_page
	 */
	public static function visualize_imported_users(){
		global $wpdb;
		$query = $wpdb->prepare("SELECT u.ID, u.user_login, u.user_email, um.meta_value FROM {$wpdb->users} u, {$wpdb->usermeta} um WHERE u.ID = um.user_id AND um.meta_key='wpgeneratedpass' order by u.ID DESC");
		$lignes = $wpdb->get_results($query);
		if ( !(count($lignes) > 0)){
			echo "<div id=\"warning\" class=\"error fade\"><p>Aucun utilisateur trouvé!</p></div>";
		}
		$codeHtml =  "<div id=\"warning\" class=\"error fade\"><table><thead></thead><tr><th>Id Utilisateur</th><th>Login</th><th>e-mail</th><th>Mot de Passe</th></tr><tbody>";
		foreach ($lignes as $ligne){
			//http://codex.wordpress.org/Function_Reference/admin_url
			$linkToUser = admin_url("user-edit.php?user_id=".$ligne->ID);
			$codeHtml .= "<tr><td><a href=\"".$linkToUser."\" target=\"_blank\">".$ligne->ID."</a></td><td>".$ligne->user_login."</td><td>".$ligne->user_email."</td><td>".$ligne->meta_value."</td></tr>";
		}
		$codeHtml .= "</tbody></table></id>";
		echo $codeHtml;
	}
}