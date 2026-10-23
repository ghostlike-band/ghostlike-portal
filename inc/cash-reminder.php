<?php
/**
 * Bandkasse: freundliche Erinnerung alle 2 Wochen an alle, die einem Bandmitglied noch Geld schulden.
 * Schalter, Vorschau und „Jetzt senden“ unter GHOSTLIKE → Bandkasse.
 */

defined( 'ABSPATH' ) || exit;

const GLP_REMINDER_DAYS = 14;

function glp_reminder_opt() {
	$o = get_option( 'glp_cash_reminder', null );
	if ( ! is_array( $o ) ) { // erster Start: nicht sofort losmailen, erste Runde in 2 Wochen
		$o = array( 'active' => 1, 'last' => time() );
		update_option( 'glp_cash_reminder', $o, false );
	}
	return $o + array( 'active' => 1, 'last' => 0 );
}

function glp_reminder_next() {
	return (int) glp_reminder_opt()['last'] + GLP_REMINDER_DAYS * DAY_IN_SECONDS;
}

// Täglich prüfen, ob die 2 Wochen um sind
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'glp_cash_reminder_check' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'glp_cash_reminder_check' );
	}
} );
add_action( 'glp_cash_reminder_check', function () {
	$o = glp_reminder_opt();
	if ( $o['active'] && time() >= glp_reminder_next() ) {
		glp_reminder_send_all();
	}
} );

/** Offene Schulden je Mitglied an andere Mitglieder (Bandkasse ausgenommen, gemeldete Zahlungen abgezogen). */
function glp_reminder_debts() {
	global $wpdb;
	$rows  = array_map( 'glp_cash_row', $wpdb->get_results( 'SELECT * FROM ' . glp_table( 'cash' ) . ' ORDER BY booked_on DESC, id DESC' ) );
	$out   = array();
	foreach ( glp_cash_debts( $rows ) as $d ) {
		if ( ! $d['debtor'] || ! $d['creditor'] ) {
			continue;
		}
		$pending = 0;
		foreach ( $rows as $b ) {
			if ( $b['kind'] === 'move' && $b['status'] === 'pending' && $b['from'] === $d['debtor'] && $b['to'] === $d['creditor'] ) {
				$pending += $b['amount'];
			}
		}
		$open = $d['amount'] - $pending;
		if ( $open > 0 ) {
			$out[ $d['debtor'] ][] = $d + array( 'open' => $open );
		}
	}
	return $out;
}

/** Fertige Mail für ein Mitglied: [subject, html, text] oder null, wenn nichts offen ist. */
function glp_reminder_mail( $uid, $list ) {
	if ( ! $list ) {
		return null;
	}
	$name  = glp_user_name( $uid );
	$total = array_sum( array_column( $list, 'open' ) );
	$link  = glp_portal_url() . '#kasse';
	$subj  = sprintf( 'Kleine Erinnerung: %s offen in der Bandkasse', glp_cash_money( $total ) );
	$intro = sprintf( 'Servus %s, kein Stress, nur ein kurzer Überblick: In der Bandkasse ist bei dir noch etwas offen.', $name );
	$outro = 'Im Portal geht das mit einem Klick per PayPal oder du trägst „Bar gegeben“ ein. Sobald die andere Person bestätigt, ist der Betrag erledigt.';
	$foot  = 'Diese Erinnerung kommt alle 2 Wochen, solange noch etwas offen ist.';

	$text = $intro . "\n\n";
	foreach ( $list as $d ) {
		$text .= sprintf( "• an %s: %s\n", glp_user_name( $d['creditor'] ), glp_cash_money( $d['open'] ) );
		foreach ( $d['items'] as $it ) {
			$text .= sprintf( "    %s · %s · %s%s\n", $it['title'], $it['why'], $it['amount'] < 0 ? '−' : '+', glp_cash_money( abs( $it['amount'] ) ) );
		}
	}
	$text .= sprintf( "\nZusammen: %s\n\nSchulden jetzt ausgleichen: %s\n\n%s\n\n%s", glp_cash_money( $total ), $link, $outro, $foot );

	if ( ! function_exists( 'gl_email_wrap' ) || ! defined( 'GL_MAIL_C' ) ) {
		return array( 'subject' => $subj, 'html' => '', 'text' => $text );
	}
	// Im GHOSTLIKE-Look: gleiche Bausteine wie die Theme-Mails
	$c    = GL_MAIL_C;
	$font = 'Arial, Helvetica, sans-serif';
	$mono = "'Courier New', monospace";
	$p    = fn( $t ) => '<tr><td style="padding:12px 36px;font-family:' . $font . ';font-size:16px;line-height:1.6;color:' . $c['text'] . '">' . $t . '</td></tr>';
	$rows = '<tr><td style="padding:6px 36px 4px;font-family:\'Arial Black\',Arial,Helvetica,sans-serif;font-size:32px;line-height:1.05;font-weight:900;text-transform:uppercase;letter-spacing:-.5px;color:' . $c['head'] . '">Kleine Erinnerung</td></tr>';
	$rows .= $p( esc_html( $intro ) );
	$tbl  = '';
	foreach ( $list as $d ) {
		$tbl .= '<tr><td style="padding:14px 0 4px;border-top:1px solid ' . $c['line'] . ';font-family:' . $font . ';font-size:16px;font-weight:bold;color:' . $c['head'] . '">an ' . esc_html( glp_user_name( $d['creditor'] ) ) . '</td>'
			. '<td align="right" style="padding:14px 0 4px;border-top:1px solid ' . $c['line'] . ';font-family:\'Arial Black\',Arial,sans-serif;font-size:18px;font-weight:900;color:#d9776b;white-space:nowrap">' . esc_html( glp_cash_money( $d['open'] ) ) . '</td></tr>';
		foreach ( $d['items'] as $it ) {
			$tbl .= '<tr><td style="padding:0 12px 4px 0;font-family:' . $mono . ';font-size:12px;line-height:1.5;color:' . $c['mute'] . '">' . esc_html( wp_date( 'd.m.', strtotime( $it['date'] ) ) . ' · ' . $it['title'] . ' · ' . $it['why'] ) . '</td>'
				. '<td align="right" valign="top" style="padding:0 0 4px;font-family:' . $mono . ';font-size:12px;line-height:1.5;white-space:nowrap;color:' . ( $it['amount'] < 0 ? $c['patina'] : $c['text'] ) . '">' . ( $it['amount'] < 0 ? '−' : '+' ) . esc_html( glp_cash_money( abs( $it['amount'] ) ) ) . '</td></tr>';
		}
		$tbl .= '<tr><td colspan="2" style="height:8px;line-height:8px;font-size:1px">&nbsp;</td></tr>';
	}
	$tbl  .= '<tr><td style="padding:14px 0 0;border-top:2px solid ' . $c['patina'] . ';font-family:' . $mono . ';font-size:12px;letter-spacing:2px;text-transform:uppercase;color:' . $c['mute'] . '">Zusammen</td>'
		. '<td align="right" style="padding:14px 0 0;border-top:2px solid ' . $c['patina'] . ';font-family:\'Arial Black\',Arial,sans-serif;font-size:22px;font-weight:900;color:' . $c['head'] . ';white-space:nowrap">' . esc_html( glp_cash_money( $total ) ) . '</td></tr>';
	$rows .= '<tr><td style="padding:12px 36px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $tbl . '</table></td></tr>';
	$rows .= '<tr><td style="padding:22px 36px 18px"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:' . $c['patina'] . '"><a href="' . esc_url( $link ) . '" style="display:inline-block;padding:15px 24px;font-family:' . $font . ';font-size:14px;font-weight:bold;letter-spacing:.5px;text-transform:uppercase;color:' . $c['bg'] . ';text-decoration:none">Schulden jetzt ausgleichen</a></td></tr></table></td></tr>';
	$rows .= $p( esc_html( $outro ) );
	return array( 'subject' => $subj, 'html' => gl_email_wrap( $rows, sprintf( '%s offen · kurzer Überblick', glp_cash_money( $total ) ), $foot ), 'text' => $text );
}

function glp_reminder_send( $uid, $list ) {
	$m = glp_reminder_mail( $uid, $list );
	$u = get_userdata( $uid );
	if ( ! $m || ! $u || ! is_email( $u->user_email ) ) {
		return false;
	}
	if ( $m['html'] === '' ) {
		return wp_mail( $u->user_email, $m['subject'], $m['text'] );
	}
	if ( function_exists( 'gl_mail_alt' ) ) {
		gl_mail_alt( $m['html'], $m['text'] );
	}
	return wp_mail( $u->user_email, $m['subject'], $m['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/** Alle Schuldner anschreiben. Gibt die Namen zurück, die eine Mail bekommen haben. */
function glp_reminder_send_all() {
	$sent = array();
	foreach ( glp_reminder_debts() as $uid => $list ) {
		if ( glp_reminder_send( $uid, $list ) ) {
			$sent[] = glp_user_name( $uid );
		}
	}
	$o         = glp_reminder_opt();
	$o['last'] = time();
	update_option( 'glp_cash_reminder', $o, false );
	glp_log( 'Bandkasse: Erinnerung verschickt an ' . ( $sent ? implode( ', ', $sent ) : 'niemanden (nichts offen)' ) );
	return $sent;
}

/* ================= BACKEND ================= */

add_action( 'admin_post_glp_reminder', function () {
	check_admin_referer( 'glp_reminder' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Keine Berechtigung.' );
	}
	$do   = sanitize_key( $_POST['do'] ?? '' );
	$back = admin_url( 'admin.php?page=ghostlike-cash' );
	$o    = glp_reminder_opt();
	if ( $do === 'toggle' ) {
		$o['active'] = $o['active'] ? 0 : 1;
		update_option( 'glp_cash_reminder', $o, false );
		$msg = $o['active'] ? 'ok:Erinnerungen sind an.' : 'ok:Erinnerungen sind aus.';
	} elseif ( $do === 'send' ) {
		$sent = glp_reminder_send_all();
		$msg  = $sent ? 'ok:Erinnerung verschickt an ' . implode( ', ', $sent ) . '. Die nächste kommt in 2 Wochen.' : 'ok:Gerade schuldet niemand einem anderen Bandmitglied etwas, keine Mail verschickt.';
	} else {
		$msg = '';
	}
	wp_safe_redirect( add_query_arg( 'm', rawurlencode( $msg ), $back ) );
	exit;
} );

// Vorschau der Mail im Browser (für ein Mitglied)
add_action( 'admin_post_glp_reminder_preview', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Keine Berechtigung.' );
	}
	$uid   = (int) ( $_GET['user'] ?? 0 );
	$debts = glp_reminder_debts();
	$m     = glp_reminder_mail( $uid, $debts[ $uid ] ?? array() );
	if ( ! $m ) {
		wp_die( esc_html( glp_user_name( $uid ) ) . ' schuldet gerade niemandem etwas.' );
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	echo $m['html'] ?: '<pre>' . esc_html( $m['text'] ) . '</pre>'; // phpcs:ignore
	exit;
} );

/** Kasten oben auf der Seite GHOSTLIKE → Bandkasse */
function glp_reminder_box() {
	$o     = glp_reminder_opt();
	$debts = glp_reminder_debts();
	$btn   = function ( $do, $label, $class = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'glp_reminder' );
		echo '<input type="hidden" name="action" value="glp_reminder"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form> ';
	};
	?>
	<div class="card" style="max-width:1160px;margin:16px 0 20px">
		<h2 style="margin-top:.4em">Erinnerungs-Mail</h2>
		<p>Alle 2 Wochen bekommt jedes Mitglied, das einem anderen Bandmitglied noch Geld schuldet, eine freundliche Mail mit der Übersicht und dem Button „Schulden jetzt ausgleichen“. Wer nichts schuldet, bekommt nichts.</p>
		<p><strong>Status:</strong> <?php echo $o['active'] ? '✓ an · nächste Runde am ' . esc_html( wp_date( 'd.m.Y', glp_reminder_next() ) ) : '✗ aus'; ?>
</p>
		<p><strong>Würde jetzt gehen an:</strong>
			<?php if ( ! $debts ) : ?>niemanden, gerade ist nichts offen.
			<?php else :
				$links = array();
				foreach ( $debts as $uid => $list ) {
					$links[] = esc_html( glp_user_name( $uid ) ) . ' (' . esc_html( glp_cash_money( array_sum( array_column( $list, 'open' ) ) ) ) . ', <a href="' . esc_url( admin_url( 'admin-post.php?action=glp_reminder_preview&user=' . (int) $uid ) ) . '" target="_blank">Vorschau ↗</a>)';
				}
				echo implode( ' · ', $links ); // phpcs:ignore
			endif; ?></p>
		<p><?php $btn( 'toggle', $o['active'] ? 'Erinnerungen ausschalten' : 'Erinnerungen einschalten' ); ?>
			<?php if ( $debts ) { $btn( 'send', 'Jetzt verschicken', 'button button-primary' ); } ?></p>
	</div>
	<?php
}
