<?php
/**
 * Administrace: menu „Bombora“ a přehled modulů (stav, verze, instalace, aktivace, aktualizace).
 *
 * @package Bombora\Manager
 */

namespace Bombora\Manager;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const PAGE = 'bombora';
	public const CAP  = 'manage_options';

	public static function init(): void {
		// Priorita 5: menu „Bombora“ musí existovat dřív, než si do něj moduly přidají podstránky.
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . BOMBORA_MANAGER_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	public static function menu(): void {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M3 3h8.5a4 4 0 0 1 2.6 7A4.5 4.5 0 0 1 11.5 17H3V3zm3 3v3h5a1.5 1.5 0 0 0 0-3H6zm0 6v2.5h5.5a1.25 1.25 0 0 0 0-2.5H6z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		add_menu_page( 'Bombora', 'Bombora', self::CAP, self::PAGE, array( __CLASS__, 'render' ), $icon, 56 );
		add_submenu_page( self::PAGE, 'Bombora', __( 'Modules', 'bombora-manager' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * @param string $hook Aktuální stránka.
	 */
	public static function assets( $hook ): void {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		$file = 'assets/admin.css';
		wp_enqueue_style( 'bombora-manager', BOMBORA_MANAGER_URL . $file, array(), BOMBORA_MANAGER_VERSION . '.' . (int) @filemtime( BOMBORA_MANAGER_DIR . $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * @param string[] $links Odkazy.
	 * @return string[]
	 */
	public static function action_links( $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Modules', 'bombora-manager' ) . '</a>' );
		return $links;
	}

	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen zobrazení stavové hlášky.
		$msg = isset( $_GET['bombora-msg'] ) ? sanitize_key( wp_unslash( $_GET['bombora-msg'] ) ) : '';
		if ( '' === $msg ) {
			return;
		}
		$map = array(
			'installed' => array( 'success', __( 'Module installed. Activate it below.', 'bombora-manager' ) ),
			'checked'   => array( 'success', __( 'Updates checked.', 'bombora-manager' ) ),
			'norelease' => array( 'warning', __( 'This module has no published release yet.', 'bombora-manager' ) ),
			'invalid'   => array( 'error', __( 'Unknown or already installed module.', 'bombora-manager' ) ),
			'error'     => array( 'error', __( 'Installation failed.', 'bombora-manager' ) ),
			'migrated'      => array( 'success', __( 'Plugin converted to the new Bombora folder. Settings and data are unchanged.', 'bombora-manager' ) ),
			'migrate-error' => array( 'error', __( 'Conversion failed.', 'bombora-manager' ) ),
		);
		if ( ! isset( $map[ $msg ] ) ) {
			return;
		}
		$text = $map[ $msg ][1];
		if ( 'error' === $msg || 'migrate-error' === $msg ) {
			$detail = get_transient( 'bombora_err_' . get_current_user_id() );
			if ( is_string( $detail ) && '' !== $detail ) {
				$text .= ' ' . $detail;
			}
			delete_transient( 'bombora_err_' . get_current_user_id() );
		}
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $text ) );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$can_mods = Installer::file_mods_allowed();
		?>
		<div class="wrap bombora">
			<header class="bombora__head">
				<div>
					<h1>Bombora <span class="bombora-badge">Manager v<?php echo esc_html( BOMBORA_MANAGER_VERSION ); ?></span></h1>
					<p><?php esc_html_e( 'In-house plugins of Bombora group s.r.o. – one place for installation, settings and secure updates.', 'bombora-manager' ); ?></p>
				</div>
				<?php if ( current_user_can( 'update_plugins' ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( Installer::CHECK ); ?>">
						<?php wp_nonce_field( Installer::CHECK ); ?>
						<button type="submit" class="button bombora-btn-light"><?php esc_html_e( 'Check for updates', 'bombora-manager' ); ?></button>
					</form>
				<?php endif; ?>
			</header>

			<?php self::notice(); ?>

			<div class="bombora-grid">
				<?php
				foreach ( Catalog::all() as $slug => $m ) {
					self::card( $slug, $m, $can_mods );
				}
				?>
			</div>

			<p class="bombora-foot">
				<?php esc_html_e( 'Updates come from public GitHub releases of bombora-tech and every package is verified with SHA-256 before installation.', 'bombora-manager' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * @param array<string,string> $m Modul.
	 */
	private static function card( string $slug, array $m, bool $can_mods ): void {
		$st        = Catalog::install_state( $m );
		$file      = $st ? $st['file'] : $m['file'];
		$legacy    = $st && $st['legacy'];
		$installed = $st ? $st['data'] : null;
		$active    = $installed && is_plugin_active( $file );
		$pending   = ! empty( $m['legacy'] ) && ( null !== Migrator::pending( $slug ) || ( ! $legacy && $installed && Catalog::legacy_present( $m ) ) );
		$rel       = GitHub::latest( $m ); // Na této stránce smí dotaz na GitHub (cache 6 h).
		$latest    = $rel ? $rel['version'] : '';
		$has_upd   = $installed && ! $legacy && $latest && version_compare( $latest, (string) $installed['Version'], '>' );
		$err       = GitHub::last_error( $m['repo'] );

		if ( $active ) {
			$state = array( 'ok', __( 'Active', 'bombora-manager' ) );
		} elseif ( $installed ) {
			$state = array( 'off', __( 'Inactive', 'bombora-manager' ) );
		} else {
			$state = array( 'none', __( 'Not installed', 'bombora-manager' ) );
		}
		?>
		<section class="bombora-card<?php echo $active ? ' is-active' : ''; ?>">
			<div class="bombora-card__top">
				<span class="dashicons dashicons-<?php echo esc_attr( $m['icon'] ); ?>" aria-hidden="true"></span>
				<div>
					<h2><?php echo esc_html( $m['name'] ); ?></h2>
					<span class="bombora-state bombora-state--<?php echo esc_attr( $state[0] ); ?>"><?php echo esc_html( $state[1] ); ?></span>
				</div>
			</div>
			<p class="bombora-card__desc"><?php echo esc_html( $m['desc'] ); ?></p>
			<?php if ( $legacy ) : ?>
				<p class="bombora-card__note">
					<?php
					/* translators: 1: old folder, 2: new folder */
					echo esc_html( sprintf( __( 'Installed in the old folder %1$s. The conversion moves it to %2$s – settings and data stay.', 'bombora-manager' ), dirname( $file ), dirname( $m['file'] ) ) );
					?>
				</p>
			<?php endif; ?>
			<dl class="bombora-meta">
				<div><dt><?php esc_html_e( 'Installed', 'bombora-manager' ); ?></dt><dd><?php echo $installed ? esc_html( (string) $installed['Version'] ) : '—'; ?></dd></div>
				<div><dt><?php esc_html_e( 'Latest', 'bombora-manager' ); ?></dt><dd>
					<?php
					if ( $latest ) {
						echo esc_html( $latest );
					} elseif ( 'no-release' === $err ) {
						esc_html_e( 'not released yet', 'bombora-manager' );
					} else {
						echo '—';
						if ( '' !== $err ) {
							echo '<br><small class="bombora-err">' . esc_html( $err ) . '</small>';
						}
					}
					?>
				</dd></div>
			</dl>
			<div class="bombora-actions">
				<?php if ( $active && ! empty( $m['settings'] ) && empty( $m['core'] ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( $m['settings'] ) ); ?>"><?php esc_html_e( 'Settings', 'bombora-manager' ); ?></a>
				<?php endif; ?>

				<?php if ( $has_upd && $can_mods && current_user_can( 'update_plugins' ) ) : ?>
					<a class="button bombora-btn-update" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $m['file'] ) ), 'upgrade-plugin_' . $m['file'] ) ); ?>">
						<?php
						/* translators: %s: version */
						echo esc_html( sprintf( __( 'Update to %s', 'bombora-manager' ), $latest ) );
						?>
					</a>
				<?php endif; ?>

				<?php if ( $pending && Migrator::allowed() ) : ?>
					<a class="button bombora-btn-update" href="<?php echo esc_url( Migrator::finish_url( $slug ) ); ?>"><?php esc_html_e( 'Finish conversion', 'bombora-manager' ); ?></a>
				<?php elseif ( $legacy && $latest && Migrator::allowed() ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( Migrator::START ); ?>">
						<input type="hidden" name="module" value="<?php echo esc_attr( $slug ); ?>">
						<?php wp_nonce_field( Migrator::START . '_' . $slug ); ?>
						<button type="submit" class="button bombora-btn-update">
							<?php
							/* translators: %s: version */
							echo esc_html( sprintf( __( 'Convert and update to %s', 'bombora-manager' ), $latest ) );
							?>
						</button>
					</form>
				<?php endif; ?>

				<?php if ( $installed && ! $active && current_user_can( 'activate_plugins' ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ), 'activate-plugin_' . $file ) ); ?>"><?php esc_html_e( 'Activate', 'bombora-manager' ); ?></a>
				<?php elseif ( $active && empty( $m['core'] ) && current_user_can( 'activate_plugins' ) ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( $file ) ), 'deactivate-plugin_' . $file ) ); ?>"><?php esc_html_e( 'Deactivate', 'bombora-manager' ); ?></a>
				<?php endif; ?>

				<?php if ( ! $installed && $can_mods && current_user_can( 'install_plugins' ) ) : ?>
					<?php if ( $latest ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( Installer::ACTION ); ?>">
							<input type="hidden" name="module" value="<?php echo esc_attr( $slug ); ?>">
							<?php wp_nonce_field( Installer::ACTION . '_' . $slug ); ?>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Install', 'bombora-manager' ); ?></button>
						</form>
					<?php else : ?>
						<button type="button" class="button" disabled><?php esc_html_e( 'Install', 'bombora-manager' ); ?></button>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}
}
