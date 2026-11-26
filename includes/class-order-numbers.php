<?php
/**
 * Číslování objednávek – vestavěný modul Bomboru (zapíná se v menu Bombora → Číslování objednávek).
 *
 * - Číslo podle šablony, např. {RR}9{N} → 26900001 (rok + číslice e-shopu + pořadí).
 * - Přiděluje se jednou při vytvoření objednávky (pokladna, blokový checkout, admin, REST, objednávky
 *   vytvořené pluginy); koncepty (checkout-draft, auto-draft) číslo nespotřebují.
 * - Pořadí se zvyšuje atomicky v databázi → bez duplicit při souběžných objednávkách.
 * - Staré objednávky si nechají své číslo (ID). Vypnutím modulu se zobrazí zase ID, uložená čísla zůstanou
 *   a po opětovném zapnutí se použijí.
 * - Číslo se ukládá do meta `_bombora_order_number`, WooCommerce ho vrací přes `get_order_number()`
 *   (e-maily, Můj účet, Eurofaktura.cz, dopravci…). Hledání v administraci podle nového čísla.
 *
 * @package Bombora\Manager
 */

namespace Bombora\Manager;

defined( 'ABSPATH' ) || exit;

final class Order_Numbers {

	public const PAGE   = 'bombora-order-numbers';
	public const OPT    = 'bombora_order_numbers';
	public const META   = '_bombora_order_number';
	private const SEQ   = 'bombora_onum_seq';
	private const DRAFT = array( 'checkout-draft', 'auto-draft', 'draft' );

	private static bool $busy = false;

	/** @return array{enabled:bool,template:string,pad:int,yearly:bool,since:int} */
	public static function settings(): array {
		$s = get_option( self::OPT, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'enabled'  => ! empty( $s['enabled'] ),
			'template' => isset( $s['template'] ) && '' !== $s['template'] ? (string) $s['template'] : '{RR}9{N}',
			'pad'      => isset( $s['pad'] ) ? max( 1, min( 10, (int) $s['pad'] ) ) : 5,
			'yearly'   => ! isset( $s['yearly'] ) || ! empty( $s['yearly'] ),
			'since'    => isset( $s['since'] ) ? (int) $s['since'] : 0, // zapnuto od (číslo dostanou jen novější objednávky)
		);
	}

	public static function enabled(): bool {
		return self::settings()['enabled'];
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_bombora_order_numbers', array( __CLASS__, 'save' ) );
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'woocommerce_order_number', array( __CLASS__, 'filter_number' ), 20, 2 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'maybe_assign' ), 5, 2 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'maybe_assign' ), 5, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'maybe_assign' ), 5, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'maybe_assign' ), 5, 1 );
		// Hledání podle nového čísla (HPOS i starší úložiště).
		add_filter( 'woocommerce_order_table_search_query_meta_keys', array( __CLASS__, 'search_keys' ) );
		add_filter( 'woocommerce_shop_order_search_fields', array( __CLASS__, 'search_keys' ) );
	}

	/* ---------------------------------------------------------------------
	 * Číslo objednávky
	 * ------------------------------------------------------------------- */

	/**
	 * @param string    $number Výchozí číslo (ID).
	 * @param \WC_Order $order  Objednávka.
	 */
	public static function filter_number( $number, $order ) {
		if ( $order instanceof \WC_Order ) {
			$n = $order->get_meta( self::META );
			if ( '' !== (string) $n ) {
				return (string) $n;
			}
		}
		return $number;
	}

	/**
	 * @param int|\WC_Order $order_id ID nebo objednávka.
	 * @param \WC_Order     $order    Objednávka (u některých hooků).
	 */
	public static function maybe_assign( $order_id, $order = null ): void {
		if ( self::$busy ) {
			return;
		}
		if ( $order_id instanceof \WC_Order ) {
			$order = $order_id;
		} elseif ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund ) {
			return;
		}
		if ( in_array( $order->get_status(), self::DRAFT, true ) || '' !== (string) $order->get_meta( self::META ) ) {
			return;
		}
		// Starší objednávky (vytvořené před zapnutím) si nechají své číslo i při pozdější úpravě.
		$since   = self::settings()['since'];
		$created = $order->get_date_created();
		if ( $since && $created && $created->getTimestamp() < $since - 60 ) {
			return;
		}
		self::$busy = true;
		try {
			$number = self::next_number();
			if ( '' !== $number ) {
				$order->update_meta_data( self::META, $number );
				$order->save_meta_data();
			}
		} finally {
			self::$busy = false;
		}
	}

	private static function year( bool $long = false ): string {
		return wp_date( $long ? 'Y' : 'y' );
	}

	private static function uses_year( string $tpl ): bool {
		return false !== strpos( $tpl, '{RR}' ) || false !== strpos( $tpl, '{RRRR}' );
	}

	private static function seq_option(): string {
		$s = self::settings();
		return $s['yearly'] && self::uses_year( $s['template'] ) ? self::SEQ . '_' . self::year( true ) : self::SEQ;
	}

	public static function format( int $seq ): string {
		$s = self::settings();
		return strtr(
			$s['template'],
			array(
				'{RRRR}' => self::year( true ),
				'{RR}'   => self::year(),
				'{N}'    => str_pad( (string) $seq, $s['pad'], '0', STR_PAD_LEFT ),
			)
		);
	}

	/** Atomicky zvýší pořadí a vrátí nové číslo (přeskočí čísla, která už existují). */
	private static function next_number(): string {
		global $wpdb;
		$opt = self::seq_option();
		add_option( $opt, '0', '', false ); // nic neudělá, když už existuje
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( CAST( option_value AS UNSIGNED ) + 1 ) WHERE option_name = %s", $opt ) );
			$seq = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( $opt, 'options' );
			if ( $seq < 1 ) {
				return '';
			}
			$number = self::format( $seq );
			if ( ! self::exists( $number ) ) {
				return $number;
			}
		}
		return '';
	}

	private static function exists( string $number ): bool {
		$ids = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'status'     => 'any',
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $number, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return ! empty( $ids );
	}

	/** Pořadové číslo, které dostane příští objednávka. */
	public static function next_seq(): int {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::seq_option() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $v + 1;
	}

	/**
	 * @param string[] $keys Meta klíče.
	 * @return string[]
	 */
	public static function search_keys( $keys ): array {
		$keys   = (array) $keys;
		$keys[] = self::META;
		return array_values( array_unique( $keys ) );
	}

	/* ---------------------------------------------------------------------
	 * Administrace
	 * ------------------------------------------------------------------- */

	public static function menu(): void {
		add_submenu_page( Admin::PAGE, __( 'Order numbers', 'bombora-manager' ), __( 'Order numbers', 'bombora-manager' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bombora-manager' ) );
		}
		check_admin_referer( 'bombora_order_numbers' );
		$in  = isset( $_POST['bon'] ) && is_array( $_POST['bon'] ) ? wp_unslash( $_POST['bon'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$tpl = isset( $in['template'] ) ? preg_replace( '/[^\p{L}\p{N}\-\/_.{}]/u', '', (string) $in['template'] ) : '{RR}9{N}';
		if ( false === strpos( $tpl, '{N}' ) ) {
			$tpl .= '{N}'; // pořadí je povinné, jinak by čísla nebyla jedinečná
		}
		$old     = self::settings();
		$enabled = ! empty( $in['enabled'] );
		update_option(
			self::OPT,
			array(
				'since'    => $old['since'] ? $old['since'] : ( $enabled ? time() : 0 ),
				'enabled'  => $enabled,
				'template' => $tpl,
				'pad'      => isset( $in['pad'] ) ? max( 1, min( 10, (int) $in['pad'] ) ) : 5,
				'yearly'   => ! empty( $in['yearly'] ),
			),
			true
		);
		// Ruční nastavení dalšího pořadového čísla (jen když se změnilo).
		if ( isset( $in['next'], $in['next_old'] ) && (int) $in['next'] !== (int) $in['next_old'] && (int) $in['next'] >= 1 ) {
			$opt = self::seq_option();
			add_option( $opt, '0', '', false );
			update_option( $opt, (string) ( (int) $in['next'] - 1 ), false );
		}
		wp_safe_redirect( add_query_arg( 'saved', '1', self::url() ) );
		exit;
	}

	/** Upozornění k náhledu čísla. @return string[] */
	private static function warnings( string $preview ): array {
		global $wpdb;
		$w = array();
		if ( ! preg_match( '/^\d+$/', $preview ) ) {
			$w[] = __( 'The number contains non-digit characters – it cannot be used as a variable symbol (bank transfer, QR payment).', 'bombora-manager' );
		} elseif ( strlen( $preview ) > 10 ) {
			$w[] = __( 'The number has more than 10 digits – too long for a variable symbol.', 'bombora-manager' );
		}
		if ( preg_match( '/^\d+$/', $preview ) ) {
			$table = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
			$max   = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $max && (int) $preview <= $max ) {
				/* translators: %s: highest existing order ID. */
				$w[] = sprintf( __( 'Numbers may overlap with older orders, which keep their ID as the number (highest ID: %s). Use a higher range, e.g. add a digit.', 'bombora-manager' ), number_format_i18n( $max ) );
			}
		}
		if ( has_filter( 'woocommerce_order_number' ) && ( class_exists( 'WC_Seq_Order_Number' ) || class_exists( 'WC_Seq_Order_Number_Pro' ) || function_exists( 'wc_sequential_order_numbers' ) || class_exists( 'Alg_WC_Custom_Order_Numbers' ) ) ) {
			$w[] = __( 'Another order numbering plugin is active – deactivate it to avoid conflicts.', 'bombora-manager' );
		}
		return $w;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s       = self::settings();
		$next    = self::next_seq();
		$preview = self::format( $next );
		?>
		<div class="wrap bombora">
			<header class="bombora__head">
				<div>
					<h1><?php esc_html_e( 'Order numbers', 'bombora-manager' ); ?> <span class="bombora-badge"><?php echo $s['enabled'] ? esc_html__( 'Active', 'bombora-manager' ) : esc_html__( 'Inactive', 'bombora-manager' ); ?></span></h1>
					<p><?php esc_html_e( 'Unique order numbers across all Bombora shops – one numeric range per shop.', 'bombora-manager' ); ?></p>
				</div>
			</header>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'bombora-manager' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( self::warnings( $preview ) as $w ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $w ); ?></p></div>
			<?php endforeach; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bombora-card bombora-onum">
				<input type="hidden" name="action" value="bombora_order_numbers">
				<?php wp_nonce_field( 'bombora_order_numbers' ); ?>
				<input type="hidden" name="bon[next_old]" value="<?php echo (int) $next; ?>">
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Module', 'bombora-manager' ); ?></th><td>
						<label><input type="checkbox" name="bon[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Use custom order numbers', 'bombora-manager' ); ?></label>
						<p class="description"><?php esc_html_e( 'Only new orders get a number. Older orders keep their current number (ID). When turned off, the ID is shown again; assigned numbers are kept.', 'bombora-manager' ); ?></p>
					</td></tr>
					<tr><th scope="row"><label for="bon-tpl"><?php esc_html_e( 'Format', 'bombora-manager' ); ?></label></th><td>
						<input id="bon-tpl" type="text" class="regular-text code" name="bon[template]" value="<?php echo esc_attr( $s['template'] ); ?>">
						<p class="description"><?php esc_html_e( '{RR} = year (26), {RRRR} = year (2026), {N} = sequence number. Anything else is copied as is. Recommended: {RR} + one digit per shop + {N}, e.g. zgruzie.cz {RR}1{N}, zgruzinska.sk {RR}2{N}, allesgeorgische.de {RR}3{N}, degeorgie.fr {RR}4{N}, uitgeorgie.nl {RR}5{N}, vo.zgruzie.cz {RR}9{N}.', 'bombora-manager' ); ?></p>
					</td></tr>
					<tr><th scope="row"><label for="bon-pad"><?php esc_html_e( 'Sequence digits', 'bombora-manager' ); ?></label></th><td>
						<input id="bon-pad" type="number" min="1" max="10" class="small-text" name="bon[pad]" value="<?php echo (int) $s['pad']; ?>">
						<p class="description"><?php esc_html_e( 'Sequence padded with zeros, e.g. 5 → 00001.', 'bombora-manager' ); ?></p>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Yearly reset', 'bombora-manager' ); ?></th><td>
						<label><input type="checkbox" name="bon[yearly]" value="1" <?php checked( $s['yearly'] ); ?>> <?php esc_html_e( 'Start the sequence from 1 every year (only if the format contains the year)', 'bombora-manager' ); ?></label>
					</td></tr>
					<tr><th scope="row"><label for="bon-next"><?php esc_html_e( 'Next sequence number', 'bombora-manager' ); ?></label></th><td>
						<input id="bon-next" type="number" min="1" class="small-text" style="width:110px" name="bon[next]" value="<?php echo (int) $next; ?>">
						<p class="description"><?php esc_html_e( 'Change only if needed (e.g. to continue an existing series).', 'bombora-manager' ); ?></p>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Next order number', 'bombora-manager' ); ?></th><td>
						<code class="bombora-onum__preview"><?php echo esc_html( $preview ); ?></code>
						<p class="description"><?php esc_html_e( 'Preview according to the saved settings.', 'bombora-manager' ); ?></p>
					</td></tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'bombora-manager' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/** Karta na stránce Moduly. */
	public static function module_card(): void {
		$on = self::enabled();
		?>
		<section class="bombora-card<?php echo $on ? ' is-active' : ''; ?>">
			<div class="bombora-card__top">
				<span class="dashicons dashicons-editor-ol" aria-hidden="true"></span>
				<div>
					<h2><?php esc_html_e( 'Order numbers', 'bombora-manager' ); ?></h2>
					<span class="bombora-state bombora-state--<?php echo $on ? 'ok' : 'off'; ?>"><?php echo $on ? esc_html__( 'Active', 'bombora-manager' ) : esc_html__( 'Inactive', 'bombora-manager' ); ?></span>
				</div>
			</div>
			<p class="bombora-card__desc"><?php esc_html_e( 'Unique order numbers across all Bombora shops – one numeric range per shop.', 'bombora-manager' ); ?></p>
			<dl class="bombora-meta">
				<div><dt><?php esc_html_e( 'Built in', 'bombora-manager' ); ?></dt><dd>Manager</dd></div>
				<?php if ( $on ) : ?>
					<div><dt><?php esc_html_e( 'Next order number', 'bombora-manager' ); ?></dt><dd><?php echo esc_html( self::format( self::next_seq() ) ); ?></dd></div>
				<?php endif; ?>
			</dl>
			<div class="bombora-actions"><a class="button<?php echo $on ? '' : ' button-primary'; ?>" href="<?php echo esc_url( self::url() ); ?>"><?php echo $on ? esc_html__( 'Settings', 'bombora-manager' ) : esc_html__( 'Set up', 'bombora-manager' ); ?></a></div>
		</section>
		<?php
	}
}
