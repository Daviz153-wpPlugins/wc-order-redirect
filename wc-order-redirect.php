<?php
/**
 * Plugin Name: WC Order Redirect
 * Plugin URI:  https://github.com/Daviz153-wpPlugins/wc-order-redirect
 * Description: 결제 완료 후 상품별로 설정한 URL로 즉시 이동합니다.
 * Version:     1.1.4
 * Author:      CRMBiz
 * Author URI:  https://github.com/Daviz153-wpPlugins
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-order-redirect
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 9.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 리다이렉트 대상 URL의 도메인이 허용 목록에 속하는지 검사합니다.
 * 서브도메인(hook.eu1.make.com 등)도 매칭합니다.
 * 추가 도메인은 wcor_allowed_redirect_domains 필터로 확장하세요.
 */
function wcor_is_url_domain_allowed( string $url ): bool {
	$allowed = apply_filters(
		'wcor_allowed_redirect_domains',
		array(
			'money153.com',
			'crmbiz.kr',
			'make.com',
			'tally.so',
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
		)
	);

	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $host ) {
		return false;
	}

	foreach ( $allowed as $domain ) {
		$domain = strtolower( (string) $domain );
		if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
			return true;
		}
	}

	return false;
}

// 자동 업데이트 — GitHub 릴리즈 기반 (vendor/가 있을 때만 로드)
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';

	$checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/Daviz153-wpPlugins/wc-order-redirect/',
		__FILE__,
		'wc-order-redirect'
	);
	// 릴리즈에 첨부된 ZIP을 다운로드 (vendor/ 포함)
	$checker->getVcsApi()->enableReleaseAssets();
}

// WooCommerce HPOS 호환성 선언
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>'
					. 'WC Order Redirect: WooCommerce가 활성화되어 있어야 합니다.'
					. '</p></div>';
				}
			);
			return;
		}

		require_once __DIR__ . '/includes/class-wc-order-redirect-meta.php';
		require_once __DIR__ . '/includes/class-wc-order-redirect.php';
		require_once __DIR__ . '/includes/class-wc-order-redirect-privacy.php';

		new WC_Order_Redirect_Meta();
		new WC_Order_Redirect();

		add_filter(
			'woocommerce_get_settings_pages',
			function ( array $pages ): array {
				require_once __DIR__ . '/includes/class-wc-order-redirect-settings.php';
				$pages[] = new WC_Order_Redirect_Settings();
				return $pages;
			}
		);

		// GDPR: 개인정보 내보내기/삭제 API 등록
		add_filter( 'wp_privacy_personal_data_exporters', 'wcor_privacy_register_exporter' );
		add_filter( 'wp_privacy_personal_data_erasers', 'wcor_privacy_register_eraser' );
	}
);
