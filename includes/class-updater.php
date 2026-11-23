<?php
/**
 * Integrace do standardních aktualizací WordPressu pro všechny moduly z katalogu
 * (Nástěnka → Aktualizace, automatické aktualizace, WP-CLI). Balíček se vždy stahuje
 * a ověřuje přes GitHub::download_verified().
 *
 * @package Bombora\Manager
 */

namespace Bombora\Manager;

defined( 'ABSPATH' ) || exit;

final class Updater {

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( GitHub::class, 'clear_cache' ), 10, 0 );
	}

	/**
	 * @param mixed $transient update_plugins.
	 * @return mixed
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		foreach ( Catalog::all() as $slug => $m ) {
			$installed = Catalog::installed( $m['file'] );
			if ( ! $installed ) {
				continue;
			}
			$rel = GitHub::latest( $m );
			if ( ! $rel ) {
				continue;
			}
			$item = (object) array(
				'id'           => $m['repo'],
				'slug'         => $slug,
				'plugin'       => $m['file'],
				'new_version'  => $rel['version'],
				'url'          => $rel['html_url'],
				'package'      => $rel['zip'],
				'requires_php' => '8.0',
				'icons'        => array(),
				'banners'      => array(),
			);
			if ( version_compare( $rel['version'], (string) $installed['Version'], '>' ) ) {
				$transient->response[ $m['file'] ] = $item;
			} else {
				unset( $transient->response[ $m['file'] ] );
				$transient->no_update[ $m['file'] ] = $item;
			}
		}
		return $transient;
	}

	/**
	 * @param mixed  $result Výsledek.
	 * @param string $action Akce.
	 * @param object $args   Argumenty.
	 * @return mixed
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}
		$m = Catalog::get( (string) $args->slug );
		if ( ! $m ) {
			return $result;
		}
		$rel = GitHub::latest( $m );
		if ( ! $rel ) {
			return $result;
		}
		return (object) array(
			'name'          => $m['name'],
			'slug'          => (string) $args->slug,
			'version'       => $rel['version'],
			'author'        => 'Bombora group s.r.o.',
			'requires'      => '6.5',
			'requires_php'  => '8.0',
			'last_updated'  => $rel['published'],
			'homepage'      => $rel['html_url'],
			'download_link' => $rel['zip'],
			'sections'      => array(
				'description' => esc_html( $m['desc'] ),
				'changelog'   => '' !== $rel['notes']
					? '<pre>' . esc_html( $rel['notes'] ) . '</pre>'
					: ( '' !== $rel['html_url'] ? '<p><a href="' . esc_url( $rel['html_url'] ) . '" target="_blank" rel="noopener noreferrer">GitHub release</a></p>' : '' ),
			),
		);
	}

	/**
	 * Naše balíčky stáhneme a ověříme sami (SHA-256 + struktura ZIPu); cizí necháme WordPressu.
	 *
	 * @param mixed  $reply      false = zatím nikdo nestahoval.
	 * @param string $package    URL balíčku.
	 * @param mixed  $upgrader   Upgrader.
	 * @param mixed  $hook_extra Extra.
	 * @return mixed
	 */
	public static function pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) || ! GitHub::host_allowed( $package ) ) {
			return $reply;
		}
		foreach ( Catalog::all() as $m ) {
			$rel = GitHub::latest( $m );
			if ( $rel && hash_equals( $rel['zip'], $package ) ) {
				return GitHub::download_verified( $m, $rel );
			}
		}
		return $reply;
	}
}
