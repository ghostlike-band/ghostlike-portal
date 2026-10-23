<?php
/**
 * Backend → GHOSTLIKE → Bandkasse: alle Einträge ansehen, korrigieren, löschen (nur Admins).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	global $admin_page_hooks;
	$parent = isset( $admin_page_hooks['ghostlike'] ) ? 'ghostlike' : 'tools.php';
	add_submenu_page( $parent, 'Bandkasse', 'Bandkasse', 'manage_options', 'ghostlike-cash', 'glp_cash_admin_page', 2 );
}, 20 );

/* ================= AKTIONEN ================= */

add_action( 'admin_post_glp_cash', function () {
	check_admin_referer( 'glp_cash' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Keine Berechtigung.' );
	}
	global $wpdb;
	$in   = wp_unslash( $_POST );
	$id   = (int) ( $in['id'] ?? 0 );
	$back = admin_url( 'admin.php?page=ghostlike-cash' );
	$row  = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . glp_table( 'cash' ) . ' WHERE id = %d', $id ) ) : null;
	if ( $id && ! $row ) {
		wp_safe_redirect( add_query_arg( 'm', rawurlencode( 'err:Eintrag nicht gefunden.' ), $back ) );
		exit;
	}

	if ( ( $in['do'] ?? '' ) === 'delete' ) {
		if ( $row->receipt ) {
			@unlink( glp_audio_dir() . '/' . basename( $row->receipt ) );
		}
		$wpdb->delete( glp_table( 'cash' ), array( 'id' => $row->id ) );
		glp_log( 'Bandkasse (Backend): gelöscht – ' . $row->title . ' · ' . glp_cash_money( (int) $row->amount ) );
		wp_safe_redirect( add_query_arg( 'm', rawurlencode( 'ok:„' . $row->title . '“ gelöscht.' ), $back ) );
		exit;
	}

	// Gleiche Prüfung wie im Portal
	$req = new WP_REST_Request( 'POST' );
	foreach ( array( 'kind', 'amount', 'date', 'title', 'category', 'from', 'to', 'note' ) as $k ) {
		$req->set_param( $k, $in[ $k ] ?? '' );
	}
	$req->set_param( 'split', array_map( 'intval', (array) ( $in['split'] ?? array() ) ) );
	$data = glp_cash_input( $req );
	if ( is_wp_error( $data ) ) {
		wp_safe_redirect( add_query_arg( array( 'm' => rawurlencode( 'err:' . $data->get_error_message() ), 'edit' => $id ?: 'new' ), $back ) );
		exit;
	}
	// Admin legt den Status direkt fest – keine Bestätigung nötig
	$status               = in_array( $in['status'] ?? 'ok', array( 'ok', 'pending', 'rejected' ), true ) ? $in['status'] : 'ok';
	$data['status']       = $data['kind'] === 'move' ? $status : 'ok';
	$data['confirmed_by'] = $data['status'] === 'ok' ? get_current_user_id() : 0;
	$data['updated_at']   = current_time( 'mysql' );

	if ( $row ) {
		$wpdb->update( glp_table( 'cash' ), $data, array( 'id' => $row->id ) );
		glp_log( 'Bandkasse (Backend): geändert – ' . $data['title'] . ' · ' . glp_cash_money( $data['amount'] ) );
		$msg = 'ok:„' . $data['title'] . '“ gespeichert.';
	} else {
		$wpdb->insert( glp_table( 'cash' ), $data + array( 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql' ) ) );
		glp_log( 'Bandkasse (Backend): neu – ' . $data['title'] . ' · ' . glp_cash_money( $data['amount'] ) );
		$msg = 'ok:„' . $data['title'] . '“ angelegt.';
	}
	wp_safe_redirect( add_query_arg( 'm', rawurlencode( $msg ), $back ) );
	exit;
} );

/* ================= SEITE ================= */

function glp_cash_admin_page() {
	global $wpdb;
	$m = isset( $_GET['m'] ) ? sanitize_text_field( wp_unslash( $_GET['m'] ) ) : '';
	[ $type, $text ] = array_pad( explode( ':', $m, 2 ), 2, '' );
	$cls  = array( 'ok' => 'notice-success', 'err' => 'notice-error' );
	$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
	echo '<div class="wrap">';
	if ( $text && isset( $cls[ $type ] ) ) {
		echo '<div class="notice ' . esc_attr( $cls[ $type ] ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
	if ( $edit !== '' ) {
		$row = $edit === 'new' ? null : $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . glp_table( 'cash' ) . ' WHERE id = %d', (int) $edit ) );
		glp_cash_admin_form( $row ? glp_cash_row( $row ) : null );
	} else {
		glp_cash_admin_list();
	}
	echo '</div>';
}

function glp_cash_admin_who( $id ) {
	return $id ? glp_user_name( $id ) : 'Bandkasse';
}

function glp_cash_admin_list() {
	global $wpdb;
	$rows  = array_map( 'glp_cash_row', $wpdb->get_results( 'SELECT * FROM ' . glp_table( 'cash' ) . ' ORDER BY booked_on DESC, id DESC' ) );
	$kinds = array( 'out' => 'Ausgabe', 'in' => 'Einnahme', 'move' => 'Ausgleich', 'fee' => 'Beitrag' );
	$stat  = array( 'ok' => '✓', 'pending' => '⏳ unbestätigt', 'rejected' => '✗ nicht angekommen' );
	$all   = array_map( 'intval', array_column( glp_members(), 'id' ) );
	$base  = admin_url( 'admin.php?page=ghostlike-cash' );
	?>
	<h1 class="wp-heading-inline">Bandkasse</h1>
	<a class="page-title-action" href="<?php echo esc_url( add_query_arg( 'edit', 'new', $base ) ); ?>">Eintrag hinzufügen</a>
	<a class="page-title-action" href="<?php echo esc_url( add_query_arg( 'kasse_csv', 1, glp_portal_url() ) ); ?>">CSV exportieren</a>
	<p style="max-width:760px">Alle Einträge aus der Bandkasse im <a href="<?php echo esc_url( glp_portal_url() ); ?>#kasse" target="_blank">Portal ↗</a>. Hier kannst du versehentliche Einträge korrigieren oder löschen. Die offenen Beträge im Portal rechnen sich danach automatisch neu.</p>
	<table class="widefat striped" style="max-width:1200px">
		<thead><tr><th>Datum</th><th>Art</th><th>Wofür</th><th style="text-align:right">Betrag</th><th>Wer</th><th>Aufgeteilt auf</th><th>Status</th><th>Angelegt von</th><th></th></tr></thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr><td colspan="9">Noch keine Einträge.</td></tr>
		<?php endif; ?>
		<?php foreach ( $rows as $b ) :
			$split = glp_cash_split( $b );
			$who   = $b['kind'] === 'move' ? glp_cash_admin_who( $b['from'] ) . ' → ' . glp_cash_admin_who( $b['to'] ) : ( $b['kind'] === 'in' ? 'Geld bei: ' : 'Bezahlt von: ' ) . glp_cash_admin_who( $b['from'] );
			$split_txt = ! $split ? '–' : ( count( $split ) === count( $all ) && ! array_diff( $all, $split ) ? 'alle (' . count( $split ) . ')' : implode( ', ', array_map( 'glp_user_name', $split ) ) );
			?>
			<tr>
				<td><?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $b['date'] ) ) ); ?></td>
				<td><?php echo esc_html( $kinds[ $b['kind'] ] ?? $b['kind'] ); ?></td>
				<td><strong><?php echo esc_html( $b['title'] ); ?></strong><?php echo $b['note'] !== '' ? '<br><span class="description">' . esc_html( $b['note'] ) . '</span>' : ''; ?></td>
				<td style="text-align:right;white-space:nowrap"><?php echo esc_html( glp_cash_money( $b['amount'] ) ); ?></td>
				<td><?php echo esc_html( $who ); ?></td>
				<td><?php echo esc_html( $split_txt ); ?></td>
				<td><?php echo esc_html( $stat[ $b['status'] ] ?? $b['status'] ); ?></td>
				<td><?php echo esc_html( $b['byName'] ); ?></td>
				<td style="white-space:nowrap">
					<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'edit', $b['id'], $base ) ); ?>">Bearbeiten</a>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( '„' . $b['title'] . '“ (' . glp_cash_money( $b['amount'] ) . ') wirklich löschen?' ); ?>')">
						<?php wp_nonce_field( 'glp_cash' ); ?>
						<input type="hidden" name="action" value="glp_cash"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
						<button class="button button-small button-link-delete">Löschen</button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function glp_cash_admin_form( $b ) {
	$d       = $b ?: array( 'id' => 0, 'kind' => 'out', 'amount' => 0, 'date' => current_time( 'Y-m-d' ), 'title' => '', 'category' => 'sonstiges', 'from' => get_current_user_id(), 'to' => 0, 'split' => array(), 'note' => '', 'status' => 'ok' );
	$members = glp_members();
	$all     = array_map( 'intval', array_column( $members, 'id' ) );
	$split   = ( count( $d['split'] ) === count( $all ) && ! array_diff( $all, $d['split'] ) ) ? array() : $d['split']; // „alle“ = nichts angehakt
	$people  = function ( $sel ) use ( $members ) {
		echo '<option value="0"' . selected( $sel, 0, false ) . '>Bandkasse</option>';
		foreach ( $members as $m ) {
			echo '<option value="' . (int) $m['id'] . '"' . selected( $sel, (int) $m['id'], false ) . '>' . esc_html( $m['name'] ) . '</option>';
		}
	};
	?>
	<h1><?php echo $b ? 'Eintrag bearbeiten' : 'Eintrag hinzufügen'; ?></h1>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ghostlike-cash' ) ); ?>">← Zurück zur Liste</a></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'glp_cash' ); ?>
		<input type="hidden" name="action" value="glp_cash"><input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
		<table class="form-table" role="presentation">
			<tr><th><label for="c-kind">Art</label></th><td><select name="kind" id="c-kind">
				<?php foreach ( array( 'out' => 'Ausgabe', 'in' => 'Einnahme (Gage, Merch …)', 'move' => 'Ausgleich (jemand hat Geld an jemanden gezahlt)', 'fee' => 'Beitrag in die Bandkasse' ) as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $d['kind'], $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select></td></tr>
			<tr><th><label for="c-amount">Betrag (€)</label></th><td><input name="amount" id="c-amount" required inputmode="decimal" value="<?php echo $d['amount'] ? esc_attr( number_format( $d['amount'] / 100, 2, ',', '' ) ) : ''; ?>"></td></tr>
			<tr><th><label for="c-title">Wofür</label></th><td><input name="title" id="c-title" class="regular-text" maxlength="190" value="<?php echo esc_attr( $d['title'] ); ?>"></td></tr>
			<tr><th><label for="c-date">Datum</label></th><td><input type="date" name="date" id="c-date" required value="<?php echo esc_attr( $d['date'] ); ?>"></td></tr>
			<tr><th><label for="c-from">Wer</label></th><td><select name="from" id="c-from"><?php $people( (int) $d['from'] ); ?></select>
				<p class="description">Ausgabe: wer bezahlt hat · Einnahme: wo das Geld jetzt ist · Ausgleich: wer gezahlt hat · Beitrag: wer einzahlt</p></td></tr>
			<tr><th><label for="c-to">An (nur Ausgleich)</label></th><td><select name="to" id="c-to"><?php $people( (int) $d['to'] ); ?></select></td></tr>
			<tr><th>Aufgeteilt auf (nur Ausgabe)</th><td><fieldset>
				<?php foreach ( $members as $m ) : ?>
					<label style="margin-right:14px"><input type="checkbox" name="split[]" value="<?php echo (int) $m['id']; ?>" <?php checked( in_array( (int) $m['id'], $split, true ) ); ?>> <?php echo esc_html( $m['name'] ); ?></label>
				<?php endforeach; ?>
				<p class="description">Nichts angehakt = auf alle Mitglieder aufteilen.</p></fieldset></td></tr>
			<tr><th><label for="c-cat">Kategorie</label></th><td><select name="category" id="c-cat">
				<?php foreach ( glp_cash_categories() as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $d['category'], $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select></td></tr>
			<tr><th><label for="c-status">Status (nur Ausgleich)</label></th><td><select name="status" id="c-status">
				<option value="ok" <?php selected( $d['status'], 'ok' ); ?>>✓ bestätigt</option>
				<option value="pending" <?php selected( $d['status'], 'pending' ); ?>>⏳ wartet auf Bestätigung</option>
				<option value="rejected" <?php selected( $d['status'], 'rejected' ); ?>>✗ nicht angekommen</option>
			</select></td></tr>
			<tr><th><label for="c-note">Notiz</label></th><td><textarea name="note" id="c-note" rows="2" class="large-text"><?php echo esc_textarea( $d['note'] ); ?></textarea></td></tr>
		</table>
		<?php submit_button( $b ? 'Änderungen speichern' : 'Eintrag anlegen' ); ?>
	</form>
	<?php
}
