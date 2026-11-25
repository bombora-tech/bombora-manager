<?php
/**
 * Plugin Name:       Bombora Manager
 * Plugin URI:        https://github.com/bombora-tech/bombora-manager
 * Description:       Správa všech pluginů Bombora na jednom místě: přehled modulů, instalace, aktivace a bezpečné automatické aktualizace z GitHubu (ověřené SHA-256). Obsahuje Varianty balení pro Bricks.
 * Version:           1.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Bombora group s.r.o.
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bombora-manager
 * Domain Path:       /languages
 * Update URI:        https://github.com/bombora-tech/bombora-manager
 *
 * @package Bombora\Manager
 */

defined( 'ABSPATH' ) || exit;

// Pojistka: druhá kopie Manageru (např. složka bombora-manager-main ze ZIPu zdrojáků) se nenačte.
if ( defined( 'BOMBORA_MANAGER_VERSION' ) ) {
	return;
}

define( 'BOMBORA_MANAGER_VERSION', '1.2.0' );
define( 'BOMBORA_MANAGER_FILE', __FILE__ );
define( 'BOMBORA_MANAGER_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOMBORA_MANAGER_URL', plugin_dir_url( __FILE__ ) );
define( 'BOMBORA_MANAGER_BASENAME', plugin_basename( __FILE__ ) );

require_once BOMBORA_MANAGER_DIR . 'includes/class-catalog.php';
require_once BOMBORA_MANAGER_DIR . 'includes/class-github.php';
require_once BOMBORA_MANAGER_DIR . 'includes/class-updater.php';
require_once BOMBORA_MANAGER_DIR . 'includes/class-installer.php';
require_once BOMBORA_MANAGER_DIR . 'includes/class-migrator.php';
require_once BOMBORA_MANAGER_DIR . 'includes/class-admin.php';
require_once BOMBORA_MANAGER_DIR . 'includes/class-bundle-variants.php';

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'bombora-manager', false, dirname( BOMBORA_MANAGER_BASENAME ) . '/languages' );
	},
	0
);

// Varianty balení pro Bricks (frontend i administrace) – jen s WooCommerce.
add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'WooCommerce' ) ) {
			Bombora\Manager\Bundle_Variants::init();
		}
	}
);

// Aktualizace musí běžet i při WP-Cron a WP-CLI, ne jen v administraci.
Bombora\Manager\Updater::init();

if ( is_admin() ) {
	Bombora\Manager\Installer::init();
	Bombora\Manager\Migrator::init();
	Bombora\Manager\Admin::init();
}
