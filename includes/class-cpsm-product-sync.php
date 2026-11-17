<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPSM_Product_Sync {

	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_meta_box' ), 20, 2 );
		add_action( 'admin_init', array( __CLASS__, 'handle_manual_post' ) );
	}

	public static function settings() {
		return wp_parse_args(
			get_option( 'cpsm_settings', array() ),
			array(
				'enabled'             => 1,
				'auto_post'           => 1,
				'auto_post_on_update' => 0,
				'caption_template'    => "{title}\n\n{price}\n\n{link}",
				'hashtags'            => '',
				'fallback_image_id'   => 0,
			)
		);
	}

	public static function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['auto_post'] ) ) {
			return;
		}
		if ( '1' === (string) get_post_meta( $post->ID, '_cpsm_skip_social', true ) ) {
			return;
		}
		self::sync_product( $post->ID );
	}

	public static function row_action( $actions, $post ) {
		if ( 'product' === $post->post_type && current_user_can( 'manage_woocommerce' ) ) {
			$url = wp_nonce_url(
				admin_url( 'admin.php?page=commerce-product-social-marketing&cpsm_sync_product=' . $post->ID ),
				'cpsm_sync_' . $post->ID
			);
			$actions['cpsm_sync'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Post to social', 'commerce-product-social-marketing' ) . '</a>';
		}
		return $actions;
	}

	public static function handle_manual_post() {
		if ( ! isset( $_GET['cpsm_sync_product'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$product_id = (int) $_GET['cpsm_sync_product'];
		check_admin_referer( 'cpsm_sync_' . $product_id );
		$result = self::sync_product( $product_id );
		$msg = $result ? 'posted' : 'post_failed';
		wp_safe_redirect( admin_url( 'edit.php?post_type=product&cpsm_msg=' . $msg ) );
		exit;
	}

	public static function build_payload( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}
		$settings = self::settings();

		$override = (string) get_post_meta( $product_id, '_cpsm_caption_override', true );
		$template = '' !== $override ? $override : $settings['caption_template'];

		$image_id  = (int) $product->get_image_id();
		if ( ! $image_id && ! empty( $settings['fallback_image_id'] ) ) {
			$image_id = (int) $settings['fallback_image_id'];
		}
		$image_url = $image_id ? (string) wp_get_attachment_image_url( $image_id, 'full' ) : '';

		$excerpt = $product->get_short_description() ? $product->get_short_description() : $product->get_description();
		$excerpt = wp_strip_all_tags( $excerpt );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $excerpt ) > 300 ) {
			$excerpt = mb_substr( $excerpt, 0, 297 ) . '...';
		} elseif ( strlen( $excerpt ) > 300 ) {
			$excerpt = substr( $excerpt, 0, 297 ) . '...';
		}

		$hashtags = trim( (string) $settings['hashtags'] );
		if ( '' !== $hashtags ) {
			$hashtags = "\n\n" . $hashtags;
		}

		$caption = strtr( $template, array(
			'{title}'   => $product->get_name(),
			'{price}'   => wp_strip_all_tags( $product->get_price_html() ),
			'{link}'    => $product->get_permalink(),
			'{excerpt}' => $excerpt,
		) );
		$caption .= $hashtags;

		return array(
			'title'     => $product->get_name(),
			'price'     => wp_strip_all_tags( $product->get_price_html() ),
			'link'      => $product->get_permalink(),
			'image_url' => $image_url,
			'excerpt'   => $excerpt,
			'caption'   => $caption,
		);
	}

	public static function sync_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}
		$payload = self::build_payload( $product_id );
		if ( null === $payload ) {
			return false;
		}

		$any_success = false;
		$any_target  = false;
		foreach ( CPSM_Providers::enabled() as $provider ) {
			if ( ! $provider->is_connected() ) {
				continue;
			}
			$targets = $provider->selected_targets();
			foreach ( $targets as $target ) {
				$any_target = true;
				$title = isset( $target['title'] ) ? $target['title'] : $target['id'];
				$result = $provider->post_product( $product, $payload, $target );
				if ( is_wp_error( $result ) ) {
					$provider->log( array(
						'product_id' => $product_id,
						'status'     => 'error',
						'message'    => $title . ': ' . $result->get_error_message(),
					) );
				} else {
					$any_success = true;
					$provider->log( array(
						'product_id' => $product_id,
						'status'     => 'success',
						'message'    => sprintf( __( 'Posted to %s.', 'commerce-product-social-marketing' ), $title ),
					) );
				}
			}
		}

		if ( ! $any_target ) {
			CPSM_Logger::log( array(
				'product_id' => $product_id,
				'status'     => 'skipped',
				'message'    => __( 'No connected provider with selected targets.', 'commerce-product-social-marketing' ),
			) );
			return false;
		}
		return $any_success;
	}

	public static function add_meta_box() {
		add_meta_box(
			'cpsm-product-social',
			__( 'Social media', 'commerce-product-social-marketing' ),
			array( __CLASS__, 'render_meta_box' ),
			'product',
			'side',
			'default'
		);
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'cpsm_meta_box', 'cpsm_meta_box_nonce' );
		$skip     = get_post_meta( $post->ID, '_cpsm_skip_social', true );
		$override = get_post_meta( $post->ID, '_cpsm_caption_override', true );
		?>
		<p>
			<label>
				<input type="checkbox" name="cpsm_skip_social" value="1" <?php checked( '1' === (string) $skip ); ?> />
				<?php esc_html_e( 'Do not auto-post this product to social media', 'commerce-product-social-marketing' ); ?>
			</label>
		</p>
		<p>
			<label for="cpsm_caption_override"><?php esc_html_e( 'Caption override (leave empty to use the template)', 'commerce-product-social-marketing' ); ?></label>
			<textarea id="cpsm_caption_override" name="cpsm_caption_override" rows="4" style="width:100%;"><?php echo esc_textarea( $override ); ?></textarea>
		</p>
		<?php
	}

	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['cpsm_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['cpsm_meta_box_nonce'] ), 'cpsm_meta_box' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_cpsm_skip_social', isset( $_POST['cpsm_skip_social'] ) ? '1' : '0' );
		$override = isset( $_POST['cpsm_caption_override'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cpsm_caption_override'] ) ) : '';
		update_post_meta( $post_id, '_cpsm_caption_override', $override );
	}
}
