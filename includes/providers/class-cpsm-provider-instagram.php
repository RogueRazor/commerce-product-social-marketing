<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPSM_Provider_Instagram extends CPSM_Provider {

	const OPTION_SETTINGS = 'cpsm_instagram_settings';

	public function id() {
		return 'instagram';
	}

	public function label() {
		return __( 'Instagram Business', 'commerce-product-social-marketing' );
	}

	public function icon() {
		return '\ud83d\udcf8';
	}

	private function facebook() {
		return CPSM_Providers::get( 'facebook' );
	}

	public function settings() {
		return wp_parse_args(
			get_option( self::OPTION_SETTINGS, array() ),
			array(
				'targets' => array(),
				'enabled' => 0,
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
	}

	public function is_configured() {
		return $this->facebook()->is_configured();
	}

	public function is_connected() {
		return $this->facebook()->is_connected();
	}

	public function redirect_uri() {
		return $this->facebook()->redirect_uri();
	}

	public function get_connect_url() {
		$url = $this->facebook()->get_connect_url();
		if ( false !== strpos( $url, 'scope=' ) ) {
			$url = preg_replace( '/scope=[^&]*/', 'scope=' . rawurlencode( CPSM_Provider_Facebook::SCOPE . ',instagram_basic,instagram_content_publish' ), $url );
		}
		return $url;
	}

	public function exchange_code( $code ) {
		return $this->facebook()->exchange_code( $code );
	}

	public function disconnect() {
		$this->update_settings( array( 'targets' => array() ) );
	}

	public function get_targets() {
		$targets = array();
		foreach ( (array) get_option( CPSM_Provider_Facebook::OPTION_TARGETS, array() ) as $page ) {
			if ( ! empty( $page['ig_id'] ) ) {
				$targets[] = array(
					'id'    => $page['ig_id'],
					'title' => ( isset( $page['title'] ) ? $page['title'] : $page['id'] ) . ' (Instagram)',
				);
			}
		}
		return $targets;
	}

	public function selected_targets() {
		$selected = (array) $this->settings()['targets'];
		$targets  = array();
		foreach ( $this->get_targets() as $target ) {
			if ( in_array( $target['id'], $selected, true ) ) {
				$targets[] = $target;
			}
		}
		return $targets;
	}

	public function post_product( $product, $payload, $target ) {
		$caption = $payload['caption'] . "\n\n" . $payload['link'];
		$container = $this->facebook()->request( 'POST', '/' . $target['id'] . '/media', array(
			'image_url' => $payload['image_url'],
			'caption'   => $caption,
		) );
		if ( is_wp_error( $container ) || empty( $container['id'] ) ) {
			return is_wp_error( $container ) ? $container : new WP_Error( 'cpsm_ig_container_failed', __( 'Instagram returned no media container ID.', 'commerce-product-social-marketing' ) );
		}
		$published = $this->facebook()->request( 'POST', '/' . $target['id'] . '/media_publish', array(
			'creation_id' => $container['id'],
		) );
		if ( is_wp_error( $published ) || empty( $published['id'] ) ) {
			return is_wp_error( $published ) ? $published : new WP_Error( 'cpsm_ig_publish_failed', __( 'Could not publish the Instagram media.', 'commerce-product-social-marketing' ) );
		}
		return $published;
	}

	public function handle_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'instagram_save' === $action ) {
			check_admin_referer( 'cpsm_instagram_save' );
			$selected = isset( $_POST['cpsm_instagram_targets'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['cpsm_instagram_targets'] ) ) : array();
			$this->update_settings( array(
				'targets' => array_values( array_filter( $selected ) ),
				'enabled' => isset( $_POST['cpsm_instagram_enabled'] ) ? 1 : 0,
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_msg=saved&tab=providers' ) );
			exit;
		}
	}

	public function render_settings() {
		$s         = $this->settings();
		$available = $this->get_targets();
		?>
		<h2><?php echo esc_html( $this->icon() . ' ' . $this->label() ); ?></h2>
		<?php if ( ! $this->is_connected() ) : ?>
			<p><?php esc_html_e( 'Instagram uses the Facebook connection above. Connect Facebook first, refresh the pages list, then select your Instagram accounts here.', 'commerce-product-social-marketing' ); ?></p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'cpsm_instagram_save' ); ?>
				<input type="hidden" name="cpsm_action" value="instagram_save" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled', 'commerce-product-social-marketing' ); ?></th>
						<td><label><input type="checkbox" name="cpsm_instagram_enabled" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Post products to this provider', 'commerce-product-social-marketing' ); ?></label></td>
					</tr>
				</table>
				<h3><?php esc_html_e( 'Instagram business accounts', 'commerce-product-social-marketing' ); ?></h3>
				<?php if ( empty( $available ) ) : ?>
					<p><?php esc_html_e( 'No Instagram business accounts found. Link an Instagram account to one of your Facebook Pages, then refresh the Facebook pages list.', 'commerce-product-social-marketing' ); ?></p>
				<?php else : ?>
					<fieldset>
						<?php foreach ( $available as $target ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" name="cpsm_instagram_targets[]" value="<?php echo esc_attr( $target['id'] ); ?>" <?php checked( in_array( $target['id'], (array) $s['targets'], true ) ); ?> />
								<?php echo esc_html( $target['title'] ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
				<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save', 'commerce-product-social-marketing' ); ?>" /></p>
			</form>
		<?php endif; ?>
		<hr />
		<?php
	}
}
