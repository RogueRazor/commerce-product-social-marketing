<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPSM_Ads {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_create' ) );
	}

	public static function settings() {
		return wp_parse_args(
			get_option( 'cpsm_ads_settings', array() ),
			array(
				'enabled'       => 0,
				'daily_budget'  => 500,
				'duration_days' => 7,
				'country'       => 'NL',
			)
		);
	}

	public static function campaigns() {
		return array_reverse( (array) get_option( 'cpsm_ad_campaigns', array() ) );
	}

	public static function create( $product_id, $provider_id, $name, $daily_budget, $days, $country ) {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) {
			return new WP_Error( 'cpsm_ads_disabled', __( 'The ads module is disabled in the settings.', 'commerce-product-social-marketing' ) );
		}
		$provider = CPSM_Providers::get( $provider_id );
		if ( ! $provider || ! $provider->supports_ads() ) {
			return new WP_Error( 'cpsm_ads_unsupported', __( 'This provider does not support ads.', 'commerce-product-social-marketing' ) );
		}
		if ( ! $provider->is_connected() ) {
			return new WP_Error( 'cpsm_not_connected', __( 'The provider is not connected.', 'commerce-product-social-marketing' ) );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'cpsm_no_product', __( 'Product not found.', 'commerce-product-social-marketing' ) );
		}
		$targets = $provider->selected_targets();
		if ( empty( $targets ) ) {
			return new WP_Error( 'cpsm_no_target', __( 'No target selected for this provider.', 'commerce-product-social-marketing' ) );
		}
		$payload = CPSM_Product_Sync::build_payload( $product_id );

		$result = $provider->create_ad_campaign( $product, array(
			'name'         => $name ? $name : $product->get_name(),
			'daily_budget' => $daily_budget,
			'days'         => $days,
			'country'      => $country,
			'link'         => $payload['link'],
			'caption'      => $payload['caption'],
			'image_url'    => $payload['image_url'],
		), reset( $targets ) );

		if ( is_wp_error( $result ) ) {
			$provider->log( array(
				'product_id' => $product_id,
				'status'     => 'error',
				'message'    => __( 'Ad creation failed: ', 'commerce-product-social-marketing' ) . $result->get_error_message(),
			) );
			return $result;
		}

		$campaigns   = (array) get_option( 'cpsm_ad_campaigns', array() );
		$campaigns[] = array(
			'time'       => current_time( 'mysql' ),
			'product_id' => $product_id,
			'provider'   => $provider_id,
			'name'       => $name ? $name : $product->get_name(),
			'daily_budget' => (int) $daily_budget,
			'days'       => (int) $days,
			'country'    => $country,
			'status'     => 'PAUSED',
			'ids'        => $result,
		);
		update_option( 'cpsm_ad_campaigns', $campaigns );

		$provider->log( array(
			'product_id' => $product_id,
			'status'     => 'success',
			'message'    => __( 'Ad campaign created (paused). Campaign ID: ', 'commerce-product-social-marketing' ) . $result['campaign_id'],
		) );
		return $result;
	}

	public static function handle_create() {
		if ( ! isset( $_POST['cpsm_ad_create'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'cpsm_ad_create' );
		$result = self::create(
			isset( $_POST['cpsm_ad_product'] ) ? (int) $_POST['cpsm_ad_product'] : 0,
			isset( $_POST['cpsm_ad_provider'] ) ? sanitize_key( $_POST['cpsm_ad_provider'] ) : '',
			isset( $_POST['cpsm_ad_name'] ) ? sanitize_text_field( wp_unslash( $_POST['cpsm_ad_name'] ) ) : '',
			isset( $_POST['cpsm_ad_budget'] ) ? (int) $_POST['cpsm_ad_budget'] : 0,
			isset( $_POST['cpsm_ad_days'] ) ? (int) $_POST['cpsm_ad_days'] : 0,
			isset( $_POST['cpsm_ad_country'] ) ? sanitize_text_field( wp_unslash( $_POST['cpsm_ad_country'] ) ) : 'NL'
		);
		$msg = is_wp_error( $result ) ? 'ad_error&cpsm_err=' . rawurlencode( $result->get_error_message() ) : 'ad_created';
		wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=' . $msg . '&tab=ads' ) );
		exit;
	}
}
