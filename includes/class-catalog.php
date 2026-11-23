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
	 * @return array<string,array<string,string>> Klíč = složka pluginu.
	 */
	public static function all(): array {
		return array(
			'bombora-manager'        => array(
				'name'     => 'Bombora Manager',
				'desc'     => self::t( 'Central management and secure updates of all Bombora plugins.' ),
				'file'     => 'bombora-manager/bombora-manager.php',
				'repo'     => 'bombora-tech/bombora-manager',
				'asset'    => 'bombora-manager.zip',
				'settings' => 'admin.php?page=bombora',
				'icon'     => 'admin-generic',
				'core'     => '1',
			),
			'bombora-cart-popup'     => array(
				'name'     => self::t( 'Cart popup' ),
				'desc'     => self::t( 'Add-to-cart popup with quantity editing, free shipping bar and suggested products.' ),
				'file'     => 'bombora-cart-popup/bombora-cart-popup.php',
				'repo'     => 'bombora-tech/bombora-cart-popup',
				'asset'    => 'bombora-cart-popup.zip',
				'settings' => 'admin.php?page=bombora-cart-popup',
				'icon'     => 'cart',
			),
			'zg-smart-search'        => array(
				'name'     => 'Smart Search',
				'desc'     => self::t( 'Live product search with typo tolerance, popular searches and recommendations.' ),
				'file'     => 'zg-smart-search/zg-smart-search.php',
				'repo'     => 'bombora-tech/bombora-smart-search',
				'asset'    => 'zg-smart-search.zip',
				'settings' => 'admin.php?page=zgss-settings',
				'icon'     => 'search',
			),
			'wine-nutrition-manager' => array(
				'name'     => 'Wine Nutrition',
				'desc'     => self::t( 'EU wine nutrition declarations and ingredients, shared across shops by SKU.' ),
				'file'     => 'wine-nutrition-manager/wine-nutrition-manager.php',
				'repo'     => 'bombora-tech/bombora-wine-nutrition',
				'asset'    => 'wine-nutrition-manager.zip',
				'settings' => 'admin.php?page=wine-nutrition',
				'icon'     => 'list-view',
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
}
