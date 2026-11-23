<?php
// Run from the CLI. HTTP responses and orders are test fixtures.
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function sanitize_text_field($v) { return strip_tags($v); }
function get_post_meta($id, $key, $single) { return $id === 1 ? 'Course, Course, -remove' : ''; }
function wp_salt($scheme) { return 'unit-test-only'; }
function wp_http_validate_url($url) { return filter_var($url, FILTER_VALIDATE_URL); }
function update_option($key, $value, $autoload) { $GLOBALS['saved'][$key] = $value; }
function get_option($key, $default) {
    if ($key === 'wcmt_connection') { return $GLOBALS['saved'][$key] ?? $default; }
    return $key === 'wcmt_store' ? 'Store A' : ($GLOBALS['annual_pattern'] ?? $default); }
function wp_timezone() { return new DateTimeZone('America/Sao_Paulo'); }
function wp_date($format, $ts, $tz) { return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($format); }
function wp_parse_url($url, $part) { return parse_url($url, $part); }
function wp_json_encode($obj) { return json_encode($obj); }
function wp_safe_remote_request($url, $opts) {
    $GLOBALS['calls'][] = [$url, $opts];
    if (!$GLOBALS['responses']) { throw new LogicException('Unexpected HTTP call'); }
    return array_shift($GLOBALS['responses']);
}
function is_wp_error($r) { return false; }
function wp_remote_retrieve_response_code($r) { return $r[0]; }
function wp_remote_retrieve_body($r) { return json_encode($r[1]); }
function wc_get_order($id) { return $GLOBALS['order']; }
function is_email($v) { return filter_var($v, FILTER_VALIDATE_EMAIL); }
function as_enqueue_async_action(...$args) { $GLOBALS['queue'][] = $args; }
function as_schedule_single_action(...$args) { $GLOBALS['retry'][] = $args; }

require dirname(__DIR__) . '/woo-mautic-purchase-tags.php';
function check($ok, $message) {
    if (!$ok) { throw new RuntimeException($message); }
    echo "OK $message\n";
}
PurchaseTags\save_connection('https://example.invalid', 'fixture', 'test-only');
check(PurchaseTags\connection_settings()['password'] === 'test-only', 'stored credential round trip');
check(strpos(serialize($GLOBALS['saved']), 'test-only') === false, 'password absent from stored options');
PurchaseTags\save_connection('https://example.invalid', 'fixture', '');
try {
    PurchaseTags\save_connection('https://other.invalid', 'fixture', '');
    throw new LogicException('Changed destination should require a password');
} catch (RuntimeException $expected) {
    check(PurchaseTags\connection_settings()['url'] === 'https://example.invalid', 'destination cannot reuse saved password');
}
define('WCMT_URL', 'https://example.invalid');
define('WCMT_USER', 'fixture');
define('WCMT_PASSWORD', 'fixture');
check(PurchaseTags\connection_settings()['password'] === WCMT_PASSWORD, 'server credentials override saved settings');
$order = new class {
    public $mapped = true, $paid = true, $meta = [], $notes = [];
    function get_items() { return $this->mapped ? [new class { function get_product_id() { return 1; } }] : []; }
    function get_date_paid() { return new DateTimeImmutable('2027-01-01T01:00:00Z'); }
    function is_paid() { return $this->paid; }
    function get_meta($k) { return $this->meta[$k] ?? ''; }
    function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    function save_meta_data() {}
    function get_billing_email() { return 'customer@example.invalid'; }
    function add_order_note($n) { $this->notes[] = $n; }
};
$tags = ['Course', 'Buyers 2026', 'Store A'];
check(PurchaseTags\purchase_tags($order) === $tags, 'store time zone, unique tags, and tag removal prevention');
$annual_pattern = 'Members {ano}';
check(in_array('Members 2026', PurchaseTags\purchase_tags($order), true), 'saved legacy year pattern still works');
unset($annual_pattern);
$order->mapped = false;
PurchaseTags\enqueue_order(10);
check(empty($queue), 'unmapped orders stay out of the queue');
$order->mapped = true;
$order->paid = false;
PurchaseTags\enqueue_order(10);
check(empty($queue), 'unpaid orders stay out of the queue');
$order->paid = true;
PurchaseTags\enqueue_order(10);
check(count($queue) === 1 && $order->get_meta(PurchaseTags\SNAPSHOT) === $tags, 'queued order has a tag snapshot');
$responses = [[503, []]];
PurchaseTags\run_job(10);
check(count($retry) === 1 && !$order->get_meta(PurchaseTags\DONE), 'transient failure schedules a retry');
$contact = ['id'=>9, 'fields'=>['all'=>['email'=>$order->get_billing_email()]]];
$responses = [[200, ['total'=>1, 'contacts'=>[$contact]]], [200, ['contact'=>['id'=>9]]]];
PurchaseTags\run_job(10, 2);
$last = end($calls);
check($last[1]['method'] === 'PATCH' && json_decode($last[1]['body'], true) === ['tags'=>$tags], 'existing contact receives only tag additions');
$count = count($calls);
PurchaseTags\run_job(10);
check(count($calls) === $count, 'confirmed order skips duplicate delivery');
$responses = [[200, ['total'=>0, 'contacts'=>[]]], [201, ['contact'=>['id'=>10]]]];
PurchaseTags\send_tags($order->get_billing_email(), $tags);
check(end($calls)[1]['method'] === 'POST', 'new contact created');
$responses = [[200, ['total'=>2, 'contacts'=>[$contact, $contact]]]];
try {
    PurchaseTags\send_tags($order->get_billing_email(), $tags);
    throw new LogicException('Duplicate email should stop the sync');
} catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 'Multiple contacts') !== false, 'duplicate email requires review');
}
