<?php
/**
 * Updates per Klick über GitHub Releases (wie beim Theme).
 * Erwartet am Release die Datei „ghostlike-portal.zip“ mit dem Ordner „ghostlike-portal/“.
 */

defined( 'ABSPATH' ) || exit;

const GLP_RELEASE_CACHE = 'glp_github_release';

function glp_latest_release( $force = false ) {
	if ( ! $force ) {
		$cached = get_site_transient( GLP_RELEASE_CACHE );
		if ( $cached !== false ) {
			return $cached ?: null;
		}
	}

	// 1. Versionsdatei über den normalen Download-Weg (kein API-Limit, wichtig bei geteilter IP)
	$release = null;
	$res     = wp_remote_get( 'https://github.com/' . GLP_GITHUB_REPO . '/releases/latest/download/release.json', array(
		'timeout'     => 15,
		'redirection' => 5,
		'headers'     => array( 'Accept' => 'application/json', 'User-Agent' => 'ghostlike-portal/' . GLP_VERSION ),
	) );
	if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
		$d = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! empty( $d['version'] ) && ! empty( $d['package'] ) ) {
			$release = array(
				'version' => ltrim( (string) $d['version'], 'vV' ),
				'package' => (string) $d['package'],
				'url'     => (string) ( $d['url'] ?? '' ),
				'notes'   => (string) ( $d['notes'] ?? '' ),
				'date'    => (string) ( $d['date'] ?? '' ),
			);
		}
	}

	// 2. Ersatzweg: GitHub-API (max. 60 Anfragen/Stunde pro IP)
	if ( ! $release ) {
		$res = wp_remote_get( 'https://api.github.com/repos/' . GLP_GITHUB_REPO . '/releases/latest', array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'ghostlike-portal/' . GLP_VERSION ),
		) );
		$code = is_wp_error( $res ) ? 0 : wp_remote_retrieve_response_code( $res );
		if ( $code === 200 ) {
			$data    = json_decode( wp_remote_retrieve_body( $res ), true );
			$package = '';
			foreach ( (array) ( $data['assets'] ?? array() ) as $a ) {
				if ( ( $a['name'] ?? '' ) === 'ghostlike-portal.zip' ) {
					$package = $a['browser_download_url'];
				}
			}
			if ( ! empty( $data['tag_name'] ) && $package ) {
				$release = array(
					'version' => ltrim( $data['tag_name'], 'vV' ),
					'package' => $package,
					'url'     => $data['html_url'] ?? '',
					'notes'   => $data['body'] ?? '',
					'date'    => $data['published_at'] ?? '',
				);
			}
		}
		if ( ! $release ) {
			$why = is_wp_error( $res ) ? $res->get_error_message() : ( $code === 403 ? 'GitHub-Abfragelimit erreicht, später erneut versuchen' : 'GitHub antwortet mit ' . $code );
			update_option( 'glp_update_error', $why . ' (' . wp_date( 'd.m. H:i' ) . ')', false );
			set_site_transient( GLP_RELEASE_CACHE, 0, 15 * MINUTE_IN_SECONDS );
			return null;
		}
	}

	delete_option( 'glp_update_error' );
	set_site_transient( GLP_RELEASE_CACHE, $release, 6 * HOUR_IN_SECONDS );
	return $release;
}

add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}
	$release = glp_latest_release();
	if ( ! $release ) {
		return $transient;
	}
	$file = plugin_basename( GLP_FILE );
	$item = (object) array(
		'id'           => 'github.com/' . GLP_GITHUB_REPO,
		'slug'         => dirname( $file ),
		'plugin'       => $file,
		'new_version'  => $release['version'],
		'url'          => $release['url'],
		'package'      => $release['package'],
		'requires'     => '6.4',
		'requires_php' => '8.0',
	);
	if ( version_compare( $release['version'], GLP_VERSION, '>' ) ) {
		$transient->response[ $file ] = $item;
	} else {
		unset( $transient->response[ $file ] );
		$transient->no_update[ $file ] = $item;
	}
	return $transient;
} );

// „Details ansehen“ im Update-Fenster.
add_filter( 'plugins_api', function ( $result, $action, $args ) {
	if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== dirname( plugin_basename( GLP_FILE ) ) ) {
		return $result;
	}
	$release = glp_latest_release();
	return (object) array(
		'name'          => 'GHOSTLIKE Member-Portal',
		'slug'          => $args->slug,
		'version'       => $release['version'] ?? GLP_VERSION,
		'author'        => 'GHOSTLIKE',
		'homepage'      => 'https://github.com/' . GLP_GITHUB_REPO,
		'download_link' => $release['package'] ?? '',
		'sections'      => array( 'changelog' => wpautop( esc_html( $release['notes'] ?? '' ) ) ),
	);
}, 10, 3 );

// Ordnernamen beim Update beibehalten.
add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	if ( ( $hook_extra['plugin'] ?? '' ) !== plugin_basename( GLP_FILE ) ) {
		return $source;
	}
	$desired = trailingslashit( $remote_source ) . dirname( plugin_basename( GLP_FILE ) ) . '/';
	if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
		return $source;
	}
	global $wp_filesystem;
	return ( $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) ? $desired : $source;
}, 10, 4 );

add_action( 'upgrader_process_complete', function () {
	delete_site_transient( GLP_RELEASE_CACHE );
} );
