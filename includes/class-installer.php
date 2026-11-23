<?php
/**
 * Instalace modulu z katalogu (admin-post). Vyžaduje install_plugins + nonce, modul musí být
 * v pevném katalogu, balíček prochází GitHub::download_verified().
 *
 * @package Bombora\Manager
 */

namespace Bombora\Manager;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public const ACTION = 'bombora_install_module';
	public const CHECK  = 'bombora_check_updates';

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'install' ) );
		add_action( 'admin_post_' . self::CHECK, array( __CLASS__, 'check' ) );
	}

	public static function file_mods_allowed(): bool {
		return ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	}

	private static function back( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . Admin::PAGE ) ) );
		exit;
	}

	public static function install(): void {
		if ( ! current_user_can( 'install_plugins' ) || ! self::file_mods_allowed() ) {
			wp_die( esc_html__( 'You are not allowed to install plugins.', 'bombora-manager' ), 403 );
		}
		$slug = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		check_admin_referer( self::ACTION . '_' . $slug );

		$m = Catalog::get( $slug );
		if ( ! $m || Catalog::install_state( $m ) ) {
			self::back( array( 'bombora-msg' => 'invalid' ) );
		}

		$rel = GitHub::latest( $m );
		if ( ! $rel ) {
			self::back( array( 'bombora-msg' => 'norelease' ) );
		}

		$tmp = GitHub::download_verified( $m, $rel );
		if ( is_wp_error( $tmp ) ) {
			set_transient( 'bombora_err_' . get_current_user_id(), $tmp->get_error_message(), 120 );
			self::back( array( 'bombora-msg' => 'error' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $tmp ); // Lokální, již ověřený soubor – WordPress ho po rozbalení smaže.

		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		if ( is_wp_error( $result ) || ! $result || $skin->get_errors()->has_errors() ) {
			$msg = is_wp_error( $result ) ? $result->get_error_message() : $skin->get_error_messages();
			set_transient( 'bombora_err_' . get_current_user_id(), wp_strip_all_tags( (string) $msg ), 120 );
			self::back( array( 'bombora-msg' => 'error' ) );
		}
		self::back( array( 'bombora-msg' => 'installed', 'module' => $slug ) );
	}

	public static function check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to update plugins.', 'bombora-manager' ), 403 );
		}
		check_admin_referer( self::CHECK );
		GitHub::clear_cache();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		self::back( array( 'bombora-msg' => 'checked' ) );
	}
}
