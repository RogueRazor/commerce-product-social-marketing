<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPSM_Provider_Google extends CPSM_Provider {

	const AUTH_URL      = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
	const SCOPE         = 'https://www.googleapis.com/auth/business.manage';
	const ACCOUNTS_BASE = 'https://mybusinessaccountmanagement.googleapis.com/v1';
	const INFO_BASE     = 'https://mybusinessbusinessinformation.googleapis.com/v1';
	const POSTS_BASE    = 'https://mybusiness.googleapis.com/v4';

	const OPTION_SETTINGS = 'cpsm_google_settings';
	const OPTION_TARGETS  = 'cpsm_google_locations';

	public function id() {
		return 'google';
	}

	public function label() {
		return __( 'Google Business Profile', 'commerce-product-social-marketing' );
	}

	public function icon() {
		return '\ud83c\udf10';
	}

	public function settings() {
		return wp_parse_args(
			get_option( self::OPTION_SETTINGS, array() ),
			array(
				'client_id'      => '',
				'client_secret'  => '',
				'access_token'   => '',
				'refresh_token'  => '',
				'token_expires'  => 0,
				'cta_type'       => 'LEARN_MORE',
				'locations'      => array(),
				'enabled'        => 0,
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
		return ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] );
	}

	public function is_connected() {
		$s = $this->settings();
		return ! empty( $s['refresh_token'] ) || ( ! empty( $s['access_token'] ) && time() < (int) $s['token_expires'] );
	}

	public function redirect_uri() {
		return admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_oauth=google' );
	}

	public function get_connect_url() {
		$s = $this->settings();
		return add_query_arg(
			array(
				'client_id'     => $s['client_id'],
				'redirect_uri'  => rawurlencode( $this->redirect_uri() ),
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => wp_create_nonce( 'cpsm_oauth_google' ),
			),
			self::AUTH_URL
		);
	}

	public function exchange_code( $code ) {
		$s = $this->settings();
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $s['client_id'],
					'client_secret' => $s['client_secret'],
					'redirect_uri'  => $this->redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		return $this->parse_token_response( $response );
	}

	public function refresh_token() {
		$s = $this->settings();
		if ( empty( $s['refresh_token'] ) ) {
			return new WP_Error( 'cpsm_no_refresh_token', __( 'Not connected to Google. Reconnect on the settings page.', 'commerce-product-social-marketing' ) );
		}
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'refresh_token' => $s['refresh_token'],
					'client_id'     => $s['client_id'],
					'client_secret' => $s['client_secret'],
					'grant_type'    => 'refresh_token',
				),
			)
		);
		return $this->parse_token_response( $response, true );
	}

	private function parse_token_response( $response, $keep_refresh = false ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$err = isset( $body['error_description'] ) ? $body['error_description'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'cpsm_token_error', $err );
		}
		$fields = array(
			'access_token'  => $body['access_token'],
			'token_expires' => time() + (int) $body['expires_in'] - 60,
		);
		if ( ! empty( $body['refresh_token'] ) && ! $keep_refresh ) {
			$fields['refresh_token'] = $body['refresh_token'];
		}
		$this->update_settings( $fields );
		return true;
	}

	private function get_access_token() {
		$s = $this->settings();
		if ( empty( $s['access_token'] ) && empty( $s['refresh_token'] ) ) {
			return new WP_Error( 'cpsm_not_connected', __( 'Google account is not connected.', 'commerce-product-social-marketing' ) );
		}
		if ( empty( $s['access_token'] ) || time() >= (int) $s['token_expires'] ) {
			$result = $this->refresh_token();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$s = $this->settings();
		}
		return $s['access_token'];
	}

	public function request( $method, $url, $body = null ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
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
		$data = $this->request( 'GET', self::ACCOUNTS_BASE . '/accounts' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$locations = array();
		$accounts  = isset( $data['accounts'] ) ? $data['accounts'] : array();
		foreach ( $accounts as $account ) {
			if ( empty( $account['name'] ) ) {
				continue;
			}
			$response = $this->request( 'GET', self::INFO_BASE . '/' . $account['name'] . '/locations?readMask=name,title' );
			if ( is_wp_error( $response ) || empty( $response['locations'] ) ) {
				continue;
			}
			foreach ( $response['locations'] as $location ) {
				if ( empty( $location['name'] ) ) {
					continue;
				}
				if ( 0 !== strpos( $location['name'], 'accounts/' ) ) {
					$location['name'] = $account['name'] . '/' . ltrim( $location['name'], '/' );
				}
				$locations[] = array(
					'id'    => $location['name'],
					'title' => isset( $location['title'] ) ? $location['title'] : $location['name'],
				);
			}
		}
		return $locations;
	}

	public function selected_targets() {
		$selected = (array) $this->settings()['locations'];
		$targets  = array();
		foreach ( (array) get_option( self::OPTION_TARGETS, array() ) as $location ) {
			if ( in_array( $location['id'], $selected, true ) ) {
				$targets[] = $location;
			}
		}
		return $targets;
	}

	public function post_product( $product, $payload, $target ) {
		$s = $this->settings();
		$body = array(
			'topicType'    => 'STANDARD',
			'languageCode' => substr( (string) get_bloginfo( 'language' ), 0, 2 ),
			'summary'      => wp_strip_all_tags( $payload['caption'] ),
			'callToAction' => array(
				'actionType' => ! empty( $s['cta_type'] ) ? $s['cta_type'] : 'LEARN_MORE',
				'url'        => $payload['link'],
			),
		);
		if ( ! empty( $payload['image_url'] ) ) {
			$body['media'] = array(
				array(
					'mediaFormat' => 'PHOTO',
					'sourceUrl'   => $payload['image_url'],
				),
			);
		}
		$response = $this->request( 'POST', self::POSTS_BASE . '/' . $target['id'] . '/localPosts', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $response['name'] ) ) {
			return new WP_Error( 'cpsm_google_no_name', __( 'Google returned no post name.', 'commerce-product-social-marketing' ) );
		}
		return $response;
	}

	public function disconnect() {
		$this->update_settings( array(
			'access_token'  => '',
			'refresh_token' => '',
			'token_expires' => 0,
			'locations'     => array(),
		) );
		delete_option( self::OPTION_TARGETS );
	}

	public function handle_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'google_save' === $action ) {
			check_admin_referer( 'cpsm_google_save' );
			$this->update_settings( array(
				'client_id'     => isset( $_POST['cpsm_google_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['cpsm_google_client_id'] ) ) : '',
				'client_secret' => isset( $_POST['cpsm_google_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['cpsm_google_client_secret'] ) ) : '',
				'cta_type'      => isset( $_POST['cpsm_google_cta_type'] ) ? sanitize_text_field( wp_unslash( $_POST['cpsm_google_cta_type'] ) ) : 'LEARN_MORE',
				'enabled'       => isset( $_POST['cpsm_google_enabled'] ) ? 1 : 0,
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=saved&tab=providers' ) );
			exit;
		}

		if ( 'google_refresh' === $action ) {
			check_admin_referer( 'cpsm_google_refresh' );
			$targets = $this->get_targets();
			if ( is_wp_error( $targets ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=targets_error&cpsm_err=' . rawurlencode( $targets->get_error_message() ) . '&tab=providers' ) );
				exit;
			}
			update_option( self::OPTION_TARGETS, $targets );
			wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=targets_refreshed&tab=providers' ) );
			exit;
		}

		if ( 'google_targets' === $action ) {
			check_admin_referer( 'cpsm_google_targets' );
			$selected = isset( $_POST['cpsm_google_locations'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['cpsm_google_locations'] ) ) : array();
			$this->update_settings( array( 'locations' => array_values( array_filter( $selected ) ) ) );
			wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=saved&tab=providers' ) );
			exit;
		}

		if ( 'google_disconnect' === $action ) {
			check_admin_referer( 'cpsm_google_disconnect' );
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
			<?php wp_nonce_field( 'cpsm_google_save' ); ?>
			<input type="hidden" name="cpsm_action" value="google_save" />
			<p><?php esc_html_e( 'Redirect URI (add this in your Google Cloud OAuth client as an authorized redirect URI):', 'commerce-product-social-marketing' ); ?></p>
			<code><?php echo esc_html( $this->redirect_uri() ); ?></code>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cpsm_google_client_id"><?php esc_html_e( 'Client ID', 'commerce-product-social-marketing' ); ?></label></th>
					<td><input type="text" class="regular-text" id="cpsm_google_client_id" name="cpsm_google_client_id" value="<?php echo esc_attr( $s['client_id'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_google_client_secret"><?php esc_html_e( 'Client Secret', 'commerce-product-social-marketing' ); ?></label></th>
					<td><input type="password" class="regular-text" autocomplete="off" id="cpsm_google_client_secret" name="cpsm_google_client_secret" value="<?php echo esc_attr( $s['client_secret'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cpsm_google_cta_type"><?php esc_html_e( 'Call-to-action type', 'commerce-product-social-marketing' ); ?></label></th>
					<td>
						<select id="cpsm_google_cta_type" name="cpsm_google_cta_type">
							<?php foreach ( array( 'LEARN_MORE', 'ORDER', 'SHOP_NOW', 'SIGN_UP' ) as $cta ) : ?>
								<option value="<?php echo esc_attr( $cta ); ?>" <?php selected( $s['cta_type'], $cta ); ?>><?php echo esc_html( $cta ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled', 'commerce-product-social-marketing' ); ?></th>
					<td><label><input type="checkbox" name="cpsm_google_enabled" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Post products to this provider', 'commerce-product-social-marketing' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection', 'commerce-product-social-marketing' ); ?></th>
					<td>
						<?php if ( $connected ) : ?>
							<p style="color:#00a32a;font-weight:bold;"><?php esc_html_e( 'Connected to Google', 'commerce-product-social-marketing' ); ?></p>
							<p><a class="button" href="<?php echo esc_url( wp_nonce_url( $base . '&cpsm_action=google_disconnect', 'cpsm_google_disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect', 'commerce-product-social-marketing' ); ?></a></p>
						<?php elseif ( $this->is_configured() ) : ?>
							<p><a class="button button-primary" href="<?php echo esc_url( $this->get_connect_url() ); ?>"><?php esc_html_e( 'Connect Google', 'commerce-product-social-marketing' ); ?></a></p>
						<?php else : ?>
							<p><?php esc_html_e( 'Save your Client ID and Client Secret first, then connect.', 'commerce-product-social-marketing' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save', 'commerce-product-social-marketing' ); ?>" /></p>
		</form>

		<?php if ( $connected ) : ?>
			<form method="post" style="margin-top:1em;">
				<?php wp_nonce_field( 'cpsm_google_refresh' ); ?>
				<input type="hidden" name="cpsm_action" value="google_refresh" />
				<p><input type="submit" class="button" value="<?php esc_attr_e( 'Refresh locations list', 'commerce-product-social-marketing' ); ?>" /></p>
			</form>
			<form method="post">
				<?php wp_nonce_field( 'cpsm_google_targets' ); ?>
				<input type="hidden" name="cpsm_action" value="google_targets" />
				<h3><?php esc_html_e( 'Locations to post products to', 'commerce-product-social-marketing' ); ?></h3>
				<?php if ( empty( $available ) ) : ?>
					<p><?php esc_html_e( 'No locations found yet. Click "Refresh locations list" above.', 'commerce-product-social-marketing' ); ?></p>
				<?php else : ?>
					<fieldset>
						<?php foreach ( $available as $location ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" name="cpsm_google_locations[]" value="<?php echo esc_attr( $location['id'] ); ?>" <?php checked( in_array( $location['id'], (array) $s['locations'], true ) ); ?> />
								<?php echo esc_html( $location['title'] ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="submit"><input type="submit" class="button" value="<?php esc_attr_e( 'Save locations', 'commerce-product-social-marketing' ); ?>" /></p>
				<?php endif; ?>
			</form>
		<?php endif; ?>
		<hr />
		<?php
	}
}
