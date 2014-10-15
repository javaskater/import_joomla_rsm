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
		//http://codex.wordpress.org/Class_Reference/WP_User_Query
		$user_query = new WP_User_Query( array ( 'orderby' => 'ID', 'order' => 'DESC' ) );
		// User Loop
		if ( ! empty( $user_query->results ) ) {
			$codeHtml =  "<div><table><thead></thead><tr><th>Id Utilisateur</th><th>Login</th><th>e-mail</th><th>Mot de Passe généré</th><th>Rôle(s)</th></tr><tbody>";
			foreach ( $user_query->results as $user ) {
				$user_id = $user->ID;
				$generated_password = get_user_meta( $user_id, 'wpgeneratedpass', true );
				$linkToUser = admin_url("user-edit.php?user_id=".$user_id); //http://codex.wordpress.org/Function_Reference/admin_url
				$codeHtml .= "<tr><td><a href=\"".$linkToUser."\" target=\"_blank\">".$user_id."</a></td><td>".$user->user_login."</td><td>".$user->user_email."</td><td>".$generated_password."</td><td>|";
				foreach($user->roles as $role){
					$codeHtml .= $role."|";
				}
				$codeHtml .= "</td></tr>";
			}
			$codeHtml .= "</tbody></table></id>";
			echo $codeHtml;
		} else {
			echo "<div id=\"warning\" class=\"error fade\"><p>Aucun utilisateur trouvé!</p></div>";;
		}
	}
}