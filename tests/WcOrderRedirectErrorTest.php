<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-wc-order-redirect.php';
require_once __DIR__ . '/../includes/class-wc-order-redirect-meta.php';

// 잘못된 key 시뮬레이션
class WC_Order_InvalidKey extends WC_Order {
    public function key_is_valid(string $key): bool { return false; }
}

// 주문 상태 시뮬레이션
class WC_Order_WithStatus extends WC_Order {
    public function __construct(array $items = [], private string $status = 'processing') {
        parent::__construct($items);
    }
    public function get_status(): string { return $this->status; }
}

class WcOrderRedirectErrorTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_post_meta']              = [];
        $GLOBALS['_options']               = [];
        $GLOBALS['_wp_redirect_called']     = false;
        $GLOBALS['_wp_redirect_url']        = '';
        $GLOBALS['_is_order_received_page'] = true;
        $GLOBALS['_wc_orders']              = [];
        $GLOBALS['_query_vars']             = [];
        $_GET = [];
    }

    // ── 잘못된 요청 시나리오 ─────────────────────────────────────────────────

    public function test_no_redirect_when_order_key_invalid(): void {
        $GLOBALS['_query_vars']['order-received'] = 42;
        $_GET['key'] = 'wc_order_WRONG';

        $item  = new WC_Order_Item_Product(10, 100.0);
        $order = new WC_Order_InvalidKey([$item]);
        $GLOBALS['_wc_orders'][42] = $order;

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://example.com/target';

        (new WC_Order_Redirect())->maybe_redirect();

        $this->assertFalse($GLOBALS['_wp_redirect_called'], '잘못된 key → 리다이렉트 차단');
    }

    public function test_no_redirect_when_order_does_not_exist(): void {
        $GLOBALS['_query_vars']['order-received'] = 9999;
        $_GET['key'] = 'wc_order_abc';

        (new WC_Order_Redirect())->maybe_redirect();

        $this->assertFalse($GLOBALS['_wp_redirect_called'], '존재하지 않는 주문 → 리다이렉트 없음');
    }

    public function test_no_redirect_when_order_id_is_zero(): void {
        $GLOBALS['_query_vars']['order-received'] = 0;

        (new WC_Order_Redirect())->maybe_redirect();

        $this->assertFalse($GLOBALS['_wp_redirect_called'], 'order-received=0 → 리다이렉트 없음');
    }

    public function test_no_redirect_when_order_has_no_items(): void {
        $GLOBALS['_query_vars']['order-received'] = 1;
        $_GET['key'] = 'wc_order_valid';

        $order = new WC_Order([]);
        $GLOBALS['_wc_orders'][1] = $order;

        (new WC_Order_Redirect())->maybe_redirect();

        $this->assertFalse($GLOBALS['_wp_redirect_called'], '아이템 없는 주문 → 리다이렉트 없음');
    }

    public function test_redirect_url_rejects_javascript_scheme(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'javascript:alert(1)';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('', $url, 'javascript: scheme → URL 반환 안 함');
    }

    public function test_redirect_url_rejects_data_scheme(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'data:text/html,<script>alert(1)</script>';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('', $url, 'data: scheme → URL 반환 안 함');
    }

    // ── 주문 상태별 동작 ──────────────────────────────────────────────────────
    // NOTE: maybe_redirect()는 failed/cancelled/refunded/checkout-draft를 denylist로 차단.
    //       get_redirect_url()은 상태를 검사하지 않으므로 아래 테스트는 URL 반환 여부만 확인한다.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_get_redirect_url_returns_url_for_pending_status(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order_WithStatus([$item], 'pending');

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://example.com/target';

        // get_redirect_url()는 상태 비검사 — pending도 URL 반환 (maybe_redirect에서 차단 아님)
        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('https://example.com/target', $url);
    }

    public function test_get_redirect_url_returns_url_for_failed_status(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order_WithStatus([$item], 'failed');

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://example.com/target';

        // get_redirect_url()는 상태 비검사 — maybe_redirect()의 denylist가 차단함
        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('https://example.com/target', $url);
    }

    /**
     * @dataProvider deniedStatusProvider
     */
    public function test_maybe_redirect_blocks_denylist_statuses(string $status): void {
        $GLOBALS['_is_order_received_page']      = true;
        $GLOBALS['_query_vars']['order-received'] = 1;

        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order_WithStatus([$item], $status);
        $GLOBALS['_wc_orders'][1] = $order;

        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://example.com/target';

        (new WC_Order_Redirect())->maybe_redirect();

        $this->assertFalse($GLOBALS['_wp_redirect_called'],
            "{$status} 상태 주문 → denylist 차단으로 리다이렉트 없음");
    }

    public static function deniedStatusProvider(): array {
        return [
            ['failed'],
            ['cancelled'],
            ['refunded'],
            ['checkout-draft'],
        ];
    }

    // ── 플러그인 공존 — 메타 키 독립성 ─────────────────────────────────────

    public function test_redirect_reads_only_own_meta_keys(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        // 웹훅 플러그인 메타만 설정 (리다이렉트 메타 없음)
        $GLOBALS['_post_meta'][10]['_wcmw_product_enabled'] = '1';
        $GLOBALS['_post_meta'][10]['_wcmw_product_url']     = 'https://hook.example.com/webhook';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('', $url, '웹훅 메타만 있을 때 → 리다이렉트 플러그인 미발동');
    }

    public function test_redirect_works_when_both_plugin_metas_coexist(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        // 두 플러그인 메타 모두 설정
        $GLOBALS['_post_meta'][10]['_wcmw_product_enabled']      = '1';
        $GLOBALS['_post_meta'][10]['_wcmw_product_url']          = 'https://hook.example.com/webhook';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'yes';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://example.com/thank-you';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('https://example.com/thank-you', $url,
            '두 플러그인 메타 공존 시 → 리다이렉트 URL 정확히 반환');
    }

    public function test_webhook_enabled_does_not_activate_redirect(): void {
        $item  = new WC_Order_Item_Product(10, 50.0);
        $order = new WC_Order([$item]);

        // 웹훅 활성 + 리다이렉트 비활성
        $GLOBALS['_post_meta'][10]['_wcmw_product_enabled']      = '1';
        $GLOBALS['_post_meta'][10]['_wcmw_product_url']          = 'https://hook.example.com/webhook';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_enabled'] = 'no';
        $GLOBALS['_post_meta'][10]['_wc_order_redirect_url']     = 'https://example.com/thank-you';

        $url = (new WC_Order_Redirect())->get_redirect_url($order);

        $this->assertSame('', $url,
            '웹훅 활성 + 리다이렉트 비활성 → 리다이렉트 미발동');
    }
}
