<?php
/**
 * Odinstalace: smaže jen cache releasů. Moduly ani jejich data se nemění.
 *
 * @package Bombora\Manager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'bombora-tech/bombora-manager', 'bombora-tech/bombora-cart-popup', 'bombora-tech/bombora-smart-search', 'bombora-tech/bombora-wine-nutrition' ) as $bombora_repo ) {
	delete_site_transient( 'bombora_rel_' . md5( $bombora_repo ) );
	delete_site_transient( 'bombora_rel_' . md5( $bombora_repo ) . '_err' );
}
