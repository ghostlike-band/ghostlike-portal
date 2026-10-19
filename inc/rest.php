<?php
/**
 * REST-API unter /wp-json/ghostlike-portal/v1/ – nur für eingeloggte Bandmitglieder.
 */

defined( 'ABSPATH' ) || exit;

const GLP_NS = 'ghostlike-portal/v1';

function glp_can() {
	return current_user_can( 'gl_portal' );
}

add_action( 'rest_api_init', function () {
	$r = function ( $route, $methods, $cb, $perm = 'glp_can' ) {
		register_rest_route( GLP_NS, $route, array( 'methods' => $methods, 'callback' => $cb, 'permission_callback' => $perm ) );
	};

	$r( '/state', 'GET', 'glp_rest_state' );
	$r( '/songs', 'POST', 'glp_rest_song_create' );
	$r( '/songs/(?P<id>\d+)', 'POST', 'glp_rest_song_update' );
	$r( '/songs/(?P<id>\d+)', 'DELETE', 'glp_rest_song_delete' );
	$r( '/songs/(?P<id>\d+)/step', 'POST', 'glp_rest_song_step' );
	$r( '/events', 'POST', 'glp_rest_event_create' );
	$r( '/events/(?P<id>\d+)', 'DELETE', 'glp_rest_event_delete' );
	$r( '/attendance', 'POST', 'glp_rest_attendance' );
	$r( '/probe-off', 'POST', 'glp_rest_probe_off' );
	$r( '/ical-reset', 'POST', 'glp_rest_ical_reset' );
	$r( '/profile', 'POST', 'glp_rest_profile' );
	$r( '/profile/email', 'POST', 'glp_rest_profile_email' );
	$r( '/profile/password', 'POST', 'glp_rest_profile_password' );
	// Kalender-Abo: ohne Login, geschützt über den persönlichen Schlüssel.
	$r( '/ical/(?P<user>\d+)/(?P<token>[A-Za-z0-9]{32})', 'GET', 'glp_rest_ical', '__return_true' );
} );

function glp_ok( $data = array() ) {
	return new WP_REST_Response( $data, 200 );
}

function glp_err( $msg, $code = 400 ) {
	return new WP_REST_Response( array( 'message' => $msg ), $code );
}

/* ================= STATE ================= */

function glp_rest_state() {
	$events = glp_events( 10 );
	return glp_ok( array(
		'me'         => glp_me(),
		'members'    => glp_members(),
		'groups'     => glp_groups(),
		'steps'      => glp_steps(),
		'songs'      => glp_songs(),
		'log'        => glp_log_list( 25 ),
		'events'     => $events,
		'attendance' => glp_attendance( array_column( $events, 'key' ) ),
		'ical'       => glp_ical_url( get_current_user_id() ),
		'probe'      => glp_probe_settings(),
	) );
}

/* ================= SONGS ================= */

function glp_rest_song_create( WP_REST_Request $req ) {
	global $wpdb;
	$title = sanitize_text_field( (string) $req->get_param( 'title' ) );
	if ( $title === '' ) {
		return glp_err( 'Bitte einen Songtitel eingeben.' );
	}
	$nr    = (int) $wpdb->get_var( 'SELECT MAX(nr) FROM ' . glp_table( 'songs' ) . ' WHERE album = 1' ) + 1;
	$steps = array_fill_keys( array_column( glp_steps(), 'k' ), 0 );
	$wpdb->insert( glp_table( 'songs' ), array(
		'nr'         => $nr,
		'title'      => $title,
		'album'      => 1,
		'steps'      => wp_json_encode( $steps ),
		'meta'       => '{}',
		'flags'      => '{}',
		'updated_at' => current_time( 'mysql' ),
		'updated_by' => get_current_user_id(),
	) );
	$id = (int) $wpdb->insert_id; // vor dem Log-Eintrag merken, der überschreibt insert_id
	if ( ! $id ) {
		return glp_err( 'Song konnte nicht gespeichert werden.', 500 );
	}
	glp_log( 'Neuer Song angelegt: ' . $title );
	return glp_ok( glp_song( $id ) );
}

function glp_rest_song_update( WP_REST_Request $req ) {
	global $wpdb;
	$song = glp_song( (int) $req['id'] );
	if ( ! $song ) {
		return glp_err( 'Song nicht gefunden.', 404 );
	}
	$data = array();
	if ( $req->has_param( 'title' ) ) {
		$t = sanitize_text_field( (string) $req->get_param( 'title' ) );
		if ( $t !== '' ) {
			$data['title'] = $t;
		}
	}
	foreach ( array( 'nr' => 'intval', 'album' => 'boolval', 'released' => 'boolval' ) as $k => $fn ) {
		if ( $req->has_param( $k ) ) {
			$data[ $k ] = (int) $fn( $req->get_param( $k ) );
		}
	}
	if ( is_array( $req->get_param( 'meta' ) ) ) {
		$meta = (array) $song['meta'];
		foreach ( array( 'bpm', 'key', 'tuning', 'link', 'note' ) as $k ) {
			$v = $req->get_param( 'meta' )[ $k ] ?? null;
			if ( $v !== null ) {
				$meta[ $k ] = $k === 'link' ? esc_url_raw( $v ) : ( $k === 'note' ? sanitize_textarea_field( $v ) : sanitize_text_field( $v ) );
			}
		}
		$data['meta'] = wp_json_encode( (object) $meta );
	}
	if ( is_array( $req->get_param( 'flags' ) ) ) {
		$flags = (array) $song['flags'];
		foreach ( array( 'single', 'video' ) as $k ) {
			if ( isset( $req->get_param( 'flags' )[ $k ] ) ) {
				$flags[ $k ] = (bool) $req->get_param( 'flags' )[ $k ];
			}
		}
		$data['flags'] = wp_json_encode( (object) $flags );
	}
	if ( ! $data ) {
		return glp_ok( $song );
	}
	$data['updated_at'] = current_time( 'mysql' );
	$data['updated_by'] = get_current_user_id();
	$wpdb->update( glp_table( 'songs' ), $data, array( 'id' => $song['id'] ) );
	if ( isset( $data['title'] ) && $data['title'] !== $song['title'] ) {
		glp_log( 'Song umbenannt: ' . $song['title'] . ' → ' . $data['title'] );
	}
	return glp_ok( glp_song( $song['id'] ) );
}

function glp_rest_song_delete( WP_REST_Request $req ) {
	global $wpdb;
	$song = glp_song( (int) $req['id'] );
	if ( ! $song ) {
		return glp_err( 'Song nicht gefunden.', 404 );
	}
	$wpdb->delete( glp_table( 'songs' ), array( 'id' => $song['id'] ) );
	glp_log( 'Song gelöscht: ' . $song['title'] );
	return glp_ok( array( 'deleted' => true ) );
}

function glp_rest_song_step( WP_REST_Request $req ) {
	global $wpdb;
	$id    = (int) $req['id'];
	$key   = sanitize_key( (string) $req->get_param( 'step' ) );
	$value = (int) $req->get_param( 'value' );
	$step  = null;
	foreach ( glp_steps() as $s ) {
		if ( $s['k'] === $key ) {
			$step = $s;
		}
	}
	if ( ! $step || ! in_array( $value, glp_step_values( $step['t'] ), true ) ) {
		return glp_err( 'Ungültiger Schritt oder Status.' );
	}

	if ( ! glp_song( $id ) ) {
		return glp_err( 'Song nicht gefunden.', 404 );
	}
	// Nur diesen einen Schritt in der Datenbank ändern (JSON_SET), damit sich
	// zwei gleichzeitige Klicks auf verschiedene Felder nicht überschreiben.
	$quiet = $wpdb->suppress_errors( true );
	$done  = $wpdb->query( $wpdb->prepare(
		'UPDATE ' . glp_table( 'songs' ) . " SET steps = JSON_SET(COALESCE(NULLIF(steps, ''), '{}'), %s, %d), updated_at = %s, updated_by = %d WHERE id = %d",
		'$.' . $key, $value, current_time( 'mysql' ), get_current_user_id(), $id
	) );
	$wpdb->suppress_errors( $quiet );
	if ( $done === false ) {
		// Fallback für Datenbanken ohne JSON-Funktionen.
		$steps         = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT steps FROM ' . glp_table( 'songs' ) . ' WHERE id = %d', $id ) ), true ) ?: array();
		$steps[ $key ] = $value;
		$wpdb->update( glp_table( 'songs' ), array( 'steps' => wp_json_encode( $steps ), 'updated_at' => current_time( 'mysql' ), 'updated_by' => get_current_user_id() ), array( 'id' => $id ) );
	}

	$song   = glp_song( $id );
	$labels = array( 'done' => array( 0 => 'offen', 2 => 'fertig' ), 'rec' => array( 0 => 'offen', 1 => 'aufgenommen', 2 => 'editiert' ), 'post' => array( 0 => 'offen', 1 => 'in Arbeit', 2 => 'fertig' ) );
	$label  = $value === -1 ? 'nicht nötig' : $labels[ $step['t'] ][ $value ];
	glp_log( $step['l'] . ' · ' . $song['title'] . ' → ' . $label );
	return glp_ok( $song );
}

/* ================= TERMINE ================= */

function glp_rest_event_create( WP_REST_Request $req ) {
	global $wpdb;
	$type  = sanitize_key( (string) $req->get_param( 'type' ) );
	$type  = in_array( $type, array( 'studio', 'konzert', 'termin' ), true ) ? $type : 'termin';
	$title = sanitize_text_field( (string) $req->get_param( 'title' ) );
	$date  = (string) $req->get_param( 'date' );
	$time  = (string) $req->get_param( 'time' );
	if ( $title === '' || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return glp_err( 'Bitte Titel und Datum angeben.' );
	}
	if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
		$time = '19:00';
	}
	$who = array_values( array_filter( array_map( 'sanitize_text_field', (array) $req->get_param( 'who' ) ) ) );
	$wpdb->insert( glp_table( 'events' ), array(
		'type'       => $type,
		'title'      => $title,
		'place'      => sanitize_text_field( (string) $req->get_param( 'place' ) ),
		'starts_at'  => $date . ' ' . $time . ':00',
		'who'        => wp_json_encode( $who ),
		'note'       => sanitize_textarea_field( (string) $req->get_param( 'note' ) ),
		'created_by' => get_current_user_id(),
	) );
	$id = (int) $wpdb->insert_id; // vor dem Log-Eintrag merken
	if ( ! $id ) {
		return glp_err( 'Termin konnte nicht gespeichert werden.', 500 );
	}
	glp_log( 'Neuer Termin: ' . $title . ' am ' . wp_date( 'd.m.', strtotime( $date ) ) );
	return glp_ok( array( 'id' => $id ) );
}

function glp_rest_event_delete( WP_REST_Request $req ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . glp_table( 'events' ) . ' WHERE id = %d', (int) $req['id'] ) );
	if ( ! $row ) {
		return glp_err( 'Termin nicht gefunden.', 404 );
	}
	$wpdb->delete( glp_table( 'events' ), array( 'id' => $row->id ) );
	$wpdb->delete( glp_table( 'attendance' ), array( 'event_key' => 'ev-' . $row->id ) );
	glp_log( 'Termin gelöscht: ' . $row->title );
	return glp_ok( array( 'deleted' => true ) );
}

function glp_rest_attendance( WP_REST_Request $req ) {
	global $wpdb;
	$key    = (string) $req->get_param( 'key' );
	$status = (string) $req->get_param( 'status' );
	if ( ! preg_match( '/^(probe-\d{4}-\d{2}-\d{2}|ev-\d+)$/', $key ) || ! in_array( $status, array( 'ja', 'nein', '' ), true ) ) {
		return glp_err( 'Ungültige Angabe.' );
	}
	$uid = get_current_user_id();
	if ( $status === '' ) {
		$wpdb->delete( glp_table( 'attendance' ), array( 'event_key' => $key, 'user_id' => $uid ) );
	} else {
		$wpdb->replace( glp_table( 'attendance' ), array( 'event_key' => $key, 'user_id' => $uid, 'status' => $status, 'updated_at' => current_time( 'mysql' ) ) );
	}
	return glp_ok( array( 'ok' => true ) );
}

function glp_rest_probe_off( WP_REST_Request $req ) {
	$date = (string) $req->get_param( 'date' );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return glp_err( 'Ungültiges Datum.' );
	}
	$off = (array) get_option( 'glp_probe_off', array() );
	$on  = (bool) $req->get_param( 'off' );
	$off = array_values( array_unique( $on ? array_merge( $off, array( $date ) ) : array_diff( $off, array( $date ) ) ) );
	// Alte Daten aufräumen
	$off = array_values( array_filter( $off, fn( $d ) => $d >= wp_date( 'Y-m-d', strtotime( '-60 days' ) ) ) );
	update_option( 'glp_probe_off', $off, false );
	glp_log( 'Probe am ' . wp_date( 'd.m.', strtotime( $date ) ) . ( $on ? ' fällt aus' : ' findet wieder statt' ) );
	return glp_ok( array( 'ok' => true ) );
}

function glp_rest_ical_reset() {
	glp_ical_token( get_current_user_id(), true );
	return glp_ok( array( 'ical' => glp_ical_url( get_current_user_id() ) ) );
}

/* ================= iCAL-ABO ================= */

function glp_rest_ical( WP_REST_Request $req ) {
	$uid   = (int) $req['user'];
	$token = (string) $req['token'];
	$saved = get_user_meta( $uid, 'glp_ical_token', true );
	if ( ! $saved || ! hash_equals( $saved, $token ) || ! user_can( $uid, 'gl_portal' ) ) {
		return glp_err( 'Ungültiger Kalender-Link.', 403 );
	}
	$esc = fn( $s ) => str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\;', '\,', '\n' ), (string) $s );
	$from = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-14 days' );
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	// ?event=probe-2026-10-20 → nur dieser eine Termin (Button „+ In meinen Kalender“), mit Erinnerung
	$only   = preg_match( '/^(probe-\d{4}-\d{2}-\d{2}|ev-\d+)$/', (string) $req->get_param( 'event' ) ) ? (string) $req->get_param( 'event' ) : '';
	$events = glp_events( 26, $from );
	if ( $only ) {
		$events = array_values( array_filter( $events, fn( $e ) => $e['key'] === $only ) );
		if ( ! $events ) {
			return glp_err( 'Termin nicht gefunden.', 404 );
		}
	}
	$out  = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//GHOSTLIKE//Portal//DE', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH' );
	if ( ! $only ) {
		$out[] = 'X-WR-CALNAME:GHOSTLIKE';
		$out[] = 'X-WR-TIMEZONE:' . wp_timezone_string();
		$out[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT6H';
		$out[] = 'X-PUBLISHED-TTL:PT6H';
	}
	// Zu- und Absagen für die Beschreibung
	$att     = (array) glp_attendance( array_column( $events, 'key' ) );
	$members = glp_members();
	$describe = function ( $e ) use ( $att, $members, $uid ) {
		$a     = (array) ( $att[ $e['key'] ] ?? array() );
		$ja    = array();
		$nein  = array();
		$offen = array();
		foreach ( $members as $m ) {
			$st = $a[ $m['id'] ] ?? '';
			if ( $st === 'ja' ) {
				$ja[] = $m['name'];
			} elseif ( $st === 'nein' ) {
				$nein[] = $m['name'];
			} else {
				$offen[] = $m['name'];
			}
		}
		$lines = array();
		if ( $e['cancelled'] ) {
			$lines[] = 'Fällt aus.';
		}
		if ( ! empty( $e['who'] ) ) {
			$lines[] = 'Eingeplant: ' . implode( ', ', $e['who'] );
		}
		$lines[] = 'Dabei: ' . ( $ja ? implode( ', ', $ja ) : '–' );
		$lines[] = 'Nicht dabei: ' . ( $nein ? implode( ', ', $nein ) : '–' );
		$lines[] = 'Noch offen: ' . ( $offen ? implode( ', ', $offen ) : '–' );
		if ( ! empty( $e['note'] ) ) {
			$lines[] = '';
			$lines[] = $e['note'];
		}
		$lines[] = '';
		if ( ! $e['cancelled'] ) {
			$lines[] = '✓ Ich bin dabei: ' . glp_rsvp_url( $uid, $e['key'], 'ja' );
			$lines[] = '✕ Ich kann nicht: ' . glp_rsvp_url( $uid, $e['key'], 'nein' );
			$lines[] = '';
		}
		$lines[] = 'Portal: ' . glp_portal_url() . '#kalender';
		return implode( "\n", $lines );
	};

	foreach ( $events as $e ) {
		$start = new DateTimeImmutable( $e['start'] );
		$hours = $e['type'] === 'probe' ? 3 : ( $e['type'] === 'konzert' ? 5 : 4 );
		$end   = $start->modify( '+' . $hours . ' hours' );
		$utc   = new DateTimeZone( 'UTC' );
		$out[] = 'BEGIN:VEVENT';
		$out[] = 'UID:' . $e['key'] . '@' . $host;
		$out[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
		$out[] = 'DTSTART:' . $start->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		$out[] = 'DTEND:' . $end->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		$out[] = 'SUMMARY:' . $esc( ( $e['cancelled'] ? 'ABGESAGT: ' : '' ) . 'GHOSTLIKE · ' . $e['title'] );
		if ( $e['place'] ) {
			$out[] = 'LOCATION:' . $esc( $e['place'] );
		}
		if ( $e['cancelled'] ) {
			$out[] = 'STATUS:CANCELLED';
		}
		$out[] = 'DESCRIPTION:' . $esc( $describe( $e ) );
		$out[] = 'LAST-MODIFIED:' . gmdate( 'Ymd\THis\Z' );
		$out[] = 'URL:' . glp_portal_url();
		if ( $only ) {
			$out = array_merge( $out, array( 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . $esc( 'GHOSTLIKE · ' . $e['title'] ), 'TRIGGER:-PT2H', 'END:VALARM' ) );
		}
		$out[] = 'END:VEVENT';
	}
	$out[] = 'END:VCALENDAR';

	nocache_headers();
	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: inline; filename="' . ( $only ? 'ghostlike-' . $only : 'ghostlike' ) . '.ics"' );
	$fold = function ( $line ) {
		$res = '';
		$cur = '';
		foreach ( preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			if ( strlen( $cur . $ch ) > ( $res === '' ? 75 : 74 ) ) {
				$res .= ( $res === '' ? '' : "\r\n " ) . $cur;
				$cur  = '';
			}
			$cur .= $ch;
		}
		return $res === '' ? $cur : $res . "\r\n " . $cur;
	};
	echo implode( "\r\n", array_map( $fold, $out ) ) . "\r\n"; // phpcs:ignore
	exit;
}

/* ================= PROFIL ================= */

function glp_me() {
	$u = wp_get_current_user();
	return array(
		'id'       => (int) $u->ID,
		'name'     => glp_user_name( $u->ID ),
		'first'    => (string) $u->first_name,
		'last'     => (string) $u->last_name,
		'role'     => (string) get_user_meta( $u->ID, 'glp_role_text', true ),
		'email'    => (string) $u->user_email,
		'login'    => (string) $u->user_login,
		'admin'    => current_user_can( 'manage_options' ),
	);
}

function glp_rest_profile( WP_REST_Request $req ) {
	$u     = wp_get_current_user();
	$first = sanitize_text_field( (string) $req->get_param( 'first' ) );
	$last  = sanitize_text_field( (string) $req->get_param( 'last' ) );
	$role  = mb_substr( sanitize_text_field( (string) $req->get_param( 'role' ) ), 0, 80 );
	if ( $first === '' ) {
		return glp_err( 'Bitte einen Vornamen angeben.' );
	}
	$old = glp_user_name( $u->ID );
	wp_update_user( array( 'ID' => $u->ID, 'first_name' => $first, 'last_name' => $last, 'display_name' => trim( $first . ' ' . $last ) ) );
	update_user_meta( $u->ID, 'glp_role_text', $role );

	// Zuständigkeiten hängen am Vornamen: alten Namen in den Arbeitsschritten ersetzen
	if ( strcasecmp( $old, $first ) !== 0 ) {
		$steps   = glp_steps();
		$changed = false;
		foreach ( $steps as &$st ) {
			foreach ( (array) $st['who'] as $i => $w ) {
				if ( strcasecmp( $w, $old ) === 0 ) {
					$st['who'][ $i ] = $first;
					$changed         = true;
				}
			}
		}
		unset( $st );
		if ( $changed ) {
			update_option( 'glp_steps', $steps );
		}
		// … und in eigenen Terminen („Wer ist dabei?“)
		global $wpdb;
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, who FROM ' . glp_table( 'events' ) . ' WHERE who LIKE %s', '%' . $wpdb->esc_like( $old ) . '%' ) ) as $ev ) {
			$who = array_map( fn( $w ) => strcasecmp( $w, $old ) === 0 ? $first : $w, (array) json_decode( (string) $ev->who, true ) );
			$wpdb->update( glp_table( 'events' ), array( 'who' => wp_json_encode( $who ) ), array( 'id' => $ev->id ) );
		}
		glp_log( $old . ' heißt jetzt ' . $first );
	}
	return glp_ok( glp_me() );
}

function glp_rest_profile_email( WP_REST_Request $req ) {
	$u     = wp_get_current_user();
	$email = sanitize_email( (string) $req->get_param( 'email' ) );
	$pass  = (string) $req->get_param( 'password' );
	if ( ! wp_check_password( $pass, $u->user_pass, $u->ID ) ) {
		return glp_err( 'Das aktuelle Passwort stimmt nicht.' ); // kein 403: das Portal würde sonst „Sitzung abgelaufen“ melden
	}
	if ( ! is_email( $email ) ) {
		return glp_err( 'Bitte eine gültige E-Mail-Adresse angeben.' );
	}
	$other = email_exists( $email );
	if ( $other && (int) $other !== (int) $u->ID ) {
		return glp_err( 'Diese E-Mail-Adresse wird schon von einem anderen Zugang benutzt.' );
	}
	$res = wp_update_user( array( 'ID' => $u->ID, 'user_email' => $email ) ); // schickt eine Info an die alte Adresse
	if ( is_wp_error( $res ) ) {
		return glp_err( $res->get_error_message() );
	}
	return glp_ok( glp_me() );
}

function glp_rest_profile_password( WP_REST_Request $req ) {
	$u   = wp_get_current_user();
	$cur = (string) $req->get_param( 'current' );
	$new = (string) $req->get_param( 'new' );
	if ( ! wp_check_password( $cur, $u->user_pass, $u->ID ) ) {
		return glp_err( 'Das aktuelle Passwort stimmt nicht.' ); // kein 403: das Portal würde sonst „Sitzung abgelaufen“ melden
	}
	if ( strlen( $new ) < 10 ) {
		return glp_err( 'Das neue Passwort braucht mindestens 10 Zeichen.' );
	}
	// setzt auch das Login-Cookie neu und schickt die Bestätigungs-Mail
	$res = wp_update_user( array( 'ID' => $u->ID, 'user_pass' => $new ) );
	if ( is_wp_error( $res ) ) {
		return glp_err( $res->get_error_message() );
	}
	return glp_ok( array( 'ok' => true ) );
}
