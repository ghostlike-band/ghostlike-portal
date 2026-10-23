<?php
/**
 * Push-Benachrichtigungen (Web Push) für die Portal-App auf dem Home-Bildschirm.
 * Ohne Fremd-Bibliothek: VAPID (RFC 8292) und Verschlüsselung aes128gcm (RFC 8291) mit OpenSSL.
 * Abos liegen pro Gerät im User-Meta glp_push_subs, Vorlieben in glp_push_prefs.
 */

defined( 'ABSPATH' ) || exit;

/** Anlässe, die man im Profil einzeln abschalten kann */
function glp_push_types() {
	return array(
		'kasse'   => 'Bandkasse: jemand hat dir gezahlt und wartet auf deine Bestätigung',
		'probe'   => 'Probentag: morgens Erinnerung, und 2 Tage vor Terminen, wenn du noch nicht zu- oder abgesagt hast',
		'version' => 'Neue Song-Version im Studio',
	);
}

function glp_push_prefs( $uid ) {
	$p = get_user_meta( $uid, 'glp_push_prefs', true );
	return array_merge( array_fill_keys( array_keys( glp_push_types() ), 1 ), is_array( $p ) ? $p : array() );
}

/* ================= KRYPTO-HELFER ================= */

function glp_b64u( $bin ) {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}

function glp_b64u_dec( $s ) {
	return (string) base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) );
}

/** Rohen P-256-Public-Key (65 Byte, 0x04|x|y) in ein OpenSSL-Key-Objekt umwandeln */
function glp_ec_public_from_raw( $raw ) {
	$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . $raw;
	$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	return openssl_pkey_get_public( $pem );
}

function glp_ec_raw_public( $key ) {
	$d = openssl_pkey_get_details( $key )['ec'];
	return "\x04" . str_pad( $d['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['y'], 32, "\0", STR_PAD_LEFT );
}

/** P-256-Schlüssel aus dem privaten Skalar d (32 Byte); OpenSSL berechnet den Public Key beim Laden */
function glp_ec_from_d( $d ) {
	$der = hex2bin( '30310201010420' ) . str_pad( $d, 32, "\0", STR_PAD_LEFT ) . hex2bin( 'a00a06082a8648ce3d030107' );
	return openssl_pkey_get_private( "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END EC PRIVATE KEY-----\n" );
}

function glp_ec_new() {
	$key = @openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
	if ( $key ) {
		return $key;
	}
	// Ohne openssl.cnf (manche Hoster) klappt openssl_pkey_new nicht: Schlüssel aus Zufallszahl bauen
	do {
		$d = random_bytes( 32 );
	} while ( ord( $d[0] ) === 0xFF || ltrim( $d, "\0" ) === '' );
	return glp_ec_from_d( $d );
}

/** Server-Schlüssel (VAPID), einmalig erzeugt. Gespeichert wird nur d (roh), das klappt auch ohne openssl.cnf. */
function glp_vapid() {
	$v = get_option( 'glp_vapid' );
	if ( ! is_array( $v ) || empty( $v['d'] ) ) {
		$key = glp_ec_new();
		if ( ! $key ) {
			return null;
		}
		$v = array( 'd' => glp_b64u( openssl_pkey_get_details( $key )['ec']['d'] ), 'public' => glp_b64u( glp_ec_raw_public( $key ) ) );
		update_option( 'glp_vapid', $v, false );
	}
	return $v;
}

/** DER-ECDSA-Signatur → 64 Byte r|s (für JWT ES256) */
function glp_der_to_raw( $der ) {
	$pos = 2;
	$out = '';
	for ( $i = 0; $i < 2; $i++ ) {
		$len  = ord( $der[ $pos + 1 ] );
		$int  = ltrim( substr( $der, $pos + 2, $len ), "\0" );
		$out .= str_pad( $int, 32, "\0", STR_PAD_LEFT );
		$pos += 2 + $len;
	}
	return $out;
}

function glp_vapid_header( $endpoint ) {
	$v   = glp_vapid();
	$p   = wp_parse_url( $endpoint );
	$aud = $p['scheme'] . '://' . $p['host'];
	$jwt = glp_b64u( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) ) . '.' . glp_b64u( wp_json_encode( array( 'aud' => $aud, 'exp' => time() + 12 * HOUR_IN_SECONDS, 'sub' => 'mailto:' . get_option( 'admin_email' ) ), JSON_UNESCAPED_SLASHES ) );
	openssl_sign( $jwt, $sig, glp_ec_from_d( glp_b64u_dec( $v['d'] ) ), OPENSSL_ALGO_SHA256 );
	return 'vapid t=' . $jwt . '.' . glp_b64u( glp_der_to_raw( $sig ) ) . ', k=' . $v['public'];
}

/** Nachricht für ein Abo verschlüsseln (aes128gcm, ein Datensatz) */
function glp_push_encrypt( $payload, $p256dh, $auth ) {
	$ua_pub = glp_b64u_dec( $p256dh );
	$secret = glp_b64u_dec( $auth );
	$as     = glp_ec_new();
	$as_pub = glp_ec_raw_public( $as );
	$ecdh   = openssl_pkey_derive( glp_ec_public_from_raw( $ua_pub ), $as, 32 );
	if ( ! $ecdh ) {
		return null;
	}
	$ikm   = hash_hkdf( 'sha256', $ecdh, 32, "WebPush: info\0" . $ua_pub . $as_pub, $secret );
	$salt  = random_bytes( 16 );
	$cek   = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
	$nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );
	$ct    = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
	return $salt . pack( 'N', 4096 ) . chr( 65 ) . $as_pub . $ct . $tag;
}

/* ================= VERSAND ================= */

/**
 * Push an ein Mitglied (alle seine Geräte). $type = Anlass aus glp_push_types().
 * $msg = [ 'title' => …, 'body' => …, 'url' => …, 'tag' => … ]
 */
function glp_push_user( $uid, $type, $msg, $force = false ) {
	if ( ! function_exists( 'openssl_pkey_derive' ) || ( ! $force && empty( glp_push_prefs( $uid )[ $type ] ) ) ) {
		return 0;
	}
	$subs = (array) get_user_meta( $uid, 'glp_push_subs', true );
	if ( ! $subs ) {
		return 0;
	}
	$payload = wp_json_encode( $msg + array( 'url' => glp_portal_url() ) );
	$sent    = 0;
	foreach ( $subs as $id => $s ) {
		$body = glp_push_encrypt( $payload, $s['p256dh'], $s['auth'] );
		if ( null === $body ) {
			continue;
		}
		$r    = wp_remote_post( $s['endpoint'], array(
			'timeout' => 10,
			'headers' => array(
				'TTL'              => (string) DAY_IN_SECONDS,
				'Urgency'          => 'normal',
				'Content-Type'     => 'application/octet-stream',
				'Content-Encoding' => 'aes128gcm',
				'Authorization'    => glp_vapid_header( $s['endpoint'] ),
				'Topic'            => substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $msg['tag'] ?? '' ) ), 0, 32 ),
			),
			'body'    => $body,
		) );
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( $code >= 200 && $code < 300 ) {
			$sent++;
		} elseif ( in_array( $code, array( 404, 410 ), true ) ) {
			unset( $subs[ $id ] ); // Gerät hat das Abo beendet
		}
	}
	update_user_meta( $uid, 'glp_push_subs', $subs );
	do_action( 'glp_push_sent', $uid, $type, $msg, $sent );
	return $sent;
}

/* ================= REST: ABO VERWALTEN ================= */

add_action( 'rest_api_init', function () {
	register_rest_route( GLP_NS, '/push', array( 'methods' => 'GET', 'callback' => 'glp_rest_push_state', 'permission_callback' => 'glp_can' ) );
	register_rest_route( GLP_NS, '/push/subscribe', array( 'methods' => 'POST', 'callback' => 'glp_rest_push_subscribe', 'permission_callback' => 'glp_can' ) );
	register_rest_route( GLP_NS, '/push/unsubscribe', array( 'methods' => 'POST', 'callback' => 'glp_rest_push_unsubscribe', 'permission_callback' => 'glp_can' ) );
	register_rest_route( GLP_NS, '/push/prefs', array( 'methods' => 'POST', 'callback' => 'glp_rest_push_prefs', 'permission_callback' => 'glp_can' ) );
	register_rest_route( GLP_NS, '/push/test', array( 'methods' => 'POST', 'callback' => 'glp_rest_push_test', 'permission_callback' => 'glp_can' ) );
} );

function glp_rest_push_state() {
	$uid = get_current_user_id();
	$v   = glp_vapid();
	return glp_ok( array(
		'key'     => $v ? $v['public'] : '',
		'devices' => count( (array) get_user_meta( $uid, 'glp_push_subs', true ) ),
		'prefs'   => glp_push_prefs( $uid ),
		'types'   => glp_push_types(),
	) );
}

function glp_rest_push_subscribe( WP_REST_Request $req ) {
	$sub      = (array) $req->get_param( 'subscription' );
	$endpoint = esc_url_raw( (string) ( $sub['endpoint'] ?? '' ), array( 'https' ) );
	$keys     = (array) ( $sub['keys'] ?? array() );
	if ( ! $endpoint || empty( $keys['p256dh'] ) || empty( $keys['auth'] ) || strlen( glp_b64u_dec( $keys['p256dh'] ) ) !== 65 ) {
		return glp_err( 'Ungültiges Push-Abo.' );
	}
	$uid           = get_current_user_id();
	$subs          = array_filter( (array) get_user_meta( $uid, 'glp_push_subs', true ) );
	$subs[ md5( $endpoint ) ] = array(
		'endpoint' => $endpoint,
		'p256dh'   => sanitize_text_field( $keys['p256dh'] ),
		'auth'     => sanitize_text_field( $keys['auth'] ),
		'device'   => mb_substr( sanitize_text_field( (string) $req->get_param( 'device' ) ), 0, 60 ),
		'added'    => time(),
	);
	update_user_meta( $uid, 'glp_push_subs', $subs );
	return glp_rest_push_state();
}

function glp_rest_push_unsubscribe( WP_REST_Request $req ) {
	$uid  = get_current_user_id();
	$subs = (array) get_user_meta( $uid, 'glp_push_subs', true );
	unset( $subs[ md5( (string) $req->get_param( 'endpoint' ) ) ] );
	update_user_meta( $uid, 'glp_push_subs', array_filter( $subs ) );
	return glp_rest_push_state();
}

function glp_rest_push_prefs( WP_REST_Request $req ) {
	$uid   = get_current_user_id();
	$prefs = glp_push_prefs( $uid );
	foreach ( array_keys( glp_push_types() ) as $k ) {
		if ( null !== $req->get_param( $k ) ) {
			$prefs[ $k ] = $req->get_param( $k ) ? 1 : 0;
		}
	}
	update_user_meta( $uid, 'glp_push_prefs', $prefs );
	return glp_rest_push_state();
}

function glp_rest_push_test() {
	$ok = glp_push_user( get_current_user_id(), 'test', array( 'title' => 'GHOSTLIKE Portal', 'body' => 'Push funktioniert 🤘 So sehen Benachrichtigungen aus.', 'tag' => 'test' ), true );
	return $ok ? glp_ok( array( 'sent' => $ok ) ) : glp_err( 'Kein Gerät hat die Test-Nachricht angenommen. Bitte Push auf diesem Gerät neu aktivieren.' );
}

/* ================= ANLÄSSE ================= */

// Bandkasse: gemeldete Zahlung wartet auf Bestätigung → Empfänger
function glp_push_cash_pending( $row ) {
	$to_ids = (int) $row->to_id ? array( (int) $row->to_id ) : array_values( array_diff( array_column( glp_members(), 'id' ), array( (int) $row->from_id ) ) );
	foreach ( $to_ids as $uid ) {
		glp_push_user( (int) $uid, 'kasse', array(
			'title' => 'Bitte bestätigen: ' . glp_cash_money( (int) $row->amount ),
			'body'  => sprintf( '%s hat %s %s gezahlt%s. Ist das Geld angekommen?', glp_user_name( $row->from_id ), (int) $row->to_id ? 'dir' : 'der Bandkasse', glp_cash_money( (int) $row->amount ), $row->note !== '' ? ' (' . $row->note . ')' : '' ),
			'url'   => glp_portal_url() . '#kasse',
			'tag'   => 'kasse-' . (int) $row->id,
		) );
	}
}

// Neue Song-Version → alle anderen
function glp_push_version( $song, $v, $note = '' ) {
	$me = get_current_user_id();
	foreach ( glp_members() as $m ) {
		if ( $m['id'] === $me ) {
			continue;
		}
		glp_push_user( $m['id'], 'version', array(
			'title' => 'Neu: ' . $song['title'] . ' v' . $v,
			'body'  => glp_user_name( $me ) . ' hat eine neue Version hochgeladen' . ( $note !== '' ? ': ' . $note : '.' ),
			'url'   => glp_song_url( $song['id'] ),
			'tag'   => 'song-' . (int) $song['id'],
		) );
	}
}

// Probentag & fehlende Zusagen: stündlich prüfen, ab 9 Uhr einmal pro Tag senden
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'glp_push_hourly' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'glp_push_hourly' );
	}
} );
add_action( 'glp_push_hourly', 'glp_push_daily_run' );

function glp_push_daily_run( $force = false ) {
	$now   = new DateTimeImmutable( 'now', wp_timezone() );
	$today = $now->format( 'Y-m-d' );
	if ( ! $force && ( (int) $now->format( 'G' ) < 9 || get_option( 'glp_push_day' ) === $today ) ) {
		return;
	}
	update_option( 'glp_push_day', $today, false );

	$events  = array_values( array_filter( glp_events( 1 ), fn( $e ) => empty( $e['cancelled'] ) ) );
	$att     = (array) glp_attendance( array_column( $events, 'key' ) );
	$members = glp_members();
	$in2     = $now->modify( '+2 days' )->format( 'Y-m-d' );
	$names   = fn( $e ) => array_map( 'mb_strtolower', (array) ( $e['who'] ?? array() ) );

	foreach ( $events as $e ) {
		$day  = substr( $e['start'], 0, 10 );
		$time = substr( $e['start'], 11, 5 );
		$resp = (array) ( $att[ $e['key'] ] ?? array() );
		$for  = $names( $e );
		foreach ( $members as $m ) {
			if ( $for && ! in_array( mb_strtolower( $m['name'] ), $for, true ) ) {
				continue; // Termin nur für bestimmte Leute
			}
			$said = $resp[ $m['id'] ] ?? '';
			if ( $day === $today && $e['type'] === 'probe' && $said !== 'nein' ) {
				glp_push_user( $m['id'], 'probe', array(
					'title' => 'Heute ' . $e['title'] . ' · ' . $time,
					'body'  => $said === 'ja' ? 'Bis später! 🤘' : 'Bist du dabei? Kurz im Portal zu- oder absagen.',
					'url'   => glp_portal_url() . '#kalender',
					'tag'   => $e['key'],
				) );
			} elseif ( $day === $in2 && $said === '' ) {
				glp_push_user( $m['id'], 'probe', array(
					'title' => $e['title'] . ' am ' . wp_date( 'D, d.m.', strtotime( $day ) ) . ' · ' . $time,
					'body'  => 'Du hast noch nicht zu- oder abgesagt. Kurz eintragen?',
					'url'   => glp_portal_url() . '#kalender',
					'tag'   => $e['key'],
				) );
			}
		}
	}
}
