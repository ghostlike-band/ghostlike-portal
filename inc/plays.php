<?php
/**
 * Plays: Streaming-Zahlen pro Song aus DistroKid (Spotify täglich, Apple Music monatlich).
 * DistroKid hat keine Schnittstelle. Der Sync-Button (Lesezeichen) liest die Statistikseiten im eingeloggten
 * Browser und schickt die Zahlen per postMessage an das offene Portal, das sie hier speichert.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	register_rest_route( GLP_NS, '/plays', array( 'methods' => 'GET', 'callback' => 'glp_rest_plays', 'permission_callback' => 'glp_can' ) );
	register_rest_route( GLP_NS, '/plays/import', array( 'methods' => 'POST', 'callback' => 'glp_rest_plays_import', 'permission_callback' => 'glp_can' ) );
} );

function glp_rest_plays_import( WP_REST_Request $req ) {
	global $wpdb;
	$tracks = (array) $req->get_param( 'tracks' );
	$titles = (array) get_option( 'glp_plays_tracks', array() );
	$rows   = array();
	$n      = 0;
	foreach ( $tracks as $t ) {
		$isrc = strtoupper( (string) ( $t['isrc'] ?? '' ) );
		if ( ! preg_match( '/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/', $isrc ) ) {
			continue;
		}
		$titles[ $isrc ] = mb_substr( sanitize_text_field( (string) ( $t['title'] ?? $isrc ) ), 0, 120 );
		foreach ( (array) ( $t['spotify'] ?? array() ) as $day => $plays ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day ) ) {
				$rows[] = $wpdb->prepare( '(%s,%s,%s,%d)', $isrc, 'spotify', $day, max( 0, (int) $plays ) );
			}
		}
		foreach ( (array) ( $t['apple'] ?? array() ) as $month => $plays ) {
			if ( preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ) {
				$rows[] = $wpdb->prepare( '(%s,%s,%s,%d)', $isrc, 'apple', $month . '-01', max( 0, (int) $plays ) );
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
	update_option( 'glp_plays_synced', array( 'at' => time(), 'by' => get_current_user_id() ), false );
	glp_log( 'Plays aus DistroKid aktualisiert (' . $n . ' Songs)' );
	return glp_ok( array( 'tracks' => $n, 'values' => count( $rows ) ) );
}

/** Alles, was die Plays-Ansicht braucht: pro Song Summen und die Tageswerte des letzten Jahres */
function glp_rest_plays() {
	global $wpdb;
	$t      = glp_table( 'plays' );
	$titles = (array) get_option( 'glp_plays_tracks', array() );
	$synced = (array) get_option( 'glp_plays_synced', array() );
	$latest = $wpdb->get_var( "SELECT MAX(day) FROM $t WHERE service = 'spotify'" ); // phpcs:ignore
	$out    = array(
		'synced'   => ! empty( $synced['at'] ) ? wp_date( 'd.m.Y, H:i', $synced['at'] ) : '',
		'syncedBy' => ! empty( $synced['by'] ) ? glp_user_name( $synced['by'] ) : '',
		'latest'   => $latest ?: '',
		'tracks'   => array(),
	);
	if ( ! $latest ) {
		return glp_ok( $out );
	}
	$end   = new DateTimeImmutable( $latest );
	$start = $end->modify( '-364 days' );
	$sums  = $wpdb->get_results( "SELECT isrc, service, SUM(plays) AS n FROM $t GROUP BY isrc, service", ARRAY_A ); // phpcs:ignore
	$days  = $wpdb->get_results( $wpdb->prepare( "SELECT isrc, day, plays FROM $t WHERE service = 'spotify' AND day >= %s ORDER BY day", $start->format( 'Y-m-d' ) ), ARRAY_A ); // phpcs:ignore
	$tracks = array();
	foreach ( $sums as $r ) {
		$tracks[ $r['isrc'] ] ??= array( 'isrc' => $r['isrc'], 'title' => $titles[ $r['isrc'] ] ?? $r['isrc'], 'spotify' => 0, 'apple' => 0, 'daily' => array_fill( 0, 365, 0 ) );
		$tracks[ $r['isrc'] ][ $r['service'] ] = (int) $r['n'];
	}
	foreach ( $days as $r ) {
		$i = (int) $start->diff( new DateTimeImmutable( $r['day'] ) )->days;
		if ( isset( $tracks[ $r['isrc'] ] ) && $i >= 0 && $i < 365 ) {
			$tracks[ $r['isrc'] ]['daily'][ $i ] = (int) $r['plays'];
		}
	}
	foreach ( $tracks as &$tr ) {
		$tr['total'] = $tr['spotify'] + $tr['apple'];
	}
	unset( $tr );
	usort( $tracks, fn( $a, $b ) => $b['total'] <=> $a['total'] );
	$out['start']  = $start->format( 'Y-m-d' );
	$out['tracks'] = array_values( $tracks );
	return glp_ok( $out );
}
