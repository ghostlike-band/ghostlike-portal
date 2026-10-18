<?php
/**
 * Bandmitglieder verwalten: Übersicht, Einladung per E-Mail, Zugang geben/entziehen.
 * Einladungslink: /portal/?einladung=<id>.<token> – 14 Tage gültig, einmal verwendbar.
 */

defined( 'ABSPATH' ) || exit;

const GLP_INVITE_DAYS = 14;

// Letzten Login merken
add_action( 'wp_login', function ( $login, $user ) {
	if ( $user->has_cap( 'gl_portal' ) ) {
		update_user_meta( $user->ID, 'glp_last_login', time() );
	}
}, 10, 2 );

/* ================= EINLADUNG ================= */

function glp_invite_url( $user_id ) {
	$token = wp_generate_password( 32, false );
	update_user_meta( $user_id, 'glp_invite_hash', hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ) );
	update_user_meta( $user_id, 'glp_invite_expires', time() + GLP_INVITE_DAYS * DAY_IN_SECONDS );
	update_user_meta( $user_id, 'glp_invited_at', time() );
	update_user_meta( $user_id, 'glp_invited_by', get_current_user_id() );
	return add_query_arg( 'einladung', $user_id . '.' . $token, glp_portal_url() );
}

/** Prüft einen Einladungslink. Gibt den Benutzer zurück oder null. */
function glp_check_invite( $param ) {
	if ( ! preg_match( '/^(\d+)\.([A-Za-z0-9]{32})$/', (string) $param, $m ) ) {
		return null;
	}
	$user = get_userdata( (int) $m[1] );
	$hash = $user ? get_user_meta( $user->ID, 'glp_invite_hash', true ) : '';
	$exp  = $user ? (int) get_user_meta( $user->ID, 'glp_invite_expires', true ) : 0;
	if ( ! $user || ! $hash || $exp < time() || ! hash_equals( $hash, hash_hmac( 'sha256', $m[2], wp_salt( 'auth' ) ) ) ) {
		return null;
	}
	return $user;
}

function glp_send_invite( $user_id ) {
	$user    = get_userdata( $user_id );
	$url     = glp_invite_url( $user_id );
	$from    = glp_user_name( get_current_user_id() );
	$band    = get_bloginfo( 'name' );
	$first   = trim( $user->first_name ) ?: $user->display_name;
	$expires = wp_date( 'd.m.Y', time() + GLP_INVITE_DAYS * DAY_IN_SECONDS );
	$subject = sprintf( '%s lädt dich ins %s Member-Portal ein', $from, $band );

	$html = '<!doctype html><html><body style="margin:0;background:#0A0B0A;font-family:Arial,Helvetica,sans-serif;color:#C9CEC6">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0A0B0A"><tr><td align="center" style="padding:32px 16px">
	<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#1A1D1B;border-left:4px solid #7C9A85">
		<tr><td style="padding:32px 32px 8px;font-family:\'Courier New\',monospace;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#6B716C">Member-Portal · Intern</td></tr>
		<tr><td style="padding:0 32px;font-size:34px;line-height:1;font-weight:900;text-transform:uppercase;color:#ECEEE8;letter-spacing:-1px">Servus, ' . esc_html( $first ) . '</td></tr>
		<tr><td style="padding:20px 32px 0;font-size:16px;line-height:1.6;color:#C9CEC6">' . esc_html( $from ) . ' hat dich ins ' . esc_html( $band ) . ' Member-Portal eingeladen. Dort findest du Proben und Termine, das Studio-Tool mit dem Aufnahmestand und bald noch mehr.</td></tr>
		<tr><td style="padding:28px 32px"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#7C9A85;color:#0A0B0A;font-weight:700;text-transform:uppercase;text-decoration:none;padding:14px 22px;font-size:14px">Passwort festlegen &amp; loslegen &#8599;</a></td></tr>
		<tr><td style="padding:0 32px 8px;font-size:13px;line-height:1.6;color:#6B716C">Dein Benutzername: <strong style="color:#C9CEC6">' . esc_html( $user->user_login ) . '</strong> (oder deine E-Mail-Adresse)<br>Der Link ist bis ' . esc_html( $expires ) . ' gültig und funktioniert einmal.</td></tr>
		<tr><td style="padding:8px 32px 32px;font-size:12px;line-height:1.6;color:#6B716C">Falls der Button nicht geht, diesen Link kopieren:<br><span style="color:#C9CEC6;word-break:break-all">' . esc_html( $url ) . '</span></td></tr>
	</table></td></tr></table></body></html>';

	$text = "Servus {$first},\n\n{$from} hat dich ins {$band} Member-Portal eingeladen.\n\nPasswort festlegen & loslegen:\n{$url}\n\nBenutzername: {$user->user_login} (oder deine E-Mail)\nDer Link ist bis {$expires} gültig.\n";

	$set_alt = function ( $phpmailer ) use ( $text ) {
		$phpmailer->AltBody = $text;
	};
	add_action( 'phpmailer_init', $set_alt );
	$ok = wp_mail( $user->user_email, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	remove_action( 'phpmailer_init', $set_alt );
	return $ok;
}

/* ================= AKTIONEN ================= */

add_action( 'admin_post_glp_member', function () {
	check_admin_referer( 'glp_member' );
	if ( ! current_user_can( 'create_users' ) ) {
		wp_die( 'Keine Berechtigung.' );
	}
	$in   = wp_unslash( $_POST );
	$do   = sanitize_key( $in['do'] ?? '' );
	$back = admin_url( 'admin.php?page=ghostlike-members' );
	$msg  = '';

	if ( $do === 'invite' ) {
		$email = sanitize_email( $in['email'] ?? '' );
		$first = sanitize_text_field( $in['first'] ?? '' );
		$last  = sanitize_text_field( $in['last'] ?? '' );
		if ( ! is_email( $email ) || $first === '' ) {
			$msg = 'err:Bitte Vorname und eine gültige E-Mail-Adresse angeben.';
		} elseif ( $existing = get_user_by( 'email', $email ) ) {
			$msg = 'err:Diese E-Mail hat schon einen Benutzer (' . $existing->user_login . '). Unten bei „Bestehendem Benutzer Zugang geben“ auswählen.';
		} else {
			$login = sanitize_user( strtolower( remove_accents( $first ) ), true );
			$base  = $login ?: 'member';
			for ( $i = 2; username_exists( $login ) || $login === ''; $i++ ) {
				$login = $base . $i;
			}
			$uid = wp_insert_user( array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24 ),
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
				'role'         => 'gl_member',
			) );
			if ( is_wp_error( $uid ) ) {
				$msg = 'err:' . $uid->get_error_message();
			} else {
				$msg = glp_send_invite( $uid ) ? 'ok:Einladung an ' . $email . ' verschickt.' : 'warn:Benutzer angelegt, aber die E-Mail konnte nicht verschickt werden. Bitte Mailversand prüfen (SMTP) oder den Link manuell schicken.';
			}
		}
	}

	$uid  = (int) ( $in['user'] ?? 0 );
	$user = $uid ? get_userdata( $uid ) : null;
	if ( in_array( $do, array( 'resend', 'revoke', 'grant', 'link' ), true ) && ! $user ) {
		$msg = 'err:Benutzer nicht gefunden.';
	} elseif ( $do === 'resend' ) {
		$msg = glp_send_invite( $uid ) ? 'ok:Einladung an ' . $user->user_email . ' erneut verschickt.' : 'warn:E-Mail konnte nicht verschickt werden.';
	} elseif ( $do === 'link' ) {
		set_transient( 'glp_invite_link_' . get_current_user_id(), array( 'name' => glp_user_name( $uid ), 'url' => glp_invite_url( $uid ) ), 10 * MINUTE_IN_SECONDS );
		$msg = 'link:';
	} elseif ( $do === 'grant' ) {
		if ( ! $user->has_cap( 'gl_portal' ) ) {
			$user->add_role( 'gl_member' );
		}
		$msg = 'ok:' . glp_user_name( $uid ) . ' hat jetzt Zugang zum Portal.';
	} elseif ( $do === 'revoke' ) {
		if ( $uid === get_current_user_id() || $user->has_cap( 'manage_options' ) ) {
			$msg = 'err:Admins behalten immer Zugang. Erst die Admin-Rolle ändern.';
		} else {
			$user->remove_role( 'gl_member' );
			if ( ! $user->roles ) {
				$user->set_role( 'subscriber' );
			}
			delete_user_meta( $uid, 'glp_invite_hash' );
			glp_ical_token( $uid, true ); // Kalender-Abo ungültig machen
			$msg = 'ok:Zugang für ' . glp_user_name( $uid ) . ' entzogen.';
		}
	}
	wp_safe_redirect( add_query_arg( 'm', rawurlencode( $msg ), $back ) );
	exit;
} );

/* ================= SEITE ================= */

add_action( 'admin_menu', function () {
	global $admin_page_hooks;
	$parent = isset( $admin_page_hooks['ghostlike'] ) ? 'ghostlike' : 'users.php';
	add_submenu_page( $parent, 'Bandmitglieder', 'Bandmitglieder', 'create_users', 'ghostlike-members', 'glp_members_page', 1 );
}, 20 );

function glp_members_page() {
	$members = get_users( array( 'capability' => 'gl_portal', 'orderby' => 'registered', 'order' => 'ASC' ) );
	$others  = get_users( array( 'capability__not_in' => array( 'gl_portal' ), 'orderby' => 'display_name', 'number' => 200 ) );
	$m       = isset( $_GET['m'] ) ? sanitize_text_field( wp_unslash( $_GET['m'] ) ) : '';
	[ $type, $text ] = array_pad( explode( ':', $m, 2 ), 2, '' );
	$link    = $type === 'link' ? get_transient( 'glp_invite_link_' . get_current_user_id() ) : null;
	$cls     = array( 'ok' => 'notice-success', 'warn' => 'notice-warning', 'err' => 'notice-error' );
	$form    = function ( $do, $uid, $label, $class = 'button button-small', $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . ( $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"' : '' ) . '>';
		wp_nonce_field( 'glp_member' );
		echo '<input type="hidden" name="action" value="glp_member"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="user" value="' . (int) $uid . '">';
		echo '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form> ';
	};
	?>
	<div class="wrap">
		<h1>Bandmitglieder</h1>
		<?php if ( $text && isset( $cls[ $type ] ) ) : ?><div class="notice <?php echo esc_attr( $cls[ $type ] ); ?> is-dismissible"><p><?php echo esc_html( $text ); ?></p></div><?php endif; ?>
		<?php if ( $link ) : ?>
			<div class="notice notice-info"><p>Persönlicher Einladungslink für <strong><?php echo esc_html( $link['name'] ); ?></strong> (<?php echo (int) GLP_INVITE_DAYS; ?> Tage gültig, z. B. per WhatsApp schicken). Frühere Links sind damit ungültig.</p>
			<p><input type="text" readonly class="large-text code" value="<?php echo esc_attr( $link['url'] ); ?>" onclick="this.select()"></p></div>
		<?php endif; ?>

		<p style="max-width:760px">Alle hier haben Zugang zum <a href="<?php echo esc_url( glp_portal_url() ); ?>" target="_blank">Member-Portal ↗</a>. Bandmitglieder sehen kein WordPress-Backend, sie landen nach dem Login direkt im Portal. Der <strong>Vorname</strong> wird im Portal angezeigt und mit den Zuständigkeiten im Studio-Tool abgeglichen.</p>

		<table class="widefat striped" style="max-width:1100px">
			<thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Status</th><th>Letzter Login</th><th>Aktionen</th></tr></thead>
			<tbody>
			<?php foreach ( $members as $u ) :
				$last    = (int) get_user_meta( $u->ID, 'glp_last_login', true );
				$invited = (int) get_user_meta( $u->ID, 'glp_invited_at', true );
				$pending = get_user_meta( $u->ID, 'glp_invite_hash', true );
				$exp     = (int) get_user_meta( $u->ID, 'glp_invite_expires', true );
				$is_adm  = $u->has_cap( 'manage_options' );
				if ( $pending && ! $last ) {
					$status = $exp < time() ? '⚠ Einladung abgelaufen' : '✉ Eingeladen am ' . wp_date( 'd.m.', $invited );
				} else {
					$status = '✓ Aktiv';
				}
				?>
				<tr>
					<td><strong><?php echo esc_html( trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name ); ?></strong><br><span class="description"><?php echo esc_html( $u->user_login ); ?></span><?php echo trim( $u->first_name ) === '' ? '<br><span style="color:#b32d2e">Vorname fehlt</span>' : ''; ?></td>
					<td><a href="mailto:<?php echo esc_attr( $u->user_email ); ?>"><?php echo esc_html( $u->user_email ); ?></a></td>
					<td><?php echo $is_adm ? 'Admin' : 'Bandmitglied'; ?></td>
					<td><?php echo esc_html( $status ); ?></td>
					<td><?php echo $last ? esc_html( wp_date( 'd.m.Y, H:i', $last ) ) : '–'; ?></td>
					<td>
						<?php
						$form( 'resend', $u->ID, $last ? 'Zugangslink schicken' : 'Einladung erneut senden' );
						$form( 'link', $u->ID, 'Neuen Link erzeugen', 'button button-small', 'Neuen Einladungslink erzeugen? Ein vorher verschickter Link wird damit ungültig.' );
						echo '<a class="button button-small" href="' . esc_url( get_edit_user_link( $u->ID ) ) . '">Bearbeiten</a> ';
						if ( ! $is_adm ) {
							$form( 'revoke', $u->ID, 'Zugang entziehen', 'button button-small button-link-delete', 'Zugang für ' . glp_user_name( $u->ID ) . ' wirklich entziehen?' );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;max-width:1100px;margin-top:24px">
			<div class="card" style="max-width:none">
				<h2>Neues Mitglied einladen</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'glp_member' ); ?>
					<input type="hidden" name="action" value="glp_member"><input type="hidden" name="do" value="invite">
					<p><label>Vorname *<br><input name="first" class="regular-text" required></label></p>
					<p><label>Nachname<br><input name="last" class="regular-text"></label></p>
					<p><label>E-Mail *<br><input name="email" type="email" class="regular-text" required></label></p>
					<p><button class="button button-primary">Einladung senden ✉</button></p>
					<p class="description">Die Person bekommt eine Mail mit einem persönlichen Link (<?php echo (int) GLP_INVITE_DAYS; ?> Tage gültig), legt ihr Passwort selbst fest und landet direkt im Portal.</p>
				</form>
			</div>
			<?php if ( $others ) : ?>
				<div class="card" style="max-width:none">
					<h2>Bestehendem Benutzer Zugang geben</h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'glp_member' ); ?>
						<input type="hidden" name="action" value="glp_member"><input type="hidden" name="do" value="grant">
						<p><select name="user"><?php foreach ( $others as $o ) : ?><option value="<?php echo (int) $o->ID; ?>"><?php echo esc_html( $o->display_name . ' (' . $o->user_email . ')' ); ?></option><?php endforeach; ?></select></p>
						<p><button class="button">Zugang geben</button></p>
					</form>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
