<?php
/**
 * Historie im Studio: jede Statusänderung (Song · Schritt · alt → neu · wer · wann).
 */

defined( 'ABSPATH' ) || exit;

function glp_history_add( $song_id, $step, $old, $new, $user_id = null, $time = null ) {
	global $wpdb;
	$wpdb->insert( glp_table( 'history' ), array(
		'song_id'    => (int) $song_id,
		'step'       => $step,
		'old_val'    => $old === null ? null : (int) $old,
		'new_val'    => (int) $new,
		'user_id'    => $user_id === null ? get_current_user_id() : (int) $user_id,
		'created_at' => $time ?: current_time( 'mysql', true ),
	) );
}

/**
 * Einmalig: bisherige Statusänderungen aus dem Aktivitäts-Log übernehmen
 * („Drums · LavaLampe → aufgenommen“). Der vorherige Status ist dort unbekannt.
 */
function glp_history_backfill() {
	global $wpdb;
	if ( get_option( 'glp_history_backfilled' ) ) {
		return;
	}
	update_option( 'glp_history_backfilled', 1, false );
	$steps  = array_column( glp_steps(), null, 'l' );
	$songs  = array();
	foreach ( glp_songs() as $s ) {
		$songs[ $s['title'] ] = $s['id'];
	}
	$labels = array();
	foreach ( glp_steps() as $st ) {
		foreach ( glp_step_values( $st['t'] ) as $v ) {
			$labels[ $st['k'] ][ glp_step_label( $st['t'], $v ) ] = $v;
		}
	}
	$rows = $wpdb->get_results( 'SELECT user_id, message, created_at FROM ' . glp_table( 'log' ) . " WHERE message LIKE '% · % → %' ORDER BY id ASC" );
	foreach ( (array) $rows as $r ) {
		if ( ! preg_match( '/^(.+?) · (.+) → (.+)$/u', $r->message, $m ) ) {
			continue;
		}
		$st = $steps[ $m[1] ] ?? null;
		$id = $songs[ $m[2] ] ?? 0;
		if ( ! $st || ! $id || ! isset( $labels[ $st['k'] ][ $m[3] ] ) ) {
			continue;
		}
		glp_history_add( $id, $st['k'], null, $labels[ $st['k'] ][ $m[3] ], (int) $r->user_id, $r->created_at );
	}
}

add_action( 'rest_api_init', function () {
	register_rest_route( GLP_NS, '/history', array( 'methods' => 'GET', 'callback' => 'glp_rest_history', 'permission_callback' => 'glp_can' ) );
} );

function glp_rest_history( WP_REST_Request $req ) {
	global $wpdb;
	$where = array( '1=1' );
	$args  = array();
	if ( $song = (int) $req->get_param( 'song' ) ) {
		$where[] = 'h.song_id = %d';
		$args[]  = $song;
	}
	if ( $user = (int) $req->get_param( 'user' ) ) {
		$where[] = 'h.user_id = %d';
		$args[]  = $user;
	}
	if ( $before = (int) $req->get_param( 'before' ) ) {
		$where[] = 'h.id < %d';
		$args[]  = $before;
	}
	$limit  = max( 10, min( 200, (int) $req->get_param( 'limit' ) ?: 60 ) );
	$args[] = $limit + 1;
	$sql    = 'SELECT h.*, s.title FROM ' . glp_table( 'history' ) . ' h LEFT JOIN ' . glp_table( 'songs' ) . ' s ON s.id = h.song_id WHERE ' . implode( ' AND ', $where ) . ' ORDER BY h.id DESC LIMIT %d';
	$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	$more   = count( $rows ) > $limit;
	$rows   = array_slice( (array) $rows, 0, $limit );
	return glp_ok( array(
		'items' => array_map( fn( $r ) => array(
			'id'   => (int) $r->id,
			'song' => (int) $r->song_id,
			'title'=> $r->title ?: '(gelöschter Song)',
			'step' => $r->step,
			'old'  => $r->old_val === null ? null : (int) $r->old_val,
			'new'  => (int) $r->new_val,
			'uid'  => (int) $r->user_id,
			'who'  => $r->user_id ? glp_user_name( $r->user_id ) : 'System',
			't'    => strtotime( $r->created_at . ' UTC' ) * 1000,
		), $rows ),
		'more'  => $more,
	) );
}
