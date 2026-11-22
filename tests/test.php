<?php
// Execute somente por CLI. As respostas HTTP e os dados de pedidos são fictícios.
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__);
define('WCMT_URL', 'https://example.invalid');
define('WCMT_USER', 'fixture');
define('WCMT_PASSWORD', 'fixture');
function add_action(...$args) {}
function add_filter(...$args) {}
function sanitize_text_field($v) { return strip_tags($v); }
function get_post_meta($id, $key, $single) { return $id === 1 ? 'Curso, Curso, -remover' : ''; }
function get_option($key, $default) { return $key === 'wcmt_store' ? 'Loja A' : $default; }
function wp_timezone() { return new DateTimeZone('America/Sao_Paulo'); }
function wp_date($format, $ts, $tz) { return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($format); }
function wp_parse_url($url, $part) { return parse_url($url, $part); }
function wp_json_encode($obj) { return json_encode($obj); }
function wp_safe_remote_request($url, $opts) {
    $GLOBALS['calls'][] = [$url, $opts];
    if (!$GLOBALS['responses']) { throw new LogicException('Chamada HTTP inesperada'); }
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
$order = new class {
    public $mapped = true, $paid = true, $meta = [], $notes = [];
    function get_items() { return $this->mapped ? [new class { function get_product_id() { return 1; } }] : []; }
    function get_date_paid() { return new DateTimeImmutable('2027-01-01T01:00:00Z'); }
    function is_paid() { return $this->paid; }
    function get_meta($k) { return $this->meta[$k] ?? ''; }
    function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    function save_meta_data() {}
    function get_billing_email() { return 'cliente@example.invalid'; }
    function add_order_note($n) { $this->notes[] = $n; }
};
$tags = ['Curso', 'Compradores 2026', 'Loja A'];
check(PurchaseTags\purchase_tags($order) === $tags, 'fuso da loja, tags únicas e proteção contra remoção');
$order->mapped = false;
PurchaseTags\enqueue_order(10);
check(empty($queue), 'pedido sem mapeamento fora da fila');
$order->mapped = true;
$order->paid = false;
PurchaseTags\enqueue_order(10);
check(empty($queue), 'pedido não pago fora da fila');
$order->paid = true;
PurchaseTags\enqueue_order(10);
check(count($queue) === 1 && $order->get_meta(PurchaseTags\SNAPSHOT) === $tags, 'fila e tags congeladas');
$responses = [[503, []]];
PurchaseTags\run_job(10);
check(count($retry) === 1 && !$order->get_meta(PurchaseTags\DONE), 'falha temporária reagendada');
$contact = ['id'=>9, 'fields'=>['all'=>['email'=>$order->get_billing_email()]]];
$responses = [[200, ['total'=>1, 'contacts'=>[$contact]]], [200, ['contact'=>['id'=>9]]]];
PurchaseTags\run_job(10, 2);
$last = end($calls);
check($last[1]['method'] === 'PATCH' && json_decode($last[1]['body'], true) === ['tags'=>$tags], 'contato existente recebe apenas acréscimos');
$count = count($calls);
PurchaseTags\run_job(10);
check(count($calls) === $count, 'pedido confirmado ignora repetição');
$responses = [[200, ['total'=>0, 'contacts'=>[]]], [201, ['contact'=>['id'=>10]]]];
PurchaseTags\send_tags($order->get_billing_email(), $tags);
check(end($calls)[1]['method'] === 'POST', 'novo contato criado');
$responses = [[200, ['total'=>2, 'contacts'=>[$contact, $contact]]]];
try {
    PurchaseTags\send_tags($order->get_billing_email(), $tags);
    throw new LogicException('Duplicidade deveria interromper');
} catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 'duplicados') !== false, 'duplicidade exige revisão');
}
