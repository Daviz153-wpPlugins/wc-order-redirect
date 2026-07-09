<?php

define('ABSPATH', dirname(__DIR__) . '/');
define('DOING_AUTOSAVE', false);

// --- WordPress 훅 스텁 (생성자 등록용, 실제 발동 불필요) ---
function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): void {}
function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): void {}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
function add_meta_box(string $id, string $title, callable $callback, string $screen, string $context = 'advanced'): void {}
function get_current_screen(): ?object { return null; }
function plugin_dir_url(string $file): string { return 'http://example.com/plugins/wc-order-redirect/'; }
function wp_enqueue_style(string $handle, string $src = '', array $deps = [], $ver = false): void {}

// --- post_meta / options 인메모리 저장소 ---
$GLOBALS['_post_meta'] = [];
$GLOBALS['_options']   = [];

function get_post_meta(int $post_id, string $key, bool $single = false) {
    $value = $GLOBALS['_post_meta'][$post_id][$key] ?? '';
    return $single ? $value : [$value];
}

function update_post_meta(int $post_id, string $key, $value): bool {
    $GLOBALS['_post_meta'][$post_id][$key] = $value;
    return true;
}

function delete_post_meta_by_key(string $key): void {
    foreach ($GLOBALS['_post_meta'] as $post_id => $_) {
        unset($GLOBALS['_post_meta'][$post_id][$key]);
    }
}

function get_option(string $key, $default = false) {
    return $GLOBALS['_options'][$key] ?? $default;
}

function update_option(string $key, $value, $autoload = null): bool {
    $GLOBALS['_options'][$key] = $value;
    return true;
}

function delete_option(string $key): bool {
    unset($GLOBALS['_options'][$key]);
    return true;
}

// --- URL 처리 ---
function wp_parse_url(string $url, int $component = -1): mixed {
    return parse_url($url, $component);
}
function home_url(string $path = ''): string {
    // 테스트 환경 기본 호스트 = example.com (테스트 픽스처 URL과 일치)
    return 'http://example.com' . ($path ? '/' . ltrim($path, '/') : '');
}
function esc_url_raw(string $url): string {
    // http/https 외 scheme 차단
    if (!preg_match('/^https?:\/\//i', $url)) return '';
    return $url;
}

function esc_attr(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function wp_http_validate_url(string $url): string|false {
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : false;
}

// --- 날짜 ---
function wp_date(string $format, ?int $timestamp = null): string {
    return date($format, $timestamp ?? time());
}

// --- 리다이렉트 ---
$GLOBALS['_wp_redirect_called'] = false;
$GLOBALS['_wp_redirect_url']    = '';

function wp_redirect(string $url, int $status = 302): void {
    $GLOBALS['_wp_redirect_called'] = true;
    $GLOBALS['_wp_redirect_url']    = $url;
}

// --- 보안 ---
function wp_nonce_field(string $action, string $name): void {}
function check_admin_referer(string $action, string $name = '_wpnonce'): bool { return true; }
function current_user_can(string $cap, ...$args): bool { return true; }
function wp_unslash($value) { return $value; }
function sanitize_text_field(string $str): string { return trim(strip_tags($str)); }
function absint($value): int { return abs((int) $value); }

// --- WooCommerce 스텁 ---
function is_order_received_page(): bool {
    return $GLOBALS['_is_order_received_page'] ?? false;
}

function wc_get_order(int $order_id): ?\WC_Order {
    return $GLOBALS['_wc_orders'][$order_id] ?? null;
}

function get_query_var(string $key): mixed {
    return $GLOBALS['_query_vars'][$key] ?? '';
}

class WC_Order {
    private array $items;
    private int $id;
    private array $meta = [];
    private string $status = 'processing';

    public function __construct(array $items = [], int $id = 0) {
        $this->items = $items;
        $this->id    = $id;
    }

    public function get_items(): array      { return $this->items; }
    public function get_id(): int           { return $this->id; }
    public function key_is_valid(string $key): bool { return true; }
    public function get_status(): string    { return $this->status; }
    public function has_status(mixed $statuses): bool {
        return in_array($this->get_status(), (array) $statuses, true);
    }

    public function get_billing_first_name(): string { return ''; }
    public function get_billing_last_name(): string  { return ''; }
    public function get_billing_email(): string      { return ''; }
    public function get_billing_phone(): string      { return ''; }

    public function get_meta(string $key, bool $single = true): mixed { return $this->meta[$key] ?? ''; }
    public function update_meta_data(string $key, mixed $value): void { $this->meta[$key] = $value; }
    public function save_meta_data(): void {}
}

class WC_Order_Item_Product {
    public function __construct(
        private int $product_id,
        private float $total,
        private string $name = ''
    ) {}

    public function get_product_id(): int   { return $this->product_id; }
    public function get_total(): float      { return $this->total; }
    public function get_name(): string      { return $this->name; }
}

class WooCommerce {}

// wcor_is_url_domain_allowed() 등 전역 함수 로드
require_once __DIR__ . '/../wc-order-redirect.php';
