<?php
/**
 * SEOmatic Connect uninstall: remove every trace of ourselves.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'seomatic_connect_api_key' );
delete_option( 'seomatic_connect_webhook_url' );
delete_option( 'seomatic_audit_state' );
delete_option( 'seomatic_audit_results' );
delete_option( 'seomatic_review_ask_dismissed' );
delete_option( 'seomatic_gsc_grant_token' );
delete_option( 'seomatic_gsc_grant_domain' );
delete_transient( 'seomatic_connect_status' );
delete_transient( 'seomatic_gsc_connect_nonce' );
delete_transient( 'seomatic_gsc_insights' );
