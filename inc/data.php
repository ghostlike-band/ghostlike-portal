<?php
/**
 * Daten: Arbeitsschritte, Mitglieder, Songs, Log, Termine, Zusagen.
 */

defined( 'ABSPATH' ) || exit;

/* ================= ARBEITSSCHRITTE ================= */

function glp_groups() {
	return array( 'pre' => 'Pre-Production', 'rec' => 'Instrumente', 'voc' => 'Vocals', 'post' => 'Mix & Master' );
}

/**
 * Statustypen und Werte:
 *   done = offen (0) / in Arbeit (3) / fertig (2)
 *   rec  = offen (0) / in Arbeit (3) / aufgenommen (1) / editiert (2)
 *   post = offen (0) / in Arbeit (1) / fertig (2)
 *   -1   = nicht nötig
 */
function glp_step_types() {
	return array( 'done' => 'offen → in Arbeit → fertig', 'rec' => 'offen → in Arbeit → aufgenommen → editiert', 'post' => 'offen → in Arbeit → fertig' );
}

function glp_default_steps() {
	return array(
		array( 'k' => 'geschrieben', 'l' => 'Geschrieben', 'g' => 'pre', 'w' => 1, 't' => 'done', 'who' => array( 'Robin' ) ),
		array( 'k' => 'struktur', 'l' => 'Struktur', 'g' => 'pre', 'w' => 1, 't' => 'done', 'who' => array( 'Robin' ) ),
		array( 'k' => 'lines', 'l' => 'Vocal-Lines', 'g' => 'pre', 'w' => 1, 't' => 'done', 'who' => array( 'Robin' ) ),
		array( 'k' => 'drums', 'l' => 'Drums', 'g' => 'rec', 'w' => 3, 't' => 'rec', 'who' => array( 'Valentin' ) ),
		array( 'k' => 'bass', 'l' => 'Bass', 'g' => 'rec', 'w' => 2, 't' => 'rec', 'who' => array( 'Alexander' ) ),
		array( 'k' => 'rhythm', 'l' => 'Rhythm', 'g' => 'rec', 'w' => 3, 't' => 'rec', 'who' => array( 'Andreas', 'Silvan' ) ),
		array( 'k' => 'lead', 'l' => 'Lead', 'g' => 'rec', 'w' => 2, 't' => 'rec', 'who' => array( 'Silvan', 'Andreas' ) ),
		array( 'k' => 'synth', 'l' => 'Synths', 'g' => 'rec', 'w' => 1, 't' => 'rec', 'who' => array( 'Robin' ) ),
		array( 'k' => 'sfx', 'l' => 'SFX', 'g' => 'rec', 'w' => 1, 't' => 'rec', 'who' => array( 'Robin' ) ),
		array( 'k' => 'screams', 'l' => 'Screams', 'g' => 'voc', 'w' => 2, 't' => 'rec', 'who' => array( 'Robin' ) ),
		array( 'k' => 'cleans', 'l' => 'Cleans', 'g' => 'voc', 'w' => 2, 't' => 'rec', 'who' => array( 'Robin' ) ),
		array( 'k' => 'backing', 'l' => 'Backings', 'g' => 'voc', 'w' => 1, 't' => 'rec', 'who' => array( 'Andreas', 'Robin' ) ),
		array( 'k' => 'mix', 'l' => 'Mix', 'g' => 'post', 'w' => 3, 't' => 'post', 'who' => array( 'Alexander' ) ),
		array( 'k' => 'master', 'l' => 'Master', 'g' => 'post', 'w' => 1, 't' => 'post', 'who' => array() ),
	);
}

function glp_steps() {
	$s = get_option( 'glp_steps' );
	return is_array( $s ) && $s ? $s : glp_default_steps();
}

function glp_step_values( $type ) {
	if ( $type === 'done' ) {
		return array( 0, 3, 2, -1 );
	}
	return $type === 'rec' ? array( 0, 3, 1, 2, -1 ) : array( 0, 1, 2, -1 );
}

function glp_step_label( $type, $value ) {
	$labels = array(
		'done' => array( 0 => 'offen', 3 => 'in Arbeit', 2 => 'fertig' ),
		'rec'  => array( 0 => 'offen', 3 => 'in Arbeit', 1 => 'aufgenommen', 2 => 'editiert' ),
		'post' => array( 0 => 'offen', 1 => 'in Arbeit', 2 => 'fertig' ),
	);
	return $value === -1 ? 'nicht nötig' : ( $labels[ $type ][ $value ] ?? 'offen' );
}

/** Release-Checkliste für fertige Songs. t: check | text | date */
function glp_release_items() {
	return array(
		array( 'k' => 'master', 'l' => 'Master final abgenommen', 't' => 'check' ),
		array( 'k' => 'cover', 'l' => 'Cover-Artwork fertig', 't' => 'check' ),
		array( 'k' => 'credits', 'l' => 'Credits & Songwriter erfasst', 't' => 'check' ),
		array( 'k' => 'gema', 'l' => 'Bei der GEMA angemeldet', 't' => 'check' ),
		array( 'k' => 'isrc', 'l' => 'ISRC-Code', 't' => 'text', 'ph' => 'z. B. DE-A12-26-00001' ),
		array( 'k' => 'lyrics', 'l' => 'Lyrics bei Musixmatch hinterlegt', 't' => 'check' ),
		array( 'k' => 'dist', 'l' => 'Beim Vertrieb hochgeladen', 't' => 'check' ),
		array( 'k' => 'pitch', 'l' => 'Spotify-Pitch abgeschickt (min. 7 Tage vorher)', 't' => 'check' ),
		array( 'k' => 'date', 'l' => 'Release-Datum', 't' => 'date' ),
	);
}

/** Geplante Schritte einer Session säubern: [{s: Song-ID, k: Schritt}] */
function glp_clean_plan( $plan ) {
	$keys = array_column( glp_steps(), 'k' );
	$out  = array();
	foreach ( (array) $plan as $p ) {
		$sid = (int) ( $p['s'] ?? 0 );
		$k   = sanitize_key( (string) ( $p['k'] ?? '' ) );
		if ( $sid && in_array( $k, $keys, true ) && glp_song( $sid ) ) {
			$out[ $sid . '|' . $k ] = array( 's' => $sid, 'k' => $k );
		}
	}
	return array_slice( array_values( $out ), 0, 80 );
}

/* ================= MITGLIEDER ================= */

function glp_members() {
	$users = get_users( array( 'capability' => 'gl_portal', 'orderby' => 'registered', 'order' => 'ASC' ) );
	$out   = array();
	foreach ( $users as $u ) {
		$name  = trim( $u->first_name ) ?: $u->display_name;
		$out[] = array( 'id' => (int) $u->ID, 'name' => $name, 'role' => (string) get_user_meta( $u->ID, 'glp_role_text', true ) );
	}
	return $out;
}

function glp_user_name( $user_id ) {
	$u = get_userdata( $user_id );
	return $u ? ( trim( $u->first_name ) ?: $u->display_name ) : 'System';
}

/* ================= SONGS ================= */

function glp_song_row( $r ) {
	return array(
		'id'       => (int) $r->id,
		'nr'       => (int) $r->nr,
		'title'    => $r->title,
		'album'    => (bool) $r->album,
		'released' => (bool) $r->released,
		'st'       => (object) ( json_decode( (string) $r->steps, true ) ?: array() ),
		'meta'     => (object) ( json_decode( (string) $r->meta, true ) ?: array() ),
		'flags'    => (object) ( json_decode( (string) $r->flags, true ) ?: array() ),
		'updated'  => $r->updated_at,
	);
}

function glp_songs() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT * FROM ' . glp_table( 'songs' ) . ' ORDER BY album DESC, nr ASC, id ASC' );
	return array_map( 'glp_song_row', $rows );
}

function glp_song( $id ) {
	global $wpdb;
	$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . glp_table( 'songs' ) . ' WHERE id = %d', $id ) );
	return $r ? glp_song_row( $r ) : null;
}

/* ================= LOG ================= */

function glp_log( $message, $user_id = null ) {
	global $wpdb;
	$wpdb->insert( glp_table( 'log' ), array(
		'user_id'    => $user_id === null ? get_current_user_id() : (int) $user_id,
		'message'    => mb_substr( $message, 0, 250 ),
		'created_at' => current_time( 'mysql', true ),
	) );
}

function glp_log_list( $limit = 20 ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . glp_table( 'log' ) . ' ORDER BY id DESC LIMIT %d', $limit ) );
	return array_map( fn( $r ) => array(
		'who' => $r->user_id ? glp_user_name( $r->user_id ) : 'System',
		'txt' => $r->message,
		't'   => strtotime( $r->created_at . ' UTC' ) * 1000,
	), $rows );
}

/* ================= TERMINE ================= */

function glp_probe_settings() {
	return wp_parse_args( (array) get_option( 'glp_probe', array() ), array(
		'active'  => 1,
		'weekday' => 2,        // 0 = Sonntag … 2 = Dienstag
		'time'    => '19:30',
		'place'   => 'Proberaum',
	) );
}

/**
 * Änderungsstand je Termin für das Kalender-Abo: [key => [seq, zeit]].
 * SEQUENCE + LAST-MODIFIED sagen iPhone, Google & Co., dass sich ein Termin geändert hat.
 */
function glp_touch_event( $key ) {
	$rev         = (array) get_option( 'glp_event_rev', array() );
	$rev[ $key ] = array( (int) ( $rev[ $key ][0] ?? 0 ) + 1, time() );
	if ( count( $rev ) > 400 ) {
		uasort( $rev, fn( $a, $b ) => $b[1] <=> $a[1] );
		$rev = array_slice( $rev, 0, 300, true );
	}
	update_option( 'glp_event_rev', $rev, false );
}

function glp_event_rev( $key ) {
	$rev = (array) get_option( 'glp_event_rev', array() );
	return $rev[ $key ] ?? array( 0, 0 );
}

/** Terminarten: Schlüssel => Name */
function glp_event_types() {
	return array( 'probe' => 'Bandprobe', 'treffen' => 'Treffen / Meeting', 'studio' => 'Studio-Session', 'konzert' => 'Konzert', 'termin' => 'Termin' );
}

function glp_type_label( $type ) {
	$t = glp_event_types();
	return $t[ $type ] ?? 'Termin';
}

/** Alle Termine von heute bis in $weeks Wochen: wöchentliche Proben + eigene Termine. */
function glp_events( $weeks = 10, $from = null ) {
	global $wpdb;
	$tz    = wp_timezone();
	$now   = $from ?: new DateTimeImmutable( 'today', $tz );
	$until = $now->modify( '+' . (int) $weeks . ' weeks' );
	$out   = array();

	$p   = glp_probe_settings();
	$off = (array) get_option( 'glp_probe_off', array() );
	$ovr = (array) get_option( 'glp_probe_override', array() ); // einzelne Proben geändert: [Datum => Felder]
	if ( $p['active'] ) {
		$d = $now;
		while ( (int) $d->format( 'w' ) !== (int) $p['weekday'] ) {
			$d = $d->modify( '+1 day' );
		}
		[ $h, $m ] = array_map( 'intval', explode( ':', $p['time'] . ':0' ) );
		for ( ; $d < $until; $d = $d->modify( '+7 days' ) ) {
			$start = $d->setTime( $h, $m );
			$date  = $d->format( 'Y-m-d' );
			$ev    = array(
				'key'       => 'probe-' . $date,
				'type'      => 'probe',
				'title'     => 'Bandprobe',
				'place'     => $p['place'] . ' · ' . $p['time'],
				'start'     => $start->format( DATE_ATOM ),
				'cancelled' => in_array( $date, $off, true ),
				'who'       => array(),
				'recurring' => true,
				'changed'   => false,
			);
			if ( ! empty( $ovr[ $date ] ) && is_array( $ovr[ $date ] ) ) {
				$o = $ovr[ $date ];
				[ $oh, $om ] = array_map( 'intval', explode( ':', ( $o['time'] ?? $p['time'] ) . ':0' ) );
				$ev = array_merge( $ev, array(
					'type'    => $o['type'] ?? 'probe',
					'title'   => ( $o['title'] ?? '' ) !== '' ? $o['title'] : glp_type_label( $o['type'] ?? 'probe' ),
					'place'   => ( $o['place'] ?? '' ) !== '' ? $o['place'] : $p['place'],
					'start'   => $d->setTime( $oh, $om )->format( DATE_ATOM ),
					'who'     => (array) ( $o['who'] ?? array() ),
					'note'    => (string) ( $o['note'] ?? '' ),
					'changed' => true,
				) );
			}
			$out[] = $ev;
		}
	}

	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . glp_table( 'events' ) . ' WHERE starts_at >= %s AND starts_at < %s ORDER BY starts_at ASC',
		$now->format( 'Y-m-d H:i:s' ),
		$until->format( 'Y-m-d H:i:s' )
	) );
	foreach ( $rows as $r ) {
		$out[] = array(
			'key'       => 'ev-' . $r->id,
			'id'        => (int) $r->id,
			'type'      => $r->type,
			'title'     => $r->title,
			'place'     => $r->place,
			'start'     => ( new DateTimeImmutable( $r->starts_at, $tz ) )->format( DATE_ATOM ),
			'cancelled' => false,
			'who'       => json_decode( (string) $r->who, true ) ?: array(),
			'note'      => (string) $r->note,
			'plan'      => json_decode( (string) $r->plan, true ) ?: array(),
		);
	}
	usort( $out, fn( $a, $b ) => strcmp( $a['start'], $b['start'] ) );
	return $out;
}

function glp_attendance( $keys ) {
	global $wpdb;
	if ( ! $keys ) {
		return (object) array();
	}
	$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_key, user_id, status FROM ' . glp_table( 'attendance' ) . " WHERE event_key IN ($in)", $keys ) );
	$out  = array();
	foreach ( $rows as $r ) {
		$out[ $r->event_key ][ (int) $r->user_id ] = $r->status;
	}
	return (object) $out;
}

/** Persönlicher Kalender-Abo-Schlüssel. */
function glp_ical_token( $user_id, $reset = false ) {
	$t = get_user_meta( $user_id, 'glp_ical_token', true );
	if ( ! $t || $reset ) {
		$t = wp_generate_password( 32, false );
		update_user_meta( $user_id, 'glp_ical_token', $t );
	}
	return $t;
}

function glp_ical_url( $user_id ) {
	return rest_url( 'ghostlike-portal/v1/ical/' . (int) $user_id . '/' . glp_ical_token( $user_id ) );
}

/** Persönlicher Ein-Tipp-Link zum Zu-/Absagen (z. B. aus dem Handy-Kalender). */
function glp_rsvp_url( $user_id, $key, $status ) {
	return add_query_arg( 'zusage', (int) $user_id . '.' . glp_ical_token( $user_id ) . '.' . $key . '.' . $status, glp_portal_url() );
}
