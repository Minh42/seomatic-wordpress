<?php
/**
 * The SEOmatic admin page: audit cards + the value ladder, in SEOmatic's
 * own design language.
 *
 * BRAND NOTE: the --sm-* palette below is the app's design system
 * (app/globals.css) transposed to hex for wp-admin — indigo-600 primary,
 * gray-900/600 text, green-500 success, the 8px radius. The plugin lives
 * in someone else's admin, so it carries the brand the way Yoast does:
 * accents and surfaces, never a takeover of WP chrome.
 *
 * INTERFACE DOCTRINE (why it looks the way it does):
 *   - The page opens with the VERDICT, not a button: one big number and
 *     what it means. The screenshot is the marketing.
 *   - The scan reveals findings live, per check — the moment of discovery
 *     is the product's emotional peak; a bare counter wastes it.
 *   - Consequences over counts: the CTR card leads with missed clicks,
 *     not a tally. Found money, not bookkeeping.
 *   - Clean states are CELEBRATED ("All good"), never dimmed to imply
 *     "ignore me" — a passed check is earned.
 *   - The ask box opens with question CHIPS (chat surface doctrine:
 *     plain-language chips beat a cold empty input).
 *   - The review ask renders inline on this page only, once, after the
 *     first scan that found something — never a site-wide notice.
 *   - The only forward pitch pre-connect asks for a Google connection,
 *     not money.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEOmatic_Audit_Page {

	const DISMISS_OPTION = 'seomatic_review_ask_dismissed';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_post_seomatic_dismiss_review', array( __CLASS__, 'dismiss_review' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'SEOmatic', 'seomatic-connect' ),
			__( 'SEOmatic', 'seomatic-connect' ),
			'manage_options',
			'seomatic',
			array( __CLASS__, 'render' ),
			'dashicons-chart-line',
			80
		);
	}

	public static function dismiss_review() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}
		check_admin_referer( 'seomatic_dismiss_review' );
		update_option( self::DISMISS_OPTION, 1, false );
		wp_safe_redirect( admin_url( 'admin.php?page=seomatic' ) );
		exit;
	}

	private static function card_meta() {
		return array(
			'title'   => array(
				'icon'  => 'dashicons-editor-textcolor',
				'label' => __( 'Title problems', 'seomatic-connect' ),
				'fix'   => __( 'Titles over 60 characters get cut off in Google, and cut-off titles lose clicks.', 'seomatic-connect' ),
			),
			'meta'    => array(
				'icon'  => 'dashicons-editor-alignleft',
				'label' => __( 'Meta descriptions', 'seomatic-connect' ),
				'fix'   => __( 'Without a meta description, Google writes its own — usually worse than yours would be.', 'seomatic-connect' ),
			),
			'thin'    => array(
				'icon'  => 'dashicons-text',
				'label' => __( 'Thin posts', 'seomatic-connect' ),
				'fix'   => __( 'Posts under 300 words rarely rank for anything competitive.', 'seomatic-connect' ),
			),
			'alt'     => array(
				'icon'  => 'dashicons-format-image',
				'label' => __( 'Missing alt text', 'seomatic-connect' ),
				'fix'   => __( 'Alt text is how Google (and screen readers) understand your images.', 'seomatic-connect' ),
			),
			'noindex' => array(
				'icon'  => 'dashicons-hidden',
				'label' => __( 'Noindex pages', 'seomatic-connect' ),
				'fix'   => __( 'These published pages tell Google to ignore them. If that is not deliberate, they are invisible for no reason.', 'seomatic-connect' ),
			),
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$results = SEOmatic_Audit::results();
		self::assets();
		$issues = $results ? array_sum( $results['counts'] ) : 0;
		?>
		<div class="wrap seomatic-audit">
			<div class="sm-brand">
				<span class="sm-brand-mark">SEO<span>matic</span></span>
				<span class="sm-brand-sub"><?php esc_html_e( 'SEO Audit', 'seomatic-connect' ); ?></span>
			</div>

			<?php if ( $results ) : ?>
				<div class="sm-hero <?php echo $issues ? 'has-issues' : 'clean'; ?>">
					<div class="sm-hero-number"><?php echo esc_html( number_format_i18n( $issues ) ); ?></div>
					<div class="sm-hero-text">
						<div class="sm-hero-verdict">
							<?php
							if ( $issues ) {
								esc_html_e( 'things on this site are costing you search traffic.', 'seomatic-connect' );
							} else {
								esc_html_e( 'on-page problems. Your site passes every check.', 'seomatic-connect' );
							}
							?>
						</div>
						<div class="sm-hero-meta">
							<?php
							printf(
								/* translators: 1: post count, 2: human time diff */
								esc_html__( '%1$d posts scanned %2$s ago, entirely on this server — nothing was sent anywhere.', 'seomatic-connect' ),
								(int) $results['scanned'],
								esc_html( human_time_diff( $results['scanned_at'] ) )
							);
							?>
							<button class="button sm-btn-ghost" id="seomatic-scan"><?php esc_html_e( 'Re-scan', 'seomatic-connect' ); ?></button>
						</div>
					</div>
				</div>
			<?php else : ?>
				<div class="sm-hero sm-hero-empty">
					<div class="sm-hero-text">
						<div class="sm-hero-verdict"><?php esc_html_e( 'One click. Every post and page, checked.', 'seomatic-connect' ); ?></div>
						<div class="sm-hero-meta"><?php esc_html_e( 'Broken titles, missing meta descriptions, thin content, missing alt text, accidental noindex — found and ranked, entirely on your own server. No account, no data sent anywhere.', 'seomatic-connect' ); ?></div>
						<p><button class="button button-primary button-hero sm-btn" id="seomatic-scan"><?php esc_html_e( 'Scan my site', 'seomatic-connect' ); ?></button></p>
					</div>
				</div>
			<?php endif; ?>

			<div class="sm-scan" id="seomatic-scanpanel" hidden>
				<div class="sm-scan-track"><div class="sm-scan-fill" id="seomatic-scanfill"></div></div>
				<div class="sm-scan-line">
					<span id="seomatic-progress" role="status"></span>
					<span class="sm-scan-live" id="seomatic-live"></span>
				</div>
			</div>

			<?php if ( $results ) : ?>
				<?php self::render_results( $results, $issues ); ?>
			<?php endif; ?>

			<?php self::render_gsc_section(); ?>
		</div>
		<?php
	}

	private static function render_results( array $results, $issues ) {
		$meta = self::card_meta();

		if ( $results['truncated'] ) {
			printf(
				'<p class="sm-note">%s</p>',
				esc_html__( 'Large site: the scan covered your 2,000 most recent posts.', 'seomatic-connect' )
			);
		}

		// The review ask: only after real value, only here, dismissible forever.
		if ( $issues > 0 && ! get_option( self::DISMISS_OPTION ) ) {
			?>
			<div class="sm-review">
				<p>
					<?php
					printf(
						/* translators: %d: number of issues found */
						esc_html__( 'This scan found %d things worth fixing. If it earned it, a review helps other WordPress users find the plugin.', 'seomatic-connect' ),
						(int) $issues
					);
					?>
					<a href="https://wordpress.org/support/plugin/seomatic-connect/reviews/#new-post" target="_blank" rel="noopener"><?php esc_html_e( 'Leave a review', 'seomatic-connect' ); ?></a>
					&nbsp;·&nbsp;
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=seomatic_dismiss_review' ), 'seomatic_dismiss_review' ) ); ?>"><?php esc_html_e( 'Dismiss forever', 'seomatic-connect' ); ?></a>
				</p>
			</div>
			<?php
		}

		echo '<div class="sm-cards">';
		foreach ( $meta as $key => $card ) {
			$count = (int) $results['counts'][ $key ];
			?>
			<div class="sm-card <?php echo $count ? 'has-issues' : 'clean'; ?>" data-check="<?php echo esc_attr( $key ); ?>">
				<div class="sm-card-top">
					<span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>"></span>
					<span class="sm-card-label"><?php echo esc_html( $card['label'] ); ?></span>
				</div>
				<?php if ( $count ) : ?>
					<div class="sm-card-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></div>
					<p class="sm-card-fix"><?php echo esc_html( $card['fix'] ); ?></p>
					<details>
						<summary>
							<?php
							printf(
								/* translators: %d: number of example pages shown */
								esc_html__( 'Show pages (%d)', 'seomatic-connect' ),
								count( $results['rows'][ $key ] )
							);
							?>
						</summary>
						<table class="sm-table">
							<tbody>
							<?php foreach ( $results['rows'][ $key ] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['title'] ); ?></td>
									<td class="sm-td-note"><?php echo esc_html( $row['note'] ); ?></td>
									<td class="sm-td-edit">
										<?php $edit = get_edit_post_link( $row['id'] ); ?>
										<?php if ( $edit ) : ?>
											<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'seomatic-connect' ); ?></a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</details>
				<?php else : ?>
					<div class="sm-card-clean"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'All good', 'seomatic-connect' ); ?></div>
				<?php endif; ?>
			</div>
			<?php
		}
		echo '</div>';

		if ( ! empty( $results['worst'] ) ) {
			?>
			<h2 class="sm-h2"><?php esc_html_e( 'Fix these first', 'seomatic-connect' ); ?></h2>
			<table class="sm-table sm-worst">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page', 'seomatic-connect' ); ?></th>
						<th><?php esc_html_e( 'Problems', 'seomatic-connect' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $results['worst'] as $row ) : ?>
					<tr>
						<td class="sm-td-title"><?php echo esc_html( $row['title'] ); ?></td>
						<td>
							<?php foreach ( $row['issues'] as $iss ) : ?>
								<span class="sm-chip"><?php echo esc_html( $iss ); ?></span>
							<?php endforeach; ?>
						</td>
						<td class="sm-td-edit">
							<?php $edit = get_edit_post_link( $row['id'] ); ?>
							<?php if ( $edit ) : ?>
								<a class="button sm-btn-small" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'seomatic-connect' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}
	}

	/**
	 * Rung 2: the Search Console section — not connected (the ladder CTA),
	 * connected (the findings), expired (an honest reconnect, never a
	 * silently empty dashboard).
	 */
	private static function render_gsc_section() {
		// Zero-paste key delivery: if the visitor signed up, their grant
		// token buys the API key right here. One-time success notice.
		if ( SEOmatic_Insights::maybe_exchange_key() ) {
			printf(
				'<div class="notice notice-success inline"><p>%s</p></div>',
				esc_html__( 'Your SEOmatic account is linked — you can now ask questions about your search data below.', 'seomatic-connect' )
			);
		}
		// Return-status from the consent handoff (?seomatic-gsc=...).
		$status = isset( $_GET['seomatic-gsc'] )
			? sanitize_key( wp_unslash( $_GET['seomatic-gsc'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only status flag, no state change.
			: '';
		if ( 'connected' === $status ) {
			printf(
				'<div class="notice notice-success inline"><p>%s</p></div>',
				esc_html__( 'Google Search Console connected. Your search data appears below.', 'seomatic-connect' )
			);
		} elseif ( 'denied' === $status ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'The Google connection was cancelled. You can retry any time.', 'seomatic-connect' )
			);
		} elseif ( '' !== $status && 'connected' !== $status ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html__( 'The connection could not be delivered to this site. Please try again.', 'seomatic-connect' )
			);
		}

		if ( ! SEOmatic_Insights::connected() ) {
			?>
			<div class="sm-ladder">
				<h2 class="sm-h2"><?php esc_html_e( 'Which of these actually cost you clicks?', 'seomatic-connect' ); ?></h2>
				<p>
					<?php esc_html_e( 'This scan sees your pages. Google Search Console sees your searchers: which keywords sit one step from page 1, which pages rank well but never get clicked, and which of your pages compete with each other. Connect it (free, read-only) to rank these fixes by real traffic impact.', 'seomatic-connect' ); ?>
				</p>
				<p>
					<a class="button button-primary sm-btn" href="<?php echo esc_url( SEOmatic_Insights::connect_url() ); ?>">
						<?php esc_html_e( 'Connect Google Search Console', 'seomatic-connect' ); ?>
					</a>
				</p>
			</div>
			<?php
			return;
		}

		$data = SEOmatic_Insights::insights();
		if ( 'expired' === $data ) {
			?>
			<div class="sm-ladder">
				<h2 class="sm-h2"><?php esc_html_e( 'Search Console connection expired', 'seomatic-connect' ); ?></h2>
				<p><?php esc_html_e( 'Connections last 30 days. Reconnect to keep the insights updating — or create a free SEOmatic account and it stays connected permanently.', 'seomatic-connect' ); ?></p>
				<p>
					<a class="button button-primary sm-btn" href="<?php echo esc_url( SEOmatic_Insights::connect_url() ); ?>">
						<?php esc_html_e( 'Reconnect', 'seomatic-connect' ); ?>
					</a>
					<a class="button sm-btn-ghost" href="<?php echo esc_url( SEOmatic_Insights::signup_url() ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Create free account', 'seomatic-connect' ); ?>
					</a>
				</p>
			</div>
			<?php
			return;
		}
		if ( ! is_array( $data ) ) {
			printf(
				'<p class="sm-note">%s</p>',
				esc_html__( 'Search Console data is temporarily unavailable — your cached insights will refresh automatically.', 'seomatic-connect' )
			);
			return;
		}
		self::render_gsc_cards( $data );
	}

	private static function render_gsc_cards( array $data ) {
		$i = $data['insights'];

		// CONSEQUENCES, not counts: the headline of each card is what the
		// finding is WORTH. Sums are over the rows shown (capped at 25),
		// so they are floors — the wording says "top keywords" to stay
		// honest about that.
		$missed = 0;
		foreach ( $i['ctrOutliers']['rows'] as $r ) {
			$missed += (int) $r['missedClicks'];
		}
		$striking_impressions = 0;
		foreach ( $i['strikingDistance']['rows'] as $r ) {
			$striking_impressions += (int) $r['impressions'];
		}
		?>
		<h2 class="sm-h2"><?php esc_html_e( 'What your searchers see', 'seomatic-connect' ); ?></h2>
		<p class="sm-note">
			<?php
			printf(
				/* translators: 1: number of days, 2: human time diff */
				esc_html__( 'From your own Google Search Console, last %1$d days. Updated %2$s ago.', 'seomatic-connect' ),
				(int) $data['windowDays'],
				esc_html( human_time_diff( $data['fetched_at'] ) )
			);
			?>
		</p>
		<?php self::render_benchmark( $data ); ?>
		<?php self::render_ask_box(); ?>
		<div class="sm-cards">
			<?php
			self::gsc_card(
				(int) $i['strikingDistance']['count'],
				'dashicons-arrow-up-alt',
				$striking_impressions
					? sprintf(
						/* translators: %s: impressions count */
						__( '%s searches/mo, one step away', 'seomatic-connect' ),
						number_format_i18n( $striking_impressions )
					)
					: '',
				__( 'Keywords one step from page 1', 'seomatic-connect' ),
				__( 'Position 11-20: real demand your pages almost reach. These are the cheapest wins in SEO.', 'seomatic-connect' ),
				$i['strikingDistance']['rows'],
				function ( $r ) {
					return array(
						$r['query'],
						sprintf(
							/* translators: 1: position, 2: impressions */
							__( 'position %1$s · %2$s impressions', 'seomatic-connect' ),
							number_format_i18n( $r['position'], 1 ),
							number_format_i18n( $r['impressions'] )
						),
					);
				}
			);
			self::gsc_card(
				(int) $i['ctrOutliers']['count'],
				'dashicons-money-alt',
				$missed
					? sprintf(
						/* translators: %s: click count */
						__( '~%s clicks/mo left on the table', 'seomatic-connect' ),
						number_format_i18n( $missed )
					)
					: '',
				__( 'Rankings nobody clicks', 'seomatic-connect' ),
				__( 'These rank on page 1 but earn under half the clicks that position normally pays. The title and meta are the usual suspects.', 'seomatic-connect' ),
				$i['ctrOutliers']['rows'],
				function ( $r ) {
					return array(
						$r['query'],
						sprintf(
							/* translators: %s: estimated missed clicks */
							__( '~%s missed clicks / 28 days', 'seomatic-connect' ),
							number_format_i18n( $r['missedClicks'] )
						),
					);
				}
			);
			self::gsc_card(
				(int) $i['cannibalization']['count'],
				'dashicons-groups',
				'',
				__( 'Pages competing with each other', 'seomatic-connect' ),
				__( 'Two of your pages splitting one query dilute both. Usually one should win and the other should link to it.', 'seomatic-connect' ),
				$i['cannibalization']['rows'],
				function ( $r ) {
					return array(
						$r['query'],
						sprintf(
							/* translators: %d: number of competing pages */
							_n( '%d page competing', '%d pages competing', count( $r['pages'] ), 'seomatic-connect' ),
							count( $r['pages'] )
						),
					);
				}
			);
			self::gsc_card(
				(int) $i['opportunityPages']['count'],
				'dashicons-flag',
				'',
				__( 'Pages worth editing first', 'seomatic-connect' ),
				__( 'Your striking-distance demand, grouped by the page already ranking for it. Start at the top.', 'seomatic-connect' ),
				$i['opportunityPages']['rows'],
				function ( $r ) {
					return array(
						$r['page'],
						sprintf(
							/* translators: 1: query count, 2: impressions */
							__( '%1$d keywords · %2$s impressions', 'seomatic-connect' ),
							(int) $r['strikingQueries'],
							number_format_i18n( $r['impressions'] )
						),
					);
				}
			);
			?>
		</div>
		<div class="sm-ladder">
			<h2 class="sm-h2"><?php esc_html_e( 'Want these fixed for you?', 'seomatic-connect' ); ?></h2>
			<p><?php esc_html_e( 'A free SEOmatic account lets you ask questions in plain language and keeps this connection permanent. Paid plans add agents that write the fixes and stage them for your approval — nothing ever changes your site without you.', 'seomatic-connect' ); ?></p>
			<p>
				<a class="button button-primary sm-btn" href="<?php echo esc_url( SEOmatic_Insights::signup_url() ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Create free account', 'seomatic-connect' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/** The fleet benchmark as a BAR: gray track, indigo p25→p75 band, a
	 * marker at this site's own value. Absent data renders nothing —
	 * never a fabricated comparison. */
	private static function render_benchmark( array $data ) {
		if ( empty( $data['benchmark'] ) || ! is_array( $data['benchmark'] ) ) {
			return;
		}
		$b     = $data['benchmark'];
		$own   = (float) $b['ownCtrEfficiency'];
		$p25   = (float) $b['fleet']['p25'];
		$p75   = (float) $b['fleet']['p75'];
		$med   = (float) $b['fleet']['median'];
		// Scale the bar 0 → max(1.5, p75*1.2, own*1.1) so the marker always fits.
		$scale = max( 1.5, $p75 * 1.2, $own * 1.1 );
		$pct   = function ( $v ) use ( $scale ) {
			return max( 0, min( 100, round( ( $v / $scale ) * 100, 1 ) ) );
		};
		?>
		<div class="sm-benchmark">
			<div class="sm-benchmark-head">
				<strong>
					<?php
					printf(
						/* translators: %s: multiplier like 0.85 */
						esc_html__( 'Your click-through efficiency: %s×', 'seomatic-connect' ),
						esc_html( number_format_i18n( $own, 2 ) )
					);
					?>
				</strong>
				<span class="sm-note">
					<?php
					printf(
						/* translators: %s: number of sites */
						esc_html__( 'vs %s sites like yours', 'seomatic-connect' ),
						esc_html( number_format_i18n( $b['workspaces'] ) )
					);
					?>
				</span>
			</div>
			<div class="sm-bench-track">
				<div class="sm-bench-band" style="left:<?php echo esc_attr( $pct( $p25 ) ); ?>%;width:<?php echo esc_attr( max( 1, $pct( $p75 ) - $pct( $p25 ) ) ); ?>%"></div>
				<div class="sm-bench-median" style="left:<?php echo esc_attr( $pct( $med ) ); ?>%"></div>
				<div class="sm-bench-own <?php echo 'behind' === $b['standing'] ? 'behind' : ''; ?>" style="left:<?php echo esc_attr( $pct( $own ) ); ?>%"></div>
			</div>
			<div class="sm-bench-legend">
				<span><span class="sm-dot sm-dot-own"></span><?php esc_html_e( 'You', 'seomatic-connect' ); ?></span>
				<span><span class="sm-dot sm-dot-band"></span><?php esc_html_e( 'Typical range', 'seomatic-connect' ); ?></span>
				<span><span class="sm-dot sm-dot-median"></span>
					<?php
					printf(
						/* translators: %s: median multiplier */
						esc_html__( 'Median %s×', 'seomatic-connect' ),
						esc_html( number_format_i18n( $med, 2 ) )
					);
					?>
				</span>
			</div>
			<?php if ( 'behind' === $b['standing'] ) : ?>
				<p class="sm-note"><?php esc_html_e( 'Your titles and metas are leaving clicks on the table — the cards below say where.', 'seomatic-connect' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Rung 3: ask in plain language. Chips first (chat surface doctrine —
	 * a cold empty input is where questions go to die), key proxied
	 * server-side, quota wall relayed verbatim. */
	private static function render_ask_box() {
		if ( ! get_option( 'seomatic_connect_api_key', '' ) ) {
			return;
		}
		$chips = array(
			__( 'What should I fix first?', 'seomatic-connect' ),
			__( 'Why is my traffic down this month?', 'seomatic-connect' ),
			__( 'Which keywords can I win quickly?', 'seomatic-connect' ),
		);
		?>
		<div class="sm-askbox">
			<div class="sm-ask-label"><span class="dashicons dashicons-format-chat"></span> <strong><?php esc_html_e( 'Ask about your search data', 'seomatic-connect' ); ?></strong></div>
			<div class="sm-ask-chips">
				<?php foreach ( $chips as $chip ) : ?>
					<button type="button" class="sm-ask-chip"><?php echo esc_html( $chip ); ?></button>
				<?php endforeach; ?>
			</div>
			<div class="sm-askrow">
				<input type="text" id="seomatic-ask-input"
					placeholder="<?php esc_attr_e( 'Or type your own question…', 'seomatic-connect' ); ?>" />
				<button class="button button-primary sm-btn" id="seomatic-ask-send"><?php esc_html_e( 'Ask', 'seomatic-connect' ); ?></button>
			</div>
			<div id="seomatic-ask-answer" hidden></div>
			<p class="sm-note" id="seomatic-ask-footer"></p>
		</div>
		<?php
	}

	/** One GSC finding card: consequence headline, count, story, rows. */
	private static function gsc_card( $count, $icon, $headline, $label, $fix, array $rows, $format ) {
		?>
		<div class="sm-card <?php echo $count ? 'has-issues' : 'clean'; ?>">
			<div class="sm-card-top">
				<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
				<span class="sm-card-label"><?php echo esc_html( $label ); ?></span>
			</div>
			<?php if ( $count ) : ?>
				<?php if ( $headline ) : ?>
					<div class="sm-card-headline"><?php echo esc_html( $headline ); ?></div>
					<div class="sm-card-sub">
						<?php
						printf(
							/* translators: %s: number of findings */
							esc_html( _n( '%s finding', '%s findings', $count, 'seomatic-connect' ) ),
							esc_html( number_format_i18n( $count ) )
						);
						?>
					</div>
				<?php else : ?>
					<div class="sm-card-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></div>
				<?php endif; ?>
				<p class="sm-card-fix"><?php echo esc_html( $fix ); ?></p>
				<details>
					<summary>
						<?php
						printf(
							/* translators: %d: number of rows shown */
							esc_html__( 'Show details (%d)', 'seomatic-connect' ),
							count( $rows )
						);
						?>
					</summary>
					<table class="sm-table">
						<tbody>
						<?php foreach ( $rows as $r ) : ?>
							<?php list( $main, $note ) = call_user_func( $format, $r ); ?>
							<tr>
								<td class="sm-td-title"><?php echo esc_html( $main ); ?></td>
								<td class="sm-td-note"><?php echo esc_html( $note ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php else : ?>
				<div class="sm-card-clean"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'All good', 'seomatic-connect' ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Inline assets, this page only — no front-end footprint. */
	private static function assets() {
		wp_register_script( 'seomatic-audit', false, array(), SEOMATIC_CONNECT_VERSION, true );
		wp_enqueue_script( 'seomatic-audit' );
		wp_add_inline_script(
			'seomatic-audit',
			'(function(){'
			. 'var btn=document.getElementById("seomatic-scan");'
			. 'var out=document.getElementById("seomatic-progress");'
			. 'var panel=document.getElementById("seomatic-scanpanel");'
			. 'var fill=document.getElementById("seomatic-scanfill");'
			. 'var live=document.getElementById("seomatic-live");'
			. 'var nonce=' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ';'
			. 'var labels=' . wp_json_encode(
				array(
					'title'   => __( 'titles', 'seomatic-connect' ),
					'meta'    => __( 'metas', 'seomatic-connect' ),
					'thin'    => __( 'thin', 'seomatic-connect' ),
					'alt'     => __( 'alt', 'seomatic-connect' ),
					'noindex' => __( 'noindex', 'seomatic-connect' ),
				)
			) . ';'
			. 'if(btn){var url=' . wp_json_encode( esc_url_raw( rest_url( 'seomatic/v1/audit-scan' ) ) ) . ';'
			// Live reveal: the bar advances and the per-check tally grows as
			// each chunk lands — discovery, not a spinner.
			. 'function paint(d){var p=d.total?Math.min(100,Math.round(d.scanned/d.total*100)):5;'
			. 'fill.style.width=p+"%";out.textContent=d.scanned+" / "+(d.total||"…");'
			. 'if(d.counts){var bits=[];for(var k in labels){if(d.counts[k]>0)bits.push(d.counts[k]+" "+labels[k]);}'
			. 'live.textContent=bits.length?("· "+bits.join("  ")):"";}}'
			. 'function chunk(fresh){'
			. 'fetch(url,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},body:JSON.stringify({fresh:fresh})})'
			. '.then(function(r){if(!r.ok)throw new Error(r.status);return r.json();})'
			. '.then(function(d){paint(d);if(d.done){window.location.reload();}else{chunk(false);}})'
			. '.catch(function(){out.textContent=' . wp_json_encode( __( 'Scan failed — try again.', 'seomatic-connect' ) ) . ';btn.disabled=false;});'
			. '}'
			. 'btn.addEventListener("click",function(){btn.disabled=true;panel.hidden=false;fill.style.width="2%";out.textContent="…";chunk(true);});}'
			// Ask: chips fill-and-send; wall messages relayed verbatim.
			. 'var ask=document.getElementById("seomatic-ask-send");'
			. 'if(ask){var inp=document.getElementById("seomatic-ask-input"),ans=document.getElementById("seomatic-ask-answer"),foot=document.getElementById("seomatic-ask-footer");'
			. 'var askUrl=' . wp_json_encode( esc_url_raw( rest_url( 'seomatic/v1/ask' ) ) ) . ';'
			. 'function send(){var qn=inp.value.trim();if(!qn)return;ask.disabled=true;ans.hidden=false;'
			. 'ans.textContent=' . wp_json_encode( __( 'Thinking…', 'seomatic-connect' ) ) . ';'
			. 'fetch(askUrl,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},body:JSON.stringify({question:qn})})'
			. '.then(function(r){return r.json().then(function(d){return{ok:r.ok,d:d};});})'
			. '.then(function(x){'
			. 'ans.textContent=x.ok?(x.d.answer||""):(x.d.error||x.d.message||"Error");'
			. 'foot.textContent=(x.ok&&typeof x.d.remaining_questions==="number")?'
			. '(x.d.remaining_questions+" "+' . wp_json_encode( __( 'free questions left this month', 'seomatic-connect' ) ) . '):"";'
			. 'ask.disabled=false;})'
			. '.catch(function(){ans.textContent=' . wp_json_encode( __( 'SEOmatic could not be reached.', 'seomatic-connect' ) ) . ';ask.disabled=false;});}'
			. 'ask.addEventListener("click",send);'
			. 'inp.addEventListener("keydown",function(e){if(e.key==="Enter")send();});'
			. 'document.querySelectorAll(".sm-ask-chip").forEach(function(c){c.addEventListener("click",function(){inp.value=c.textContent;send();});});}'
			. '})();'
		);

		wp_register_style( 'seomatic-audit', false, array(), SEOMATIC_CONNECT_VERSION );
		wp_enqueue_style( 'seomatic-audit' );
		// The app's design tokens (app/globals.css), transposed to hex.
		wp_add_inline_style(
			'seomatic-audit',
			':root{--sm-primary:#4f46e5;--sm-primary-hover:#4338ca;--sm-primary-subtle:#eef2ff;--sm-primary-muted:#e0e7ff;--sm-primary-border:#a5b4fc;'
			. '--sm-fg:#111827;--sm-fg-muted:#4b5563;--sm-border:#e5e7eb;--sm-card:#fff;'
			. '--sm-success:#16a34a;--sm-success-subtle:#f0fdf4;--sm-danger:#dc2626;--sm-danger-subtle:#fef2f2;--sm-radius:8px}'
			. '.seomatic-audit{max-width:1060px;color:var(--sm-fg)}'
			// Brand strip
			. '.sm-brand{display:flex;align-items:baseline;gap:10px;margin:18px 0 14px}'
			. '.sm-brand-mark{font-size:20px;font-weight:800;letter-spacing:-.02em;color:var(--sm-fg)}'
			. '.sm-brand-mark span{color:var(--sm-primary)}'
			. '.sm-brand-sub{color:var(--sm-fg-muted);font-size:13px;border-left:1px solid var(--sm-border);padding-left:10px}'
			// Hero verdict
			. '.sm-hero{display:flex;gap:20px;align-items:center;background:var(--sm-card);border:1px solid var(--sm-border);border-left:4px solid var(--sm-primary);border-radius:var(--sm-radius);padding:20px 24px;margin-bottom:16px;box-shadow:0 1px 2px rgba(16,24,40,.05)}'
			. '.sm-hero.has-issues{border-left-color:var(--sm-danger)}'
			. '.sm-hero.clean{border-left-color:var(--sm-success)}'
			. '.sm-hero-number{font-size:56px;font-weight:800;line-height:1;letter-spacing:-.03em}'
			. '.sm-hero.has-issues .sm-hero-number{color:var(--sm-danger)}'
			. '.sm-hero.clean .sm-hero-number{color:var(--sm-success)}'
			. '.sm-hero-verdict{font-size:17px;font-weight:600;margin-bottom:4px}'
			. '.sm-hero-meta{color:var(--sm-fg-muted);font-size:13px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}'
			. '.sm-hero-empty .sm-hero-verdict{font-size:20px}'
			// Scan progress
			. '.sm-scan{margin:0 0 16px}'
			. '.sm-scan-track{height:8px;background:var(--sm-primary-subtle);border-radius:99px;overflow:hidden}'
			. '.sm-scan-fill{height:100%;width:0;background:var(--sm-primary);border-radius:99px;transition:width .3s ease}'
			. '.sm-scan-line{display:flex;gap:10px;margin-top:6px;font-size:13px;color:var(--sm-fg-muted)}'
			. '.sm-scan-live{color:var(--sm-danger);font-weight:600}'
			// Cards
			. '.sm-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin:14px 0 24px}'
			. '.sm-card{background:var(--sm-card);border:1px solid var(--sm-border);border-radius:var(--sm-radius);padding:16px;box-shadow:0 1px 2px rgba(16,24,40,.05)}'
			. '.sm-card-top{display:flex;align-items:center;gap:8px;margin-bottom:10px}'
			. '.sm-card-top .dashicons{color:var(--sm-fg-muted)}'
			. '.sm-card.has-issues .sm-card-top .dashicons{color:var(--sm-danger)}'
			. '.sm-card-label{font-weight:600;font-size:13px}'
			. '.sm-card-count{font-size:34px;font-weight:800;line-height:1;letter-spacing:-.02em;color:var(--sm-danger)}'
			. '.sm-card-headline{font-size:19px;font-weight:800;letter-spacing:-.01em;color:var(--sm-danger)}'
			. '.sm-card-sub{color:var(--sm-fg-muted);font-size:12px;margin-top:2px}'
			. '.sm-card-fix{color:var(--sm-fg-muted);font-size:13px;margin:8px 0}'
			. '.sm-card-clean{color:var(--sm-success);font-weight:600;display:flex;align-items:center;gap:6px;background:var(--sm-success-subtle);border-radius:6px;padding:8px 10px}'
			. '.sm-card-clean .dashicons{color:var(--sm-success)}'
			. '.sm-card details summary{cursor:pointer;color:var(--sm-primary);font-size:13px}'
			// Tables + chips
			. '.sm-table{width:100%;border-collapse:collapse;margin-top:8px;background:var(--sm-card)}'
			. '.sm-table td,.sm-table th{padding:8px 10px;border-top:1px solid var(--sm-border);font-size:13px;text-align:left;vertical-align:top}'
			. '.sm-table thead th{border-top:0;color:var(--sm-fg-muted);font-weight:600}'
			. '.sm-td-note{color:var(--sm-fg-muted)}'
			. '.sm-td-edit{text-align:right;white-space:nowrap}'
			. '.sm-td-title{max-width:380px;overflow:hidden;text-overflow:ellipsis}'
			. '.sm-worst{background:var(--sm-card);border:1px solid var(--sm-border);border-radius:var(--sm-radius);overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,.05)}'
			. '.sm-chip{display:inline-block;background:var(--sm-danger-subtle);color:var(--sm-danger);border-radius:99px;padding:2px 10px;font-size:12px;margin:2px 4px 2px 0}'
			// Benchmark bar
			. '.sm-benchmark{background:var(--sm-card);border:1px solid var(--sm-border);border-radius:var(--sm-radius);padding:16px 20px;margin:0 0 14px;max-width:640px;box-shadow:0 1px 2px rgba(16,24,40,.05)}'
			. '.sm-benchmark-head{display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:10px}'
			. '.sm-bench-track{position:relative;height:10px;background:#f3f4f6;border-radius:99px}'
			. '.sm-bench-band{position:absolute;top:0;height:100%;background:var(--sm-primary-muted);border-radius:99px}'
			. '.sm-bench-median{position:absolute;top:-2px;width:2px;height:14px;background:var(--sm-primary)}'
			. '.sm-bench-own{position:absolute;top:-4px;width:18px;height:18px;margin-left:-9px;border-radius:50%;background:var(--sm-primary);border:3px solid #fff;box-shadow:0 1px 3px rgba(16,24,40,.3)}'
			. '.sm-bench-own.behind{background:var(--sm-danger)}'
			. '.sm-bench-legend{display:flex;gap:16px;margin-top:10px;font-size:12px;color:var(--sm-fg-muted)}'
			. '.sm-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:5px;vertical-align:middle}'
			. '.sm-dot-own{background:var(--sm-primary)}'
			. '.sm-dot-band{background:var(--sm-primary-muted)}'
			. '.sm-dot-median{background:var(--sm-primary);width:3px;height:12px;border-radius:1px}'
			// Ask box
			. '.sm-askbox{background:var(--sm-primary-subtle);border:1px solid var(--sm-primary-border);border-radius:var(--sm-radius);padding:16px 20px;margin:0 0 16px;max-width:640px}'
			. '.sm-ask-label{display:flex;align-items:center;gap:6px;margin-bottom:10px}'
			. '.sm-ask-label .dashicons{color:var(--sm-primary)}'
			. '.sm-ask-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}'
			. '.sm-ask-chip{background:#fff;border:1px solid var(--sm-primary-border);color:var(--sm-primary);border-radius:99px;padding:5px 14px;font-size:13px;cursor:pointer}'
			. '.sm-ask-chip:hover{background:var(--sm-primary-muted)}'
			. '.sm-askrow{display:flex;gap:8px}'
			. '#seomatic-ask-input{flex:1;border:1px solid var(--sm-border);border-radius:6px;padding:6px 12px}'
			. '#seomatic-ask-answer{white-space:pre-wrap;background:#fff;border:1px solid var(--sm-border);border-radius:6px;padding:12px 14px;margin-top:10px;font-size:13px;line-height:1.6}'
			// Ladder + notes + buttons
			. '.sm-ladder{background:var(--sm-card);border:1px solid var(--sm-border);border-radius:var(--sm-radius);padding:8px 20px 18px;margin-top:20px;max-width:640px;box-shadow:0 1px 2px rgba(16,24,40,.05)}'
			. '.sm-h2{font-size:16px;font-weight:700;margin:22px 0 6px}'
			. '.sm-note{color:var(--sm-fg-muted);font-size:13px}'
			. '.sm-review{background:var(--sm-card);border-left:4px solid var(--sm-success);border-radius:0 var(--sm-radius) var(--sm-radius) 0;padding:1px 14px;margin:12px 0}'
			. '.seomatic-audit .button-primary.sm-btn{background:var(--sm-primary);border-color:var(--sm-primary)}'
			. '.seomatic-audit .button-primary.sm-btn:hover{background:var(--sm-primary-hover);border-color:var(--sm-primary-hover)}'
			. '.sm-btn-small{font-size:12px}'
		);
	}
}
