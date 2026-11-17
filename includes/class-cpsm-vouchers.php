<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPSM_Vouchers {

	const POST_TYPE = 'cpsm_voucher';
	const SESSION_KEY = 'cpsm_voucher_code';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'render_checkout_field' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_checkout' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_fee' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_processed' ), 10, 1 );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
	}

	public static function register_post_type() {
		register_post_type( self::POST_TYPE, array(
			'labels'       => array(
				'name'          => __( 'Vouchers', 'commerce-product-social-marketing' ),
				'singular_name' => __( 'Voucher', 'commerce-product-social-marketing' ),
			),
			'public'       => false,
			'show_ui'      => false,
			'show_in_menu' => false,
			'supports'     => array( 'title' ),
		) );
	}

	public static function generate_code() {
		do {
			$code = strtoupper( wp_generate_password( 12, false ) );
		} while ( self::get_by_code( $code ) );
		return $code;
	}

	public static function get_by_code( $code ) {
		$posts = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'any',
			'title'          => strtoupper( trim( (string) $code ) ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );
		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	public static function get_meta( $voucher_id ) {
		return array(
			'code'        => get_the_title( $voucher_id ),
			'type'        => get_post_meta( $voucher_id, '_cpsm_type', true ) ?: 'fixed',
			'amount'      => (float) get_post_meta( $voucher_id, '_cpsm_amount', true ),
			'balance'     => (float) get_post_meta( $voucher_id, '_cpsm_balance', true ),
			'expires'     => get_post_meta( $voucher_id, '_cpsm_expires', true ),
			'usage_limit' => (int) get_post_meta( $voucher_id, '_cpsm_usage_limit', true ),
			'used_count'  => (int) get_post_meta( $voucher_id, '_cpsm_used_count', true ),
			'email'       => get_post_meta( $voucher_id, '_cpsm_email', true ),
			'status'      => get_post_meta( $voucher_id, '_cpsm_status', true ) ?: 'active',
		);
	}

	public static function create( $args ) {
		$type = in_array( $args['type'], array( 'percent', 'fixed', 'giftcard' ), true ) ? $args['type'] : 'fixed';
		$code = self::generate_code();
		$voucher_id = wp_insert_post( array(
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $code,
		) );
		if ( is_wp_error( $voucher_id ) || ! $voucher_id ) {
			return new WP_Error( 'cpsm_voucher_failed', __( 'Could not create the voucher.', 'commerce-product-social-marketing' ) );
		}
		$amount  = isset( $args['amount'] ) ? (float) $args['amount'] : 0;
		$balance = 'giftcard' === $type ? $amount : 0;
		update_post_meta( $voucher_id, '_cpsm_type', $type );
		update_post_meta( $voucher_id, '_cpsm_amount', $amount );
		update_post_meta( $voucher_id, '_cpsm_balance', $balance );
		update_post_meta( $voucher_id, '_cpsm_expires', isset( $args['expires'] ) ? sanitize_text_field( $args['expires'] ) : '' );
		update_post_meta( $voucher_id, '_cpsm_usage_limit', isset( $args['usage_limit'] ) ? (int) $args['usage_limit'] : 0 );
		update_post_meta( $voucher_id, '_cpsm_used_count', 0 );
		update_post_meta( $voucher_id, '_cpsm_email', isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '' );
		update_post_meta( $voucher_id, '_cpsm_status', 'active' );
		return $voucher_id;
	}

	public static function validate( $code ) {
		$voucher_id = self::get_by_code( $code );
		if ( ! $voucher_id ) {
			return new WP_Error( 'cpsm_invalid', __( 'This voucher code is not valid.', 'commerce-product-social-marketing' ) );
		}
		$v = self::get_meta( $voucher_id );
		if ( 'active' !== $v['status'] ) {
			return new WP_Error( 'cpsm_inactive', __( 'This voucher is no longer active.', 'commerce-product-social-marketing' ) );
		}
		if ( ! empty( $v['expires'] ) && strtotime( $v['expires'] . ' 23:59:59' ) < current_time( 'timestamp' ) ) {
			update_post_meta( $voucher_id, '_cpsm_status', 'expired' );
			return new WP_Error( 'cpsm_expired', __( 'This voucher has expired.', 'commerce-product-social-marketing' ) );
		}
		if ( $v['usage_limit'] > 0 && $v['used_count'] >= $v['usage_limit'] ) {
			update_post_meta( $voucher_id, '_cpsm_status', 'used' );
			return new WP_Error( 'cpsm_used_up', __( 'This voucher has been used the maximum number of times.', 'commerce-product-social-marketing' ) );
		}
		if ( 'giftcard' === $v['type'] && $v['balance'] <= 0 ) {
			update_post_meta( $voucher_id, '_cpsm_status', 'used' );
			return new WP_Error( 'cpsm_empty', __( 'This gift certificate has no balance left.', 'commerce-product-social-marketing' ) );
		}
		$v['voucher_id'] = $voucher_id;
		return $v;
	}

	public static function calculate_discount( $v, $base ) {
		$base = max( 0, (float) $base );
		if ( 'percent' === $v['type'] ) {
			return min( round( $base * $v['amount'] / 100, 2 ), $base );
		}
		if ( 'giftcard' === $v['type'] ) {
			return min( $v['balance'], $base );
		}
		return min( $v['amount'], $base );
	}

	public static function render_checkout_field() {
		$code = ( WC()->session ) ? (string) WC()->session->get( self::SESSION_KEY ) : '';
		?>
		<p class="form-row form-row-wide" id="cpsm_voucher_code_field">
			<label for="cpsm_voucher_code"><?php esc_html_e( 'Voucher or gift certificate code', 'commerce-product-social-marketing' ); ?></label>
			<input type="text" class="input-text" name="cpsm_voucher_code" id="cpsm_voucher_code" value="<?php echo esc_attr( $code ); ?>" placeholder="<?php esc_attr_e( 'Enter code', 'commerce-product-social-marketing' ); ?>" />
		</p>
		<?php
	}

	public static function validate_checkout() {
		$code = isset( $_POST['cpsm_voucher_code'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['cpsm_voucher_code'] ) ) ) ) : '';
		if ( '' === $code ) {
			if ( WC()->session ) {
				WC()->session->__unset( self::SESSION_KEY );
			}
			return;
		}
		$result = self::validate( $code );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return;
		}
		WC()->session->set( self::SESSION_KEY, $code );
	}

	public static function apply_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! WC()->session ) {
			return;
		}
		$code = WC()->session->get( self::SESSION_KEY );
		if ( empty( $code ) ) {
			return;
		}
		$result = self::validate( $code );
		if ( is_wp_error( $result ) ) {
			WC()->session->__unset( self::SESSION_KEY );
			return;
		}
		$base = max( 0, (float) $cart->get_subtotal() - (float) $cart->get_discount_total() );
		$amount = self::calculate_discount( $result, $base );
		if ( $amount <= 0 ) {
			return;
		}
		$cart->add_fee(
			sprintf( __( 'Voucher %s', 'commerce-product-social-marketing' ), $code ),
			-$amount,
			false
		);
	}

	public static function on_order_processed( $order_id ) {
		if ( ! WC()->session ) {
			return;
		}
		$code = WC()->session->get( self::SESSION_KEY );
		if ( empty( $code ) ) {
			return;
		}
		$result = self::validate( $code );
		if ( is_wp_error( $result ) ) {
			WC()->session->__unset( self::SESSION_KEY );
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$base = max( 0, (float) $order->get_subtotal() - (float) $order->get_discount_total() );
		$amount = self::calculate_discount( $result, $base );
		if ( $amount <= 0 ) {
			WC()->session->__unset( self::SESSION_KEY );
			return;
		}

		$voucher_id = $result['voucher_id'];
		if ( 'giftcard' === $result['type'] ) {
			update_post_meta( $voucher_id, '_cpsm_balance', max( 0, $result['balance'] - $amount ) );
		}
		$used = $result['used_count'] + 1;
		update_post_meta( $voucher_id, '_cpsm_used_count', $used );
		if ( $result['usage_limit'] > 0 && $used >= $result['usage_limit'] ) {
			update_post_meta( $voucher_id, '_cpsm_status', 'used' );
		}
		if ( 'giftcard' === $result['type'] && $result['balance'] - $amount <= 0 ) {
			update_post_meta( $voucher_id, '_cpsm_status', 'used' );
		}

		$order->update_meta_data( '_cpsm_voucher_code', $code );
		$order->update_meta_data( '_cpsm_voucher_discount', $amount );
		$order->save();

		WC()->session->__unset( self::SESSION_KEY );
	}

	public static function send_email( $voucher_id ) {
		$v = self::get_meta( $voucher_id );
		if ( empty( $v['email'] ) ) {
			return new WP_Error( 'cpsm_no_email', __( 'No recipient email set for this voucher.', 'commerce-product-social-marketing' ) );
		}
		$subject = sprintf( __( 'Your voucher code for %s', 'commerce-product-social-marketing' ), get_bloginfo( 'name' ) );
		if ( 'giftcard' === $v['type'] ) {
			$body = sprintf(
				__( "Hi!\n\nYou received a gift certificate for %s.\n\nCode: %s\nBalance: %s\n\nRedeem it at checkout on %s", 'commerce-product-social-marketing' ),
				get_bloginfo( 'name' ),
				$v['code'],
				function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $v['balance'] ) ) : $v['balance'],
				home_url()
			);
		} else {
			$discount = 'percent' === $v['type'] ? $v['amount'] . '%' : ( function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $v['amount'] ) ) : $v['amount'] );
			$body = sprintf(
				__( "Hi!\n\nYou received a voucher for %s.\n\nCode: %s\nDiscount: %s\n\nRedeem it at checkout on %s", 'commerce-product-social-marketing' ),
				get_bloginfo( 'name' ),
				$v['code'],
				$discount,
				home_url()
			);
		}
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$sent = wp_mail( $v['email'], $subject, $body, $headers );
		if ( ! $sent ) {
			return new WP_Error( 'cpsm_mail_failed', __( 'The email could not be sent.', 'commerce-product-social-marketing' ) );
		}
		return true;
	}

	public static function all_vouchers() {
		$posts = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		$vouchers = array();
		foreach ( $posts as $post ) {
			$vouchers[] = array_merge( array( 'id' => $post->ID ), self::get_meta( $post->ID ) );
		}
		return $vouchers;
	}

	public static function handle_actions() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$base = admin_url( 'admin.php?page=commerce-product-social-marketing&tab=vouchers' );

		if ( isset( $_POST['cpsm_voucher_create'] ) ) {
			check_admin_referer( 'cpsm_voucher_create' );
			$voucher_id = self::create( array(
				'type'        => isset( $_POST['cpsm_v_type'] ) ? sanitize_key( $_POST['cpsm_v_type'] ) : 'fixed',
				'amount'      => isset( $_POST['cpsm_v_amount'] ) ? (float) $_POST['cpsm_v_amount'] : 0,
				'expires'     => isset( $_POST['cpsm_v_expires'] ) ? sanitize_text_field( wp_unslash( $_POST['cpsm_v_expires'] ) ) : '',
				'usage_limit' => isset( $_POST['cpsm_v_usage_limit'] ) ? (int) $_POST['cpsm_v_usage_limit'] : 0,
				'email'       => isset( $_POST['cpsm_v_email'] ) ? sanitize_email( wp_unslash( $_POST['cpsm_v_email'] ) ) : '',
			) );
			if ( is_wp_error( $voucher_id ) ) {
				wp_safe_redirect( $base . '&cpsm_msg=voucher_error&cpsm_err=' . rawurlencode( $voucher_id->get_error_message() ) );
				exit;
			}
			if ( ! empty( $_POST['cpsm_v_send'] ) && ! empty( $_POST['cpsm_v_email'] ) ) {
				self::send_email( $voucher_id );
			}
			wp_safe_redirect( $base . '&cpsm_msg=voucher_created' );
			exit;
		}

		if ( isset( $_GET['cpsm_voucher_send'] ) ) {
			$voucher_id = (int) $_GET['cpsm_voucher_send'];
			check_admin_referer( 'cpsm_voucher_send_' . $voucher_id );
			$result = self::send_email( $voucher_id );
			$msg = is_wp_error( $result ) ? 'send_error&cpsm_err=' . rawurlencode( $result->get_error_message() ) : 'sent';
			wp_safe_redirect( $base . '&cpsm_msg=' . $msg );
			exit;
		}

		if ( isset( $_GET['cpsm_voucher_delete'] ) ) {
			$voucher_id = (int) $_GET['cpsm_voucher_delete'];
			check_admin_referer( 'cpsm_voucher_delete_' . $voucher_id );
			wp_delete_post( $voucher_id, true );
			wp_safe_redirect( $base . '&cpsm_msg=voucher_deleted' );
			exit;
		}
	}
}
