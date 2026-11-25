<?php
/**
 * Varianty balení („Iné varianty“ / „Další varianty“) pro Bricks – bez ACF a bez snippetů.
 *
 * Zdroj variant:
 *   1. Ručně: nativní WooCommerce Up-selly produktu (Produkt → Propojené produkty → Up-selly).
 *   2. Automaticky (když Up-selly chybí): rodina balení – jednotlivý produkt + všechny
 *      publikované WPC bundly (woosb), které obsahují jen tento jeden produkt (3×, 6×, 20× …).
 *
 * Bricks:
 *   - Query loop typ „Bombora: varianty balení“ (objectType bombora_bundle_variants).
 *   - Dynamické tagy {bombora_bundle_saving}, {bombora_bundle_saving_pct}, {bombora_bundle_qty},
 *     {bombora_bundle_unit_price}, {bombora_bundle_variants_count}.
 *
 * Na frontendu se nevypisuje žádný text – popisky („Ušetríte“, nadpis sekce) jsou v Bricks,
 * takže se překládají stejně jako zbytek šablony (WPML / jednojazyčné weby).
 *
 * @package Bombora\Manager
 */

namespace Bombora\Manager;

defined( 'ABSPATH' ) || exit;

final class Bundle_Variants {

	public const QUERY_TYPE = 'bombora_bundle_variants';
	private const VER_OPT   = 'bombora_bv_ver';
	private const LIMIT     = 12;

	/** @var array<string,string> Tag => popisek v Bricks. */
	private const TAGS = array(
		'bombora_bundle_saving'         => 'Bundle: saving amount',
		'bombora_bundle_saving_pct'     => 'Bundle: saving %',
		'bombora_bundle_qty'            => 'Bundle: number of items',
		'bombora_bundle_unit_price'     => 'Bundle: price per item',
		'bombora_bundle_variants_count' => 'Bundle variants: count (current product)',
	);

	/** @var array<int,int[]> Cache v rámci požadavku. */
	private static array $memo = array();

	/** Aktuální položka smyčky „Varianty balení“ (podmínky Bricks nemusí dostat správný $post). */
	private static int $loop_item = 0;

	public static function init(): void {
		// Bricks: vlastní typ query loopu.
		add_filter( 'bricks/setup/control_options', array( __CLASS__, 'query_type' ) );
		add_filter( 'bricks/query/run', array( __CLASS__, 'run' ), 10, 2 );
		add_filter( 'bricks/query/loop_object', array( __CLASS__, 'loop_object' ), 10, 3 );
		add_action( 'bricks/query/after_loop', array( __CLASS__, 'after_loop' ), 10, 1 );

		// Bricks: dynamické tagy.
		add_filter( 'bricks/dynamic_tags_list', array( __CLASS__, 'tags_list' ) );
		add_filter( 'bricks/dynamic_data/render_tag', array( __CLASS__, 'render_tag' ), 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( __CLASS__, 'render_content' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( __CLASS__, 'render_content' ), 20, 2 );

		// Zneplatnění cache automatických rodin při změně produktu.
		add_action( 'save_post_product', array( __CLASS__, 'bump' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'bump' ) );
		add_action( 'trashed_post', array( __CLASS__, 'bump' ) );

		// Nápověda u pole Up-selly.
		add_action( 'woocommerce_product_options_related', array( __CLASS__, 'admin_hint' ) );
	}

	/* ------------------------------------------------------------------ Bricks query loop */

	/**
	 * @param array $options Bricks control options.
	 */
	public static function query_type( $options ) {
		if ( is_array( $options ) ) {
			$options['queryTypes'][ self::QUERY_TYPE ] = 'Bombora: ' . __( 'Bundle variants', 'bombora-manager' );
		}
		return $options;
	}

	/**
	 * @param mixed  $results   Výsledky.
	 * @param object $query_obj \Bricks\Query.
	 */
	public static function run( $results, $query_obj ) {
		if ( ! is_object( $query_obj ) || self::QUERY_TYPE !== ( $query_obj->object_type ?? '' ) ) {
			return $results;
		}
		$product_id = self::current_product_id();
		if ( ! $product_id ) {
			return array();
		}
		$posts = array();
		foreach ( self::ids( $product_id ) as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}
		return $posts;
	}

	/**
	 * Nastaví globální $post (a přes akci the_post i WooCommerce $product) na položku smyčky.
	 *
	 * @param mixed  $loop_object Položka.
	 * @param mixed  $loop_key    Index.
	 * @param object $query_obj   \Bricks\Query.
	 */
	public static function loop_object( $loop_object, $loop_key, $query_obj ) {
		if ( ! is_object( $query_obj ) || self::QUERY_TYPE !== ( $query_obj->object_type ?? '' ) ) {
			return $loop_object;
		}
		if ( $loop_object instanceof \WP_Post ) {
			self::$loop_item = (int) $loop_object->ID;
			global $post;
			$post = $loop_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $post );
		}
		return $loop_object;
	}

	/**
	 * @param object $query_obj \Bricks\Query.
	 */
	public static function after_loop( $query_obj ): void {
		if ( is_object( $query_obj ) && self::QUERY_TYPE === ( $query_obj->object_type ?? '' ) ) {
			self::$loop_item = 0;
			wp_reset_postdata();
		}
	}

	/* ------------------------------------------------------------------ Bricks dynamic data */

	/**
	 * @param array $tags Seznam tagů.
	 */
	public static function tags_list( $tags ) {
		foreach ( self::TAGS as $name => $label ) {
			$tags[] = array(
				'name'  => '{' . $name . '}',
				'label' => $label,
				'group' => 'Bombora',
			);
		}
		return $tags;
	}

	/**
	 * @param mixed  $tag     Tag.
	 * @param mixed  $post    Post položky.
	 * @param string $context Kontext.
	 */
	public static function render_tag( $tag, $post = null, $context = 'text' ) {
		if ( ! is_string( $tag ) ) {
			return $tag;
		}
		$clean = str_replace( array( '{', '}' ), '', $tag );
		if ( ! isset( self::TAGS[ $clean ] ) ) {
			return $tag;
		}
		return self::value( $clean, $post, (string) $context );
	}

	/**
	 * @param mixed  $content Obsah.
	 * @param mixed  $post    Post.
	 * @param string $context Kontext.
	 */
	public static function render_content( $content, $post = null, $context = 'text' ) {
		if ( ! is_string( $content ) || false === strpos( $content, '{bombora_bundle_' ) ) {
			return $content;
		}
		foreach ( array_keys( self::TAGS ) as $name ) {
			if ( false !== strpos( $content, '{' . $name . '}' ) ) {
				$content = str_replace( '{' . $name . '}', self::value( $name, $post, (string) $context ), $content );
			}
		}
		return $content;
	}

	private static function value( string $name, $post, string $context ): string {
		if ( 'bombora_bundle_variants_count' === $name ) {
			// Prázdné při 0 – Bricks podmínka „není prázdné“ (hodnotu „0“ Bricks v podmínkách bere jako nevyplněnou).
			$id    = self::current_product_id();
			$count = $id ? count( self::ids( $id ) ) : 0;
			return $count > 0 ? (string) $count : '';
		}

		if ( self::$loop_item ) {
			$post_id = self::$loop_item;
		} else {
			$post_id = $post instanceof \WP_Post ? $post->ID : ( is_numeric( $post ) ? (int) $post : get_the_ID() );
		}
		$product = $post_id ? wc_get_product( $post_id ) : null;
		if ( ! $product ) {
			return '';
		}
		$plain = in_array( $context, array( 'link', 'image', 'media' ), true );

		switch ( $name ) {
			case 'bombora_bundle_qty':
				$qty = self::bundle_qty( $product );
				return $qty > 1 ? (string) $qty : '';

			case 'bombora_bundle_unit_price':
				$qty = self::bundle_qty( $product );
				if ( $qty < 2 ) {
					return '';
				}
				return self::money( (float) wc_get_price_to_display( $product ) / $qty, $plain );

			case 'bombora_bundle_saving':
				$s = self::saving( $product );
				return $s ? self::money( $s['amount'], $plain ) : '';

			case 'bombora_bundle_saving_pct':
				$s = self::saving( $product );
				return $s ? (string) $s['pct'] : '';
		}
		return '';
	}

	private static function money( float $amount, bool $plain ): string {
		$html = wc_price( $amount );
		return $plain ? html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) : $html;
	}

	/**
	 * Úspora balení = běžná cena balení (součet položek) − aktuální cena, s DPH jako na webu.
	 * Jen pro balíčky (stejně jako dosavadní „Ušetríte“).
	 *
	 * @return array{amount:float,pct:int}|null
	 */
	public static function saving( \WC_Product $product ): ?array {
		if ( ! in_array( $product->get_type(), (array) apply_filters( 'bombora_bundle_variants_bundle_types', array( 'woosb', 'bundle' ) ), true ) ) {
			return null;
		}
		$regular = (float) $product->get_regular_price();
		if ( $regular <= 0 ) {
			return null;
		}
		$regular_display = (float) wc_get_price_to_display( $product, array( 'price' => $regular ) );
		$active_display  = (float) wc_get_price_to_display( $product );
		$amount          = round( $regular_display - $active_display, wc_get_price_decimals() );
		if ( $amount <= 0 ) {
			return null;
		}
		return array(
			'amount' => $amount,
			'pct'    => (int) round( $amount / $regular_display * 100 ),
		);
	}

	/* ------------------------------------------------------------------ Data */

	/**
	 * ID variant k zobrazení (v aktuálním jazyce): ruční Up-selly, jinak automatická rodina.
	 *
	 * @return int[]
	 */
	public static function ids( int $product_id ): array {
		if ( isset( self::$memo[ $product_id ] ) ) {
			return self::$memo[ $product_id ];
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return self::$memo[ $product_id ] = array();
		}

		$ids = array_map( array( __CLASS__, 'to_current_lang' ), $product->get_upsell_ids() );
		if ( ! $ids && apply_filters( 'bombora_bundle_variants_auto', true, $product ) ) {
			$ids = self::family( $product_id );
		}

		$ids = array_values( array_unique( array_filter( $ids, static fn( $id ) => $id && $id !== $product_id && 'publish' === get_post_status( $id ) && 'product' === get_post_type( $id ) ) ) );

		// Jednotlivý kus první, pak podle počtu kusů (3×, 6×, 20×).
		$weight = array();
		foreach ( $ids as $id ) {
			$p             = wc_get_product( $id );
			$weight[ $id ] = $p ? self::bundle_qty( $p ) : 99;
		}
		usort( $ids, static fn( $a, $b ) => array( $weight[ $a ], $a ) <=> array( $weight[ $b ], $b ) );

		$ids = array_slice( (array) apply_filters( 'bombora_bundle_variants_ids', $ids, $product_id ), 0, self::LIMIT );
		return self::$memo[ $product_id ] = array_map( 'intval', $ids );
	}

	/**
	 * Automatická rodina balení (jednotlivý produkt + bundly jen z něj), bez aktuálního produktu.
	 *
	 * @return int[]
	 */
	public static function family( int $product_id ): array {
		$lang      = (string) apply_filters( 'wpml_current_language', '' );
		$cache_key = 'bombora_bv_' . md5( $product_id . '|' . $lang . '|' . (int) get_option( self::VER_OPT, 1 ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		// Výpočet ve výchozím jazyce (WPML) – bundly ukládají ID položek původního jazyka.
		$default = (string) apply_filters( 'wpml_default_language', '' );
		$src_id  = $default ? (int) apply_filters( 'wpml_object_id', $product_id, 'product', true, $default ) : $product_id;
		$src     = wc_get_product( $src_id );
		$family  = array();

		if ( $src ) {
			$base = $src_id;
			if ( self::is_bundle( $src ) ) {
				$items = array_unique( array_keys( self::items( $src_id ) ) );
				$base  = 1 === count( $items ) ? (int) $items[0] : 0;
			}
			if ( $base ) {
				$family[] = $base;
				foreach ( self::bundles_of( $base ) as $bid ) {
					$family[] = $bid;
				}
			}
		}

		$family = array_values(
			array_unique(
				array_filter(
					array_map( array( __CLASS__, 'to_current_lang' ), $family ),
					static fn( $id ) => $id && $id !== $product_id
				)
			)
		);
		set_transient( $cache_key, $family, 12 * HOUR_IN_SECONDS );
		return $family;
	}

	/**
	 * Publikované bundly, jejichž jedinou položkou je $base.
	 *
	 * @return int[]
	 */
	private static function bundles_of( int $base ): array {
		$q = new \WP_Query(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'posts_per_page'   => 50,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array(
						'key'     => 'woosb_ids',
						'value'   => '"' . $base . '"',
						'compare' => 'LIKE',
					),
					array(
						'key'     => 'woosb_ids',
						'value'   => $base . '/',
						'compare' => 'LIKE',
					),
				),
			)
		);
		$out = array();
		foreach ( $q->posts as $id ) {
			$items = array_keys( self::items( (int) $id ) );
			if ( array( $base ) === array_values( array_unique( $items ) ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Položky WPC bundlu: [product_id => qty] (oba formáty meta woosb_ids).
	 *
	 * @return array<int,float>
	 */
	public static function items( int $bundle_id ): array {
		$raw = get_post_meta( $bundle_id, 'woosb_ids', true );
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $item ) {
				if ( is_array( $item ) && ! empty( $item['id'] ) ) {
					$id         = (int) $item['id'];
					$out[ $id ] = ( $out[ $id ] ?? 0 ) + max( 1, (float) ( $item['qty'] ?? 1 ) );
				}
			}
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			foreach ( explode( ',', $raw ) as $part ) {
				$bits = explode( '/', trim( $part ) );
				$id   = (int) $bits[0];
				if ( $id ) {
					$out[ $id ] = ( $out[ $id ] ?? 0 ) + max( 1, (float) ( $bits[1] ?? 1 ) );
				}
			}
		}
		return $out;
	}

	public static function bundle_qty( \WC_Product $product ): int {
		if ( ! self::is_bundle( $product ) ) {
			return 1;
		}
		$qty = (int) round( array_sum( self::items( $product->get_id() ) ) );
		return max( 1, $qty );
	}

	private static function is_bundle( \WC_Product $product ): bool {
		return 'woosb' === $product->get_type();
	}

	private static function to_current_lang( $id ): int {
		return (int) apply_filters( 'wpml_object_id', (int) $id, 'product', true );
	}

	/** Produkt stránky (funguje i uvnitř jiné smyčky a v náhledu šablony v Bricks). */
	private static function current_product_id(): int {
		$id = (int) get_queried_object_id();
		if ( $id && 'product' === get_post_type( $id ) ) {
			return $id;
		}
		if ( class_exists( '\Bricks\Database' ) && ! empty( \Bricks\Database::$page_data['preview_or_post_id'] ) ) {
			$pid = (int) \Bricks\Database::$page_data['preview_or_post_id'];
			if ( 'product' === get_post_type( $pid ) ) {
				return $pid;
			}
		}
		$id = (int) get_the_ID();
		return ( $id && 'product' === get_post_type( $id ) ) ? $id : 0;
	}

	public static function bump(): void {
		update_option( self::VER_OPT, (int) get_option( self::VER_OPT, 1 ) + 1 );
		self::$memo = array();
	}

	/* ------------------------------------------------------------------ Admin */

	public static function admin_hint(): void {
		echo '<p class="form-field"><span class="description" style="margin-left:150px;display:block">'
			. esc_html__( 'Up-sells are shown as “Other variants” (bundle sizes 3×, 6× …). Leave empty to pick bundles of this product automatically.', 'bombora-manager' )
			. '</span></p>';
	}
}
