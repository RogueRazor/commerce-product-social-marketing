<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPSM_Provider_Facebook extends CPSM_Provider {

	const GRAPH_VERSION = 'v21.0';
	const AUTH_URL       = 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth';
	const TOKEN_URL      = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/oauth/access_token';
	const GRAPH_BASE     = 'https://graph.facebook.com/' . self::GRAPH_VERSION;
	const SCOPE          = 'pages_show_list,pages_manage_posts,pages_read_engagement,business_management,ads_management';

	const OPTION_SETTINGS = 'cpsm_facebook_settings';
	const OPTION_TARGETS  = 'cpsm_facebook_pages';

	public function id() {
		return 'facebook';
	}

	public function label() {
		return __( 'Facebook Pages', 'commerce-product-social-marketing' );
	}

	public function icon() {
		return '\ud83d\udcd8';
	}

	public function settings() {
		return wp_parse_args(
			get_option( self::OPTION_SETTINGS, array() ),
			array(
				'app_id'        => '',
				'app_secret'    => '',
				'access_token'  => '',
				'token_expires' => 0,
				'ad_account_id' => '',
				'targets'       => array(),
				'enabled'       => 0,
			)
		);
	}

	public function update_settings( $fields ) {
		$settings = $this->settings();
		foreach ( $fields as $key => $value ) {
			$settings[ $key ] = $value;
		}
		update_option( self::OPTION_SETTINGS, $settings );
	}

	public function install_defaults() {
		if ( false === get_option( self::OPTION_SETTINGS ) ) {
			add_option( self::OPTION_SETTINGS, $this->settings() );
		}
		if ( false === get_option( self::OPTION_TARGETS ) ) {
			add_option( self::OPTION_TARGETS, array() );
		}
	}

	public function is_configured() {
		$s = $this->settings();
		return ! empty( $s['app_id'] ) && ! empty( $s['app_secret'] );
	}

	public function is_connected() {
		$s = $this->settings();
		return ! empty( $s['access_token'] ) && ( empty( $s['token_expires'] ) || time() < (int) $s['token_expires'] );
	}

	public function redirect_uri() {
		return admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_oauth=facebook' );
	}

	public function get_connect_url() {
		$s = $this->settings();
		return add_query_arg(
			array(
				'client_id'     => $s['app_id'],
				'redirect_uri'  => rawurlencode( $this->redirect_uri() ),
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'state'         => wp_create_nonce( 'cpsm_oauth_facebook' ),
			),
			self::AUTH_URL
		);
	}

	public function exchange_code( $code ) {
		$s = $this->settings();
		$response = wp_remote_get(
			add_query_arg(
				array(
					'client_id'     => $s['app_id'],
					'client_secret' => $s['app_secret'],
					'redirect_uri'  => rawurlencode( $this->redirect_uri() ),
					'code'          => $code,
				),
				self::TOKEN_URL
			),
			array( 'timeout' => 30 )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$err = isset( $body['error']['message'] ) ? $body['error']['message'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'cpsm_token_error', $err );
		}

		$long_response = wp_remote_get(
			add_query_arg(
				array(
					'grant_type'        => 'fb_exchange_token',
					'client_id'         => $s['app_id'],
					'client_secret'     => $s['app_secret'],
					'fb_exchange_token' => $body['access_token'],
				),
				self::TOKEN_URL
			),
			array( 'timeout' => 30 )
		);
		if ( ! is_wp_error( $long_response ) ) {
			$long_body = json_decode( wp_remote_retrieve_body( $long_response ), true );
			if ( ! empty( $long_body['access_token'] ) ) {
				$body = $long_body;
			}
		}

		$this->update_settings( array(
			'access_token'  => $body['access_token'],
			'token_expires' => empty( $body['expires_in'] ) ? 0 : time() + (int) $body['expires_in'] - 60,
		) );
		return true;
	}

	public function disconnect() {
		$this->update_settings( array(
			'access_token'  => '',
			'token_expires' => 0,
			'targets'       => array(),
		) );
		delete_option( self::OPTION_TARGETS );
	}

	public function request( $method, $path, $body = null, $token = null ) {
		$s = $this->settings();
		if ( null === $token ) {
			$token = $s['access_token'];
		}
		$url = self::GRAPH_BASE . '/' . ltrim( $path, '/' );
		$args = array(
			'method'  => $method,
			'timeout' => 30,
		);
		if ( null !== $body ) {
			if ( empty( $body['access_token'] ) ) {
				$body['access_token'] = $token;
			}
			$args['body'] = $body;
		} else {
			$url = add_query_arg( 'access_token', rawurlencode( $token ), $url );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'cpsm_api_error', $msg, array( 'status' => $code ) );
		}
		return $data;
	}

	public function get_targets() {
		$data = $this->request( 'GET', '/me/accounts?fields=name,access_token,instagram_business_account&limit=100' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$targets = array();
		if ( ! empty( $data['data'] ) ) {
			foreach ( $data['data'] as $page ) {
				if ( ! empty( $page['id'] ) ) {
					$targets[] = array(
						'id'    => $page['id'],
						'title' => isset( $page['name'] ) ? $page['name'] : $page['id'],
						'token' => isset( $page['access_token'] ) ? $page['access_token'] : '',
						'ig_id' => isset( $page['instagram_business_account']['id'] ) ? $page['instagram_business_account']['id'] : '',
					);
				}
			}
		}
		return $targets;
	}

	public function selected_targets() {
		$selected = (array) $this->settings()['targets'];
		$targets  = array();
		foreach ( (array) get_option( self::OPTION_TARGETS, array() ) as $page ) {
			if ( in_array( $page['id'], $selected, true ) ) {
				$targets[] = $page;
			}
		}
		return $targets;
	}

	public function post_product( $product, $payload, $target ) {
		$body = array(
			'message' => $payload['caption'],
			'link'    => $payload['link'],
		);
		if ( ! empty( $payload['image_url'] ) ) {
			$body['picture'] = $payload['image_url'];
		}
		if ( ! empty( $target['token'] ) ) {
			$body['access_token'] = $target['token'];
		}
		$response = $this->request( 'POST', '/' . $target['id'] . '/feed', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $response['id'] ) ) {
			return new WP_Error( 'cpsm_facebook_no_id', __( 'Facebook returned no post ID.', 'commerce-product-social-marketing' ) );
		}
		return $response;
	}

	public function supports_ads() {
		return true;
	}

	private function get_ad_account_id() {
		$s = $this->settings();
		if ( ! empty( $s['ad_account_id'] ) ) {
			return $s['ad_account_id'];
		}
		$data = $this->request( 'GET', '/me/adaccounts?fields=id,name&limit=1' );
		if ( is_wp_error( $data ) || empty( $data['data'][0]['id'] ) ) {
			return '';
		}
		$ad_account_id = $data['data'][0]['id'];
		$this->update_settings( array( 'ad_account_id' => $ad_account_id ) );
		return $ad_account_id;
	}

	public function create_ad_campaign( $product, $args, $target ) {
		$ad_account_id = $this->get_ad_account_id();
		if ( '' === $ad_account_id ) {
			return new WP_Error( 'cpsm_no_ad_account', __( 'No Meta ad account found. Add one in the provider settings.', 'commerce-product-social-marketing' ) );
		}
		$account = 'act_' . ltrim( $ad_account_id, 'act_' );

		$name = ! empty( $args['name'] ) ? $args['name'] : $product->get_name();

		$campaign = $this->request( 'POST', '/' . $account . '/campaigns', array(
			'name'                  => $name . ' - ' . gmdate( 'Y-m-d' ),
			'objective'             => 'OUTCOME_TRAFFIC',
			'status'                => 'PAUSED',
			'special_ad_categories' => array(),
		) );
		if ( is_wp_error( $campaign ) || empty( $campaign['id'] ) ) {
			return is_wp_error( $campaign ) ? $campaign : new WP_Error( 'cpsm_campaign_failed', __( 'Could not create the campaign.', 'commerce-product-social-marketing' ) );
		}

		$start = time();
		$end   = $start + max( 1, (int) $args['days'] ) * DAY_IN_SECONDS;
		$adset = $this->request( 'POST', '/' . $account . '/adsets', array(
			'name'              => $name,
			'campaign_id'       => $campaign['id'],
			'billing_event'     => 'IMPRESSIONS',
			'optimization_goal' => 'LINK_CLICKS',
			'daily_budget'      => (string) max( 100, (int) $args['daily_budget'] ),
			'start_time'        => gmdate( 'Y-m-d\TH:i:s\Z', $start ),
			'end_time'          => gmdate( 'Y-m-d\TH:i:s\Z', $end ),
			'targeting'         => array(
				'geo_locations' => array( 'countries' => array( $args['country'] ) ),
				'age_min'       => 18,
			),
			'status'            => 'PAUSED',
		) );
		if ( is_wp_error( $adset ) || empty( $adset['id'] ) ) {
			return is_wp_error( $adset ) ? $adset : new WP_Error( 'cpsm_adset_failed', __( 'Could not create the ad set.', 'commerce-product-social-marketing' ) );
		}

		$link_data = array(
			'link'    => $args['link'],
			'message' => $args['caption'],
		);
		if ( ! empty( $args['image_url'] ) ) {
			$link_data['image_url'] = $args['image_url'];
		}
		$link_data['call_to_action'] = array(
			'type'  => 'SHOP_NOW',
			'value' => array( 'link' => $args['link'] ),
		);

		$creative = $this->request( 'POST', '/' . $account . '/adcreatives', array(
			'name'              => $name,
			'object_story_spec' => array(
				'page_id'   => $target['id'],
				'link_data' => $link_data,
			),
		) );
		if ( is_wp_error( $creative ) || empty( $creative['id'] ) ) {
			return is_wp_error( $creative ) ? $creative : new WP_Error( 'cpsm_creative_failed', __( 'Could not create the ad creative.', 'commerce-product-social-marketing' ) );
		}

		$ad = $this->request( 'POST', '/' . $account . '/ads', array(
			'name'       => $name,
			'adset_id'   => $adset['id'],
			'creative'   => array( 'creative_id' => $creative['id'] ),
			'status'     => 'PAUSED',
		) );
		if ( is_wp_error( $ad ) || empty( $ad['id'] ) ) {
			return is_wp_error( $ad ) ? $ad : new WP_Error( 'cpsm_ad_failed', __( 'Could not create the ad.', 'commerce-product-social-marketing' ) );
		}

		return array(
			'ad_account_id' => $ad_account_id,
			'campaign_id'   => $campaign['id'],
			'adset_id'      => $adset['id'],
			'creative_id'   => $creative['id'],
			'ad_id'         => $ad['id'],
		);
	}

	public function handle_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'facebook_save' === $action ) {
			check_admin_referer( 'cpsm_facebook_save' );
			$this->update_settings( array(
				'app_id'        => isset( $_POST['cpsm_facebook_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['cpsm_facebook_app_id'] ) ) : '',
				'app_secret'    => isset( $_POST['cpsm_facebook_app_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['cpsm_facebook_app_secret'] ) ) : '',
				'ad_account_id' => isset( $_POST['cpsm_facebook_ad_account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['cpsm_facebook_ad_account_id'] ) ) : '',
				'enabled'       => isset( $_POST['cpsm_facebook_enabled'] ) ? 1 : 0,
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=saved&tab=providers' ) );
			exit;
		}

		if ( 'facebook_refresh' === $action ) {
			check_admin_referer( 'cpsm_facebook_refresh' );
			$targets = $this->get_targets();
			if ( is_wp_error( $targets ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=targets_error&cpsm_err=' . rawurlencode( $targets->get_error_message() ) . '&tab=providers' ) );
				exit;
			}
			update_option( self::OPTION_TARGETS, $targets );
			wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=targets_refreshed&tab=providers' ) );
			exit;
		}

		if ( 'facebook_targets' === $action ) {
			check_admin_referer( 'cpsm_facebook_targets' );
			$selected = isset( $_POST['cpsm_facebook_targets'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['cpsm_facebook_targets'] ) ) : array();
			$this->update_settings( array( 'targets' => array_values( array_filter( $selected ) ) ) );
			wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=saved&tab=providers' ) );
			exit;
		}

		if ( 'facebook_disconnect' === $action ) {
			check_admin_referer( 'cpsm_facebook_disconnect' );
			$this->disconnect();
			wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=disconnected&tab=providers' ) );
			exit;
		}
	}

	public function render_settings() {
		$s         = $this->settings();
		$available = get_option( self::OPTION_TARGETS, array() );
		$connected = $this->is_connected();
		$base      = admin_url( 'admin.php?page=commerce-product-social-marketing&tab=providers' );
		?>
		<h2><?php echo esc_html( $this->icon() . ' ' . $this->label() ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'cpsm_facebook_save' ); ?>
			<input type="hidden" name="cpsm_action" value="facebook_save" />
			<p><?php esc_html_e( 'Redirect URI (add this in your Facebook app settings under Valid OAuth Redirect URIs):', 'commerce-product-social-marketing' ); ?></p>
			<code><?php echo esc_html( $this->redirect_uri() ); ?></code>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cpsm_facebook_app_id"><?php esc_html_e( 'App ID', 'commerce-product-social-marketing' ); ?></label></th>
					<td><input type="text" class="regular-text" id="cpsm_facebook_app_id" name="cpsm_facebook_app_id" value="<?php echo esc_attr( $s['app_id'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_facebook_app_secret"><?php esc_html_e( 'App Secret', 'commerce-product-social-marketing' ); ?></label></th>
					<td><input type="password" class="regular-text" autocomplete="off" id="cpsm_facebook_app_secret" name="cpsm_facebook_app_secret" value="<?php echo esc_attr( $s['app_secret'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_facebook_ad_account_id"><?php esc_html_e( 'Ad Account ID (for ads, optional)', 'commerce-product-social-marketing' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="cpsm_facebook_ad_account_id" name="cpsm_facebook_ad_account_id" value="<?php echo esc_attr( $s['ad_account_id'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave empty to auto-detect the first ad account of your Meta Business. The ads scopes need app review by Meta.', 'commerce-product-social-marketing' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled', 'commerce-product-social-marketing' ); ?></th>
					<td><label><input type="checkbox" name="cpsm_facebook_enabled" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Post products to this provider', 'commerce-product-social-marketing' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection', 'commerce-product-social-marketing' ); ?></th>
					<td>
						<?php if ( $connected ) : ?>
							<p style="color:#00a32a;font-weight:bold;"><?php esc_html_e( 'Connected to Facebook', 'commerce-product-social-marketing' ); ?></p>
							<p>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( $base . '&cpsm_action=facebook_disconnect', 'cpsm_facebook_disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect', 'commerce-product-social-marketing' ); ?></a>
							</p>
						<?php elseif ( $this->is_configured() ) : ?>
							<p><a class="button button-primary" href="<?php echo esc_url( $this->get_connect_url() ); ?>"><?php esc_html_e( 'Connect Facebook', 'commerce-product-social-marketing' ); ?></a></p>
						<?php else : ?>
							<p><?php esc_html_e( 'Save your App ID and App Secret first, then connect.', 'commerce-product-social-marketing' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save', 'commerce-product-social-marketing' ); ?>" /></p>
		</form>

		<?php if ( $connected ) : ?>
			<form method="post" style="margin-top:1em;">
				<?php wp_nonce_field( 'cpsm_facebook_refresh' ); ?>
				<input type="hidden" name="cpsm_action" value="facebook_refresh" />
				<p>
					<input type="submit" class="button" value="<?php esc_attr_e( 'Refresh pages list', 'commerce-product-social-marketing' ); ?>" />
				</p>
			</form>
			<form method="post">
				<?php wp_nonce_field( 'cpsm_facebook_targets' ); ?>
				<input type="hidden" name="cpsm_action" value="facebook_targets" />
				<h3><?php esc_html_e( 'Pages to post products to', 'commerce-product-social-marketing' ); ?></h3>
				<?php if ( empty( $available ) ) : ?>
					<p><?php esc_html_e( 'No pages found yet. Click "Refresh pages list" above.', 'commerce-product-social-marketing' ); ?></p>
				<?php else : ?>
					<fieldset>
						<?php foreach ( $available as $page ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" name="cpsm_facebook_targets[]" value="<?php echo esc_attr( $page['id'] ); ?>" <?php checked( in_array( $page['id'], (array) $s['targets'], true ) ); ?> />
								<?php echo esc_html( $page['title'] ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="submit"><input type="submit" class="button" value="<?php esc_attr_e( 'Save pages', 'commerce-product-social-marketing' ); ?>" /></p>
				<?php endif; ?>
			</form>
		<?php endif; ?>
		<hr />
		<?php
	}
}
