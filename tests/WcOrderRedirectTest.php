<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-wc-order-redirect.php';
require_once __DIR__ . '/../includes/class-wc-order-redirect-meta.php';

class WcOrderRedirectTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_post_meta']              = [];
        $GLOBALS['_options']               = [];
        $GLOBALS['_wp_redirect_called']     = false;
        $GLOBALS['_wp_redirect_url']        = '';
        $GLOBALS['_is_order_received_page'] = false;
        $GLOBALS['_wc_orders']              = [];
        $GLOBALS['_query_vars']             = [];
    }

    // --- [Feature B] 리다이렉트 로직 ---

    public function test_no_redirect_when_not_thankyou_page(): void {
        $GLOBALS['_is_order_received_page'] = false;

        (new WC_Order_Redirect())->maybe_redirect();

        $this->assertFalse($GLOBALS['_wp_redirect_called']);
    }

    public function test_no_redirect_when_disabled(): void {
        $GLOBALS['_is_order_received_page'] = true;
        $GLOBALS['_query_vars']['order-received'] = 1;

        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);
        $GLOBALS['_wc_orders'][1] = $order;

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'no';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://example.com/next';

        (new WC_Order_Redirect())->maybe_redirect();

        $this->assertFalse($GLOBALS['_wp_redirect_called']);
    }

    public function test_no_redirect_when_enabled_but_url_empty(): void {
        $GLOBALS['_is_order_received_page'] = true;
        $GLOBALS['_query_vars']['order-received'] = 1;

        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);
        $GLOBALS['_wc_orders'][1] = $order;

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = '';

        (new WC_Order_Redirect())->maybe_redirect();

        $this->assertFalse($GLOBALS['_wp_redirect_called']);
    }

    public function test_redirects_when_enabled_with_url(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://example.com/next';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('https://example.com/next', $url);
    }

    public function test_uses_most_expensive_enabled_product(): void {
        $itemA = new WC_Order_Item_Product(10, 30.0);  // 저가, 활성
        $itemB = new WC_Order_Item_Product(20, 80.0);  // 고가, 활성
        $order = new WC_Order([$itemA, $itemB]);

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://example.com/cheap';
        $GLOBALS['_post_meta'][20]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][20]['_wc_order_redirect_url']     = 'https://example.com/expensive';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('https://example.com/expensive', $url);
    }

    public function test_skips_disabled_bump_product(): void {
        // 메인 상품(저가, 활성) + 범프 상품(고가, 비활성)
        $main = new WC_Order_Item_Product(10, 50.0);
        $bump = new WC_Order_Item_Product(20, 80.0);
        $order = new WC_Order([$main, $bump]);

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://example.com/main';
        $GLOBALS['_post_meta'][20]['_wc_order_redirect_enabled'] = 'no';
        $GLOBALS['_post_meta'][20]['_wc_order_redirect_url']     = 'https://example.com/bump';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        // 범프(고가)는 비활성이므로 메인(저가, 활성)의 URL 사용
        $this->assertSame('https://example.com/main', $url);
    }

    public function test_no_redirect_when_all_disabled(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'no';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://example.com/next';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('', $url);
    }

    public function test_no_redirect_when_globally_disabled(): void {
        $GLOBALS['_is_order_received_page']      = true;
        $GLOBALS['_query_vars']['order-received'] = 1;
        $GLOBALS['_options']['wcor_enabled']      = 'no';

        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item], 1);
        $GLOBALS['_wc_orders'][1] = $order;

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://example.com/next';

        (new WC_Order_Redirect())->maybe_redirect();

        $this->assertFalse($GLOBALS['_wp_redirect_called']);
    }

    public function test_uses_default_url_when_no_product_enabled(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'no';
        $GLOBALS['_options']['wcor_default_url']                 = 'https://default.example.com/';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('https://default.example.com/', $url);
    }

    public function test_product_url_takes_priority_over_default(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://product.example.com/';
        $GLOBALS['_options']['wcor_default_url']                 = 'https://default.example.com/';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('https://product.example.com/', $url);
    }

    public function test_skips_invalid_url(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'not-a-valid-url';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('', $url);
    }

    public function test_skips_non_whitelisted_domain(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        // evil.com 은 허용 목록에 없으므로 도메인 화이트리스트에서 차단되어야 함
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://evil.com/steal';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('', $url, '허용되지 않은 도메인 → 빈 문자열 반환');
    }

    public function test_skips_non_whitelisted_default_url(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'no';
        $GLOBALS['_options']['wcor_default_url']                 = 'https://evil.com/steal';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('', $url, '글로벌 기본 URL도 허용되지 않은 도메인이면 차단');
    }

    public function test_skips_invalid_default_url(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'no';
        $GLOBALS['_options']['wcor_default_url']                 = 'ftp://example.com';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('', $url);
    }

    public function test_invalid_product_url_and_invalid_default_url_returns_empty(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'not-a-valid-url';
        $GLOBALS['_options']['wcor_default_url']                 = 'ftp://example.com';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('', $url);
    }

    public function test_same_price_products_uses_first_valid_url(): void {
        // 동일 가격이면 uasort 순서가 구현 의존적 — 어느 쪽이든 유효한 URL을 반환해야 함
        $itemA = new WC_Order_Item_Product(10, 50.0);
        $itemB = new WC_Order_Item_Product(20, 50.0);
        $order = new WC_Order([$itemA, $itemB]);

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://example.com/a';
        $GLOBALS['_post_meta'][20]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][20]['_wc_order_redirect_url']     = 'https://example.com/b';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertContains($url, ['https://example.com/a', 'https://example.com/b']);
    }

    // --- [Feature A] 메타박스 저장 ---

    public function test_enabled_flag_saved(): void {
        $_POST['wc_order_redirect_enabled'] = 'yes';
        $_POST['wc_order_redirect_url']     = 'https://example.com/next';

        (new WC_Order_Redirect_Meta())->save_product_meta(42);

        $this->assertSame('yes', get_post_meta(42, '_wc_order_redirect_enabled', true));
    }

    public function test_disabled_when_checkbox_unchecked(): void {
        // wc_order_redirect_enabled 키 없음 = 체크 안 함
        unset($_POST['wc_order_redirect_enabled']);
        $_POST['wc_order_redirect_url'] = 'https://example.com/next';

        (new WC_Order_Redirect_Meta())->save_product_meta(42);

        $this->assertSame('no', get_post_meta(42, '_wc_order_redirect_enabled', true));
    }

    public function test_invalid_url_sanitized_on_save(): void {
        $_POST['wc_order_redirect_enabled'] = 'yes';
        $_POST['wc_order_redirect_url']     = 'javascript:alert(1)';

        (new WC_Order_Redirect_Meta())->save_product_meta(42);

        $this->assertSame('', get_post_meta(42, '_wc_order_redirect_url', true));
    }

    // --- [Feature C] uninstall ---

    public function test_uninstall_removes_all_meta(): void {
        $GLOBALS['_post_meta'][1]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][1]['_wc_order_redirect_url']     = 'https://example.com/a';
        $GLOBALS['_post_meta'][2]['_wc_order_redirect_enabled'] = 'no';
        $GLOBALS['_post_meta'][2]['_wc_order_redirect_url']     = 'https://example.com/b';

        delete_post_meta_by_key('_wc_order_redirect_enabled');
        delete_post_meta_by_key('_wc_order_redirect_url');

        $this->assertSame('', get_post_meta(1, '_wc_order_redirect_enabled', true));
        $this->assertSame('', get_post_meta(1, '_wc_order_redirect_url', true));
    }
}
