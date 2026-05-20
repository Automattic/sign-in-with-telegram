<?php
/**
 * Sign in with Telegram section on the user-edit / profile screens.
 *
 * @package Automattic\Telegram\SignIn
 */

declare(strict_types=1);

namespace Automattic\Telegram\SignIn;

use Automattic\Telegram\SignIn\Settings;
use Automattic\Telegram\SignIn\Login_Handler;
use Automattic\Telegram\SignIn\Endpoints;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the per-user "Connect / Disconnect Telegram" controls inside
 * `wp-admin/profile.php` (for the current user) and `wp-admin/user-edit.php`
 * (for an admin viewing someone else).
 *
 * Disconnect goes through Endpoints::ACTION_UNLINK on `wp-login.php` rather
 * than admin-post.php so the action stays reachable for non-admin roles
 * gated out of `/wp-admin/` by membership/security plugins.
 */
class Profile_Section {

	/**
	 * Build the section renderer.
	 *
	 * @param Settings $settings Drives the "is the plugin configured?" gate
	 *                           around the Connect button.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Hook into the profile screens + admin notices.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
	}

	/**
	 * Print the section.
	 *
	 * Already-linked users always see their status + a disconnect button
	 * — disconnecting works regardless of whether the plugin can still
	 * complete a fresh OIDC handshake. The Connect Telegram button only
	 * renders when the plugin is configured, since there's nowhere to
	 * redirect to without credentials.
	 *
	 * @param WP_User $user Profile owner.
	 */
	public function render( WP_User $user ): void {
		$sub       = (string) get_user_meta( $user->ID, Login_Handler::USERMETA_SUB, true );
		$is_linked = '' !== $sub;

		// Skip the whole section when the plugin isn't configured AND the
		// user isn't already linked — there's nothing to do or surface.
		// Linked users still see the section so disconnect stays reachable
		// after credentials get rotated out.
		if ( ! $is_linked && ! $this->settings->is_configured() ) {
			return;
		}

		$is_self = get_current_user_id() === $user->ID;
		?>
		<h2><?php esc_html_e( 'Sign in with Telegram', 'sign-in-with-telegram' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Telegram account', 'sign-in-with-telegram' ); ?></th>
				<td>
					<?php if ( $is_linked ) : ?>
						<p>
							<?php
							printf(
								/* translators: %s: short identifier for the linked Telegram account. */
								esc_html__( 'Linked to Telegram account %s.', 'sign-in-with-telegram' ),
								'<code>' . esc_html( self::format_sub( $sub ) ) . '</code>'
							);
							?>
						</p>
						<p>
							<a href="<?php echo esc_url( self::unlink_url( $user->ID ) ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Disconnect Telegram', 'sign-in-with-telegram' ); ?>
							</a>
						</p>
					<?php elseif ( $is_self ) : ?>
						<p><?php esc_html_e( 'Your account is not connected to Telegram.', 'sign-in-with-telegram' ); ?></p>
						<p>
							<a href="<?php echo esc_url( self::link_url() ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Connect Telegram', 'sign-in-with-telegram' ); ?>
							</a>
						</p>
					<?php else : ?>
						<p><?php esc_html_e( 'This account is not connected to Telegram.', 'sign-in-with-telegram' ); ?></p>
						<p class="description">
							<?php esc_html_e( 'Only the account owner can connect a Telegram account.', 'sign-in-with-telegram' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render the post-link / post-unlink success notice on the profile screen.
	 *
	 * Hooked unconditionally; bails out when neither query param is present
	 * or when we're not on the profile / user-edit screen.
	 */
	public function maybe_render_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! in_array( $screen->id, array( 'profile', 'user-edit' ), true ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only success indicators set by our own redirects; no state change.
		$linked   = ! empty( $_GET['telegram_signin_linked'] );
		$unlinked = ! empty( $_GET['telegram_signin_unlinked'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $linked && ! $unlinked ) {
			return;
		}

		$message = $linked
			? __( 'Telegram account connected.', 'sign-in-with-telegram' )
			: __( 'Telegram account disconnected.', 'sign-in-with-telegram' );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Build the URL that kicks off the link flow.
	 *
	 * @return string Fully-formed link URL with WP nonce.
	 */
	private static function link_url(): string {
		return (string) add_query_arg(
			array(
				'action'   => Endpoints::ACTION_LINK,
				'_wpnonce' => wp_create_nonce( Endpoints::ACTION_LINK ),
			),
			wp_login_url()
		);
	}

	/**
	 * Build the URL that triggers the unlink action.
	 *
	 * The target user id is carried explicitly so admins viewing another
	 * user's profile can disconnect that user (vs. only their own session).
	 * Authorization is enforced server-side via `current_user_can( 'edit_user', $target )`
	 * in Endpoints::handle_unlink. The nonce is scoped to the target id so
	 * a nonce captured for one profile can't be replayed against another.
	 *
	 * @param int $user_id Owner of the profile being viewed.
	 *
	 * @return string Fully-formed unlink URL.
	 */
	private static function unlink_url( int $user_id ): string {
		// add_query_arg URL-encodes values itself — passing a raw absolute URL
		// is correct here. A previous version pre-encoded with rawurlencode(),
		// which produced double-encoded `%25...` redirects.
		return (string) add_query_arg(
			array(
				'action'      => Endpoints::ACTION_UNLINK,
				'user_id'     => $user_id,
				'_wpnonce'    => wp_create_nonce( Endpoints::ACTION_UNLINK . '_' . $user_id ),
				'redirect_to' => self::profile_url( $user_id ),
			),
			wp_login_url()
		);
	}

	/**
	 * Profile URL for a given user — own profile screen if it's the current
	 * user, user-edit screen otherwise.
	 *
	 * @param int $user_id Target user.
	 *
	 * @return string Absolute URL.
	 */
	private static function profile_url( int $user_id ): string {
		if ( get_current_user_id() === $user_id ) {
			return admin_url( 'profile.php' );
		}
		return (string) add_query_arg( 'user_id', $user_id, admin_url( 'user-edit.php' ) );
	}

	/**
	 * Truncate a Telegram `sub` to a short display form. We don't expose the
	 * full identifier because it's effectively a stable per-bot user id and
	 * there's no upside to printing it in full.
	 *
	 * @param string $sub Raw sub claim.
	 *
	 * @return string Display-safe truncated form.
	 */
	private static function format_sub( string $sub ): string {
		if ( strlen( $sub ) <= 8 ) {
			return $sub;
		}
		return substr( $sub, 0, 4 ) . '…' . substr( $sub, -4 );
	}
}
