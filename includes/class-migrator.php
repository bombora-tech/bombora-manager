<?php
/**
 * Převod modulu ze staré složky (např. zg-smart-search) na novou bombora-*.
 *
 * Proč dva kroky: stará verze je v aktuálním požadavku už načtená, takže aktivace nové kopie ve stejném
 * požadavku by skončila „Cannot redeclare“. Proto:
 *   1) start:  ověřený balíček → instalace nové složky → TICHÁ deaktivace staré (bez deactivation hooku),
 *              stav do volby, přesměrování;
 *   2) finish: (stará verze už se nenačítá) aktivace nové verze; při chybě návrat ke staré;
 *              po úspěchu smazání staré SLOŽKY přímo přes WP_Filesystem – BEZ uninstall.php,
 *              takže data (tabulky, nastavení) zůstanou.
 * Data všech modulů jsou v databázi pod stejnými klíči – na názvu složky nezávisí.
 *
 * Bezpečnost: install_plugins + activate_plugins + delete_plugins, nonce pro každý krok a modul,
 * jen moduly a cesty z pevného katalogu, kontrola, že mazaná cesta leží přímo v adresáři pluginů.
 *
 * @package Bombora\Manager
 */

namespace Bombora\Manager;

defined( 'ABSPATH' ) || exit;

final class Migrator {

	public const START  = 'bombora_migrate_module';
	public const FINISH = 'bombora_migrate_finish';
	private const STATE = 'bombora_migration_';

	public static function init(): void {
		add_action( 'admin_post_' . self::START, array( __CLASS__, 'start' ) );
		add_action( 'admin_post_' . self::FINISH, array( __CLASS__, 'finish' ) );
	}

	public static function allowed(): bool {
		return Installer::file_mods_allowed()
			&& current_user_can( 'install_plugins' )
			&& current_user_can( 'activate_plugins' )
			&& current_user_can( 'delete_plugins' );
	}

	/**
	 * Rozpracovaný převod (krok 1 hotový, krok 2 ne).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function pending( string $slug ): ?array {
		$s = get_option( self::STATE . $slug );
		return is_array( $s ) ? $s : null;
	}

	public static function finish_url( string $slug ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::FINISH,
					'module' => $slug,
				),
				admin_url( 'admin-post.php' )
			),
			self::FINISH . '_' . $slug
		);
	}

	private static function back( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . Admin::PAGE ) ) );
		exit;
	}

	private static function fail( string $message ): void {
		set_transient( 'bombora_err_' . get_current_user_id(), wp_strip_all_tags( $message ), 300 );
		self::back( array( 'bombora-msg' => 'migrate-error' ) );
	}

	/**
	 * @return array<string,string>
	 */
	private static function module_from_request( string $nonce_action ): array {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bombora-manager' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- nonce se ověří hned níže (závisí na modulu).
		$slug = isset( $_REQUEST['module'] ) ? sanitize_key( wp_unslash( $_REQUEST['module'] ) ) : '';
		check_admin_referer( $nonce_action . '_' . $slug );
		$m = Catalog::get( $slug );
		if ( ! $m || empty( $m['legacy'] ) ) {
			self::back( array( 'bombora-msg' => 'invalid' ) );
		}
		$m['slug'] = $slug;
		return $m;
	}

	private static function filesystem_ready(): bool {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method( array(), WP_PLUGIN_DIR ) ) {
			return false;
		}
		return (bool) WP_Filesystem();
	}

	/**
	 * Krok 1.
	 */
	public static function start(): void {
		$m = self::module_from_request( self::START );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_clean_plugins_cache( false );

		if ( ! Catalog::installed( $m['legacy'] ) ) {
			self::back( array( 'bombora-msg' => 'invalid' ) );
		}
		if ( is_multisite() && is_plugin_active_for_network( $m['legacy'] ) ) {
			self::fail( __( 'Network-activated plugins must be converted manually.', 'bombora-manager' ) );
		}
		if ( ! self::filesystem_ready() ) {
			self::fail( __( 'Direct file access is required to convert the plugin folder.', 'bombora-manager' ) );
		}

		// Nová složka: nainstalovat z ověřeného releasu (pokud ji tam už nemáme z předchozího pokusu).
		if ( ! Catalog::installed( $m['file'] ) ) {
			$rel = GitHub::latest( $m );
			if ( ! $rel ) {
				self::fail( __( 'This module has no published release yet.', 'bombora-manager' ) );
			}
			$tmp = GitHub::download_verified( $m, $rel );
			if ( is_wp_error( $tmp ) ) {
				self::fail( $tmp->get_error_message() );
			}
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$skin     = new \WP_Ajax_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $tmp );
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			if ( is_wp_error( $result ) || ! $result || $skin->get_errors()->has_errors() ) {
				self::fail( is_wp_error( $result ) ? $result->get_error_message() : (string) $skin->get_error_messages() );
			}
			wp_clean_plugins_cache( false );
			if ( ! Catalog::installed( $m['file'] ) ) {
				self::fail( __( 'The new plugin folder was not found after installation.', 'bombora-manager' ) );
			}
		}

		$was_active = is_plugin_active( $m['legacy'] );
		update_option(
			self::STATE . $m['slug'],
			array(
				'was_active' => $was_active,
				'time'       => time(),
				'user'       => get_current_user_id(),
			),
			false
		);
		if ( $was_active ) {
			// Tiše: bez deactivation hooku (cache/cron nové verze nastaví její aktivace v kroku 2).
			deactivate_plugins( $m['legacy'], true );
		}

		wp_safe_redirect( html_entity_decode( self::finish_url( $m['slug'] ) ) );
		exit;
	}

	/**
	 * Krok 2 – také „úklid“, když nová verze běží a stará složka zůstala na disku.
	 */
	public static function finish(): void {
		$m = self::module_from_request( self::FINISH );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_clean_plugins_cache( false );

		$state = self::pending( $m['slug'] );
		if ( ! Catalog::installed( $m['file'] ) ) {
			self::fail( __( 'The new plugin folder was not found after installation.', 'bombora-manager' ) );
		}
		if ( is_plugin_active( $m['legacy'] ) ) {
			if ( ! is_plugin_active( $m['file'] ) ) {
				// Stará verze běží a nová ne – novou nelze aktivovat ve stejném požadavku. Začít znovu krokem 1.
				self::fail( __( 'The old plugin is still active. Start the conversion again.', 'bombora-manager' ) );
			}
			// Obě aktivní (nová se kvůli pojistce nenačítá) – stačí tiše vypnout starou a smazat její složku.
			deactivate_plugins( $m['legacy'], true );
		}

		$activate = $state ? ! empty( $state['was_active'] ) : false;
		if ( $activate && ! is_plugin_active( $m['file'] ) ) {
			$result = activate_plugin( $m['file'], '', false, false );
			if ( is_wp_error( $result ) ) {
				// Návrat ke staré verzi – její soubory jsou ještě na disku a nová se nenačetla.
				activate_plugin( $m['legacy'], '', false, true );
				delete_option( self::STATE . $m['slug'] );
				self::delete_plugin_folder( dirname( $m['file'] ), dirname( $m['legacy'] ) ); // Nová (neaktivní) složka pryč – čistý stav pro další pokus.
				wp_clean_plugins_cache( false );
				self::fail( $result->get_error_message() );
			}
		}

		$deleted = self::delete_legacy_folder( $m );
		delete_option( self::STATE . $m['slug'] );
		self::forget_legacy_file( $m );
		wp_clean_plugins_cache( false );
		GitHub::clear_cache();

		if ( is_wp_error( $deleted ) ) {
			self::fail( $deleted->get_error_message() );
		}
		self::back(
			array(
				'bombora-msg' => 'migrated',
				'module'      => $m['slug'],
			)
		);
	}

	/**
	 * Smaže starou složku pluginu (bez uninstall.php).
	 *
	 * @param array<string,string> $m Modul.
	 * @return true|\WP_Error
	 */
	private static function delete_legacy_folder( array $m ) {
		return self::delete_plugin_folder( dirname( $m['legacy'] ), dirname( $m['file'] ) );
	}

	/**
	 * Smaže složku v adresáři pluginů přes WP_Filesystem – bez uninstall.php. Jen jednoduchý název
	 * složky z katalogu, nikdy symlink ani cesta mimo adresář pluginů.
	 *
	 * @param string $folder Mazaná složka.
	 * @param string $keep   Složka, která se smazat nesmí (druhá podoba modulu).
	 * @return true|\WP_Error
	 */
	private static function delete_plugin_folder( string $folder, string $keep ) {
		global $wp_filesystem;
		if ( '.' === $folder || '' === $folder || $folder === $keep || ! preg_match( '/^[a-z0-9-]+$/', $folder ) ) {
			return new \WP_Error( 'bombora_path', 'Bombora: unexpected plugin folder.' );
		}
		$base = wp_normalize_path( (string) realpath( WP_PLUGIN_DIR ) );
		$path = wp_normalize_path( WP_PLUGIN_DIR . '/' . $folder );
		if ( ! is_dir( $path ) ) {
			return true; // Už smazáno.
		}
		$real = wp_normalize_path( (string) realpath( $path ) );
		if ( '' === $base || $real !== $base . '/' . $folder || is_link( $path ) ) {
			return new \WP_Error( 'bombora_path', 'Bombora: plugin folder is outside the plugins directory.' );
		}
		if ( ! self::filesystem_ready() || ! $wp_filesystem->delete( $real, true ) ) {
			return new \WP_Error( 'bombora_delete', __( 'The old plugin folder could not be deleted. Delete it via FTP; its data is not affected.', 'bombora-manager' ) );
		}
		return true;
	}

	/**
	 * Odstraní starý soubor z voleb WordPressu, které na něj odkazují.
	 *
	 * @param array<string,string> $m Modul.
	 */
	private static function forget_legacy_file( array $m ): void {
		$recent = (array) get_option( 'recently_activated', array() );
		if ( isset( $recent[ $m['legacy'] ] ) ) {
			unset( $recent[ $m['legacy'] ] );
			update_option( 'recently_activated', $recent );
		}
		$auto = (array) get_site_option( 'auto_update_plugins', array() );
		if ( in_array( $m['legacy'], $auto, true ) ) {
			$auto   = array_values( array_diff( $auto, array( $m['legacy'] ) ) );
			$auto[] = $m['file'];
			update_site_option( 'auto_update_plugins', array_values( array_unique( $auto ) ) );
		}
	}
}
