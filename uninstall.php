<?php
/**
 * SEOmatic Connect uninstall: remove every trace of ourselves.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'seomatic_connect_api_key' );
delete_option( 'seomatic_connect_webhook_url' );
delete_transient( 'seomatic_connect_status' );
