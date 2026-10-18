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

	// Zu-/Absage per Ein-Tipp-Link (aus dem Handy-Kalender): ?zusage=<user>.<token>.<termin>.<ja|nein>
	if ( isset( $_GET['zusage'] ) ) {
		glp_handle_rsvp( (string) wp_unslash( $_GET['zusage'] ) );
		exit;
	}

	// Einladungslink: Passwort festlegen und direkt einloggen
	$invite_error = '';
	if ( isset( $_GET['einladung'] ) ) {
		$invite_user = glp_check_invite( wp_unslash( $_GET['einladung'] ) );
		if ( $invite_user && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			check_admin_referer( 'glp_set_password' );
			$p1 = (string) wp_unslash( $_POST['pass1'] ?? '' );
			$p2 = (string) wp_unslash( $_POST['pass2'] ?? '' );
			if ( strlen( $p1 ) < 10 ) {
				$invite_error = 'Bitte mindestens 10 Zeichen verwenden.';
			} elseif ( $p1 !== $p2 ) {
				$invite_error = 'Die beiden Passwörter stimmen nicht überein.';
			} else {
				wp_set_password( $p1, $invite_user->ID );
				delete_user_meta( $invite_user->ID, 'glp_invite_hash' );
				delete_user_meta( $invite_user->ID, 'glp_invite_expires' );
				wp_set_current_user( $invite_user->ID );
				wp_set_auth_cookie( $invite_user->ID, true, is_ssl() );
				do_action( 'wp_login', $invite_user->user_login, $invite_user );
				wp_safe_redirect( glp_portal_url() );
				exit;
			}
		}
		$view = $invite_user ? 'invite' : 'invite-invalid';
		include GLP_DIR . 'templates/portal.php';
		exit;
	}

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

function glp_handle_rsvp( $param ) {
	global $wpdb;
	$ok = preg_match( '/^(\d+)\.([A-Za-z0-9]{32})\.(probe-\d{4}-\d{2}-\d{2}|ev-\d+)\.(ja|nein)$/', $param, $m );
	$uid = $ok ? (int) $m[1] : 0;
	$saved = $uid ? get_user_meta( $uid, 'glp_ical_token', true ) : '';
	if ( ! $ok || ! $saved || ! hash_equals( $saved, $m[2] ) || ! user_can( $uid, 'gl_portal' ) ) {
		glp_simple_page( 'Link ungültig', '<p>Dieser Link funktioniert nicht (mehr). Bitte im Portal zu- oder absagen.</p>' );
		return;
	}
	$key    = $m[3];
	$status = $m[4];
	$from   = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-1 day' );
	$event  = null;
	foreach ( glp_events( 26, $from ) as $e ) {
		if ( $e['key'] === $key ) {
			$event = $e;
		}
	}
	if ( ! $event ) {
		glp_simple_page( 'Termin vorbei', '<p>Diesen Termin gibt es nicht mehr oder er ist schon vorbei.</p>' );
		return;
	}
	$when = wp_date( 'D, d.m.', strtotime( $event['start'] ) );
	if ( $event['cancelled'] ) {
		glp_simple_page( 'Fällt aus', '<p><strong>' . esc_html( $event['title'] ) . '</strong> am ' . esc_html( $when ) . ' fällt aus. Da musst du nichts tun.</p>' );
		return;
	}
	$wpdb->replace( glp_table( 'attendance' ), array( 'event_key' => $key, 'user_id' => $uid, 'status' => $status, 'updated_at' => current_time( 'mysql' ) ) );

	$other = glp_rsvp_url( $uid, $key, $status === 'ja' ? 'nein' : 'ja' );
	$head  = $status === 'ja' ? '✓ Du bist dabei' : '✕ Abgesagt';
	$body  = '<p><strong>' . esc_html( $event['title'] ) . '</strong><br>' . esc_html( $when . ( $event['place'] ? ' · ' . $event['place'] : '' ) ) . '</p>'
		. '<p>Gespeichert, die anderen sehen es im Portal sofort. In deinem Kalender steht es nach dem nächsten Abgleich.</p>'
		. '<p class="aktionen"><a class="btn" href="' . esc_url( $other ) . '">' . ( $status === 'ja' ? 'Doch nicht' : 'Doch dabei' ) . '</a> <a class="btn btn--patina" href="' . esc_url( glp_portal_url() . '#kalender' ) . '">Zum Portal ↗</a></p>';
	glp_simple_page( $head, $body );
}

/** Kleine Seite im Portal-Look (Bestätigungen). */
function glp_simple_page( $title, $html ) {
	$v = fn( $f ) => GLP_VERSION . '.' . (int) @filemtime( GLP_DIR . $f );
	?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#0A0B0A">
<title><?php echo esc_html( $title ); ?> · <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
<?php foreach ( array( 'assets/css/fonts.css', 'assets/css/base.css', 'assets/css/portal.css' ) as $f ) : ?><link rel="stylesheet" href="<?php echo esc_url( GLP_URL . $f . '?ver=' . $v( $f ) ); ?>"><?php endforeach; ?>
</head><body class="glp glp--simple"><main class="simple">
<span class="meta">Member-Portal · <?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
<h1 class="display-l"><?php echo esc_html( $title ); ?></h1>
<?php echo $html; // phpcs:ignore -- oben escaped ?>
</main></body></html><?php
}
