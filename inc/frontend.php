<?php
/**
 * Portal-Seite unter /portal, Login-Weiterleitung, Zugriffsschutz.
 */

defined( 'ABSPATH' ) || exit;

function glp_add_rewrite() {
	add_rewrite_rule( '^portal/?$', 'index.php?glp_portal=1', 'top' );
}
add_action( 'init', 'glp_add_rewrite' );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'glp_portal';
	return $vars;
} );

function glp_portal_url() {
	return home_url( '/portal/' );
}

add_action( 'template_redirect', function () {
	if ( ! get_query_var( 'glp_portal' ) ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );
	$view = ! is_user_logged_in() ? 'login' : ( current_user_can( 'gl_portal' ) ? 'app' : 'denied' );
	include GLP_DIR . 'templates/portal.php';
	exit;
} );

// Bandmitglieder landen nach dem Login im Portal.
add_filter( 'login_redirect', function ( $redirect, $requested, $user ) {
	if ( $user instanceof WP_User && $user->has_cap( 'gl_portal' ) && ! $user->has_cap( 'edit_posts' ) ) {
		return glp_portal_url();
	}
	return $redirect;
}, 10, 3 );

// Bandmitglieder brauchen kein Backend: weiter ins Portal (Profil bleibt erreichbar).
add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || ! is_user_logged_in() ) {
		return;
	}
	$u = wp_get_current_user();
	if ( $u->has_cap( 'gl_portal' ) && ! $u->has_cap( 'edit_posts' ) ) {
		global $pagenow;
		if ( ! in_array( $pagenow, array( 'profile.php', 'admin-post.php' ), true ) ) {
			wp_safe_redirect( glp_portal_url() );
			exit;
		}
	}
} );

add_filter( 'show_admin_bar', function ( $show ) {
	$u = wp_get_current_user();
	return ( $u->exists() && $u->has_cap( 'gl_portal' ) && ! $u->has_cap( 'edit_posts' ) ) ? false : $show;
} );

// Login-Seite im Band-Look.
add_action( 'login_enqueue_scripts', function () {
	?>
	<style>
		body.login { background: #0A0B0A; }
		body.login #login h1 a { background: url(<?php echo esc_url( GLP_URL . 'assets/img/favicon.svg' ); ?>) center / contain no-repeat; width: 64px; height: 64px; }
		body.login form { background: #1A1D1B; border: 1px solid rgba(201,206,198,.14); border-radius: 0; box-shadow: none; }
		body.login label, body.login #nav a, body.login #backtoblog a, body.login .privacy-policy-link { color: #C9CEC6 !important; }
		body.login input[type=text], body.login input[type=password], body.login input[type=email] { background: #0A0B0A; border-color: rgba(201,206,198,.32); color: #ECEEE8; border-radius: 0; }
		body.login .button-primary { background: #7C9A85 !important; border-color: #7C9A85 !important; color: #0A0B0A !important; border-radius: 0; font-weight: 700; text-transform: uppercase; }
		body.login .message, body.login .notice, body.login #login_error { background: #1A1D1B; color: #ECEEE8; border-left-color: #7C9A85; }
	</style>
	<?php
} );
add_filter( 'login_headerurl', fn() => home_url( '/' ) );
add_filter( 'login_headertext', fn() => get_bloginfo( 'name' ) );
