<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPSM_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_oauth' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function add_menu() {
		add_menu_page(
			__( 'Commerce Product Social Marketing', 'commerce-product-social-marketing' ),
			__( 'Social & Vouchers', 'commerce-product-social-marketing' ),
			'manage_woocommerce',
			'commerce-product-social-marketing',
			array( __CLASS__, 'render_page' ),
			'dashicons-share',
			56
		);
	}

	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'providers';
		return in_array( $tab, array( 'providers', 'posting', 'ads', 'vouchers', 'log' ), true ) ? $tab : 'providers';
	}

	public static function handle_oauth() {
		if ( ! isset( $_GET['cpsm_oauth'], $_GET['code'], $_GET['state'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$provider_id = sanitize_key( $_GET['cpsm_oauth'] );
		$provider    = CPSM_Providers::get( $provider_id );
		if ( ! $provider ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_GET['state'] ), 'cpsm_oauth_' . $provider_id ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=oauth_failed&tab=providers' ) );
			exit;
		}
		$result = $provider->exchange_code( sanitize_text_field( wp_unslash( $_GET['code'] ) ) );
		$msg = is_wp_error( $result )
			? 'oauth_error&cpsm_err=' . rawurlencode( $result->get_error_message() )
			: 'connected';
		wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=' . $msg . '&tab=providers' ) );
		exit;
	}

	public static function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = isset( $_POST['cpsm_action'] ) ? sanitize_key( $_POST['cpsm_action'] ) : '';
		if ( '' === $action ) {
			return;
		}

		if ( 'posting_save' === $action ) {
			check_admin_referer( 'cpsm_posting_save' );
			update_option( 'cpsm_settings', array(
				'enabled'             => isset( $_POST['cpsm_enabled'] ) ? 1 : 0,
				'auto_post'           => isset( $_POST['cpsm_auto_post'] ) ? 1 : 0,
				'auto_post_on_update' => isset( $_POST['cpsm_auto_post_on_update'] ) ? 1 : 0,
				'caption_template'    => isset( $_POST['cpsm_caption_template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cpsm_caption_template'] ) ) : '',
				'hashtags'            => isset( $_POST['cpsm_hashtags'] ) ? sanitize_text_field( wp_unslash( $_POST['cpsm_hashtags'] ) ) : '',
				'fallback_image_id'   => isset( $_POST['cpsm_fallback_image_id'] ) ? (int) $_POST['cpsm_fallback_image_id'] : 0,
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=saved&tab=posting' ) );
			exit;
		}

		if ( 'ads_save' === $action ) {
			check_admin_referer( 'cpsm_ads_save' );
			update_option( 'cpsm_ads_settings', array(
				'enabled'       => isset( $_POST['cpsm_ads_enabled'] ) ? 1 : 0,
				'daily_budget'  => isset( $_POST['cpsm_ads_budget'] ) ? max( 0, (int) $_POST['cpsm_ads_budget'] ) : 500,
				'duration_days' => isset( $_POST['cpsm_ads_days'] ) ? max( 1, (int) $_POST['cpsm_ads_days'] ) : 7,
				'country'       => isset( $_POST['cpsm_ads_country'] ) ? strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['cpsm_ads_country'] ) ), 0, 2 ) ) : 'NL',
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=saved&tab=ads' ) );
			exit;
		}

		if ( 'log_clear' === $action ) {
			check_admin_referer( 'cpsm_log_clear' );
			CPSM_Logger::clear();
			wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=log_cleared&tab=log' ) );
			exit;
		}

		foreach ( CPSM_Providers::all() as $provider ) {
			if ( 0 === strpos( $action, $provider->id() . '_' ) ) {
				$provider->handle_action( $action );
				return;
			}
		}
	}

	public static function notices() {
		if ( ! isset( $_GET['cpsm_msg'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$messages = array(
			'saved'            => array( 'updated', __( 'Settings saved.', 'commerce-product-social-marketing' ) ),
			'connected'        => array( 'updated', __( 'Account connected.', 'commerce-product-social-marketing' ) ),
			'disconnected'     => array( 'updated', __( 'Disconnected.', 'commerce-product-social-marketing' ) ),
			'targets_refreshed' => array( 'updated', __( 'Target list refreshed.', 'commerce-product-social-marketing' ) ),
			'targets_error'    => array( 'error', __( 'Could not refresh the target list.', 'commerce-product-social-marketing' ) ),
			'oauth_failed'     => array( 'error', __( 'OAuth verification failed. Please try connecting again.', 'commerce-product-social-marketing' ) ),
			'oauth_error'      => array( 'error', __( 'OAuth failed.', 'commerce-product-social-marketing' ) ),
			'posted'           => array( 'updated', __( 'Product posted to the selected social channels.', 'commerce-product-social-marketing' ) ),
			'post_failed'      => array( 'error', __( 'Nothing was posted. Check the log tab for details.', 'commerce-product-social-marketing' ) ),
			'ad_created'       => array( 'updated', __( 'Ad campaign created (paused). Activate it in your ad manager.', 'commerce-product-social-marketing' ) ),
			'ad_error'         => array( 'error', __( 'Could not create the ad campaign.', 'commerce-product-social-marketing' ) ),
			'voucher_created'  => array( 'updated', __( 'Voucher created.', 'commerce-product-social-marketing' ) ),
			'voucher_error'    => array( 'error', __( 'Could not create the voucher.', 'commerce-product-social-marketing' ) ),
			'voucher_deleted'  => array( 'updated', __( 'Voucher deleted.', 'commerce-product-social-marketing' ) ),
			'sent'             => array( 'updated', __( 'Voucher email sent.', 'commerce-product-social-marketing' ) ),
			'send_error'       => array( 'error', __( 'Could not send the voucher email.', 'commerce-product-social-marketing' ) ),
			'log_cleared'      => array( 'updated', __( 'Log cleared.', 'commerce-product-social-marketing' ) ),
		);
		$msg = sanitize_key( $_GET['cpsm_msg'] );
		if ( ! isset( $messages[ $msg ] ) ) {
			return;
		}
		list( $type, $text ) = $messages[ $msg ];
		if ( isset( $_GET['cpsm_err'] ) ) {
			$text .= ' ' . sanitize_text_field( wp_unslash( $_GET['cpsm_err'] ) );
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	public static function render_page() {
		$tab  = self::current_tab();
		$tabs = array(
			'providers' => __( 'Providers', 'commerce-product-social-marketing' ),
			'posting'   => __( 'Product posting', 'commerce-product-social-marketing' ),
			'ads'       => __( 'Ads', 'commerce-product-social-marketing' ),
			'vouchers'  => __( 'Vouchers & gift certificates', 'commerce-product-social-marketing' ),
			'log'       => __( 'Log', 'commerce-product-social-marketing' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Commerce Product Social Marketing', 'commerce-product-social-marketing' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=commerce-product-social-marketing&tab=' . $slug ) ); ?>" class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>
			<?php
			switch ( $tab ) {
				case 'posting':
					self::render_posting_tab();
					break;
				case 'ads':
					self::render_ads_tab();
					break;
				case 'vouchers':
					self::render_vouchers_tab();
					break;
				case 'log':
					self::render_log_tab();
					break;
				default:
					self::render_providers_tab();
			}
			?>
		</div>
		<?php
	}

	private static function render_providers_tab() {
		echo '<p>' . esc_html__( 'Connect your accounts once; product posts then go out automatically when you publish a product. All calls use the official APIs of each platform.', 'commerce-product-social-marketing' ) . '</p>';
		foreach ( CPSM_Providers::all() as $provider ) {
			$provider->render_settings();
		}
	}

	private static function render_posting_tab() {
		$s = CPSM_Product_Sync::settings();
		?>
		<form method="post">
			<?php wp_nonce_field( 'cpsm_posting_save' ); ?>
			<input type="hidden" name="cpsm_action" value="posting_save" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-posting', 'commerce-product-social-marketing' ); ?></th>
					<td>
						<label><input type="checkbox" name="cpsm_enabled" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Enable product posting', 'commerce-product-social-marketing' ); ?></label><br />
						<label><input type="checkbox" name="cpsm_auto_post" value="1" <?php checked( $s['auto_post'] ); ?> /> <?php esc_html_e( 'Post automatically when a product is published', 'commerce-product-social-marketing' ); ?></label><br />
						<label><input type="checkbox" name="cpsm_auto_post_on_update" value="1" <?php checked( $s['auto_post_on_update'] ); ?> /> <?php esc_html_e( 'Also post again when a published product is updated', 'commerce-product-social-marketing' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_caption_template"><?php esc_html_e( 'Caption template', 'commerce-product-social-marketing' ); ?></label></th>
					<td>
						<textarea id="cpsm_caption_template" name="cpsm_caption_template" rows="5" class="large-text"><?php echo esc_textarea( $s['caption_template'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Placeholders: {title} {price} {link} {excerpt}', 'commerce-product-social-marketing' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_hashtags"><?php esc_html_e( 'Hashtags', 'commerce-product-social-marketing' ); ?></label></th>
					<td><input type="text" class="regular-text" id="cpsm_hashtags" name="cpsm_hashtags" value="<?php echo esc_attr( $s['hashtags'] ); ?>" placeholder="#shop #new" />
						<p class="description"><?php esc_html_e( 'Appended to every caption, e.g. "#webshop #sale".', 'commerce-product-social-marketing' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_fallback_image_id"><?php esc_html_e( 'Fallback image ID', 'commerce-product-social-marketing' ); ?></label></th>
					<td>
						<input type="number" min="0" class="small-text" id="cpsm_fallback_image_id" name="cpsm_fallback_image_id" value="<?php echo esc_attr( $s['fallback_image_id'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Media library attachment ID used when a product has no featured image.', 'commerce-product-social-marketing' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save', 'commerce-product-social-marketing' ); ?>" /></p>
		</form>
		<p><?php esc_html_e( 'You can also post a single product manually via the "Post to social" action in the product list, and skip auto-posting or override the caption per product in the product editor.', 'commerce-product-social-marketing' ); ?></p>
		<?php
	}

	private static function render_ads_tab() {
		$s = CPSM_Ads::settings();
		$products = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		$ad_providers = array();
		foreach ( CPSM_Providers::all() as $provider ) {
			if ( $provider->supports_ads() && $provider->is_connected() ) {
				$ad_providers[] = $provider;
			}
		}
		?>
		<h2><?php esc_html_e( 'Ads settings', 'commerce-product-social-marketing' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'cpsm_ads_save' ); ?>
			<input type="hidden" name="cpsm_action" value="ads_save" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Ads module', 'commerce-product-social-marketing' ); ?></th>
					<td><label><input type="checkbox" name="cpsm_ads_enabled" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Enable ads', 'commerce-product-social-marketing' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_ads_budget"><?php esc_html_e( 'Default daily budget (in cents)', 'commerce-product-social-marketing' ); ?></label></th>
					<td><input type="number" min="100" class="small-text" id="cpsm_ads_budget" name="cpsm_ads_budget" value="<?php echo esc_attr( $s['daily_budget'] ); ?>" /> <span class="description"><?php esc_html_e( '500 = 5.00 in your account currency.', 'commerce-product-social-marketing' ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_ads_days"><?php esc_html_e( 'Default duration (days)', 'commerce-product-social-marketing' ); ?></label></th>
					<td><input type="number" min="1" class="small-text" id="cpsm_ads_days" name="cpsm_ads_days" value="<?php echo esc_attr( $s['duration_days'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_ads_country"><?php esc_html_e( 'Target country (ISO code)', 'commerce-product-social-marketing' ); ?></label></th>
					<td><input type="text" maxlength="2" class="small-text" id="cpsm_ads_country" name="cpsm_ads_country" value="<?php echo esc_attr( $s['country'] ); ?>" /></td>
				</tr>
			</table>
			<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save', 'commerce-product-social-marketing' ); ?>" /></p>
		</form>

		<h2><?php esc_html_e( 'Create an ad for a product', 'commerce-product-social-marketing' ); ?></h2>
		<?php if ( empty( $ad_providers ) ) : ?>
			<p><?php esc_html_e( 'No connected provider supports ads yet. Connect Facebook (with the ads scopes) on the Providers tab first.', 'commerce-product-social-marketing' ); ?></p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'cpsm_ad_create' ); ?>
				<input type="hidden" name="cpsm_ad_create" value="1" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cpsm_ad_product"><?php esc_html_e( 'Product', 'commerce-product-social-marketing' ); ?></label></th>
						<td>
							<select id="cpsm_ad_product" name="cpsm_ad_product" required>
								<?php foreach ( $products as $product_post ) : ?>
									<option value="<?php echo esc_attr( $product_post->ID ); ?>"><?php echo esc_html( $product_post->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cpsm_ad_provider"><?php esc_html_e( 'Provider', 'commerce-product-social-marketing' ); ?></label></th>
						<td>
							<select id="cpsm_ad_provider" name="cpsm_ad_provider">
								<?php foreach ( $ad_providers as $provider ) : ?>
									<option value="<?php echo esc_attr( $provider->id() ); ?>"><?php echo esc_html( $provider->label() ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cpsm_ad_name"><?php esc_html_e( 'Campaign name (optional)', 'commerce-product-social-marketing' ); ?></label></th>
						<td><input type="text" class="regular-text" id="cpsm_ad_name" name="cpsm_ad_name" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cpsm_ad_budget"><?php esc_html_e( 'Daily budget (cents)', 'commerce-product-social-marketing' ); ?></label></th>
						<td><input type="number" min="100" class="small-text" id="cpsm_ad_budget" name="cpsm_ad_budget" value="<?php echo esc_attr( $s['daily_budget'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cpsm_ad_days"><?php esc_html_e( 'Duration (days)', 'commerce-product-social-marketing' ); ?></label></th>
						<td><input type="number" min="1" class="small-text" id="cpsm_ad_days" name="cpsm_ad_days" value="<?php echo esc_attr( $s['duration_days'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cpsm_ad_country"><?php esc_html_e( 'Target country', 'commerce-product-social-marketing' ); ?></label></th>
						<td><input type="text" maxlength="2" class="small-text" id="cpsm_ad_country" name="cpsm_ad_country" value="<?php echo esc_attr( $s['country'] ); ?>" /></td>
					</tr>
				</table>
				<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Create ad campaign (paused)', 'commerce-product-social-marketing' ); ?>" /></p>
				<p class="description"><?php esc_html_e( 'Campaigns are created paused; activate and edit them in your Meta Ads Manager.', 'commerce-product-social-marketing' ); ?></p>
			</form>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Created campaigns', 'commerce-product-social-marketing' ); ?></h2>
		<?php $campaigns = CPSM_Ads::campaigns(); ?>
		<?php if ( empty( $campaigns ) ) : ?>
			<p><?php esc_html_e( 'No campaigns yet.', 'commerce-product-social-marketing' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Created', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Product', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Name', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Provider', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Budget/day', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Campaign ID', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Status', 'commerce-product-social-marketing' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $campaigns as $campaign ) : ?>
						<tr>
							<td><?php echo esc_html( $campaign['time'] ); ?></td>
							<td><?php echo esc_html( get_the_title( (int) $campaign['product_id'] ) ); ?></td>
							<td><?php echo esc_html( $campaign['name'] ); ?></td>
							<td><?php echo esc_html( $campaign['provider'] ); ?></td>
							<td><?php echo esc_html( (string) ( $campaign['daily_budget'] / 100 ) ); ?></td>
							<td><code><?php echo esc_html( isset( $campaign['ids']['campaign_id'] ) ? $campaign['ids']['campaign_id'] : '' ); ?></code></td>
							<td><?php echo esc_html( $campaign['status'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function render_vouchers_tab() {
		$vouchers = CPSM_Vouchers::all_vouchers();
		$base     = admin_url( 'admin.php?page=commerce-product-social-marketing&tab=vouchers' );
		?>
		<h2><?php esc_html_e( 'Create a voucher or gift certificate', 'commerce-product-social-marketing' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'cpsm_voucher_create' ); ?>
			<input type="hidden" name="cpsm_voucher_create" value="1" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cpsm_v_type"><?php esc_html_e( 'Type', 'commerce-product-social-marketing' ); ?></label></th>
					<td>
						<select id="cpsm_v_type" name="cpsm_v_type">
							<option value="fixed"><?php esc_html_e( 'Fixed amount off (e.g. 10.00)', 'commerce-product-social-marketing' ); ?></option>
							<option value="percent"><?php esc_html_e( 'Percentage off (e.g. 20%)', 'commerce-product-social-marketing' ); ?></option>
							<option value="giftcard"><?php esc_html_e( 'Gift certificate with balance', 'commerce-product-social-marketing' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_v_amount"><?php esc_html_e( 'Amount', 'commerce-product-social-marketing' ); ?></label></th>
					<td><input type="number" step="0.01" min="0" class="small-text" id="cpsm_v_amount" name="cpsm_v_amount" value="10" required />
						<span class="description"><?php esc_html_e( 'For percentages this is the percent value; for gift certificates this is the starting balance.', 'commerce-product-social-marketing' ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_v_expires"><?php esc_html_e( 'Expires on (optional)', 'commerce-product-social-marketing' ); ?></label></th>
					<td><input type="date" id="cpsm_v_expires" name="cpsm_v_expires" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_v_usage_limit"><?php esc_html_e( 'Usage limit (0 = unlimited)', 'commerce-product-social-marketing' ); ?></label></th>
					<td><input type="number" min="0" class="small-text" id="cpsm_v_usage_limit" name="cpsm_v_usage_limit" value="1" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_v_email"><?php esc_html_e( 'Recipient email (optional)', 'commerce-product-social-marketing' ); ?></label></th>
					<td><input type="email" class="regular-text" id="cpsm_v_email" name="cpsm_v_email" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Send now', 'commerce-product-social-marketing' ); ?></th>
					<td><label><input type="checkbox" name="cpsm_v_send" value="1" /> <?php esc_html_e( 'Email the code to the recipient right away', 'commerce-product-social-marketing' ); ?></label></td>
				</tr>
			</table>
			<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Create voucher', 'commerce-product-social-marketing' ); ?>" /></p>
		</form>

		<h2><?php esc_html_e( 'All vouchers', 'commerce-product-social-marketing' ); ?></h2>
		<?php if ( empty( $vouchers ) ) : ?>
			<p><?php esc_html_e( 'No vouchers yet.', 'commerce-product-social-marketing' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Code', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Type', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Balance', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Used', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Expires', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Status', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'commerce-product-social-marketing' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $vouchers as $v ) : ?>
						<tr>
							<td><code><?php echo esc_html( $v['code'] ); ?></code></td>
							<td><?php echo esc_html( $v['type'] ); ?></td>
							<td><?php echo esc_html( (string) $v['amount'] ); ?></td>
							<td><?php echo esc_html( 'giftcard' === $v['type'] ? (string) $v['balance'] : '-' ); ?></td>
							<td><?php echo esc_html( $v['used_count'] . ( $v['usage_limit'] > 0 ? ' / ' . $v['usage_limit'] : '' ) ); ?></td>
							<td><?php echo esc_html( $v['expires'] ? $v['expires'] : '-' ); ?></td>
							<td><?php echo esc_html( $v['status'] ); ?></td>
							<td>
								<a href="<?php echo esc_url( wp_nonce_url( $base . '&cpsm_voucher_send=' . $v['id'], 'cpsm_voucher_send_' . $v['id'] ) ); ?>"><?php esc_html_e( 'Send email', 'commerce-product-social-marketing' ); ?></a> |
								<a href="<?php echo esc_url( wp_nonce_url( $base . '&cpsm_voucher_delete=' . $v['id'], 'cpsm_voucher_delete_' . $v['id'] ) ); ?>" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'commerce-product-social-marketing' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function render_log_tab() {
		$entries = CPSM_Logger::get_entries();
		?>
		<form method="post" style="margin-bottom:1em;">
			<?php wp_nonce_field( 'cpsm_log_clear' ); ?>
			<input type="hidden" name="cpsm_action" value="log_clear" />
			<input type="submit" class="button" value="<?php esc_attr_e( 'Clear log', 'commerce-product-social-marketing' ); ?>" />
		</form>
		<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( 'No log entries yet.', 'commerce-product-social-marketing' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Product', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Provider', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Status', 'commerce-product-social-marketing' ); ?></th>
						<th><?php esc_html_e( 'Message', 'commerce-product-social-marketing' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $entry['time'] ); ?></td>
							<td><?php echo $entry['product_id'] ? esc_html( get_the_title( (int) $entry['product_id'] ) ) : '-'; ?></td>
							<td><?php echo esc_html( $entry['provider'] ); ?></td>
							<td><?php echo esc_html( $entry['status'] ); ?></td>
							<td><?php echo esc_html( $entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
