<?php
/**
 * Bandkasse: Einnahmen, Ausgaben, Auslagen, Ausgleiche und Beiträge.
 *
 * Arten (kind):
 *   out  Ausgabe.  from = wer bezahlt hat (0 = Bandkasse, sonst Mitglied, das ausgelegt hat).
 *                  split = wer die Kosten trägt: leer = die Band, sonst Liste von Mitgliedern.
 *   in   Einnahme. from = wo das Geld gelandet ist (0 = Bandkasse, sonst Mitglied, das es verwahrt).
 *                  split leer = gehört der Band, sonst den genannten Mitgliedern.
 *   move Ausgleich: from → to (0 = Bandkasse).
 *   fee  Beitrag:   from (Mitglied) zahlt in die Bandkasse ein, ohne dass etwas geschuldet war.
 *
 * Salden: positiv = die Band schuldet dem Mitglied Geld, negativ = das Mitglied schuldet der Band.
 */

defined( 'ABSPATH' ) || exit;

function glp_cash_categories() {
	return array(
		'studio'    => 'Studio & Produktion',
		'proberaum' => 'Proberaum',
		'equipment' => 'Equipment',
		'merch'     => 'Merch',
		'gage'      => 'Gage & Konzerte',
		'fahrt'     => 'Fahrt & Reise',
		'marketing' => 'Marketing & Video',
		'vertrieb'  => 'Vertrieb & Streaming',
		'beitrag'   => 'Beiträge',
		'sonstiges' => 'Sonstiges',
	);
}

function glp_cash_row( $r ) {
	return array(
		'id'       => (int) $r->id,
		'kind'     => $r->kind,
		'amount'   => (int) $r->amount,
		'date'     => $r->booked_on,
		'title'    => $r->title,
		'category' => $r->category,
		'from'     => (int) $r->from_id,
		'to'       => (int) $r->to_id,
		'split'    => array_map( 'intval', (array) ( json_decode( (string) $r->split, true ) ?: array() ) ),
		'note'     => (string) $r->note,
		'receipt'  => $r->receipt !== '' ? array( 'name' => $r->receipt_name, 'url' => add_query_arg( 'beleg', (int) $r->id, glp_portal_url() ) ) : null,
		'by'       => (int) $r->created_by,
		'byName'   => glp_user_name( $r->created_by ),
		'status'   => $r->status ?: 'ok', // ok | pending (gemeldet, wartet auf Empfänger) | rejected
		'confirmedBy' => (int) $r->confirmed_by,
	);
}

/** Beträge auf Personen verteilen, ohne Cent zu verlieren */
function glp_cash_shares( $amount, $ids ) {
	$ids = array_values( $ids );
	$n   = count( $ids );
	if ( ! $n ) {
		return array();
	}
	$base = intdiv( $amount, $n );
	$rest = $amount - $base * $n;
	$out  = array();
	foreach ( $ids as $i => $id ) {
		$out[ $id ] = $base + ( $i < $rest ? 1 : 0 );
	}
	return $out;
}

/** Ausgabe eines Mitglieds ohne Aufteilung: gilt für alle Mitglieder (die Bandkasse übernimmt vorerst nichts) */
function glp_cash_split( $b ) {
	if ( $b['kind'] === 'out' && $b['from'] && ! $b['split'] ) {
		static $all = null;
		if ( null === $all ) {
			$all = array_map( 'intval', array_column( glp_members(), 'id' ) );
		}
		return $all;
	}
	return $b['split'];
}

/** Kassenstand, Salden je Mitglied und Beiträge aus allen Buchungen */
function glp_cash_balances( $rows ) {
	$cash = 0;
	$bal  = array();
	$fees = array();
	$add  = function ( $id, $v ) use ( &$bal ) {
		$bal[ $id ] = ( $bal[ $id ] ?? 0 ) + $v;
	};
	foreach ( $rows as $b ) {
		if ( $b['status'] !== 'ok' ) {
			continue; // gemeldete, noch nicht bestätigte Zahlungen zählen nicht
		}
		$a = $b['amount'];
		switch ( $b['kind'] ) {
			case 'out':
				$b['from'] ? $add( $b['from'], $a ) : $cash -= $a;
				foreach ( glp_cash_shares( $a, glp_cash_split( $b ) ) as $id => $s ) {
					$add( $id, -$s );
				}
				break;
			case 'in':
				$b['from'] ? $add( $b['from'], -$a ) : $cash += $a;
				foreach ( glp_cash_shares( $a, $b['split'] ) as $id => $s ) {
					$add( $id, $s );
				}
				break;
			case 'move':
				$b['from'] ? $add( $b['from'], $a ) : $cash -= $a;
				$b['to'] ? $add( $b['to'], -$a ) : $cash += $a;
				break;
			case 'fee':
				$cash += $a;
				$fees[ $b['from'] ] = ( $fees[ $b['from'] ] ?? 0 ) + $a;
				break;
		}
	}
	return array( 'cash' => $cash, 'balances' => (object) $bal, 'fees' => (object) $fees );
}

/**
 * Wer schuldet wem? Paarweise, mit den Buchungen als Begründung.
 * Knoten: Mitglieder-IDs und 0 = Bandkasse.
 * Ergebnis: [{debtor, creditor, amount, items:[{id, date, title, amount, why}]}], amount > 0.
 */
function glp_cash_debts( $rows ) {
	$edges = array(); // "a|b" => [ [id, date, title, cents (a schuldet b), why], … ]
	$owe   = function ( $a, $b, $cents, $bk, $why ) use ( &$edges ) {
		if ( $a === $b || ! $cents ) {
			return;
		}
		$edges[ $a . '|' . $b ][] = array( 'id' => $bk['id'], 'date' => $bk['date'], 'title' => $bk['title'], 'amount' => $cents, 'why' => $why );
	};
	$name = fn( $id ) => $id ? glp_user_name( $id ) : 'Bandkasse';
	foreach ( array_reverse( $rows ) as $b ) {
		if ( $b['status'] !== 'ok' ) {
			continue;
		}
		$a  = $b['amount'];
		$eu = glp_cash_money( $a );
		switch ( $b['kind'] ) {
			case 'out':
				$b['split'] = glp_cash_split( $b );
				if ( $b['split'] ) {
					$n = count( $b['split'] );
					foreach ( glp_cash_shares( $a, $b['split'] ) as $id => $share ) {
						$owe( $id, $b['from'], $share, $b, sprintf( '%s hat %s bezahlt · geteilt durch %d', $name( $b['from'] ), $eu, $n ) );
					}
				} elseif ( $b['from'] ) {
					$owe( 0, $b['from'], $a, $b, sprintf( '%s hat %s ausgelegt · Bandkasse erstattet', $name( $b['from'] ), $eu ) );
				}
				break;
			case 'in':
				if ( $b['split'] ) {
					$n = count( $b['split'] );
					foreach ( glp_cash_shares( $a, $b['split'] ) as $id => $share ) {
						$owe( $b['from'], $id, $share, $b, sprintf( '%s hat %s eingenommen · geteilt durch %d', $name( $b['from'] ), $eu, $n ) );
					}
				} elseif ( $b['from'] ) {
					$owe( $b['from'], 0, $a, $b, sprintf( '%s hat %s für die Band eingenommen', $name( $b['from'] ), $eu ) );
				}
				break;
			case 'move':
				// from zahlt an to: to „schuldet“ from den Betrag (verrechnet sich mit offenen Schulden)
				$owe( $b['to'], $b['from'], $a, $b, sprintf( '%s hat %s an %s gezahlt%s', $name( $b['from'] ), $eu, $name( $b['to'] ), $b['note'] !== '' ? ' (' . $b['note'] . ')' : '' ) );
				break;
		}
	}
	$out  = array();
	$done = array();
	foreach ( $edges as $key => $list ) {
		[ $x, $y ] = array_map( 'intval', explode( '|', $key ) );
		$pair = min( $x, $y ) . '|' . max( $x, $y );
		if ( isset( $done[ $pair ] ) ) {
			continue;
		}
		$done[ $pair ] = true;
		$xy    = $edges[ $x . '|' . $y ] ?? array();
		$yx    = $edges[ $y . '|' . $x ] ?? array();
		$net   = array_sum( array_column( $xy, 'amount' ) ) - array_sum( array_column( $yx, 'amount' ) );
		$items = array_merge( $xy, array_map( fn( $i ) => array_merge( $i, array( 'amount' => -$i['amount'] ) ), $yx ) );
		if ( ! $net ) {
			continue; // ausgeglichen
		}
		if ( $net < 0 ) { // umdrehen, damit amount positiv ist
			[ $x, $y ] = array( $y, $x );
			$net   = -$net;
			$items = array_map( fn( $i ) => array_merge( $i, array( 'amount' => -$i['amount'] ) ), $items );
		}
		usort( $items, fn( $p, $q ) => strcmp( $q['date'], $p['date'] ) ?: $q['id'] <=> $p['id'] );
		$out[] = array( 'debtor' => $x, 'creditor' => $y, 'amount' => $net, 'items' => $items );
	}
	usort( $out, fn( $p, $q ) => $q['amount'] <=> $p['amount'] );
	return $out;
}

/** Buchungsdaten aus der Anfrage prüfen */
function glp_cash_input( WP_REST_Request $req ) {
	$kind   = sanitize_key( (string) $req->get_param( 'kind' ) );
	$amount = (int) round( (float) str_replace( ',', '.', (string) $req->get_param( 'amount' ) ) * 100 );
	$date   = (string) $req->get_param( 'date' );
	$title  = mb_substr( sanitize_text_field( (string) $req->get_param( 'title' ) ), 0, 190 );
	$cat    = sanitize_key( (string) $req->get_param( 'category' ) );
	$from   = (int) $req->get_param( 'from' );
	$to     = (int) $req->get_param( 'to' );
	$member = array_column( glp_members(), 'id' );
	$split  = array_values( array_intersect( array_map( 'intval', (array) $req->get_param( 'split' ) ), $member ) );

	if ( ! in_array( $kind, array( 'out', 'in', 'move', 'fee' ), true ) ) {
		return new WP_Error( 'kind', 'Bitte eine Art wählen.' );
	}
	if ( $amount <= 0 || $amount > 100000000 ) {
		return new WP_Error( 'amount', 'Bitte einen Betrag größer 0 eingeben.' );
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return new WP_Error( 'date', 'Bitte ein Datum wählen.' );
	}
	if ( $from && ! in_array( $from, $member, true ) ) {
		return new WP_Error( 'from', 'Unbekanntes Mitglied.' );
	}
	if ( $kind === 'move' ) {
		if ( $to && ! in_array( $to, $member, true ) ) {
			return new WP_Error( 'to', 'Unbekanntes Mitglied.' );
		}
		if ( $from === $to ) {
			return new WP_Error( 'to', 'Von und An dürfen nicht gleich sein.' );
		}
		$split = array();
	} else {
		$to = 0;
	}
	if ( $kind === 'fee' ) {
		if ( ! $from ) {
			return new WP_Error( 'from', 'Bitte wählen, wer den Beitrag zahlt.' );
		}
		$split = array();
		$cat   = 'beitrag';
	}
	if ( $title === '' ) {
		$title = array( 'out' => 'Ausgabe', 'in' => 'Einnahme', 'move' => 'Ausgleich', 'fee' => 'Beitrag' )[ $kind ];
	}
	return array(
		'kind'      => $kind,
		'amount'    => $amount,
		'booked_on' => $date,
		'title'     => $title,
		'category'  => array_key_exists( $cat, glp_cash_categories() ) ? $cat : ( $kind === 'move' ? '' : 'sonstiges' ),
		'from_id'   => $from,
		'to_id'     => $to,
		'split'     => wp_json_encode( $split ),
		'note'      => mb_substr( sanitize_textarea_field( (string) $req->get_param( 'note' ) ), 0, 2000 ),
	);
}

function glp_cash_money( $cents ) {
	return number_format( $cents / 100, 2, ',', '.' ) . ' €';
}

function glp_cash_can_edit( $row ) {
	return current_user_can( 'manage_options' ) || (int) $row->created_by === get_current_user_id();
}

/* ================= REST ================= */

add_action( 'rest_api_init', function () {
	$r = fn( $route, $m, $cb ) => register_rest_route( GLP_NS, $route, array( 'methods' => $m, 'callback' => $cb, 'permission_callback' => 'glp_can' ) );
	$r( '/cash', 'GET', 'glp_rest_cash' );
	$r( '/cash', 'POST', 'glp_rest_cash_create' );
	$r( '/cash/(?P<id>\d+)', 'POST', 'glp_rest_cash_update' );
	$r( '/cash/(?P<id>\d+)', 'DELETE', 'glp_rest_cash_delete' );
	$r( '/cash/(?P<id>\d+)/receipt', 'POST', 'glp_rest_cash_receipt' );
	$r( '/cash/(?P<id>\d+)/confirm', 'POST', 'glp_rest_cash_confirm' );
} );

function glp_cash_all() {
	global $wpdb;
	return array_map( 'glp_cash_row', (array) $wpdb->get_results( 'SELECT * FROM ' . glp_table( 'cash' ) . ' ORDER BY booked_on DESC, id DESC' ) );
}

function glp_rest_cash() {
	$rows = glp_cash_all();
	return glp_ok( array_merge( array( 'items' => $rows, 'categories' => glp_cash_categories(), 'debts' => glp_cash_debts( $rows ) ), glp_cash_balances( $rows ) ) );
}

function glp_rest_cash_create( WP_REST_Request $req ) {
	global $wpdb;
	$data = glp_cash_input( $req );
	if ( is_wp_error( $data ) ) {
		return glp_err( $data->get_error_message() );
	}
	$me = get_current_user_id();
	// Zahlungen (Ausgleich) gelten erst, wenn der Empfänger sie bestätigt.
	// Ausnahme: der Empfänger trägt sie selbst ein („Bar erhalten“).
	$data['status'] = $data['kind'] === 'move' && glp_cash_needs_confirm( $data['to_id'], $data['from_id'], $me ) ? 'pending' : 'ok';
	$wpdb->insert( glp_table( 'cash' ), $data + array( 'created_by' => $me, 'created_at' => current_time( 'mysql' ), 'confirmed_by' => $data['status'] === 'ok' && $data['kind'] === 'move' ? $me : 0 ) );
	$id = (int) $wpdb->insert_id;
	if ( ! $id ) {
		return glp_err( 'Buchung konnte nicht gespeichert werden.', 500 );
	}
	if ( $data['status'] === 'pending' ) {
		glp_log( 'Bandkasse: Zahlung gemeldet · ' . glp_user_name( $data['from_id'] ) . ' → ' . ( $data['to_id'] ? glp_user_name( $data['to_id'] ) : 'Bandkasse' ) . ' · ' . glp_cash_money( $data['amount'] ) );
		glp_cash_notify_pending( $id );
	} else {
		glp_log( 'Bandkasse: ' . $data['title'] . ' · ' . glp_cash_money( $data['amount'] ) );
	}
	return glp_ok( array( 'id' => $id, 'status' => $data['status'] ) );
}

/** Muss diese Zahlung noch vom Empfänger bestätigt werden? */
function glp_cash_needs_confirm( $to, $from, $me ) {
	if ( $to ) {
		return (int) $to !== (int) $me; // an ein Mitglied: nur der Empfänger selbst bestätigt sofort
	}
	return (int) $from === (int) $me; // an die Bandkasse: ein anderes Mitglied muss bestätigen
}

/** Darf $uid diese gemeldete Zahlung bestätigen? */
function glp_cash_can_confirm( $row, $uid ) {
	if ( $row->status !== 'pending' ) {
		return false;
	}
	if ( (int) $row->to_id ) {
		return (int) $row->to_id === (int) $uid;
	}
	return (int) $row->from_id !== (int) $uid; // Bandkasse: jeder außer dem Zahlenden
}

function glp_rest_cash_confirm( WP_REST_Request $req ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . glp_table( 'cash' ) . ' WHERE id = %d', (int) $req['id'] ) );
	$uid = get_current_user_id();
	if ( ! $row ) {
		return glp_err( 'Zahlung nicht gefunden.', 404 );
	}
	if ( ! glp_cash_can_confirm( $row, $uid ) ) {
		return glp_err( 'Diese Zahlung kann nur der Empfänger bestätigen.' );
	}
	$ok = (bool) $req->get_param( 'ok' );
	$wpdb->update( glp_table( 'cash' ), array( 'status' => $ok ? 'ok' : 'rejected', 'confirmed_by' => $uid, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
	$to = (int) $row->to_id ? glp_user_name( $row->to_id ) : 'Bandkasse';
	glp_log( 'Bandkasse: Zahlung ' . glp_user_name( $row->from_id ) . ' → ' . $to . ' · ' . glp_cash_money( (int) $row->amount ) . ( $ok ? ' bestätigt ✓' : ' nicht angekommen' ) );
	return glp_ok( array( 'status' => $ok ? 'ok' : 'rejected' ) );
}

/** Mail an den Empfänger: bitte bestätigen */
function glp_cash_notify_pending( $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . glp_table( 'cash' ) . ' WHERE id = %d', $id ) );
	if ( ! $row ) {
		return;
	}
	glp_push_safely( 'glp_push_cash_pending', $row );
	$to_ids = (int) $row->to_id ? array( (int) $row->to_id ) : array_values( array_diff( array_column( glp_members(), 'id' ), array( (int) $row->from_id ) ) );
	$from   = glp_user_name( $row->from_id );
	$amount = glp_cash_money( (int) $row->amount );
	$how    = $row->note !== '' ? ' (' . $row->note . ')' : '';
	foreach ( $to_ids as $uid ) {
		$u = get_userdata( $uid );
		if ( ! $u || ! is_email( $u->user_email ) ) {
			continue;
		}
		$target = (int) $row->to_id ? 'dir' : 'der Bandkasse';
		wp_mail(
			$u->user_email,
			sprintf( 'Bitte bestätigen: %s hat %s %s gezahlt', $from, $target, $amount ),
			sprintf( "Servus %s,

%s hat im Portal gemeldet, %s %s gezahlt zu haben%s.

Ist das Geld angekommen? Bitte kurz bestätigen:
%s

Erst nach deiner Bestätigung gilt die Schuld als bezahlt.
", glp_user_name( $uid ), $from, $target, $amount, $how, glp_portal_url() . '#kasse' )
		);
	}
}

function glp_rest_cash_update( WP_REST_Request $req ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . glp_table( 'cash' ) . ' WHERE id = %d', (int) $req['id'] ) );
	if ( ! $row ) {
		return glp_err( 'Buchung nicht gefunden.', 404 );
	}
	if ( ! glp_cash_can_edit( $row ) ) {
		return glp_err( 'Ändern können nur, wer die Buchung angelegt hat, und Admins.' );
	}
	$data = glp_cash_input( $req );
	if ( is_wp_error( $data ) ) {
		return glp_err( $data->get_error_message() );
	}
	if ( $data['kind'] === 'move' ) { // geänderte Zahlung muss der Empfänger erneut bestätigen
		$data['status']       = glp_cash_needs_confirm( $data['to_id'], $data['from_id'], get_current_user_id() ) ? 'pending' : 'ok';
		$data['confirmed_by'] = $data['status'] === 'ok' ? get_current_user_id() : 0;
	} else {
		$data['status'] = 'ok';
	}
	$wpdb->update( glp_table( 'cash' ), $data + array( 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
	if ( $data['status'] === 'pending' && $row->status !== 'pending' ) {
		glp_cash_notify_pending( (int) $row->id );
	}
	glp_log( 'Bandkasse geändert: ' . $data['title'] . ' · ' . glp_cash_money( $data['amount'] ) );
	return glp_ok( array( 'id' => (int) $row->id ) );
}

function glp_rest_cash_delete( WP_REST_Request $req ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . glp_table( 'cash' ) . ' WHERE id = %d', (int) $req['id'] ) );
	if ( ! $row ) {
		return glp_err( 'Buchung nicht gefunden.', 404 );
	}
	if ( ! glp_cash_can_edit( $row ) ) {
		return glp_err( 'Löschen können nur, wer die Buchung angelegt hat, und Admins.' );
	}
	if ( $row->receipt ) {
		@unlink( glp_audio_dir() . '/' . basename( $row->receipt ) );
	}
	$wpdb->delete( glp_table( 'cash' ), array( 'id' => $row->id ) );
	glp_log( 'Bandkasse: Buchung gelöscht (' . $row->title . ' · ' . glp_cash_money( (int) $row->amount ) . ')' );
	return glp_ok( array( 'deleted' => true ) );
}

const GLP_RECEIPT_TYPES = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic' );

function glp_rest_cash_receipt( WP_REST_Request $req ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . glp_table( 'cash' ) . ' WHERE id = %d', (int) $req['id'] ) );
	$f   = $req->get_file_params()['file'] ?? null;
	if ( ! $row ) {
		return glp_err( 'Buchung nicht gefunden.', 404 );
	}
	if ( ! glp_cash_can_edit( $row ) ) {
		return glp_err( 'Belege ändern können nur, wer die Buchung angelegt hat, und Admins.' );
	}
	$ok = glp_cash_store_receipt( $row, $f );
	return is_wp_error( $ok ) ? glp_err( $ok->get_error_message() ) : glp_ok( array( 'ok' => true ) );
}

/** Hochgeladene Datei als Beleg an eine Buchung hängen (ersetzt einen alten Beleg). Portal und Backend. */
function glp_cash_store_receipt( $row, $f ) {
	global $wpdb;
	if ( ! $f || ! empty( $f['error'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
		return new WP_Error( 'upload', 'Upload fehlgeschlagen.' );
	}
	$ext = strtolower( pathinfo( (string) $f['name'], PATHINFO_EXTENSION ) );
	if ( ! isset( GLP_RECEIPT_TYPES[ $ext ] ) ) {
		return new WP_Error( 'type', 'Bitte ein Foto (JPG, PNG, HEIC) oder PDF hochladen.' );
	}
	$file = 'beleg-' . wp_generate_password( 24, false ) . '.' . $ext;
	if ( ! @move_uploaded_file( $f['tmp_name'], glp_audio_dir() . '/' . $file ) ) {
		return new WP_Error( 'save', 'Beleg konnte nicht gespeichert werden.' );
	}
	glp_cash_remove_receipt( $row );
	$wpdb->update( glp_table( 'cash' ), array( 'receipt' => $file, 'receipt_name' => mb_substr( sanitize_file_name( (string) $f['name'] ), 0, 190 ) ), array( 'id' => $row->id ) );
	return true;
}

function glp_cash_remove_receipt( $row ) {
	global $wpdb;
	if ( $row->receipt ) {
		@unlink( glp_audio_dir() . '/' . basename( $row->receipt ) );
		$wpdb->update( glp_table( 'cash' ), array( 'receipt' => '', 'receipt_name' => '' ), array( 'id' => $row->id ) );
	}
}

/* ================= BELEG ANZEIGEN & CSV ================= */

add_action( 'template_redirect', function () {
	if ( ! get_query_var( 'glp_portal' ) || ( ! isset( $_GET['beleg'] ) && ! isset( $_GET['kasse_csv'] ) ) ) {
		return;
	}
	if ( ! is_user_logged_in() || ! current_user_can( 'gl_portal' ) ) {
		status_header( 403 );
		exit;
	}
	global $wpdb;
	if ( isset( $_GET['beleg'] ) ) {
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . glp_table( 'cash' ) . ' WHERE id = %d', (int) $_GET['beleg'] ) );
		$path = $row && $row->receipt ? glp_audio_dir() . '/' . basename( $row->receipt ) : '';
		if ( ! $path || ! is_file( $path ) ) {
			status_header( 404 );
			exit;
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		nocache_headers();
		header( 'Content-Type: ' . ( GLP_RECEIPT_TYPES[ $ext ] ?? 'application/octet-stream' ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: ' . ( $ext === 'heic' ? 'attachment' : 'inline' ) . '; filename="' . str_replace( '"', '', $row->receipt_name ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}
	// CSV für Steuer/Abrechnung (Excel-tauglich: Semikolon, UTF-8 mit BOM)
	$cats  = glp_cash_categories();
	$kinds = array( 'out' => 'Ausgabe', 'in' => 'Einnahme', 'move' => 'Ausgleich', 'fee' => 'Beitrag' );
	$name  = fn( $id ) => $id ? glp_user_name( $id ) : 'Bandkasse';
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="ghostlike-bandkasse-' . wp_date( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );
	fputcsv( $out, array( 'Datum', 'Art', 'Titel', 'Kategorie', 'Betrag (EUR)', 'Von / bezahlt durch', 'An', 'Kosten trägt', 'Notiz', 'Beleg', 'Erfasst von', 'Status' ), ';' );
	foreach ( array_reverse( glp_cash_all() ) as $b ) {
		$sign = in_array( $b['kind'], array( 'in', 'fee' ), true ) ? 1 : ( $b['kind'] === 'out' ? -1 : 0 );
		fputcsv( $out, array(
			wp_date( 'd.m.Y', strtotime( $b['date'] ) ),
			$kinds[ $b['kind'] ] ?? $b['kind'],
			$b['title'],
			$cats[ $b['category'] ] ?? '',
			number_format( $sign ? $sign * $b['amount'] / 100 : $b['amount'] / 100, 2, ',', '' ),
			$name( $b['from'] ),
			$b['kind'] === 'move' ? $name( $b['to'] ) : '',
			$b['split'] ? implode( ', ', array_map( 'glp_user_name', $b['split'] ) ) : 'Band',
			$b['note'],
			$b['receipt'] ? 'ja' : '',
			$b['byName'],
			array( 'ok' => 'bestätigt', 'pending' => 'gemeldet, unbestätigt', 'rejected' => 'nicht angekommen' )[ $b['status'] ] ?? $b['status'],
		), ';' );
	}
	fclose( $out );
	exit;
}, 4 );
