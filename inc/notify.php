<?php
/**
 * Benachrichtigungen: Mail an die anderen, wenn eine neue Version hochgeladen wurde.
 * Jede*r kann das unter „Mein Profil“ abschalten (User-Meta glp_notify_versions = '0').
 */

defined( 'ABSPATH' ) || exit;

function glp_wants_version_mail( $user_id ) {
	return get_user_meta( $user_id, 'glp_notify_versions', true ) !== '0';
}

/** Direkt-Link: öffnet im Portal das Studio mit diesem Song. */
function glp_song_url( $song_id ) {
	return add_query_arg( 'song', (int) $song_id, glp_portal_url() ) . '#studio';
}

function glp_notify_version( $song, $v, $note = '' ) {
	$me   = get_current_user_id();
	$from = glp_user_name( $me );
	$link = glp_song_url( $song['id'] );
	glp_push_safely( 'glp_push_version', $song, $v, $note );
	foreach ( glp_members() as $m ) {
		if ( $m['id'] === $me || ! glp_wants_version_mail( $m['id'] ) ) {
			continue;
		}
		$u = get_userdata( $m['id'] );
		if ( ! $u || ! is_email( $u->user_email ) ) {
			continue;
		}
		$vars = array(
			'vorname' => $m['name'],
			'von'     => $from,
			'song'    => $song['title'],
			'version' => 'v' . $v,
			'notiz'   => $note !== '' ? $note : '–',
			'link'    => $link,
			'profil'  => glp_portal_url() . '#profil',
		);
		// Texte und Design aus dem Theme (GHOSTLIKE → E-Mails), sonst schlichte Text-Mail
		if ( function_exists( 'gl_send_email' ) && function_exists( 'gl_email_tpl' ) && gl_email_tpl( 'portal_version' ) ) {
			gl_send_email( $u->user_email, 'portal_version', $vars );
			continue;
		}
		wp_mail(
			$u->user_email,
			sprintf( 'Neu: %s %s von %s', $song['title'], $vars['version'], $from ),
			sprintf(
				"Servus %s,\n\n%s hat eine neue Version hochgeladen: %s %s\nNotiz: %s\n\nJetzt anhören:\n%s\n\nKeine Mails mehr dazu? Im Portal unter Mein Profil abschalten:\n%s\n",
				$m['name'], $from, $song['title'], $vars['version'], $vars['notiz'], $link, $vars['profil']
			)
		);
	}
}
