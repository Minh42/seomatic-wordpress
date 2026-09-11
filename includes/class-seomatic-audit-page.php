<?php
/**
 * The SEOmatic admin page: audit cards + the value ladder.
 *
 * Layout doctrine: the page leads with what the user's site needs FIXED
 * (rung 1, local, free, no consent), and each finding ends with the ladder's
 * next rung — "connect Search Console to see which of these actually cost
 * you clicks". Never a paid nag on rung 1: the only paid mention on this
 * page is inside the Search Console section the user has not unlocked yet.
 *
 * The review ask renders INLINE on this page only, once, after the first
 * scan that found something — never as a site-wide admin_notice (directory
 * guideline 11: no nag banners). Dismissal is permanent.
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
				'label' => __( 'Title problems', 'seomatic-connect' ),
				'fix'   => __( 'Titles over 60 characters get cut off in Google, and cut-off titles lose clicks.', 'seomatic-connect' ),
			),
			'meta'    => array(
				'label' => __( 'Meta descriptions', 'seomatic-connect' ),
				'fix'   => __( 'Without a meta description, Google writes its own — usually worse than yours would be.', 'seomatic-connect' ),
			),
			'thin'    => array(
				'label' => __( 'Thin posts', 'seomatic-connect' ),
				'fix'   => __( 'Posts under 300 words rarely rank for anything competitive.', 'seomatic-connect' ),
			),
			'alt'     => array(
				'label' => __( 'Missing alt text', 'seomatic-connect' ),
				'fix'   => __( 'Alt text is how Google (and screen readers) understand your images.', 'seomatic-connect' ),
			),
			'noindex' => array(
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
		self::assets( $results );
		?>
		<div class="wrap seomatic-audit">
			<h1><?php esc_html_e( 'SEOmatic — SEO Audit', 'seomatic-connect' ); ?></h1>

			<div class="seomatic-scanbar">
				<button class="button button-primary" id="seomatic-scan">
					<?php echo $results ? esc_html__( 'Re-scan site', 'seomatic-connect' ) : esc_html__( 'Scan my site', 'seomatic-connect' ); ?>
				</button>
				<span id="seomatic-progress" role="status"></span>
				<?php if ( $results ) : ?>
					<span class="seomatic-scanned-at">
						<?php
						printf(
							/* translators: 1: post count, 2: human time diff */
							esc_html__( '%1$d posts scanned, %2$s ago. Everything below was computed on this server — nothing was sent anywhere.', 'seomatic-connect' ),
							(int) $results['scanned'],
							esc_html( human_time_diff( $results['scanned_at'] ) )
						);
						?>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( ! $results ) : ?>
				<p class="seomatic-intro">
					<?php esc_html_e( 'One click scans every published post and page for the problems that actually cost search traffic: broken titles, missing meta descriptions, thin content, missing alt text, and accidental noindex. The scan runs entirely on your own server.', 'seomatic-connect' ); ?>
				</p>
			<?php else : ?>
				<?php self::render_results( $results ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_results( array $results ) {
		$meta   = self::card_meta();
		$counts = $results['counts'];
		$total_issues = array_sum( $counts );

		if ( $results['truncated'] ) {
			printf(
				'<p class="seomatic-note">%s</p>',
				esc_html__( 'Large site: the scan covered your 2,000 most recent posts.', 'seomatic-connect' )
			);
		}

		if ( 0 === $total_issues ) {
			printf(
				'<div class="notice notice-success inline"><p>%s</p></div>',
				esc_html__( 'No on-page problems found. The next wins are in your Search Console data — connect it below.', 'seomatic-connect' )
			);
		}

		// The review ask: only after real value, only here, dismissible forever.
		if ( $total_issues > 0 && ! get_option( self::DISMISS_OPTION ) ) {
			?>
			<div class="seomatic-review-ask">
				<p>
					<?php
					printf(
						/* translators: %d: number of issues found */
						esc_html__( 'This scan found %d things worth fixing. If it earned it, a review helps other WordPress users find the plugin.', 'seomatic-connect' ),
						(int) $total_issues
					);
					?>
					<a href="https://wordpress.org/support/plugin/seomatic-connect/reviews/#new-post" target="_blank" rel="noopener"><?php esc_html_e( 'Leave a review', 'seomatic-connect' ); ?></a>
					&nbsp;·&nbsp;
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=seomatic_dismiss_review' ), 'seomatic_dismiss_review' ) ); ?>"><?php esc_html_e( 'Dismiss forever', 'seomatic-connect' ); ?></a>
				</p>
			</div>
			<?php
		}

		echo '<div class="seomatic-cards">';
		foreach ( $meta as $key => $card ) {
			$count = (int) $counts[ $key ];
			?>
			<div class="seomatic-card <?php echo $count ? 'has-issues' : 'clean'; ?>">
				<div class="seomatic-card-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></div>
				<div class="seomatic-card-label"><?php echo esc_html( $card['label'] ); ?></div>
				<?php if ( $count ) : ?>
					<p class="seomatic-card-fix"><?php echo esc_html( $card['fix'] ); ?></p>
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
						<table class="widefat striped seomatic-rows">
							<tbody>
							<?php foreach ( $results['rows'][ $key ] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['title'] ); ?></td>
									<td class="seomatic-row-note"><?php echo esc_html( $row['note'] ); ?></td>
									<td class="seomatic-row-edit">
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
				<?php endif; ?>
			</div>
			<?php
		}
		echo '</div>';

		if ( ! empty( $results['worst'] ) ) {
			?>
			<h2><?php esc_html_e( 'Fix these first', 'seomatic-connect' ); ?></h2>
			<table class="widefat striped seomatic-worst">
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
						<td><?php echo esc_html( $row['title'] ); ?></td>
						<td><?php echo esc_html( implode( ' · ', $row['issues'] ) ); ?></td>
						<td class="seomatic-row-edit">
							<?php $edit = get_edit_post_link( $row['id'] ); ?>
							<?php if ( $edit ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'seomatic-connect' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		// The ladder's next rung — the ONLY forward pitch on this page, and
		// it asks for a Google connection, not money.
		?>
		<div class="seomatic-ladder">
			<h2><?php esc_html_e( 'Which of these actually cost you clicks?', 'seomatic-connect' ); ?></h2>
			<p>
				<?php esc_html_e( 'This scan sees your pages. Google Search Console sees your searchers: which keywords sit one step from page 1, which pages rank well but never get clicked, and which of your pages compete with each other. Connect it (free, read-only) to rank these fixes by real traffic impact.', 'seomatic-connect' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( SEOmatic_Connect::connect_url() ); ?>">
					<?php esc_html_e( 'Connect Google Search Console', 'seomatic-connect' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/** Inline assets, registered only on this page — no front-end footprint. */
	private static function assets( $results ) {
		wp_register_script( 'seomatic-audit', false, array(), SEOMATIC_CONNECT_VERSION, true );
		wp_enqueue_script( 'seomatic-audit' );
		wp_add_inline_script(
			'seomatic-audit',
			'(function(){'
			. 'var btn=document.getElementById("seomatic-scan");if(!btn)return;'
			. 'var out=document.getElementById("seomatic-progress");'
			. 'var url=' . wp_json_encode( esc_url_raw( rest_url( 'seomatic/v1/audit-scan' ) ) ) . ';'
			. 'var nonce=' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ';'
			. 'function chunk(fresh){'
			. 'fetch(url,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},body:JSON.stringify({fresh:fresh})})'
			. '.then(function(r){if(!r.ok)throw new Error(r.status);return r.json();})'
			. '.then(function(d){'
			. 'out.textContent=d.scanned+" / "+(d.total||"…");'
			. 'if(d.done){window.location.reload();}else{chunk(false);}'
			. '})'
			. '.catch(function(){out.textContent=' . wp_json_encode( __( 'Scan failed — try again.', 'seomatic-connect' ) ) . ';btn.disabled=false;});'
			. '}'
			. 'btn.addEventListener("click",function(){btn.disabled=true;out.textContent="…";chunk(true);});'
			. '})();'
		);

		wp_register_style( 'seomatic-audit', false, array(), SEOMATIC_CONNECT_VERSION );
		wp_enqueue_style( 'seomatic-audit' );
		wp_add_inline_style(
			'seomatic-audit',
			'.seomatic-scanbar{display:flex;align-items:center;gap:12px;margin:16px 0}'
			. '.seomatic-scanned-at{color:#646970}'
			. '.seomatic-intro{max-width:640px;font-size:14px}'
			. '.seomatic-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin:16px 0 24px}'
			. '.seomatic-card{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px}'
			. '.seomatic-card.clean{opacity:.55}'
			. '.seomatic-card-count{font-size:32px;font-weight:600;line-height:1}'
			. '.seomatic-card.has-issues .seomatic-card-count{color:#d63638}'
			. '.seomatic-card.clean .seomatic-card-count{color:#00a32a}'
			. '.seomatic-card-label{font-weight:600;margin-top:4px}'
			. '.seomatic-card-fix{color:#646970;margin:8px 0}'
			. '.seomatic-rows{margin-top:8px}'
			. '.seomatic-row-note{color:#646970}'
			. '.seomatic-row-edit{text-align:right}'
			. '.seomatic-worst{max-width:900px}'
			. '.seomatic-review-ask{background:#fff;border-left:4px solid #00a32a;padding:1px 12px;margin:12px 0}'
			. '.seomatic-ladder{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:8px 20px 20px;margin-top:24px;max-width:640px}'
			. '.seomatic-note{color:#646970}'
		);
		// Silence the unused-parameter sniff without dropping the seam: the
		// results shape may drive conditional assets later.
		unset( $results );
	}
}
