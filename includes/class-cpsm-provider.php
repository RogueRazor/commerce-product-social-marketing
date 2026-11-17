<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class CPSM_Provider {

	abstract public function id();

	abstract public function label();

	abstract public function icon();

	abstract public function settings();

	abstract public function update_settings( $fields );

	abstract public function is_configured();

	abstract public function is_connected();

	abstract public function redirect_uri();

	abstract public function get_connect_url();

	abstract public function exchange_code( $code );

	abstract public function disconnect();

	abstract public function get_targets();

	abstract public function selected_targets();

	abstract public function render_settings();

	abstract public function handle_action( $action );

	abstract public function post_product( $product, $payload, $target );

	public function supports_ads() {
		return false;
	}

	public function create_ad_campaign( $product, $args, $target ) {
		return new WP_Error( 'cpsm_ads_unsupported', __( 'This provider does not support ads.', 'commerce-product-social-marketing' ) );
	}

	public function install_defaults() {}

	public function enabled() {
		return ! empty( $this->settings()['enabled'] );
	}

	public function log( $entry ) {
		$entry['provider'] = $this->id();
		CPSM_Logger::log( $entry );
	}
}
