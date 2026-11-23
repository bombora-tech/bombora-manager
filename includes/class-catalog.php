<?php
/**
 * Katalog modulů Bombora. Zdroje (GitHub repa) jsou pevně v kódu – administrace ani databáze
 * nemohou podstrčit jiný zdroj instalace.
 *
 * @package Bombora\Manager
 */

namespace Bombora\Manager;

defined( 'ABSPATH' ) || exit;

final class Catalog {

	/**
	 * @return array<string,array<string,string>> Klíč = složka pluginu. „legacy“ = původní soubor pluginu před přejmenováním na bombora-*.
	 */
	public static function all(): array {
		return array(
			'bombora-manager'          => array(
				'name'     => 'Bombora Manager',
				'desc'     => self::t( 'Central management and secure updates of all Bombora plugins.' ),
				'file'     => 'bombora-manager/bombora-manager.php',
				'repo'     => 'bombora-tech/bombora-manager',
				'asset'    => 'bombora-manager.zip',
				'settings' => 'admin.php?page=bombora',
				'icon'     => 'admin-generic',
				'core'     => '1',
			),
			'bombora-cart-popup'       => array(
				'name'     => 'Bombora Cart Popup',
				'desc'     => self::t( 'Add-to-cart popup with quantity editing, free shipping bar and suggested products.' ),
				'file'     => 'bombora-cart-popup/bombora-cart-popup.php',
				'repo'     => 'bombora-tech/bombora-cart-popup',
				'asset'    => 'bombora-cart-popup.zip',
				'settings' => 'admin.php?page=bombora-cart-popup',
				'icon'     => 'cart',
			),
			'bombora-smart-search'     => array(
				'name'     => 'Bombora Smart Search',
				'desc'     => self::t( 'Live product search with typo tolerance, popular searches and recommendations.' ),
				'file'     => 'bombora-smart-search/bombora-smart-search.php',
				'legacy'   => 'zg-smart-search/zg-smart-search.php',
				'repo'     => 'bombora-tech/bombora-smart-search',
				'asset'    => 'bombora-smart-search.zip',
				'settings' => 'admin.php?page=zgss-settings',
				'icon'     => 'search',
			),
			'bombora-wine-nutrition'   => array(
				'name'     => 'Bombora Wine Nutrition',
				'desc'     => self::t( 'EU wine nutrition declarations and ingredients, shared across shops by SKU.' ),
				'file'     => 'bombora-wine-nutrition/bombora-wine-nutrition.php',
				'legacy'   => 'wine-nutrition-manager/wine-nutrition-manager.php',
				'repo'     => 'bombora-tech/bombora-wine-nutrition',
				'asset'    => 'bombora-wine-nutrition.zip',
				'settings' => 'admin.php?page=wine-nutrition',
				'icon'     => 'list-view',
			),
			'bombora-read-more-toggle' => array(
				'name'     => 'Bombora Read More Toggle',
				'desc'     => self::t( 'The [read]…[/read] tag hides text behind a “Continue reading” button, translated into all shop languages.' ),
				'file'     => 'bombora-read-more-toggle/bombora-read-more-toggle.php',
				'legacy'   => 'read-more-toggle/read-more-toggle.php',
				'repo'     => 'bombora-tech/bombora-read-more-toogle',
				'asset'    => 'bombora-read-more-toggle.zip',
				'settings' => '',
				'icon'     => 'editor-insertmore',
			),
		);
	}

	/**
	 * Překlad jen po načtení textové domény (katalog se volá i z WP-Cronu před init).
	 */
	private static function t( string $text ): string {
		return did_action( 'init' ) ? translate( $text, 'bombora-manager' ) : $text; // phpcs:ignore WordPress.WP.I18n
	}

	/**
	 * @return array<string,string>|null
	 */
	public static function get( string $slug ): ?array {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}

	/**
	 * Modul podle souboru pluginu (např. „zg-smart-search/zg-smart-search.php“).
	 *
	 * @return array{0:string,1:array<string,string>}|null
	 */
	public static function by_file( string $file ): ?array {
		foreach ( self::all() as $slug => $m ) {
			if ( $m['file'] === $file ) {
				return array( $slug, $m );
			}
		}
		return null;
	}

	/**
	 * Data nainstalovaného pluginu (nebo null).
	 *
	 * @return array<string,string>|null
	 */
	public static function installed( string $file ): ?array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		return $plugins[ $file ] ?? null;
	}

	/**
	 * Skutečně nainstalovaná podoba modulu: nová složka má přednost, jinak stará (legacy).
	 *
	 * @param array<string,string> $m Modul.
	 * @return array{file:string,legacy:bool,data:array<string,string>}|null
	 */
	public static function install_state( array $m ): ?array {
		$data = self::installed( $m['file'] );
		if ( $data ) {
			// Nová složka je na disku, ale běží stará (neaktivní nová) → modul se ještě musí převést.
			if ( ! empty( $m['legacy'] ) && is_plugin_active( $m['legacy'] ) && ! is_plugin_active( $m['file'] ) && self::installed( $m['legacy'] ) ) {
				return array( 'file' => $m['legacy'], 'legacy' => true, 'data' => (array) self::installed( $m['legacy'] ) );
			}
			return array( 'file' => $m['file'], 'legacy' => false, 'data' => $data );
		}
		if ( ! empty( $m['legacy'] ) ) {
			$data = self::installed( $m['legacy'] );
			if ( $data ) {
				return array( 'file' => $m['legacy'], 'legacy' => true, 'data' => $data );
			}
		}
		return null;
	}

	/**
	 * Je stará složka modulu ještě na disku (vedle nové)?
	 *
	 * @param array<string,string> $m Modul.
	 */
	public static function legacy_present( array $m ): bool {
		return ! empty( $m['legacy'] ) && null !== self::installed( $m['legacy'] );
	}
}
