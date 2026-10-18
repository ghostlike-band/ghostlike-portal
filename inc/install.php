<?php
/**
 * Installation: Tabellen, Rolle „Bandmitglied“, Startdaten.
 */

defined( 'ABSPATH' ) || exit;

function glp_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'glp_' . $name;
}

function glp_activate() {
	glp_install();
	glp_add_rewrite();
	flush_rewrite_rules();
}

function glp_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE " . glp_table( 'songs' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		nr int(11) NOT NULL DEFAULT 0,
		title varchar(190) NOT NULL DEFAULT '',
		album tinyint(1) NOT NULL DEFAULT 1,
		released tinyint(1) NOT NULL DEFAULT 0,
		steps longtext NULL,
		meta longtext NULL,
		flags longtext NULL,
		updated_at datetime NULL,
		updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (id)
	) $c;" );

	dbDelta( "CREATE TABLE " . glp_table( 'log' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		message varchar(255) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY created_at (created_at)
	) $c;" );

	dbDelta( "CREATE TABLE " . glp_table( 'events' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		type varchar(20) NOT NULL DEFAULT 'termin',
		title varchar(190) NOT NULL DEFAULT '',
		place varchar(190) NOT NULL DEFAULT '',
		starts_at datetime NOT NULL,
		who longtext NULL,
		note text NULL,
		created_by bigint(20) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY starts_at (starts_at)
	) $c;" );

	dbDelta( "CREATE TABLE " . glp_table( 'attendance' ) . " (
		event_key varchar(40) NOT NULL,
		user_id bigint(20) unsigned NOT NULL,
		status varchar(10) NOT NULL DEFAULT '',
		updated_at datetime NULL,
		PRIMARY KEY  (event_key,user_id)
	) $c;" );

	// Rolle „Bandmitglied“: darf nur ins Portal und ins eigene Profil.
	if ( ! get_role( 'gl_member' ) ) {
		add_role( 'gl_member', 'Bandmitglied', array( 'read' => true, 'gl_portal' => true ) );
	}
	foreach ( array( 'administrator', 'editor' ) as $r ) {
		$role = get_role( $r );
		if ( $role && ! $role->has_cap( 'gl_portal' ) ) {
			$role->add_cap( 'gl_portal' );
		}
	}

	glp_seed_songs();
	update_option( 'glp_db_version', GLP_DB_VERSION );
}

/** Startstand = eure Recording-Tabelle (x = aufgenommen + editiert). Nur wenn noch leer. */
function glp_seed_songs() {
	global $wpdb;
	if ( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . glp_table( 'songs' ) ) > 0 ) {
		return;
	}
	$pre = array( 'geschrieben', 'struktur', 'lines' );
	$all = array_column( glp_default_steps(), 'k' );
	$rows = array(
		array( 1, 'Nothing’s Okay', $all, 1, 1 ),
		array( 2, 'The Price We Pay', $all, 1, 1 ),
		array( 3, 'Deep Blue Sea', $all, 1, 1 ),
		array( 4, 'Who Am I', $all, 1, 1 ),
		array( 5, 'Spiral', $all, 1, 1 ),
		array( 6, 'LavaLampe', array_merge( $pre, array( 'drums', 'bass', 'rhythm' ) ), 1, 0 ),
		array( 7, 'Mikrofilet', array_merge( $pre, array( 'bass', 'rhythm' ) ), 1, 0 ),
		array( 8, 'Veynuss', array_merge( $pre, array( 'drums', 'bass', 'rhythm', 'lead', 'synth', 'sfx' ) ), 1, 0 ),
		array( 9, 'HE', array( 'geschrieben', 'struktur' ), 1, 0 ),
		array( 10, 'Teckkk', array( 'geschrieben', 'struktur' ), 1, 0 ),
		array( 11, 'GnusmaS', array_merge( $pre, array( 'rhythm', 'lead' ) ), 1, 0 ),
		array( 12, 'ArcheK7', array( 'geschrieben' ), 1, 0 ),
		array( 13, 'iPfuffi', array_merge( $pre, array( 'rhythm', 'lead', 'screams', 'cleans' ) ), 1, 0 ),
		array( 14, 'Erding?', array( 'geschrieben' ), 1, 0 ),
		array( 15, 'Irony?', array( 'geschrieben' ), 1, 0 ),
		array( 0, 'Goltec', array( 'geschrieben' ), 0, 0 ),
	);
	foreach ( $rows as $r ) {
		$steps = array_fill_keys( $all, 0 );
		foreach ( $r[2] as $k ) {
			$steps[ $k ] = 2;
		}
		$meta = $r[1] === 'iPfuffi' ? array( 'note' => 'Rhythm-Polyphia-Gitarre fehlt noch.' ) : array();
		$wpdb->insert( glp_table( 'songs' ), array(
			'nr'         => $r[0],
			'title'      => $r[1],
			'album'      => $r[3],
			'released'   => $r[4],
			'steps'      => wp_json_encode( $steps ),
			'meta'       => wp_json_encode( (object) $meta ),
			'flags'      => wp_json_encode( (object) array() ),
			'updated_at' => current_time( 'mysql' ),
		) );
	}
	glp_log( 'Studio-Tool angelegt · Stand aus eurer Recording-Tabelle übernommen', 0 );
}
