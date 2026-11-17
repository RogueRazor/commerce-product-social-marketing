<?php
/**
 * Plugin Name: Commerce Product Social Marketing
 * Description: Auto-post WooCommerce products to social media, create ads through official APIs, and manage your own vouchers and gift certificates.
 * Version: 1.0.0
 * Author: RogueRazor
 * License: GPL-2.0-or-later
 * Text Domain: commerce-product-social-marketing
 * Update URI: https://github.com/RogueRazor/commerce-product-social-marketing
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CPSM_VERSION', '1.0.0' );
define( 'CPSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPSM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CPSM_PLUGIN_DIR . 'includes/class-cpsm-logger.php';
require_once CPSM_PLUGIN_DIR . 'includes/class-cpsm-provider.php';
require_once CPSM_PLUGIN_DIR . 'includes/class-cpsm-providers.php';
require_once CPSM_PLUGIN_DIR . 'includes/providers/class-cpsm-provider-facebook.php';
require_once CPSM_PLUGIN_DIR . 'includes/providers/class-cpsm-provider-instagram.php';
require_once CPSM_PLUGIN_DIR . 'includes/providers/class-cpsm-provider-google.php';
require_once CPSM_PLUGIN_DIR . 'includes/class-cpsm-product-sync.php';
require_once CPSM_PLUGIN_DIR . 'includes/class-cpsm-ads.php';
require_once CPSM_PLUGIN_DIR . 'includes/class-cpsm-vouchers.php';
require_once CPSM_PLUGIN_DIR . 'includes/class-cpsm-admin.php';
require_once CPSM_PLUGIN_DIR . 'includes/class-cpsm-updater.php';

final class CPSM_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function activate() {
		if ( false === get_option( 'cpsm_settings' ) ) {
			add_option( 'cpsm_settings', array(
				'enabled'             => 1,
				'auto_post'           => 1,
				'auto_post_on_update' => 0,
				'caption_template'    => "{title}\n\n{price}\n\n{link}",
				'hashtags'            => '',
				'fallback_image_id'   => 0,
			) );
		}
		if ( false === get_option( 'cpsm_ads_settings' ) ) {
			add_option( 'cpsm_ads_settings', array(
				'enabled'       => 0,
				'daily_budget'  => 500,
				'duration_days' => 7,
				'country'       => 'NL',
			) );
		}
		if ( false === get_option( 'cpsm_log' ) ) {
			add_option( 'cpsm_log', array() );
		}
		if ( false === get_option( 'cpsm_ad_campaigns' ) ) {
			add_option( 'cpsm_ad_campaigns', array() );
		}
		foreach ( CPSM_Providers::all() as $provider ) {
			$provider->install_defaults();
		}
		CPSM_Vouchers::register_post_type();
		flush_rewrite_rules();
	}

	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}
		CPSM_Vouchers::init();
		CPSM_Product_Sync::init();
		CPSM_Ads::init();
		CPSM_Admin::init();
		CPSM_Updater::init();
		if ( get_option( 'cpsm_version' ) !== CPSM_VERSION ) {
			update_option( 'cpsm_version', CPSM_VERSION );
		}
	}

	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Commerce Product Social Marketing requires WooCommerce to be installed and active.', 'commerce-product-social-marketing' ) . '</p></div>';
	}
}

function cpsm() {
	return CPSM_Plugin::instance();
}

cpsm();
