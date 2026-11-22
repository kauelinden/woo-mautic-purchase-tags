<?php
/**
 * Plugin Name: Purchase Tags for WooCommerce and Mautic
 * Description: Associa compras de produtos selecionados a tags no Mautic.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 */

namespace PurchaseTags;

defined('ABSPATH') || exit;

const JOB = 'wc_mautic_purchase_sync';
const GROUP = 'wc-mautic-purchase-tags';
const PRODUCT_KEY = '_wc_mautic_purchase_tags';
const SNAPSHOT = '_wc_mautic_purchase_snapshot';
const DONE = '_wc_mautic_purchase_done';

function clean_tags($values) {
    $result = [];
    foreach ($values as $value) {
        $tag = trim(sanitize_text_field($value));
        // Um hífen inicial é uma instrução de remoção na API do Mautic.
        if ($tag !== '' && $tag[0] !== '-') {
            $result[] = $tag;
        }
    }
    return array_values(array_unique($result));
}

function purchase_tags($order) {
    $tags = [];
    foreach ($order->get_items() as $item) {
        $mapped = get_post_meta($item->get_product_id(), PRODUCT_KEY, true);
        $tags = array_merge($tags, explode(',', (string) $mapped));
    }
    $tags = clean_tags($tags);
    $paid = $order->get_date_paid();
    if (!$tags || !$paid) {
        return [];
    }
    $year = wp_date('Y', $paid->getTimestamp(), wp_timezone());
    $tags[] = str_replace('{ano}', $year, get_option('wcmt_year', 'Compradores {ano}'));
    $tags[] = get_option('wcmt_store', '');
    return clean_tags($tags);
}

add_action('before_woocommerce_init', function () {
    if (class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('woocommerce_product_options_general_product_data', function () {
    woocommerce_wp_text_input([
        'id' => PRODUCT_KEY, 'label' => 'Tags no Mautic',
        'desc_tip' => true,
        'description' => 'Separe as tags por vírgula. Em branco, o produto fica fora do mapeamento. As variações herdam o produto principal.',
    ]);
});

add_action('woocommerce_admin_process_product_object', function ($product) {
    if (current_user_can('edit_post', $product->get_id()) && isset($_POST[PRODUCT_KEY]) && is_string($_POST[PRODUCT_KEY])) {
        $tags = clean_tags(explode(',', wp_unslash($_POST[PRODUCT_KEY])));
        $product->update_meta_data(PRODUCT_KEY, implode(', ', $tags));
    }
});

add_action('admin_init', function () {
    foreach (['wcmt_store', 'wcmt_year'] as $option) {
        register_setting('wcmt', $option, ['sanitize_callback' => 'sanitize_text_field']);
    }
});

add_action('admin_menu', function () {
    add_options_page('Tags de compras', 'Tags de compras', 'manage_options', 'wcmt', function () {
        ?>
        <div class="wrap"><h1>Tags de compras no Mautic</h1>
        <p>Em Produtos, abra um produto e preencha “Tags no Mautic” na aba Geral.
        A conexão usa as constantes WCMT_URL, WCMT_USER e WCMT_PASSWORD descritas no README.</p>
        <form method="post" action="options.php"><?php settings_fields('wcmt'); ?>
        <p><label>Tag da loja <input name="wcmt_store" value="<?php echo esc_attr(get_option('wcmt_store', '')); ?>" placeholder="Loja - Exemplo"></label></p>
        <p><label>Tag anual <input name="wcmt_year" value="<?php echo esc_attr(get_option('wcmt_year', 'Compradores {ano}')); ?>"></label></p>
        <p>Use {ano} para o ano do pagamento. Um campo vazio desativa aquela tag.</p>
        <?php submit_button(); ?></form></div>
        <?php
    });
});

function enqueue_order($id) {
    $order = wc_get_order($id);
    if (!$order || !$order->is_paid() || $order->get_meta(DONE) || !function_exists('as_enqueue_async_action')) {
        return;
    }
    if (!$order->get_meta(SNAPSHOT)) {
        $tags = purchase_tags($order);
        if (!$tags) {
            return;
        }
        $order->update_meta_data(SNAPSHOT, $tags);
        $order->save_meta_data();
    }
    as_enqueue_async_action(JOB, [(int) $id, 1], GROUP, true);
}

foreach (['payment_complete', 'order_status_processing', 'order_status_completed'] as $event) {
    add_action('woocommerce_' . $event, __NAMESPACE__ . '\\enqueue_order', 20);
}

function api($path, $method = 'GET', $payload = null) {
    if (!defined('WCMT_URL') || !defined('WCMT_USER') || !defined('WCMT_PASSWORD') ||
        !WCMT_USER || !WCMT_PASSWORD || wp_parse_url(WCMT_URL, PHP_URL_SCHEME) !== 'https') {
        throw new \RuntimeException('Confira as constantes da conexão HTTPS.');
    }
    $options = [
        'method' => $method, 'timeout' => 20, 'redirection' => 0,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode(WCMT_USER . ':' . WCMT_PASSWORD),
            'Accept' => 'application/json',
        ],
    ];
    if ($payload !== null) {
        $options['headers']['Content-Type'] = 'application/json';
        $options['body'] = wp_json_encode($payload);
    }
    $response = wp_safe_remote_request(rtrim(WCMT_URL, '/') . '/api/' . $path, $options);
    if (is_wp_error($response)) {
        throw new \RuntimeException('Falha de transporte.', 503);
    }
    $status = wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        throw new \RuntimeException('Resposta HTTP ' . $status, $status);
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body) || !empty($body['errors'])) {
        throw new \RuntimeException('Resposta inesperada do Mautic.');
    }
    return $body;
}

function send_tags($email, $tags) {
    $query = http_build_query(['search' => 'email:' . $email, 'limit' => 100], '', '&', PHP_QUERY_RFC3986);
    $list = api('contacts?' . $query);
    if (!isset($list['contacts'], $list['total']) || !is_array($list['contacts']) || $list['total'] > count($list['contacts'])) {
        throw new \RuntimeException('Busca incompleta. Confira os contatos no Mautic.');
    }
    $ids = [];
    foreach ($list['contacts'] as $contact) {
        $address = $contact['fields']['all']['email'] ?? $contact['fields']['core']['email']['value'] ?? null;
        if (!is_string($address)) {
            throw new \RuntimeException('A API não retornou o e-mail para conferência.');
        }
        if (strcasecmp($address, $email) === 0) {
            if (empty($contact['id'])) {
                throw new \RuntimeException('Contato sem identificador.');
            }
            $ids[] = (int) $contact['id'];
        }
    }
    if (count($ids) > 1) {
        throw new \RuntimeException('Há contatos duplicados com este e-mail.');
    }
    $saved = $ids
        ? api('contacts/' . $ids[0] . '/edit', 'PATCH', ['tags' => $tags])
        : api('contacts/new', 'POST', ['email' => $email, 'tags' => $tags]);
    if (empty($saved['contact']['id'])) {
        throw new \RuntimeException('O Mautic não confirmou o contato.');
    }
}

function run_job($id, $attempt = 1) {
    $order = wc_get_order($id);
    if (!$order || !$order->is_paid() || $order->get_meta(DONE)) {
        return;
    }
    $tags = $order->get_meta(SNAPSHOT);
    $email = $order->get_billing_email();
    if (!$tags || !is_email($email)) {
        $order->add_order_note('Mautic: confira o e-mail de cobrança e o mapeamento antes de reenviar.');
        return;
    }
    try {
        send_tags($email, $tags);
    } catch (\RuntimeException $error) {
        $delays = [1 => 60, 2 => 300, 3 => 1800, 4 => 7200];
        $code = $error->getCode();
        if (isset($delays[$attempt]) && ($code >= 500 || in_array($code, [408, 429], true))) {
            as_schedule_single_action(time() + $delays[$attempt], JOB, [(int) $id, $attempt + 1], GROUP, true);
        } else {
            $order->add_order_note('Mautic: sincronização pendente. ' . $error->getMessage());
        }
        return;
    }
    $order->update_meta_data(DONE, 'yes');
    $order->save_meta_data();
    $order->add_order_note('Mautic: tags de compra sincronizadas.');
}

add_action(JOB, __NAMESPACE__ . '\\run_job', 10, 2);
add_filter('woocommerce_order_actions', function ($actions) {
    $actions['wcmt_retry'] = 'Mautic: tentar sincronizar tags pendentes';
    return $actions;
});
add_action('woocommerce_order_action_wcmt_retry', function ($order) {
    enqueue_order($order->get_id());
});
