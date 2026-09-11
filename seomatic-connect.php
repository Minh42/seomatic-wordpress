<?php
/**
 * Plugin Name:       SEOmatic Connect
 * Plugin URI:        https://seomatic.ai/integrations/wordpress
 * Description:       Connect your WordPress site to SEOmatic: AI SEO agents that read your Search Console, find the fixes that matter, and apply them with your approval.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            SEOmatic
 * Author URI:        https://seomatic.ai
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seomatic-connect
 *
 * SEOmatic Connect is a thin connector, on purpose. The heavy lifting (audits,
 * agents, approvals) happens in the SEOmatic service; this plugin gives you a
 * one-click connection, a dashboard status card, and instant content-freshness
 * pings so SEOmatic always sees your real site. It sends NOTHING anywhere
 * until you connect an account.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEOMATIC_CONNECT_VERSION', '1.0.0' );
define( 'SEOMATIC_CONNECT_APP_URL', 'https://app.seomatic.ai' );

/**
 * Options:
 *  - seomatic_connect_api_key      SEOmatic API key (optional; powers the widget)
 *  - seomatic_connect_webhook_url  Freshness webhook URL (optional; powers pings)
 */
class SEOmatic_Connect {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			array( __CLASS__, 'action_links' )
		);

		// Content-freshness pings. Guideline 7 compliance: these hooks only
		// POST when the site owner has pasted a webhook URL - the plugin
		// phones nowhere before an explicit connection.
		add_action( 'save_post', array( __CLASS__, 'ping_freshness' ), 10, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'ping_freshness_deleted' ) );
		add_action( 'trashed_post', array( __CLASS__, 'ping_freshness_deleted' ) );
	}

	public static function action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=seomatic-connect' ) ),
			esc_html__( 'Settings', 'seomatic-connect' )
		);
		array_unshift( $links, $settings );
		return $links;
	}

	public static function admin_menu() {
		add_options_page(
			__( 'SEOmatic Connect', 'seomatic-connect' ),
			__( 'SEOmatic', 'seomatic-connect' ),
			'manage_options',
			'seomatic-connect',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'seomatic_connect',
			'seomatic_connect_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_api_key' ),
				'default'           => '',
			)
		);
		register_setting(
			'seomatic_connect',
			'seomatic_connect_webhook_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_webhook_url' ),
				'default'           => '',
			)
		);
	}

	public static function sanitize_api_key( $value ) {
		$value = trim( (string) $value );
		// SEOmatic keys are smk_ prefixed; accept empty (disconnect).
		if ( '' !== $value && ! preg_match( '/^smk_[A-Za-z0-9_]{8,128}$/', $value ) ) {
			add_settings_error(
				'seomatic_connect_api_key',
				'invalid_key',
				__( 'That does not look like a SEOmatic API key (it starts with smk_).', 'seomatic-connect' )
			);
			return get_option( 'seomatic_connect_api_key', '' );
		}
		delete_transient( 'seomatic_connect_status' );
		return $value;
	}

	public static function sanitize_webhook_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 0 !== strpos( $value, SEOMATIC_CONNECT_APP_URL . '/api/webhooks/cms/' ) ) {
			add_settings_error(
				'seomatic_connect_webhook_url',
				'invalid_webhook',
				__( 'The webhook URL should come from SEOmatic (Connections page) and start with https://app.seomatic.ai/api/webhooks/cms/.', 'seomatic-connect' )
			);
			return get_option( 'seomatic_connect_webhook_url', '' );
		}
		return esc_url_raw( $value );
	}

	/** The one-click connect URL: lands on SEOmatic's login-aware deep link,
	 * which forwards the admin into this site's own
	 * wp-admin/authorize-application.php (WordPress core issues the
	 * Application Password; SEOmatic never sees the admin's real password). */
	public static function connect_url() {
		// Built by hand, not add_query_arg: its value-encoding behavior varies
		// across WP versions and a double-encoded site URL breaks the connect.
		return SEOMATIC_CONNECT_APP_URL . '/connect/wordpress?site='
			. rawurlencode( home_url() ) . '&source=wp-plugin';
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$api_key = get_option( 'seomatic_connect_api_key', '' );
		$status  = $api_key ? self::account_status() : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SEOmatic Connect', 'seomatic-connect' ); ?></h1>

			<?php if ( ! $api_key ) : ?>
				<p style="max-width:640px;">
					<?php esc_html_e( 'SEOmatic gives your site AI SEO agents: they read your Google Search Console, find pages one step from page 1, and stage fixes you approve before anything changes. Connecting is free, and the analysis tools stay free.', 'seomatic-connect' ); ?>
				</p>
				<p>
					<a class="button button-primary button-hero" href="<?php echo esc_url( self::connect_url() ); ?>">
						<?php esc_html_e( 'Connect to SEOmatic', 'seomatic-connect' ); ?>
					</a>
				</p>
				<p style="max-width:640px;color:#646970;">
					<?php esc_html_e( 'You approve the connection inside your own WordPress admin (Application Passwords) - SEOmatic never sees your password. A free SEOmatic account is required.', 'seomatic-connect' ); ?>
				</p>
				<hr />
			<?php elseif ( $status && ! empty( $status['ok'] ) ) : ?>
				<p>
					<strong style="color:#00a32a;">&#9679; <?php esc_html_e( 'Connected to SEOmatic', 'seomatic-connect' ); ?></strong>
					<?php if ( ! empty( $status['label'] ) ) : ?>
						&mdash; <?php echo esc_html( $status['label'] ); ?>
					<?php endif; ?>
				</p>
				<p>
					<a class="button" href="<?php echo esc_url( SEOMATIC_CONNECT_APP_URL . '/dashboard' ); ?>">
						<?php esc_html_e( 'Open SEOmatic', 'seomatic-connect' ); ?>
					</a>
				</p>
			<?php else : ?>
				<p><strong style="color:#d63638;">&#9679; <?php esc_html_e( 'API key saved, but SEOmatic did not accept it. Double-check it below.', 'seomatic-connect' ); ?></strong></p>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'seomatic_connect' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="seomatic_connect_api_key"><?php esc_html_e( 'SEOmatic API key', 'seomatic-connect' ); ?></label>
						</th>
						<td>
							<input type="password" id="seomatic_connect_api_key" name="seomatic_connect_api_key"
								value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" autocomplete="off" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: URL of the SEOmatic API keys page */
									esc_html__( 'Optional - powers the dashboard status card. Create one in SEOmatic under %s.', 'seomatic-connect' ),
									'<a href="' . esc_url( SEOMATIC_CONNECT_APP_URL . '/dashboard/settings?tab=integrations' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Settings & Integrations', 'seomatic-connect' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="seomatic_connect_webhook_url"><?php esc_html_e( 'Freshness webhook URL', 'seomatic-connect' ); ?></label>
						</th>
						<td>
							<input type="url" id="seomatic_connect_webhook_url" name="seomatic_connect_webhook_url"
								value="<?php echo esc_attr( get_option( 'seomatic_connect_webhook_url', '' ) ); ?>" class="regular-text" />
							<p class="description">
								<?php esc_html_e( 'Optional - copy it from SEOmatic (Connections page). When set, publishing or deleting content notifies SEOmatic within seconds instead of waiting for the daily sync.', 'seomatic-connect' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/** Cached (1h) account check via the SEOmatic REST API. Only ever called
	 * when an API key is saved. */
	public static function account_status() {
		$cached = get_transient( 'seomatic_connect_status' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$api_key  = get_option( 'seomatic_connect_api_key', '' );
		$response = wp_remote_get(
			SEOMATIC_CONNECT_APP_URL . '/api/v1/me',
			array(
				'timeout' => 5,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'User-Agent'    => 'seomatic-connect/' . SEOMATIC_CONNECT_VERSION,
				),
			)
		);
		$status = array( 'ok' => false );
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body   = json_decode( wp_remote_retrieve_body( $response ), true );
			$status = array(
				'ok'    => true,
				'label' => isset( $body['workspace']['name'] ) ? (string) $body['workspace']['name'] : '',
			);
		}
		set_transient( 'seomatic_connect_status', $status, HOUR_IN_SECONDS );
		return $status;
	}

	public static function dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_option( 'seomatic_connect_api_key', '' ) ) {
			return; // Nothing to show pre-connect; no nag widgets (guideline 11).
		}
		wp_add_dashboard_widget(
			'seomatic_connect_widget',
			__( 'SEOmatic', 'seomatic-connect' ),
			array( __CLASS__, 'render_widget' )
		);
	}

	public static function render_widget() {
		$status = self::account_status();
		if ( empty( $status['ok'] ) ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'SEOmatic could not verify the saved API key. Check it in Settings > SEOmatic.', 'seomatic-connect' )
			);
			return;
		}
		printf(
			'<p><strong style="color:#00a32a;">&#9679; %s</strong>%s</p>',
			esc_html__( 'Connected', 'seomatic-connect' ),
			$status['label'] ? esc_html( ' - ' . $status['label'] ) : ''
		);
		printf(
			'<p>%s</p>',
			esc_html__( 'Your agents watch Search Console and stage fixes for your approval.', 'seomatic-connect' )
		);
		printf(
			'<p><a class="button button-primary" href="%s" target="_blank" rel="noopener">%s</a></p>',
			esc_url( SEOMATIC_CONNECT_APP_URL . '/dashboard' ),
			esc_html__( 'Open your SEO queue', 'seomatic-connect' )
		);
	}

	/** save_post variant: skip autosaves/revisions/drafts. */
	public static function ping_freshness( $post_id, $post, $update ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		// Only PUBLIC post types. save_post also fires for plugin internals
		// (ACF field groups, menu items, block templates); SEOmatic indexes
		// what is publicly reachable, so pinging for the rest is pure noise.
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || empty( $type->public ) ) {
			return;
		}
		self::ping_freshness_simple( $post_id );
	}

	/** deleted_post/trashed_post variant: these fire for REVISIONS and
	 * auto-drafts too, and WordPress deletes every revision of a post before
	 * the post itself — so an unguarded handler sent one ping per revision, a
	 * burst of dozens for a single deletion that the receiver then has to
	 * debounce. Filter to real, public content first. */
	public static function ping_freshness_deleted( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || empty( $type->public ) ) {
			return;
		}
		self::ping_freshness_simple( $post_id );
	}

	/** Fire-and-forget freshness ping. Non-blocking, 2s cap, body ignored by
	 * the receiver (the webhook secret in the URL is the auth). */
	public static function ping_freshness_simple( $post_id ) {
		$url = get_option( 'seomatic_connect_webhook_url', '' );
		if ( '' === $url ) {
			return;
		}
		wp_remote_post(
			$url,
			array(
				'timeout'  => 2,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'source'  => 'seomatic-connect',
						'event'   => current_action(),
						'post_id' => (int) $post_id,
					)
				),
			)
		);
	}
}

SEOmatic_Connect::init();
