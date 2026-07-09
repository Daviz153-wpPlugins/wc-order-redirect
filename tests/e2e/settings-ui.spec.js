/**
 * WC 설정 탭 UI 저장 E2E 테스트
 *
 * 검증 항목:
 *  1. 기본 URL 입력 → 저장 → 설정 탭 필드에 반영되고 DB에 저장됨
 *  2. UI로 저장한 기본 URL → 상품 URL 없는 주문에서 실제 리다이렉트 발동
 *  3. 유효하지 않은 URL 저장 시도 → 오류 메시지 표시, DB 미저장
 *
 * 기존 default-redirect.spec.js는 dockerPhp로 DB를 직접 조작하므로
 * 설정 폼 자체의 버그(필드명 불일치, 저장 훅 누락 등)를 잡지 못한다.
 * 이 파일은 관리자가 실제로 거치는 UI 경로를 검증한다.
 */

const { test, expect } = require('@playwright/test');
const { dockerPhp }    = require('./helpers');
const fs               = require('fs');
const path             = require('path');

let testData;

test.beforeAll(() => {
    testData = JSON.parse(
        fs.readFileSync(path.join(__dirname, '.auth', 'test-data.json'), 'utf8')
    );
});

test.beforeEach(() => {
    dockerPhp(`delete_option('wcor_default_url'); update_option('wcor_enabled', 'yes');`);
});

test.afterAll(() => {
    dockerPhp(`delete_option('wcor_default_url'); update_option('wcor_enabled', 'yes');`);
});

function adminUrl(adminPath) {
    const base = (testData.siteUrl || testData.frontendBaseUrl).replace(/\/$/, '');
    return `${base}/wp-admin/${adminPath.replace(/^\//, '')}`;
}

function frontendUrl(urlPath) {
    return testData.frontendBaseUrl.replace(/\/$/, '') + '/' + urlPath.replace(/^\//, '');
}

test.setTimeout(60_000);

// ── 1. 기본 URL 저장 → 필드·DB 반영 ────────────────────────────────────────

test('기본 URL 입력 후 저장 → 설정 탭 필드에 반영되고 DB에 저장됨', async ({ page }) => {
    const TARGET_URL = 'https://tally.so/settings-ui-test';

    await page.goto(adminUrl('admin.php?page=wc-settings&tab=wcor'));

    const urlField = page.locator('#wcor_default_url');
    await expect(urlField).toBeVisible();
    await urlField.fill(TARGET_URL);

    await page.click('button.woocommerce-save-button');

    // 저장 후 페이지 리로드 대기
    await expect(page).toHaveURL(/wc-settings/, { timeout: 15_000 });

    // 필드에 저장된 값이 표시돼야 함
    await expect(urlField).toHaveValue(TARGET_URL);

    // DB에서도 일치 확인
    const saved = dockerPhp(`echo get_option('wcor_default_url', '');`);
    expect(saved).toBe(TARGET_URL);
});

// ── 2. UI 저장 → 실제 리다이렉트 발동 ──────────────────────────────────────

test('설정 탭 UI로 저장한 기본 URL → 상품 URL 없는 주문에서 리다이렉트 발동', async ({ page }) => {
    // UI로 기본 URL 저장
    await page.goto(adminUrl('admin.php?page=wc-settings&tab=wcor'));
    await page.locator('#wcor_default_url').fill(testData.redirectTarget);
    await page.click('button.woocommerce-save-button');
    await expect(page).toHaveURL(/wc-settings/, { timeout: 15_000 });

    // 상품 URL 없는 주문 생성
    const result = dockerPhp(`
$order = wc_create_order();
$order->add_product(wc_get_product(${testData.productWithoutUrl}), 1);
$order->set_status('processing');
$order->save();
echo $order->get_id() . ':' . $order->get_order_key();
`);
    const [id, key] = result.split(':');

    // order-received → 기본 URL로 리다이렉트
    await page.goto(frontendUrl(`checkout/order-received/${id}/?key=${key}`));
    await expect(page).toHaveURL(testData.redirectTarget, { timeout: 15_000 });
});

// ── 3. 유효하지 않은 URL → 오류 메시지, DB 미저장 ──────────────────────────

test('유효하지 않은 URL 저장 시도 → 오류 메시지 표시, DB 미저장', async ({ page }) => {
    await page.goto(adminUrl('admin.php?page=wc-settings&tab=wcor'));

    // ftp:// — 브라우저 type="url" 검사는 통과하지만 서버는 http/https만 허용
    await page.locator('#wcor_default_url').fill('ftp://example.com');
    await page.click('button.woocommerce-save-button');

    // WooCommerce 오류 메시지: <div id="message" class="error inline">
    await expect(page.locator('#message.error')).toContainText(
        '유효하지 않습니다',
        { timeout: 15_000 }
    );

    // DB에 저장되지 않았는지 확인
    const saved = dockerPhp(`echo get_option('wcor_default_url', '');`);
    expect(saved).toBe('');
});
