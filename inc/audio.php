<?php
/**
 * Song-Versionen (MP3 & Co.): Upload, geschütztes Abspielen mit Spulen, Download, Löschen.
 *
 * Dateien liegen in wp-content/uploads/ghostlike-portal-audio/ unter Zufallsnamen,
 * der Ordner ist per .htaccess gesperrt. Abgespielt wird nur über /portal/?audio=<id>,
 * und das nur für eingeloggte Bandmitglieder.
 */

defined( 'ABSPATH' ) || exit;

const GLP_AUDIO_TYPES = array(
	'mp3' => 'audio/mpeg',
	'm4a' => 'audio/mp4',
	'aac' => 'audio/aac',
	'wav' => 'audio/wav',
);

// Weitere Dateien pro Song: Tabs, Noten, MIDI, Lyrics-Blätter
const GLP_FILE_TYPES = array(
	'gp'   => 'application/octet-stream',
	'gp3'  => 'application/octet-stream',
	'gp4'  => 'application/octet-stream',
	'gp5'  => 'application/octet-stream',
	'gpx'  => 'application/octet-stream',
	'mscz' => 'application/octet-stream',
	'mid'  => 'audio/midi',
	'midi' => 'audio/midi',
	'pdf'  => 'application/pdf',
	'txt'  => 'text/plain',
	'png'  => 'image/png',
	'jpg'  => 'image/jpeg',
	'jpeg' => 'image/jpeg',
);

function glp_audio_dir() {
	$up  = wp_upload_dir( null, false );
	$dir = trailingslashit( $up['basedir'] ) . 'ghostlike-portal-audio';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		@file_put_contents( $dir . '/.htaccess', "# Nur über das Portal abspielbar\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		@file_put_contents( $dir . '/index.php', "<?php // Stille.\n" );
	}
	return $dir;
}

/** Alle Versionen pro Song: [song_id => [ {id, v, name, note, by, date, size}, … neueste zuerst ]]. */
function glp_audio_by_song() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT id, song_id, name, note, size, bpm, length, uploaded_by, created_at FROM ' . glp_table( 'audio' ) . " WHERE kind = 'audio' ORDER BY song_id ASC, created_at ASC, id ASC" );
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$sid           = (int) $r->song_id;
		$out[ $sid ][] = array(
			'id'   => (int) $r->id,
			'v'    => count( $out[ $sid ] ?? array() ) + 1,
			'name' => $r->name,
			'note' => $r->note,
			'size' => (int) $r->size,
			'bpm'  => (float) $r->bpm ? glp_fmt_bpm( (float) $r->bpm ) : '',
			'len'  => (int) $r->length,
			'by'   => glp_user_name( $r->uploaded_by ),
			'date' => mysql2date( 'c', $r->created_at ),
		);
	}
	foreach ( $out as &$list ) {
		$list = array_reverse( $list );
	}
	return $out;
}

/** Tabs & Co. pro Song: [song_id => [ {id, name, note, by, date, size, ext}, … neueste zuerst ]]. */
function glp_files_by_song() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT id, song_id, name, note, size, uploaded_by, created_at FROM ' . glp_table( 'audio' ) . " WHERE kind = 'file' ORDER BY created_at DESC, id DESC" );
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r->song_id ][] = array(
			'id'   => (int) $r->id,
			'name' => $r->name,
			'ext'  => strtolower( pathinfo( $r->name, PATHINFO_EXTENSION ) ),
			'note' => $r->note,
			'size' => (int) $r->size,
			'by'   => glp_user_name( $r->uploaded_by ),
			'date' => mysql2date( 'c', $r->created_at ),
		);
	}
	return $out;
}

/** 165.00 → "165", 87.50 → "87.5" */
function glp_fmt_bpm( $bpm ) {
	return rtrim( rtrim( number_format( (float) $bpm, 2, '.', '' ), '0' ), '.' );
}

/**
 * Tempo und Länge aus den Datei-Infos lesen (ID3 „TBPM“ bei MP3, „tmpo“ bei M4A).
 * Nutzt getID3, das in WordPress enthalten ist. Liefert [bpm (0 = keins), länge in s].
 */
function glp_audio_read_meta( $path ) {
	if ( ! function_exists( 'wp_read_audio_metadata' ) ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}
	$meta = @wp_read_audio_metadata( $path );
	$bpm  = 0.0;
	if ( is_array( $meta ) ) {
		foreach ( array( 'bpm', 'tbpm', 'tempo', 'beats_per_minute' ) as $k ) {
			if ( ! empty( $meta[ $k ] ) && is_scalar( $meta[ $k ] ) ) {
				$bpm = (float) str_replace( ',', '.', preg_replace( '/[^0-9.,]/', '', (string) $meta[ $k ] ) );
				break;
			}
		}
	}
	if ( $bpm < 30 || $bpm > 400 ) {
		$bpm = 0.0; // unplausibel → ignorieren
	}
	return array( round( $bpm, 2 ), is_array( $meta ) ? (int) round( (float) ( $meta['length'] ?? 0 ) ) : 0 );
}

function glp_audio_url( $id, $download = false ) {
	return add_query_arg( array_filter( array( 'audio' => (int) $id, 'download' => $download ? 1 : null ) ), glp_portal_url() );
}

/* ================= REST: HOCHLADEN / LÖSCHEN ================= */

add_action( 'rest_api_init', function () {
	register_rest_route( GLP_NS, '/songs/(?P<id>\d+)/audio', array( 'methods' => 'POST', 'callback' => 'glp_rest_audio_upload', 'permission_callback' => 'glp_can' ) );
	register_rest_route( GLP_NS, '/songs/(?P<id>\d+)/files', array( 'methods' => 'POST', 'callback' => 'glp_rest_file_upload', 'permission_callback' => 'glp_can' ) );
	register_rest_route( GLP_NS, '/audio/(?P<id>\d+)', array( 'methods' => 'DELETE', 'callback' => 'glp_rest_audio_delete', 'permission_callback' => 'glp_can' ) );
} );

function glp_rest_audio_upload( WP_REST_Request $req ) {
	global $wpdb;
	$song  = glp_song( (int) $req['id'] );
	$files = $req->get_file_params();
	$f     = $files['file'] ?? null;
	if ( ! $song ) {
		return glp_err( 'Song nicht gefunden.', 404 );
	}
	if ( ! $f || ! empty( $f['error'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
		$too_big = $f && in_array( (int) $f['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
		return glp_err( $too_big ? 'Die Datei ist zu groß für den Server (max. ' . size_format( wp_max_upload_size() ) . ').' : 'Upload fehlgeschlagen. Bitte noch einmal versuchen.' );
	}
	$ext = strtolower( pathinfo( (string) $f['name'], PATHINFO_EXTENSION ) );
	if ( ! isset( GLP_AUDIO_TYPES[ $ext ] ) ) {
		return glp_err( 'Bitte eine Audiodatei hochladen (MP3, M4A, AAC oder WAV).' );
	}
	// Inhalt prüfen, nicht nur die Endung
	$real = function_exists( 'mime_content_type' ) ? (string) @mime_content_type( $f['tmp_name'] ) : '';
	if ( $real && ! preg_match( '#^(audio/|video/mp4|application/octet-stream)#', $real ) ) {
		return glp_err( 'Das sieht nicht nach einer Audiodatei aus.' );
	}
	$file = wp_generate_password( 24, false ) . '.' . $ext;
	if ( ! @move_uploaded_file( $f['tmp_name'], glp_audio_dir() . '/' . $file ) ) {
		return glp_err( 'Die Datei konnte nicht gespeichert werden.', 500 );
	}
	[ $bpm, $length ] = glp_audio_read_meta( glp_audio_dir() . '/' . $file );
	$wpdb->insert( glp_table( 'audio' ), array(
		'song_id'     => $song['id'],
		'file'        => $file,
		'name'        => mb_substr( sanitize_file_name( (string) $f['name'] ), 0, 190 ),
		'mime'        => GLP_AUDIO_TYPES[ $ext ],
		'size'        => (int) $f['size'],
		'note'        => mb_substr( sanitize_text_field( (string) $req->get_param( 'note' ) ), 0, 190 ),
		'bpm'         => $bpm,
		'length'      => $length,
		'uploaded_by' => get_current_user_id(),
		'created_at'  => current_time( 'mysql' ),
	) );
	$all = glp_audio_by_song()[ $song['id'] ] ?? array();
	$v   = $all ? $all[0]['v'] : 1;
	glp_log( $song['title'] . ' v' . $v . ' hochgeladen' . ( $req->get_param( 'note' ) ? ' (' . mb_substr( sanitize_text_field( (string) $req->get_param( 'note' ) ), 0, 60 ) . ')' : '' ) );

	glp_notify_version( $song, $v, sanitize_text_field( (string) $req->get_param( 'note' ) ) );

	// BPM aus der Datei: nur eintragen, wenn das Feld noch leer ist – nie überschreiben
	$applied = false;
	if ( $bpm && trim( (string) ( ( (array) $song['meta'] )['bpm'] ?? '' ) ) === '' ) {
		$meta        = (array) $song['meta'];
		$meta['bpm'] = glp_fmt_bpm( $bpm );
		$wpdb->update( glp_table( 'songs' ), array( 'meta' => wp_json_encode( (object) $meta ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $song['id'] ) );
		glp_log( 'BPM aus Datei übernommen: ' . $song['title'] . ' → ' . glp_fmt_bpm( $bpm ) );
		$applied = true;
	}
	return glp_ok( array(
		'versions'    => $all,
		'bpm'         => $bpm ? glp_fmt_bpm( $bpm ) : '',
		'bpm_applied' => $applied,
		'song'        => glp_song( $song['id'] ),
	) );
}

function glp_rest_file_upload( WP_REST_Request $req ) {
	global $wpdb;
	$song  = glp_song( (int) $req['id'] );
	$files = $req->get_file_params();
	$f     = $files['file'] ?? null;
	if ( ! $song ) {
		return glp_err( 'Song nicht gefunden.', 404 );
	}
	if ( ! $f || ! empty( $f['error'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
		$too_big = $f && in_array( (int) $f['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
		return glp_err( $too_big ? 'Die Datei ist zu groß für den Server (max. ' . size_format( wp_max_upload_size() ) . ').' : 'Upload fehlgeschlagen. Bitte noch einmal versuchen.' );
	}
	$ext = strtolower( pathinfo( (string) $f['name'], PATHINFO_EXTENSION ) );
	if ( ! isset( GLP_FILE_TYPES[ $ext ] ) ) {
		return glp_err( 'Erlaubt sind Guitar Pro (.gp, .gp5, .gpx …), MuseScore, MIDI, PDF, TXT und Bilder.' );
	}
	$file = wp_generate_password( 24, false ) . '.' . $ext;
	if ( ! @move_uploaded_file( $f['tmp_name'], glp_audio_dir() . '/' . $file ) ) {
		return glp_err( 'Die Datei konnte nicht gespeichert werden.', 500 );
	}
	$name = mb_substr( sanitize_file_name( (string) $f['name'] ), 0, 190 );
	$wpdb->insert( glp_table( 'audio' ), array(
		'song_id'     => $song['id'],
		'kind'        => 'file',
		'file'        => $file,
		'name'        => $name,
		'mime'        => GLP_FILE_TYPES[ $ext ],
		'size'        => (int) $f['size'],
		'note'        => mb_substr( sanitize_text_field( (string) $req->get_param( 'note' ) ), 0, 190 ),
		'uploaded_by' => get_current_user_id(),
		'created_at'  => current_time( 'mysql' ),
	) );
	glp_log( 'Datei zu ' . $song['title'] . ': ' . $name );
	return glp_ok( array( 'files' => glp_files_by_song()[ $song['id'] ] ?? array() ) );
}

function glp_rest_audio_delete( WP_REST_Request $req ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . glp_table( 'audio' ) . ' WHERE id = %d', (int) $req['id'] ) );
	if ( ! $row ) {
		return glp_err( 'Version nicht gefunden.', 404 );
	}
	if ( (int) $row->uploaded_by !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
		return glp_err( 'Löschen können nur, wer hochgeladen hat, und Admins.' );
	}
	@unlink( glp_audio_dir() . '/' . basename( $row->file ) );
	$wpdb->delete( glp_table( 'audio' ), array( 'id' => $row->id ) );
	$song = glp_song( (int) $row->song_id );
	glp_log( ( $row->kind === 'file' ? 'Datei gelöscht: ' : 'Version gelöscht: ' ) . ( $song['title'] ?? '?' ) . ' (' . $row->name . ')' );
	return glp_ok( array(
		'versions' => glp_audio_by_song()[ (int) $row->song_id ] ?? array(),
		'files'    => glp_files_by_song()[ (int) $row->song_id ] ?? array(),
	) );
}

/* ================= ABSPIELEN (mit Spulen) ================= */

function glp_stream_audio( $id, $download = false ) {
	global $wpdb;
	if ( ! is_user_logged_in() || ! current_user_can( 'gl_portal' ) ) {
		status_header( 403 );
		exit;
	}
	$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . glp_table( 'audio' ) . ' WHERE id = %d', (int) $id ) );
	$path = $row ? glp_audio_dir() . '/' . basename( $row->file ) : '';
	if ( ! $row || ! is_file( $path ) ) {
		status_header( 404 );
		exit;
	}
	$size  = filesize( $path );
	$start = 0;
	$end   = $size - 1;

	while ( ob_get_level() ) {
		ob_end_clean();
	}
	header( 'Content-Type: ' . $row->mime );
	header( 'Accept-Ranges: bytes' );
	header( 'Cache-Control: private, max-age=86400' );
	header( 'X-Robots-Tag: noindex' );
	header( 'X-Content-Type-Options: nosniff' );
	if ( $row->kind === 'file' && ! preg_match( '#^(application/pdf|image/|text/plain)#', $row->mime ) ) {
		$download = true; // Guitar Pro & Co. kann der Browser nicht anzeigen
	}
	header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . str_replace( '"', '', $row->name ) . '"' );

	if ( ! $download && isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m ) ) {
		if ( $m[1] === '' ) {
			$start = max( 0, $size - (int) $m[2] );
		} else {
			$start = (int) $m[1];
			$end   = $m[2] !== '' ? min( (int) $m[2], $size - 1 ) : $size - 1;
		}
		if ( $start > $end || $start >= $size ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . $size );
			exit;
		}
		status_header( 206 );
		header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
	} else {
		status_header( 200 );
	}
	header( 'Content-Length: ' . ( $end - $start + 1 ) );

	$fp   = fopen( $path, 'rb' );
	$left = $end - $start + 1;
	fseek( $fp, $start );
	while ( $left > 0 && ! feof( $fp ) && ! connection_aborted() ) {
		$chunk = fread( $fp, (int) min( 262144, $left ) );
		echo $chunk; // phpcs:ignore
		$left -= strlen( $chunk );
		flush();
	}
	fclose( $fp );
	exit;
}

// Vor allem anderen auf /portal/ reagieren (eigene Cache-Header)
add_action( 'template_redirect', function () {
	if ( get_query_var( 'glp_portal' ) && isset( $_GET['audio'] ) ) {
		glp_stream_audio( (int) $_GET['audio'], ! empty( $_GET['download'] ) );
	}
}, 5 );
