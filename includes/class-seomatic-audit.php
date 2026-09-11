<?php
/**
 * The local SEO audit engine — rung 1 of the value ladder.
 *
 * Everything in this file runs INSIDE the customer's WordPress: WP_Query over
 * their own posts, get_post_meta over their own SEO-plugin fields. Nothing
 * leaves the site, no account is needed, no consent is asked. That is the
 * point — the audit is the value a visitor sees BEFORE any permission ask,
 * and it keeps the readme's "sends nothing anywhere until you connect"
 * disclosure literally true.
 *
 * The thresholds (title <= 60, meta description <= 155) are the SAME numbers
 * the SEOmatic agent enforces when it writes fixes, so what we flag for free
 * is exactly what the paid product repairs — no drift between diagnosis and
 * treatment.
 *
 * The per-plugin meta keys are ported from SEOmatic's publisher config
 * (lib/config/seo-plugins-config.ts) and carry its two traps:
 *   - SEOPress stores noindex as 'yes' on an INDEX-named field.
 *   - Rank Math stores robots as an array; 'noindex' is a token inside it.
 *
 * Scanning is CHUNKED (100 posts per request, 2000 cap) through a REST route
 * the admin page loops from JS. A 5k-post site must never hit a PHP timeout
 * on one request, and the cap is stated in the UI rather than silently
 * truncating.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEOmatic_Audit {

	const CHUNK_SIZE   = 100;
	const MAX_POSTS    = 2000;
	const TITLE_MAX    = 60;
	const META_MAX     = 155;
	const THIN_WORDS   = 300;
	const STATE_OPTION = 'seomatic_audit_state';
	const RESULT_OPTION = 'seomatic_audit_results';
	/** Max example rows kept per finding — the option must stay small. */
	const MAX_ROWS = 25;
	const MAX_WORST = 15;

	/** SEO plugin meta keys, ported from seomatic-app's publisher config. */
	private static function seo_plugins() {
		return array(
			array(
				'title'         => '_yoast_wpseo_title',
				'desc'          => '_yoast_wpseo_metadesc',
				'noindex_field' => '_yoast_wpseo_meta-robots-noindex',
				'noindex_value' => '1',
			),
			array(
				'title'         => 'rank_math_title',
				'desc'          => 'rank_math_description',
				// Rank Math: array of robots tokens; noindex is a member.
				'noindex_field' => 'rank_math_robots',
				'noindex_value' => 'noindex',
				'noindex_array' => true,
			),
			array(
				'title'         => '_aioseo_title',
				'desc'          => '_aioseo_description',
				'noindex_field' => '_aioseo_robots_noindex',
				'noindex_value' => '1',
			),
			array(
				'title'         => '_seopress_titles_title',
				'desc'          => '_seopress_titles_desc',
				// SEOPress trap: 'yes' on the INDEX field means NOINDEX.
				'noindex_field' => '_seopress_robots_index',
				'noindex_value' => 'yes',
			),
		);
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'seomatic/v1',
			'/audit-scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_scan_chunk' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/** The post types worth auditing: public, queryable, not attachments. */
	private static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * One chunk of the scan. The admin page's JS calls this in a loop until
	 * `done` comes back true. State between chunks lives in an option, not a
	 * transient — a scan interrupted by an object-cache flush must resume,
	 * not silently restart at zero.
	 */
	public static function handle_scan_chunk( WP_REST_Request $request ) {
		$fresh = (bool) $request->get_param( 'fresh' );
		$state = get_option( self::STATE_OPTION, null );

		if ( $fresh || ! is_array( $state ) ) {
			$state = self::blank_state();
		}

		$query = new WP_Query(
			array(
				'post_type'              => self::post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => self::CHUNK_SIZE,
				'offset'                 => $state['offset'],
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $query->posts as $post ) {
			self::audit_post( $post, $state );
			$state['scanned']++;
		}
		$state['offset'] += self::CHUNK_SIZE;

		$total   = min( (int) $query->found_posts, self::MAX_POSTS );
		$done    = $state['scanned'] >= $total || count( $query->posts ) < self::CHUNK_SIZE;
		$state['total']     = $total;
		$state['truncated'] = $query->found_posts > self::MAX_POSTS;

		if ( $done ) {
			self::finalize( $state );
			delete_option( self::STATE_OPTION );
		} else {
			update_option( self::STATE_OPTION, $state, false );
		}

		return rest_ensure_response(
			array(
				'done'    => $done,
				'scanned' => $state['scanned'],
				'total'   => $total,
			)
		);
	}

	private static function blank_state() {
		return array(
			'offset'    => 0,
			'scanned'   => 0,
			'total'     => 0,
			'truncated' => false,
			'counts'    => array(
				'title'   => 0,
				'meta'    => 0,
				'thin'    => 0,
				'alt'     => 0,
				'noindex' => 0,
			),
			'rows'      => array(
				'title'   => array(),
				'meta'    => array(),
				'thin'    => array(),
				'alt'     => array(),
				'noindex' => array(),
			),
			'worst'     => array(),
		);
	}

	/** Audit ONE post into the running state. */
	private static function audit_post( $post, array &$state ) {
		$issues = array();

		// ---- Effective SEO title: first explicit per-post override wins;
		// otherwise the raw post_title. An override containing template vars
		// (%%sep%% etc.) cannot be length-checked honestly, so it is SKIPPED
		// rather than guessed at — the audit's failure direction is
		// deliberately under-flagging, same doctrine as the app's CTR curve.
		$title          = '';
		$title_explicit = false;
		foreach ( self::seo_plugins() as $p ) {
			$v = get_post_meta( $post->ID, $p['title'], true );
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				$title          = trim( $v );
				$title_explicit = true;
				break;
			}
		}
		if ( ! $title_explicit ) {
			$title = (string) $post->post_title;
		}
		$title_skippable = $title_explicit && false !== strpos( $title, '%%' );
		if ( ! $title_skippable ) {
			if ( '' === trim( $title ) ) {
				$issues['title'] = __( 'No title', 'seomatic-connect' );
			} elseif ( mb_strlen( $title ) > self::TITLE_MAX ) {
				$issues['title'] = sprintf(
					/* translators: 1: character count, 2: recommended max */
					__( 'Title is %1$d characters (max %2$d)', 'seomatic-connect' ),
					mb_strlen( $title ),
					self::TITLE_MAX
				);
			}
		}

		// ---- Meta description: first non-empty plugin field.
		$desc = '';
		foreach ( self::seo_plugins() as $p ) {
			$v = get_post_meta( $post->ID, $p['desc'], true );
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				$desc = trim( $v );
				break;
			}
		}
		if ( '' === $desc ) {
			$issues['meta'] = __( 'No meta description', 'seomatic-connect' );
		} elseif ( mb_strlen( $desc ) > self::META_MAX ) {
			$issues['meta'] = sprintf(
				/* translators: 1: character count, 2: recommended max */
				__( 'Meta description is %1$d characters (max %2$d)', 'seomatic-connect' ),
				mb_strlen( $desc ),
				self::META_MAX
			);
		}

		// ---- Thin content: POSTS only. A contact page is legitimately
		// short; flagging it would teach the user to ignore the audit.
		if ( 'post' === $post->post_type ) {
			$text  = wp_strip_all_tags( (string) $post->post_content );
			$words = count( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) );
			if ( $words < self::THIN_WORDS && $words > 0 ) {
				$issues['thin'] = sprintf(
					/* translators: %d: word count */
					__( 'Only %d words', 'seomatic-connect' ),
					$words
				);
			}
		}

		// ---- Images missing alt text.
		$missing_alt = 0;
		if ( false !== stripos( (string) $post->post_content, '<img' ) ) {
			preg_match_all( '/<img\b[^>]*>/i', (string) $post->post_content, $imgs );
			foreach ( $imgs[0] as $tag ) {
				if ( ! preg_match( '/\balt\s*=\s*(["\'])(?!\1)[^"\']*\S[^"\']*\1/i', $tag ) ) {
					$missing_alt++;
				}
			}
		}
		if ( $missing_alt > 0 ) {
			$issues['alt'] = sprintf(
				/* translators: %d: number of images */
				_n( '%d image without alt text', '%d images without alt text', $missing_alt, 'seomatic-connect' ),
				$missing_alt
			);
		}

		// ---- Noindex on a PUBLISHED post — usually an accident, always
		// worth a look. Per-plugin value semantics from the config above.
		foreach ( self::seo_plugins() as $p ) {
			$v = get_post_meta( $post->ID, $p['noindex_field'], true );
			$hit = ! empty( $p['noindex_array'] )
				? ( is_array( $v ) && in_array( $p['noindex_value'], $v, true ) )
				: ( (string) $v === $p['noindex_value'] );
			if ( $hit ) {
				$issues['noindex'] = __( 'Marked noindex', 'seomatic-connect' );
				break;
			}
		}

		if ( empty( $issues ) ) {
			return;
		}

		$row = array(
			'id'    => $post->ID,
			'title' => wp_html_excerpt( (string) $post->post_title, 80, '…' ),
		);
		foreach ( $issues as $key => $label ) {
			$state['counts'][ $key ]++;
			if ( count( $state['rows'][ $key ] ) < self::MAX_ROWS ) {
				$state['rows'][ $key ][] = $row + array( 'note' => $label );
			}
		}

		// Worst-pages score: noindex outranks everything (invisible page),
		// then missing basics, then polish.
		$weights = array(
			'noindex' => 5,
			'meta'    => 2,
			'title'   => 2,
			'thin'    => 2,
			'alt'     => 1,
		);
		$score = 0;
		foreach ( $issues as $key => $_ ) {
			$score += $weights[ $key ];
		}
		$state['worst'][] = $row + array(
			'score'  => $score,
			'issues' => array_values( $issues ),
		);
		usort(
			$state['worst'],
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		$state['worst'] = array_slice( $state['worst'], 0, self::MAX_WORST );
	}

	private static function finalize( array $state ) {
		update_option(
			self::RESULT_OPTION,
			array(
				'scanned_at' => time(),
				'scanned'    => $state['scanned'],
				'truncated'  => $state['truncated'],
				'counts'     => $state['counts'],
				'rows'       => $state['rows'],
				'worst'      => $state['worst'],
			),
			false
		);
	}

	public static function results() {
		$r = get_option( self::RESULT_OPTION, null );
		return is_array( $r ) ? $r : null;
	}
}
