<?php
/**
 * AI-crawler analytics ingest — the WordPress lane of SEOmatic's crawler
 * analytics (contract: seomatic-app docs/integrations/CRAWLER-ANALYTICS.md).
 *
 * When an AI bot (GPTBot, ChatGPT-User, PerplexityBot, ClaudeBot…) requests a
 * front-end page, fire-and-forget one event to the workspace's ingest
 * endpoint. Classification is SERVER-side in SEOmatic — this pre-filter only
 * exists so human traffic never generates a request. Human visitors are never
 * logged, never posted, never slowed: the post is non-blocking with a 2s cap,
 * and a per-minute transient cap protects the site under a bot storm.
 *
 * Setting: seomatic_connect_crawler_ingest_url — copied from SEOmatic
 * (AI Visibility → overview → "AI crawlers on your site"). Empty = feature off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEOmatic_Crawlers {

	/** Max ingest posts per minute — a storm-guard, not a meter (SEOmatic
	 * rolls up server-side; dropping excess under a storm loses only
	 * resolution, never correctness of "this bot was here today"). */
	const MAX_POSTS_PER_MINUTE = 60;

	/** Broad UA pre-filter. Being in sync with the server list is NOT
	 * required — SEOmatic classifies and drops non-AI UAs server-side. */
	const AI_BOT_HINT = '/gptbot|oai-searchbot|chatgpt-user|claude|anthropic|perplexity|google-extended|cloudvertexbot|meta-external|facebookbot|mistralai|duckassist|youbot|cohere|amazonbot|applebot-extended|bytespider|ccbot|diffbot|timpibot|omgili|ai2bot/i';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_record_hit' ), 1 );
	}

	public static function register_settings() {
		register_setting(
			'seomatic_connect',
			'seomatic_connect_crawler_ingest_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_ingest_url' ),
				'default'           => '',
			)
		);
	}

	public static function sanitize_ingest_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		// The URL embeds the workspace's write token — accept only SEOmatic's
		// own ingest path so a typo can't leak bot data to a third party.
		if ( 0 !== strpos( $value, SEOMATIC_CONNECT_APP_URL . '/api/ingest/crawler/' ) ) {
			add_settings_error(
				'seomatic_connect_crawler_ingest_url',
				'invalid_ingest_url',
				__( 'The AI-crawler endpoint should come from SEOmatic (AI Visibility page) and start with https://app.seomatic.ai/api/ingest/crawler/.', 'seomatic-connect' )
			);
			return get_option( 'seomatic_connect_crawler_ingest_url', '' );
		}
		return esc_url_raw( $value );
	}

	/**
	 * Front-end only, earliest sensible hook. Admin, AJAX, cron, REST and
	 * feed requests are skipped — the feature is a picture of AI bots reading
	 * PAGES, not of machinery talking to machinery.
	 */
	public static function maybe_record_hit() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		$ingest = get_option( 'seomatic_connect_crawler_ingest_url', '' );
		if ( '' === $ingest ) {
			return;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( '' === $ua || ! preg_match( self::AI_BOT_HINT, $ua ) ) {
			return; // human traffic: never logged, never posted.
		}

		// Storm guard: at most MAX_POSTS_PER_MINUTE ingest posts per minute.
		$bucket = 'seomatic_crawler_rate_' . gmdate( 'YmdHi' );
		$count  = (int) get_transient( $bucket );
		if ( $count >= self::MAX_POSTS_PER_MINUTE ) {
			return;
		}
		set_transient( $bucket, $count + 1, 2 * MINUTE_IN_SECONDS );

		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		wp_remote_post(
			$ingest,
			array(
				'timeout'  => 2,
				'blocking' => false,
				'headers'  => array(
					'Content-Type'      => 'application/json',
					'X-Seomatic-Source' => 'wp',
				),
				'body'     => wp_json_encode(
					array(
						'events' => array(
							array(
								'ua'   => $ua,
								'path' => $path,
								'ts'   => (int) round( microtime( true ) * 1000 ),
							),
						),
					)
				),
			)
		);
	}

	/** Settings row, rendered inside the existing seomatic_connect form. */
	public static function render_settings_row() {
		?>
		<tr>
			<th scope="row">
				<label for="seomatic_connect_crawler_ingest_url"><?php esc_html_e( 'AI-crawler analytics endpoint', 'seomatic-connect' ); ?></label>
			</th>
			<td>
				<input type="url" id="seomatic_connect_crawler_ingest_url" name="seomatic_connect_crawler_ingest_url"
					value="<?php echo esc_attr( get_option( 'seomatic_connect_crawler_ingest_url', '' ) ); ?>" class="regular-text" />
				<p class="description">
					<?php esc_html_e( 'Optional - copy it from SEOmatic (AI Visibility page, "AI crawlers on your site"). When set, visits from AI bots like GPTBot and PerplexityBot appear in your SEOmatic dashboard. Human visitors are never logged. Treat the URL like a password.', 'seomatic-connect' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}
}
