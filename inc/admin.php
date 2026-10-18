<?php
/**
 * Einstellungen → GHOSTLIKE Portal: Probe, Arbeitsschritte, Mitglieder, Version.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_options_page( 'GHOSTLIKE Portal', 'GHOSTLIKE Portal', 'manage_options', 'ghostlike-portal', 'glp_admin_page' );
} );

add_filter( 'plugin_action_links_' . plugin_basename( GLP_FILE ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( glp_portal_url() ) . '">Portal öffnen</a>', '<a href="' . esc_url( admin_url( 'options-general.php?page=ghostlike-portal' ) ) . '">Einstellungen</a>' );
	return $links;
} );

add_action( 'admin_post_glp_save', function () {
	check_admin_referer( 'glp_save' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Keine Berechtigung.' );
	}
	$in = wp_unslash( $_POST );

	// Probe
	$time = preg_match( '/^\d{2}:\d{2}$/', $in['probe_time'] ?? '' ) ? $in['probe_time'] : '19:30';
	update_option( 'glp_probe', array(
		'active'  => empty( $in['probe_active'] ) ? 0 : 1,
		'weekday' => max( 0, min( 6, (int) ( $in['probe_weekday'] ?? 2 ) ) ),
		'time'    => $time,
		'place'   => sanitize_text_field( $in['probe_place'] ?? 'Proberaum' ),
	) );

	// Arbeitsschritte
	$groups = glp_groups();
	$types  = glp_step_types();
	$steps  = array();
	$keys   = array();
	foreach ( (array) ( $in['steps'] ?? array() ) as $row ) {
		$l = sanitize_text_field( $row['l'] ?? '' );
		if ( $l === '' || ! empty( $row['remove'] ) ) {
			continue;
		}
		$k = sanitize_key( $row['k'] ?? '' ) ?: sanitize_key( remove_accents( $l ) );
		while ( in_array( $k, $keys, true ) ) {
			$k .= '2';
		}
		$keys[]  = $k;
		$steps[] = array(
			'k'   => $k,
			'l'   => $l,
			'g'   => isset( $groups[ $row['g'] ?? '' ] ) ? $row['g'] : 'rec',
			't'   => isset( $types[ $row['t'] ?? '' ] ) ? $row['t'] : 'rec',
			'w'   => max( 0, min( 10, (int) ( $row['w'] ?? 1 ) ) ),
			'who' => array_values( array_filter( array_map( 'trim', array_map( 'sanitize_text_field', explode( ',', $row['who'] ?? '' ) ) ) ) ),
		);
	}
	if ( $steps ) {
		update_option( 'glp_steps', $steps );
	}
	wp_safe_redirect( admin_url( 'options-general.php?page=ghostlike-portal&saved=1' ) );
	exit;
} );

add_action( 'admin_post_glp_check_updates', function () {
	check_admin_referer( 'glp_check_updates' );
	if ( ! current_user_can( 'update_plugins' ) ) {
		wp_die( 'Keine Berechtigung.' );
	}
	glp_latest_release( true );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	wp_safe_redirect( admin_url( 'options-general.php?page=ghostlike-portal&checked=1' ) );
	exit;
} );

function glp_admin_page() {
	$probe   = glp_probe_settings();
	$steps   = glp_steps();
	$groups  = glp_groups();
	$types   = glp_step_types();
	$release = glp_latest_release();
	$members = glp_members();
	$days    = array( 'Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag' );
	$steps[] = array( 'k' => '', 'l' => '', 'g' => 'rec', 't' => 'rec', 'w' => 1, 'who' => array() ); // leere Zeile zum Hinzufügen
	?>
	<div class="wrap">
		<h1>GHOSTLIKE Portal</h1>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div><?php endif; ?>
		<?php if ( isset( $_GET['checked'] ) ) : ?><div class="notice notice-info is-dismissible"><p>Update-Prüfung abgeschlossen.</p></div><?php endif; ?>

		<p><a class="button button-primary" href="<?php echo esc_url( glp_portal_url() ); ?>" target="_blank">Portal öffnen ↗</a></p>

		<h2>Bandmitglieder</h2>
		<p style="max-width:760px"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ghostlike-members' ) ); ?>">Bandmitglieder verwalten &amp; einladen</a></p>
		<p style="max-width:760px">Jedes Mitglied braucht einen eigenen WordPress-Benutzer mit der Rolle <strong>Bandmitglied</strong>, am einfachsten über die Einladung oben. Der <strong>Vorname</strong> wird im Portal angezeigt und mit den Zuständigkeiten unten abgeglichen. Bandmitglieder sehen kein Backend, sie landen nach dem Login direkt im Portal.</p>
		<p><?php echo $members ? 'Im Portal: <strong>' . esc_html( implode( ', ', array_column( $members, 'name' ) ) ) . '</strong>' : 'Noch niemand.'; ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'glp_save' ); ?>
			<input type="hidden" name="action" value="glp_save">

			<h2>Wöchentliche Bandprobe</h2>
			<table class="form-table"><tbody>
				<tr><th>Aktiv</th><td><label><input type="checkbox" name="probe_active" value="1" <?php checked( $probe['active'] ); ?>> Probe jede Woche automatisch im Kalender</label></td></tr>
				<tr><th>Wochentag</th><td><select name="probe_weekday"><?php foreach ( $days as $i => $d ) : ?><option value="<?php echo (int) $i; ?>" <?php selected( (int) $probe['weekday'], $i ); ?>><?php echo esc_html( $d ); ?></option><?php endforeach; ?></select></td></tr>
				<tr><th>Uhrzeit</th><td><input type="time" name="probe_time" value="<?php echo esc_attr( $probe['time'] ); ?>"></td></tr>
				<tr><th>Ort</th><td><input type="text" class="regular-text" name="probe_place" value="<?php echo esc_attr( $probe['place'] ); ?>"></td></tr>
			</tbody></table>

			<h2>Arbeitsschritte im Studio-Tool</h2>
			<p style="max-width:760px">Gewicht = wie stark der Schritt in den Fortschritt einfließt (0–10). Zuständig = Vornamen mit Komma. Neue Schritte (z. B. „Reamping“ oder „Gang-Vocals“) in der leeren Zeile unten eintragen. Entfernen löscht nur die Spalte, der gespeicherte Stand bleibt erhalten, falls ihr sie wieder anlegt.</p>
			<table class="widefat striped" style="max-width:1000px">
				<thead><tr><th>Name</th><th>Bereich</th><th>Status-Art</th><th style="width:70px">Gewicht</th><th>Zuständig</th><th>Entfernen</th></tr></thead>
				<tbody>
				<?php foreach ( $steps as $i => $s ) : ?>
					<tr>
						<td><input type="hidden" name="steps[<?php echo (int) $i; ?>][k]" value="<?php echo esc_attr( $s['k'] ); ?>"><input type="text" name="steps[<?php echo (int) $i; ?>][l]" value="<?php echo esc_attr( $s['l'] ); ?>" placeholder="<?php echo $s['k'] ? '' : '+ neuer Schritt'; ?>"></td>
						<td><select name="steps[<?php echo (int) $i; ?>][g]"><?php foreach ( $groups as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['g'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td>
						<td><select name="steps[<?php echo (int) $i; ?>][t]"><?php foreach ( $types as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['t'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td>
						<td><input type="number" min="0" max="10" name="steps[<?php echo (int) $i; ?>][w]" value="<?php echo (int) $s['w']; ?>" style="width:60px"></td>
						<td><input type="text" name="steps[<?php echo (int) $i; ?>][who]" value="<?php echo esc_attr( implode( ', ', $s['who'] ) ); ?>"></td>
						<td><?php if ( $s['k'] ) : ?><label><input type="checkbox" name="steps[<?php echo (int) $i; ?>][remove]" value="1"> ✕</label><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( 'Speichern' ); ?>
		</form>

		<h2>Version &amp; Updates</h2>
		<table class="widefat striped" style="max-width:720px"><tbody>
			<tr><td>Installiert</td><td><strong><?php echo esc_html( GLP_VERSION ); ?></strong></td></tr>
			<tr><td>Neueste auf GitHub</td><td><?php echo $release ? '<strong>' . esc_html( $release['version'] ) . '</strong>' : '– (noch kein Release gefunden)'; ?></td></tr>
			<tr><td>Repository</td><td><a href="<?php echo esc_url( 'https://github.com/' . GLP_GITHUB_REPO . '/releases' ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( GLP_GITHUB_REPO ); ?></code> – alle Versionen ↗</a></td></tr>
		</tbody></table>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=glp_check_updates' ), 'glp_check_updates' ) ); ?>">Jetzt nach Updates suchen</a></p>
	</div>
	<?php
}
