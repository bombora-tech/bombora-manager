<?php
/**
 * GitHub Releases: zjištění poslední verze a bezpečné stažení balíčku.
 *
 * Bezpečnost:
 *  - pouze HTTPS + povolení hostitelé (API, CDN přesměrování), přesměrování ručně a BEZ tokenu;
 *  - ZIP se ověří proti SHA-256 z assetu „<asset>.sha256“ (hash_equals), limit velikosti;
 *  - kontrola struktury ZIPu: vše musí ležet v očekávané složce pluginu, žádné „..“ ani absolutní cesty;
 *  - SemVer X.Y.Z, draft/prerelease ignorovány; repozitáře pouze z pevného katalogu;
 *  - volitelný token jen z konstanty BOMBORA_GITHUB_TOKEN (wp-config), nikdy v DB ani ve výstupu.
 *
 * @package Bombora\Manager
 */

namespace Bombora\Manager;

defined( 'ABSPATH' ) || exit;

final class GitHub {

	private const TTL       = 6 * HOUR_IN_SECONDS;
	private const ERROR_TTL = HOUR_IN_SECONDS;
	private const MAX_ZIP   = 20971520; // 20 MB.
	private const HOSTS     = array( 'api.github.com', 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com', 'github-releases.githubusercontent.com' );

	private static function cache_key( string $repo ): string {
		return 'bombora_rel_' . md5( $repo );
	}

	public static function clear_cache(): void {
		foreach ( Catalog::all() as $m ) {
			delete_site_transient( self::cache_key( $m['repo'] ) );
			delete_site_transient( self::cache_key( $m['repo'] ) . '_err' );
		}
	}

	public static function last_error( string $repo ): string {
		$e = get_site_transient( self::cache_key( $repo ) . '_err' );
		return is_string( $e ) ? $e : '';
	}

	private static function has_token(): bool {
		return defined( 'BOMBORA_GITHUB_TOKEN' ) && is_string( BOMBORA_GITHUB_TOKEN ) && '' !== BOMBORA_GITHUB_TOKEN;
	}

	/**
	 * @return array<string,string>
	 */
	private static function headers( string $accept ): array {
		$h = array(
			'Accept'               => $accept,
			'User-Agent'           => 'Bombora-Manager/' . BOMBORA_MANAGER_VERSION,
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( self::has_token() ) {
			$h['Authorization'] = 'Bearer ' . BOMBORA_GITHUB_TOKEN;
		}
		return $h;
	}

	public static function host_allowed( string $url ): bool {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || 'https' !== ( $p['scheme'] ?? '' ) || empty( $p['host'] ) ) {
			return false;
		}
		$host = strtolower( $p['host'] );
		return in_array( $host, self::HOSTS, true ) || (bool) preg_match( '/\.githubusercontent\.com$/', $host );
	}

	/**
	 * Poslední release modulu (cache 6 h, chyba 1 h).
	 *
	 * @param array<string,string> $module Položka katalogu.
	 * @return array<string,string>|null
	 */
	public static function latest( array $module, bool $allow_remote = true ): ?array {
		$repo  = $module['repo'];
		$key   = self::cache_key( $repo );
		$cache = get_site_transient( $key );
		if ( is_array( $cache ) ) {
			return empty( $cache ) ? null : $cache;
		}
		if ( ! $allow_remote || ! preg_match( '#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#', $repo ) ) {
			return null;
		}

		$fail = static function ( string $msg ) use ( $key ) {
			set_site_transient( $key, array(), self::ERROR_TTL );
			set_site_transient( $key . '_err', $msg, self::ERROR_TTL );
			return null;
		};

		$resp = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => self::headers( 'application/vnd.github+json' ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $fail( $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 404 === $code ) {
			return $fail( 'no-release' );
		}
		if ( 200 !== $code ) {
			return $fail( sprintf( 'GitHub API HTTP %d', $code ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return $fail( 'Invalid release data' );
		}
		$version = ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return $fail( 'Invalid version tag' );
		}

		$zip = '';
		$sha = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			$api  = (string) ( $asset['url'] ?? '' );
			if ( ! self::host_allowed( $api ) ) {
				continue;
			}
			if ( $module['asset'] === $name ) {
				$zip = $api;
			} elseif ( $module['asset'] . '.sha256' === $name ) {
				$sha = $api;
			}
		}
		if ( '' === $zip || '' === $sha ) {
			return $fail( 'Release is missing ' . $module['asset'] . ' or its .sha256' );
		}

		$info = array(
			'version'   => $version,
			'zip'       => $zip,
			'sha'       => $sha,
			'notes'     => substr( (string) ( $data['body'] ?? '' ), 0, 20000 ),
			'html_url'  => self::host_allowed( (string) ( $data['html_url'] ?? '' ) ) ? (string) $data['html_url'] : '',
			'published' => (string) ( $data['published_at'] ?? '' ),
		);
		set_site_transient( $key, $info, self::TTL );
		delete_site_transient( $key . '_err' );
		return $info;
	}

	/**
	 * Stáhne a ověří balíček. Vrací cestu k dočasnému ZIPu.
	 *
	 * @param array<string,string> $module  Položka katalogu.
	 * @param array<string,string> $release Výsledek latest().
	 * @return string|\WP_Error
	 */
	public static function download_verified( array $module, array $release ) {
		$sha_body = self::fetch( $release['sha'], null );
		if ( is_wp_error( $sha_body ) ) {
			return $sha_body;
		}
		if ( ! preg_match( '/\b([a-f0-9]{64})\b/i', (string) $sha_body, $m ) ) {
			return new \WP_Error( 'bombora_sha_missing', 'Bombora: invalid SHA-256 file in release.' );
		}
		$expected = strtolower( $m[1] );

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = wp_tempnam( $module['asset'] );
		if ( ! $tmp ) {
			return new \WP_Error( 'bombora_tmp', 'Bombora: could not create a temporary file.' );
		}
		$ok = self::fetch( $release['zip'], $tmp );
		if ( is_wp_error( $ok ) ) {
			wp_delete_file( $tmp );
			return $ok;
		}

		$size = (int) filesize( $tmp );
		if ( $size <= 0 || $size > self::MAX_ZIP ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'bombora_size', 'Bombora: unexpected package size.' );
		}
		$actual = hash_file( 'sha256', $tmp );
		if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'bombora_sha_mismatch', 'Bombora: SHA-256 checksum mismatch – installation aborted.' );
		}

		$folder = strtok( $module['file'], '/' );
		$check  = self::check_zip_structure( $tmp, (string) $folder );
		if ( is_wp_error( $check ) ) {
			wp_delete_file( $tmp );
			return $check;
		}
		return $tmp;
	}

	/**
	 * Všechny položky ZIPu musí být uvnitř „<folder>/“, bez traversalu a absolutních cest.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_zip_structure( string $file, string $folder ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return true; // Bez ZipArchive spoléhá na SHA-256 a ochrany unzip_file().
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			return new \WP_Error( 'bombora_zip', 'Bombora: package is not a valid ZIP.' );
		}
		$prefix = $folder . '/';
		$bad    = '';
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			$norm = str_replace( '\\', '/', $name );
			if ( 0 !== strpos( $norm, $prefix ) || false !== strpos( $norm, '../' ) || '/' === substr( $norm, 0, 1 ) || preg_match( '/^[A-Za-z]:/', $norm ) ) {
				$bad = $name;
				break;
			}
		}
		$zip->close();
		if ( '' !== $bad ) {
			return new \WP_Error( 'bombora_zip_layout', sprintf( 'Bombora: unexpected file in package (%s).', sanitize_text_field( $bad ) ) );
		}
		return true;
	}

	/**
	 * @return string|true|\WP_Error
	 */
	private static function fetch( string $api_url, ?string $file ) {
		if ( ! self::host_allowed( $api_url ) ) {
			return new \WP_Error( 'bombora_host', 'Bombora: disallowed download host.' );
		}
		$args = array(
			'timeout'     => 60,
			'redirection' => 0,
			'headers'     => self::headers( 'application/octet-stream' ),
		);
		if ( null !== $file ) {
			$args['stream']   = true;
			$args['filename'] = $file;
		}
		$resp = wp_remote_get( $api_url, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code >= 300 && $code < 400 ) {
			$location = (string) wp_remote_retrieve_header( $resp, 'location' );
			if ( ! self::host_allowed( $location ) ) {
				return new \WP_Error( 'bombora_redirect', 'Bombora: disallowed redirect host.' );
			}
			$args2 = array(
				'timeout'     => 60,
				'redirection' => 2,
				'headers'     => array( 'User-Agent' => 'Bombora-Manager/' . BOMBORA_MANAGER_VERSION ),
			);
			if ( null !== $file ) {
				$args2['stream']   = true;
				$args2['filename'] = $file;
			}
			$resp = wp_remote_get( $location, $args2 );
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
		}
		if ( 200 !== $code ) {
			return new \WP_Error( 'bombora_http', sprintf( 'Bombora: download failed (HTTP %d).', $code ) );
		}
		return null === $file ? (string) wp_remote_retrieve_body( $resp ) : true;
	}
}
