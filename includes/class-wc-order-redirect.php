<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Order_Redirect {

	private string $last_source = 'product';

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ) );
	}

	public function maybe_redirect(): void {
		if ( get_option( 'wcor_enabled', 'yes' ) !== 'yes' ) {
			return;
		}

		if ( ! is_order_received_page() ) {
			return;
		}

		$order = $this->get_order();
		if ( ! $order ) {
			return;
		}

		$skip_statuses = apply_filters( 'wcor_skip_redirect_statuses', array( 'failed', 'cancelled', 'refunded', 'checkout-draft' ) );
		if ( $order->has_status( $skip_statuses ) ) {
			return;
		}

		$url = $this->get_redirect_url( $order );
		if ( ! $url ) {
			return;
		}

		// 최초 방문 시에만 로그 기록 (주문완료 페이지 재방문 로그 중복 방지)
		if ( $order->get_meta( '_wcor_redirected' ) !== '1' ) {
			$products = implode( ', ', array_map( fn( $item ) => $item->get_name(), $order->get_items() ) );
			$customer = trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() );
			$this->write_log(
				array(
					'ts'       => time(),
					'order'    => $order->get_id(),
					'url'      => $url,
					'src'      => $this->last_source,
					'products' => $products,
					'customer' => $customer,
					'email'    => $order->get_billing_email(),
					'phone'    => $order->get_billing_phone(),
				)
			);
			$order->update_meta_data( '_wcor_redirected', '1' );
			$order->save_meta_data();
		}

		wp_redirect( esc_url_raw( $url ), 302 );
		exit();
	}

	private function get_order(): ?\WC_Order {
		$order_id = absint( get_query_var( 'order-received' ) );
		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! ( $order instanceof \WC_Order ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public order-received URL; ownership verified via order key below
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $order->key_is_valid( $key ) ) {
			return null;
		}

		return $order;
	}

	public function get_redirect_url( \WC_Order $order ): string {
		$items = $order->get_items();

		// 활성화된 상품만 추출, 최고가 우선
		$enabled_items = array_filter(
			$items,
			function ( $item ) {
				return 'yes' === get_post_meta( $item->get_product_id(), '_wc_order_redirect_enabled', true );
			}
		);

		if ( ! empty( $enabled_items ) ) {
			uasort( $enabled_items, fn( $a, $b ) => $b->get_total() <=> $a->get_total() );

			foreach ( $enabled_items as $item ) {
				$url = get_post_meta( $item->get_product_id(), '_wc_order_redirect_url', true );
				if ( $url && $this->is_valid_url( $url ) ) {
					$this->last_source = 'product';
					return $url;
				}
			}
		}

		// 상품별 리다이렉트 없으면 글로벌 기본 URL로 fallback
		$default = get_option( 'wcor_default_url', '' );
		if ( $default && $this->is_valid_url( $default ) ) {
			$this->last_source = 'default';
			return $default;
		}

		return '';
	}

	private function is_valid_url( string $url ): bool {
		return (bool) filter_var( $url, FILTER_VALIDATE_URL )
			&& ( str_starts_with( $url, 'http://' ) || str_starts_with( $url, 'https://' ) )
			&& wcor_is_url_domain_allowed( $url );
	}

	private function write_log( array $entry ): void {
		$log = (array) get_option( 'wcor_redirect_log', array() );
		array_unshift( $log, $entry );
		update_option( 'wcor_redirect_log', array_slice( $log, 0, 50 ), false );
	}
}
