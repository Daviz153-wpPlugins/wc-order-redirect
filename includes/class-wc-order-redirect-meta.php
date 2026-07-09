<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Order_Redirect_Meta {

	public function __construct() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	public function enqueue_styles( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style(
			'wcor-meta-box',
			plugin_dir_url( __DIR__ ) . 'assets/css/meta-box.css',
			array(),
			'1.1.4'
		);
		wp_enqueue_script(
			'wcor-meta-box',
			plugin_dir_url( __DIR__ ) . 'assets/js/meta-box.js',
			array(),
			'1.1.4',
			true
		);
	}

	public function add_product_tab( array $tabs ): array {
		$tabs['wcor'] = array(
			'label'  => '리다이렉트',
			'target' => 'wcor_product_data',
			'class'  => array(),
		);
		return $tabs;
	}

	public function render_product_panel(): void {
		$product_id   = get_the_ID();
		$globally_on  = get_option( 'wcor_enabled', 'yes' ) === 'yes';
		$enabled      = get_post_meta( $product_id, '_wc_order_redirect_enabled', true ) === 'yes';
		$url          = esc_attr( get_post_meta( $product_id, '_wc_order_redirect_url', true ) );
		$track_color  = $enabled ? '#2271b1' : '#ccc';
		$thumb_left   = $enabled ? '22px' : '2px';
		$url_hidden   = $enabled ? '' : 'display:none;';
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=wcor' );
		$product      = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		$is_variable  = $product && $product->is_type( 'variable' );
		?>
		<div id="wcor_product_data" class="panel woocommerce_options_panel">
			<div class="options_group" style="padding:12px 16px">

				<?php if ( $is_variable ) : ?>
					<p style="margin:0 0 14px; padding:10px 14px; background:#f0f6fc; border-left:4px solid #72aee6; border-radius:2px; font-size:12px; color:#50575e;">
						변형 상품(Variable Product)입니다. 여기서 설정한 리다이렉트 URL은 부모 상품 기준으로 저장되며 모든 변형(Variation)에 동일하게 적용됩니다.
					</p>
				<?php endif; ?>

				<?php if ( ! $globally_on ) : ?>
					<p style="margin:0 0 14px; padding:10px 14px; background:#fff8e1; border-left:4px solid #f0b429; border-radius:2px; font-size:12px; color:#50575e;">
						전체 리다이렉트 기능이 비활성화되어 있습니다.
						&nbsp;<a href="<?php echo esc_url( $settings_url ); ?>">설정에서 활성화하기 →</a>
					</p>
				<?php endif; ?>

				<p style="margin:0 0 12px; padding:0">
					<span id="wcor-toggle-wrap"
							data-wcor-globally-disabled="<?php echo $globally_on ? '0' : '1'; ?>"
							style="display:inline-flex; align-items:center; gap:10px; cursor:<?php echo $globally_on ? 'pointer' : 'not-allowed'; ?>; <?php echo $globally_on ? '' : 'opacity:0.45;'; ?>">
						<input type="checkbox" id="wc_order_redirect_enabled"
								name="wc_order_redirect_enabled" value="yes"
								style="display:none" <?php checked( true, $enabled ); ?>>
						<span id="wcor-track" style="position:relative; display:inline-block; width:44px; height:24px; border-radius:12px; flex-shrink:0; transition:background .2s; background:<?php echo esc_attr( $track_color ); ?>">
							<span id="wcor-thumb" style="position:absolute; width:20px; height:20px; background:#fff; border-radius:50%; top:2px; left:<?php echo esc_attr( $thumb_left ); ?>; transition:left .2s; box-shadow:0 1px 3px rgba(0,0,0,.3)"></span>
						</span>
						<span style="font-size:13px; color:#1d2327; font-weight:600">리다이렉트 사용</span>
					</span>
				</p>

				<p id="wcor-url-field" style="margin:0 0 8px; padding:0; <?php echo esc_attr( $url_hidden ); ?>">
					<span style="display:block; font-size:12px; font-weight:600; color:#50575e; margin-bottom:4px">결제 완료 시 이동할 URL</span>
					<input type="url" id="wc_order_redirect_url" name="wc_order_redirect_url"
							value="<?php echo $url; ?>" style="width:100%; max-width:420px"
							placeholder="https://example.com">
					<span style="display:block; font-size:12px; color:#757575; margin-top:3px">활성화 시 결제 완료 후 즉시 이동합니다.</span>
				</p>

			</div>
		</div>
		<?php
	}

	public function save_product_meta( int $post_id ): void {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce already verifies nonce in woocommerce_process_product_meta
		$enabled = isset( $_POST['wc_order_redirect_enabled'] ) ? 'yes' : 'no';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$url = esc_url_raw( wp_unslash( $_POST['wc_order_redirect_url'] ?? '' ) );

		update_post_meta( $post_id, '_wc_order_redirect_enabled', $enabled );
		update_post_meta( $post_id, '_wc_order_redirect_url', $url );
	}
}
