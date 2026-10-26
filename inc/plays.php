<?php
/**
 * Plays: Streaming-Zahlen pro Song (gleiche ISRC = gleicher Song, egal über welchen Vertrieb).
 * Quellen, alle über den Sync-Button (Lesezeichen) im eingeloggten Browser, per postMessage ans Portal:
 *  - DistroKid: Spotify täglich (service 'spotify'), Apple Music monatlich (service 'apple', Wert am 1.)
 *  - iMusician: alle Shops täglich (service 'im:<shop>'), ca. 6 Monate rückwirkend
 *  - Spotify-Künstlerseite: Gesamt-Plays pro Song laut Spotify (Option glp_plays_spotify_all)
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	register_rest_route( GLP_NS, '/plays', array( 'methods' => 'GET', 'callback' => 'glp_rest_plays', 'permission_callback' => 'glp_can' ) );
	register_rest_route( GLP_NS, '/plays/import', array( 'methods' => 'POST', 'callback' => 'glp_rest_plays_import', 'permission_callback' => fn() => current_user_can( 'manage_options' ) ) ); // nur der Admin holt Zahlen
} );

/** Releases mit mehreren Songs (per ISRC). Bei neuen EPs/Alben hier ergänzen. */
function glp_plays_releases() {
	return array(
		array(
			'title' => 'The Quiet Collapse',
			'type'  => 'EP',
			'date'  => '2026-01-16',
			'cover' => 16, // Mediathek: Cover – The Quiet Collapse
			'isrcs' => array( 'CH6542570736', 'CH6542570739', 'CH6542570737', 'CH6542570742', 'CH6542570741' ),
		),
	);
}

/** Cover pro Song (Mediathek-ID). Songs ohne eigenes Cover bekommen das Cover ihrer EP. */
function glp_plays_covers() {
	return array(
		'CH6542570736' => 17, // Nothing's Okay – Single-Cover Hand
		'CH6542570737' => 19, // Deep Blue Sea – Single-Cover Eis
		'CH6542570739' => 18, // The Price We Pay – Single-Cover Dornen
	);
}

function glp_plays_cover_url( $id ) {
	return $id ? ( wp_get_attachment_image_url( (int) $id, 'thumbnail' ) ?: '' ) : '';
}

function glp_plays_norm( $title ) {
	return preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( (string) $title ) ) );
}

function glp_rest_plays_import( WP_REST_Request $req ) {
	global $wpdb;
	$source = sanitize_key( (string) ( $req->get_param( 'source' ) ?: 'distrokid' ) );
	$titles = (array) get_option( 'glp_plays_tracks', array() );
	$synced = (array) get_option( 'glp_plays_synced', array() );

	// Spotify-Künstlerseite: nur Gesamtzahlen pro Titel
	if ( $source === 'spotify' ) {
		$all = array();
		foreach ( (array) $req->get_param( 'items' ) as $it ) {
			$k = glp_plays_norm( $it['title'] ?? '' );
			if ( $k !== '' && isset( $it['plays'] ) && is_numeric( $it['plays'] ) ) {
				$all[ $k ] = array( 'title' => mb_substr( sanitize_text_field( $it['title'] ), 0, 120 ), 'plays' => (int) $it['plays'] );
			}
		}
		if ( ! $all ) {
			return glp_err( 'Keine Play-Zahlen auf der Spotify-Seite gefunden.' );
		}
		update_option( 'glp_plays_spotify_all', array( 'items' => $all, 'at' => time() ), false );
		$synced['spotify'] = array( 'at' => time(), 'by' => get_current_user_id() );
		update_option( 'glp_plays_synced', $synced, false );
		glp_log( 'Plays: Gesamtzahlen von Spotify übernommen (' . count( $all ) . ' Songs)' );
		return glp_ok( array( 'tracks' => count( $all ), 'values' => count( $all ) ) );
	}

	$rows = array();
	$n    = 0;
	foreach ( (array) $req->get_param( 'tracks' ) as $t ) {
		$isrc = strtoupper( (string) ( $t['isrc'] ?? '' ) );
		if ( ! preg_match( '/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/', $isrc ) ) {
			continue;
		}
		$titles[ $isrc ] = mb_substr( sanitize_text_field( (string) ( $t['title'] ?? $isrc ) ), 0, 120 );
		$series          = array();
		if ( $source === 'imusician' ) {
			foreach ( (array) ( $t['shops'] ?? array() ) as $shop => $days ) {
				$shop = substr( preg_replace( '/[^a-z]/', '', strtolower( (string) $shop ) ), 0, 9 );
				if ( $shop !== '' ) {
					$series[ 'im:' . $shop ] = (array) $days;
				}
			}
		} else {
			$series['spotify'] = (array) ( $t['spotify'] ?? array() );
			$apple             = array();
			foreach ( (array) ( $t['apple'] ?? array() ) as $month => $plays ) {
				if ( preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ) {
					$apple[ $month . '-01' ] = $plays;
				}
			}
			$series['apple'] = $apple;
		}
		foreach ( $series as $service => $days ) {
			foreach ( $days as $day => $plays ) {
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day ) ) {
					$rows[] = $wpdb->prepare( '(%s,%s,%s,%d)', $isrc, $service, $day, max( 0, (int) $plays ) );
				}
			}
		}
		$n++;
	}
	if ( ! $n ) {
		return glp_err( 'Keine Ghostlike-Tracks in den Daten gefunden.' );
	}
	foreach ( array_chunk( $rows, 500 ) as $chunk ) {
		$wpdb->query( 'INSERT INTO ' . glp_table( 'plays' ) . ' (isrc, service, day, plays) VALUES ' . implode( ',', $chunk ) . ' ON DUPLICATE KEY UPDATE plays = VALUES(plays)' ); // phpcs:ignore
	}
	update_option( 'glp_plays_tracks', $titles, false );
	$synced[ $source ] = array( 'at' => time(), 'by' => get_current_user_id() );
	update_option( 'glp_plays_synced', $synced, false );
	glp_log( 'Plays aus ' . ( $source === 'imusician' ? 'iMusician' : 'DistroKid' ) . ' aktualisiert (' . $n . ' Songs)' );
	return glp_ok( array( 'tracks' => $n, 'values' => count( $rows ) ) );
}

/** Alles, was die Plays-Ansicht braucht: pro Song Summen je Shop und die Tageswerte des letzten Jahres */
function glp_rest_plays() {
	global $wpdb;
	$t      = glp_table( 'plays' );
	$titles = (array) get_option( 'glp_plays_tracks', array() );
	$synced = (array) get_option( 'glp_plays_synced', array() );
	$spall  = (array) get_option( 'glp_plays_spotify_all', array() );
	$when   = function ( $k ) use ( $synced ) {
		$s = $synced[ $k ] ?? ( $k === 'distrokid' && isset( $synced['at'] ) ? $synced : null ); // altes Format (nur DistroKid)
		return $s ? array( 'at' => wp_date( 'd.m., H:i', $s['at'] ), 'ts' => (int) $s['at'], 'by' => glp_user_name( $s['by'] ?? 0 ) ) : null;
	};
	$latest = $wpdb->get_var( "SELECT MAX(day) FROM $t WHERE service <> 'apple'" ); // phpcs:ignore
	$out    = array(
		'synced' => array( 'distrokid' => $when( 'distrokid' ), 'imusician' => $when( 'imusician' ), 'spotify' => $when( 'spotify' ) ),
		'latest' => $latest ?: '',
		'tracks' => array(),
	);
	if ( ! $latest ) {
		return glp_ok( $out );
	}
	$end   = new DateTimeImmutable( $latest );
	$start = $end->modify( '-364 days' );
	$sums  = $wpdb->get_results( "SELECT isrc, service, SUM(plays) AS n FROM $t GROUP BY isrc, service", ARRAY_A ); // phpcs:ignore
	$days  = $wpdb->get_results( $wpdb->prepare( "SELECT isrc, day, SUM(plays) AS n FROM $t WHERE service <> 'apple' AND day >= %s GROUP BY isrc, day", $start->format( 'Y-m-d' ) ), ARRAY_A ); // phpcs:ignore
	$label  = array( 'spotify' => 'Spotify', 'apple' => 'Apple Music', 'deezer' => 'Deezer', 'amazon' => 'Amazon', 'youtube' => 'YouTube', 'tidal' => 'Tidal' );
	$tracks = array();
	foreach ( $sums as $r ) {
		$tracks[ $r['isrc'] ] ??= array( 'isrc' => $r['isrc'], 'title' => $titles[ $r['isrc'] ] ?? $r['isrc'], 'shops' => array(), 'daily' => array_fill( 0, 365, 0 ) );
		$shop  = preg_replace( '/^im:/', '', $r['service'] );
		$shop  = $label[ $shop ] ?? ucfirst( $shop );
		$tracks[ $r['isrc'] ]['shops'][ $shop ] = ( $tracks[ $r['isrc'] ]['shops'][ $shop ] ?? 0 ) + (int) $r['n'];
	}
	foreach ( $days as $r ) {
		$i = (int) $start->diff( new DateTimeImmutable( $r['day'] ) )->days;
		if ( isset( $tracks[ $r['isrc'] ] ) && $i >= 0 && $i < 365 ) {
			$tracks[ $r['isrc'] ]['daily'][ $i ] = (int) $r['n'];
		}
	}
	foreach ( $tracks as &$tr ) {
		arsort( $tr['shops'] );
		$counted   = $tr['shops']['Spotify'] ?? 0;
		$pub       = $spall['items'][ glp_plays_norm( $tr['title'] ) ]['plays'] ?? null; // Gesamt laut Spotify (alle Versionen, seit Release)
		$tr['spotifyAll'] = $pub;
		$tr['total']      = array_sum( $tr['shops'] ) - $counted + max( (int) $pub, $counted );
	}
	unset( $tr );
	usort( $tracks, fn( $a, $b ) => $b['total'] <=> $a['total'] );
	$out['start']     = $start->format( 'Y-m-d' );
	$out['tracks']    = array_values( $tracks );
	$out['spotifyAt'] = ! empty( $spall['at'] ) ? wp_date( 'd.m.Y', $spall['at'] ) : '';
	$covers   = glp_plays_covers();
	$releases = glp_plays_releases();
	foreach ( $releases as &$rel ) {
		$rel['cover'] = glp_plays_cover_url( $rel['cover'] ?? 0 );
	}
	unset( $rel );
	foreach ( $out['tracks'] as &$tr ) {
		$tr['cover'] = glp_plays_cover_url( $covers[ $tr['isrc'] ] ?? 0 );
		foreach ( $releases as $rel ) {
			if ( ! $tr['cover'] && in_array( $tr['isrc'], $rel['isrcs'], true ) ) {
				$tr['cover'] = $rel['cover'];
			}
		}
	}
	unset( $tr );
	$out['releases'] = $releases;
	return glp_ok( $out );
}
