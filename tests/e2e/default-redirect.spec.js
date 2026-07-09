/**
 * 디폴트 리다이렉트 및 충돌 시나리오 E2E 테스트
 *
 * 검증 항목:
 *  1. 디폴트 URL만 설정 → 글로벌 URL로 리다이렉트
 *  2. 상품 URL + 디폴트 URL 동시 설정 → 상품 URL 우선
 *  3. 상품 리다이렉트 활성 + URL 비어있음 → 디폴트 URL로 폴백
 *  4. 상품 URL 유효하지 않음 → 디폴트 URL로 폴백
 *  5. 플러그인 전체 비활성 → 어떤 URL도 설정돼 있어도 리다이렉트 없음
 *  6. WordPress/WooCommerce 핵심 화면 호환성 (PHP 오류 없음)
 */

const { test, expect } = require('@playwright/test');
const { dockerPhp }    = require('./helpers');
const fs               = require('fs');
const path             = require('path');

const WP_BASE      = (process.env.WP_BASE_URL || 'http://localhost:8080/').replace(/\/$/, '');
const DEFAULT_URL  = WP_BASE + '/';
const PRODUCT_URL  = WP_BASE + '/?from=product';

let testData;

test.beforeAll(() => {
    testData = JSON.parse(
        fs.readFileSync(path.join(__dirname, '.auth', 'test-data.json'), 'utf8')
    );
});

test.beforeEach(() => {
    // 각 테스트 전 플러그인 전역 옵션 초기화 (직렬 실행 전제)
    dockerPhp(`delete_option('wcor_default_url'); update_option('wcor_enabled', 'yes');`);
});

test.afterAll(() => {
    // 디폴트 URL 정리
    dockerPhp(`delete_option('wcor_default_url');`);
    dockerPhp(`update_option('wcor_enabled', 'yes');`);
});

function frontendUrl(urlPath) {
    return testData.frontendBaseUrl.replace(/\/$/, '') + '/' + urlPath.replace(/^\//, '');
}

// ── 1. 디폴트 URL만 설정 ──────────────────────────────────────────────────────

test('디폴트 URL 설정 + 상품 리다이렉트 없음 → 글로벌 URL로 리다이렉트', async ({ page }) => {
    dockerPhp(`update_option('wcor_default_url', '${DEFAULT_URL}');`);

    const result = dockerPhp(`
$order = wc_create_order();
$order->add_product(wc_get_product(${testData.productWithoutUrl}), 1);
$order->set_status('processing');
$order->save();
echo $order->get_id() . ':' . $order->get_order_key();
`);
    const [id, key] = result.split(':');

    await page.goto(frontendUrl(`checkout/order-received/${id}/?key=${key}`));
    await expect(page).toHaveURL(DEFAULT_URL);

    dockerPhp(`delete_option('wcor_default_url');`);
});

// ── 2. 상품 URL + 디폴트 URL 충돌 → 상품 URL 우선 ───────────────────────────

test('상품 URL + 디폴트 URL 동시 설정 → 상품 URL이 디폴트보다 우선', async ({ page }) => {
    dockerPhp(`update_option('wcor_default_url', '${DEFAULT_URL}');`);

    const productId = dockerPhp(`
$id = wp_insert_post([
    'post_title'  => 'Product-URL-Priority Test',
    'post_type'   => 'product',
    'post_status' => 'publish',
]);
wp_set_object_terms($id, 'simple', 'product_type');
update_post_meta($id, '_price',            '50000');
update_post_meta($id, '_regular_price',    '50000');
update_post_meta($id, '_stock_status',     'instock');
update_post_meta($id, '_virtual',          'yes');
update_post_meta($id, '_wc_order_redirect_enabled', 'yes');
update_post_meta($id, '_wc_order_redirect_url',     '${PRODUCT_URL}');
echo $id;
`);

    const result = dockerPhp(`
$order = wc_create_order();
$order->add_product(wc_get_product(${productId}), 1);
$order->set_status('processing');
$order->save();
echo $order->get_id() . ':' . $order->get_order_key();
`);
    const [id, key] = result.split(':');

    await page.goto(frontendUrl(`checkout/order-received/${id}/?key=${key}`));
    // 상품 URL로 이동해야 함 (디폴트 URL이 아님)
    await expect(page).toHaveURL(PRODUCT_URL);
    expect(page.url()).not.toBe(DEFAULT_URL);

    dockerPhp(`delete_option('wcor_default_url');`);
});

// ── 3. 상품 활성 + URL 비어있음 → 디폴트 폴백 ──────────────────────────────

test('상품 리다이렉트 활성화 + URL 비어있음 → 디폴트 URL로 폴백', async ({ page }) => {
    dockerPhp(`update_option('wcor_default_url', '${DEFAULT_URL}');`);

    const productId = dockerPhp(`
$id = wp_insert_post([
    'post_title'  => 'Empty-URL Fallback Test',
    'post_type'   => 'product',
    'post_status' => 'publish',
]);
wp_set_object_terms($id, 'simple', 'product_type');
update_post_meta($id, '_price',            '50000');
update_post_meta($id, '_regular_price',    '50000');
update_post_meta($id, '_stock_status',     'instock');
update_post_meta($id, '_virtual',          'yes');
update_post_meta($id, '_wc_order_redirect_enabled', 'yes');
update_post_meta($id, '_wc_order_redirect_url',     '');
echo $id;
`);

    const result = dockerPhp(`
$order = wc_create_order();
$order->add_product(wc_get_product(${productId}), 1);
$order->set_status('processing');
$order->save();
echo $order->get_id() . ':' . $order->get_order_key();
`);
    const [id, key] = result.split(':');

    await page.goto(frontendUrl(`checkout/order-received/${id}/?key=${key}`));
    await expect(page).toHaveURL(DEFAULT_URL);

    dockerPhp(`delete_option('wcor_default_url');`);
});

// ── 4. 상품 URL 유효하지 않음 → 디폴트 폴백 ────────────────────────────────

test('상품 URL 유효하지 않음 → 디폴트 URL로 폴백', async ({ page }) => {
    dockerPhp(`update_option('wcor_default_url', '${DEFAULT_URL}');`);

    const productId = dockerPhp(`
$id = wp_insert_post([
    'post_title'  => 'Invalid-URL Fallback Test',
    'post_type'   => 'product',
    'post_status' => 'publish',
]);
wp_set_object_terms($id, 'simple', 'product_type');
update_post_meta($id, '_price',            '50000');
update_post_meta($id, '_regular_price',    '50000');
update_post_meta($id, '_stock_status',     'instock');
update_post_meta($id, '_virtual',          'yes');
update_post_meta($id, '_wc_order_redirect_enabled', 'yes');
update_post_meta($id, '_wc_order_redirect_url',     'not-a-valid-url');
echo $id;
`);

    const result = dockerPhp(`
$order = wc_create_order();
$order->add_product(wc_get_product(${productId}), 1);
$order->set_status('processing');
$order->save();
echo $order->get_id() . ':' . $order->get_order_key();
`);
    const [id, key] = result.split(':');

    await page.goto(frontendUrl(`checkout/order-received/${id}/?key=${key}`));
    await expect(page).toHaveURL(DEFAULT_URL);

    dockerPhp(`delete_option('wcor_default_url');`);
});

// ── 5. 플러그인 전체 비활성 ──────────────────────────────────────────────────

test('플러그인 전체 비활성(wcor_enabled=no) → 리다이렉트 없음', async ({ page }) => {
    dockerPhp(`
update_option('wcor_enabled',     'no');
update_option('wcor_default_url', '${DEFAULT_URL}');
`);

    const productId = dockerPhp(`
$id = wp_insert_post([
    'post_title'  => 'Plugin Disabled Test',
    'post_type'   => 'product',
    'post_status' => 'publish',
]);
wp_set_object_terms($id, 'simple', 'product_type');
update_post_meta($id, '_price',            '50000');
update_post_meta($id, '_regular_price',    '50000');
update_post_meta($id, '_stock_status',     'instock');
update_post_meta($id, '_virtual',          'yes');
update_post_meta($id, '_wc_order_redirect_enabled', 'yes');
update_post_meta($id, '_wc_order_redirect_url',     '${PRODUCT_URL}');
echo $id;
`);

    const result = dockerPhp(`
$order = wc_create_order();
$order->add_product(wc_get_product(${productId}), 1);
$order->set_status('processing');
$order->save();
echo $order->get_id() . ':' . $order->get_order_key();
`);
    const [id, key] = result.split(':');

    await page.goto(frontendUrl(`checkout/order-received/${id}/?key=${key}`));
    // 리다이렉트가 없으므로 order-received 페이지에 머물러야 함
    await expect(page).toHaveURL(new RegExp(`order-received/${id}`));
    expect(page.url()).not.toBe(DEFAULT_URL);
    expect(page.url()).not.toBe(PRODUCT_URL);

    dockerPhp(`
update_option('wcor_enabled', 'yes');
delete_option('wcor_default_url');
`);
});

// ── 6. WordPress/WooCommerce 호환성 ─────────────────────────────────────────

test('WP 플러그인 목록 → WC Order Redirect 활성 상태, PHP 오류 없음', async ({ page }) => {
    const siteBase = (testData.siteUrl || testData.frontendBaseUrl).replace(/\/$/, '');
    await page.goto(`${siteBase}/wp-admin/plugins.php`);
    await expect(page).toHaveURL(/plugins\.php/);
    await expect(page.locator('body')).not.toContainText('Fatal error');
    await expect(page.locator('body')).not.toContainText('Parse error');
    const row = page.locator('tr[data-slug="wc-order-redirect"]');
    await expect(row).toBeVisible();
    await expect(row).not.toHaveClass(/inactive/);
});

test('WC 설정 페이지 → PHP 오류 없음, 플러그인 탭 표시', async ({ page }) => {
    const siteBase = (testData.siteUrl || testData.frontendBaseUrl).replace(/\/$/, '');
    await page.goto(`${siteBase}/wp-admin/admin.php?page=wc-settings`);
    await expect(page).toHaveURL(/wc-settings/);
    await expect(page.locator('body')).not.toContainText('Fatal error');
    await expect(page.locator('body')).not.toContainText('Parse error');
    // 플러그인이 추가한 설정 탭 확인
    await expect(page.locator('a[href*="tab=wcor"]')).toBeVisible();
});

test('WC 상태 페이지 → PHP 오류 없음', async ({ page }) => {
    const siteBase = (testData.siteUrl || testData.frontendBaseUrl).replace(/\/$/, '');
    await page.goto(`${siteBase}/wp-admin/admin.php?page=wc-status`);
    await expect(page).toHaveURL(/wc-status/);
    await expect(page.locator('body')).not.toContainText('Fatal error');
    await expect(page.locator('body')).not.toContainText('Parse error');
});
