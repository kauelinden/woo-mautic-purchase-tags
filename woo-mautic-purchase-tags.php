<?php
/**
 * Plugin Name: Purchase Tags for WooCommerce and Mautic
 * Description: Apply Mautic tags when a customer buys a mapped WooCommerce product.
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
        // Mautic treats a leading hyphen as a request to remove a tag.
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
    $tags[] = str_replace(['{year}', '{ano}'], $year, get_option('wcmt_year', 'Buyers {year}'));
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
        'id' => PRODUCT_KEY, 'label' => 'Mautic tags',
        'desc_tip' => true,
        'description' => 'Separate tags with commas. Leave blank to skip this product. Variations inherit the parent product’s tags.',
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
    add_options_page('Purchase Tags', 'Purchase Tags', 'manage_options', 'wcmt', function () {
        ?>
        <div class="wrap"><h1>Mautic purchase tags</h1>
        <p>Under Products, open a product and enter its Mautic tags on the General tab.
        See the README for instructions on setting WCMT_URL, WCMT_USER, and WCMT_PASSWORD.</p>
        <form method="post" action="options.php"><?php settings_fields('wcmt'); ?>
        <p><label>Store tag <input name="wcmt_store" value="<?php echo esc_attr(get_option('wcmt_store', '')); ?>" placeholder="Store - Example"></label></p>
        <p><label>Annual tag <input name="wcmt_year" value="<?php echo esc_attr(get_option('wcmt_year', 'Buyers {year}')); ?>"></label></p>
        <p>Use {year} for the payment year. Leave a field blank to skip that tag.</p>
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
        throw new \RuntimeException('Check the HTTPS connection settings.');
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
        throw new \RuntimeException('Could not reach Mautic.', 503);
    }
    $status = wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        throw new \RuntimeException('HTTP response ' . $status, $status);
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body) || !empty($body['errors'])) {
        throw new \RuntimeException('Unexpected response from Mautic.');
    }
    return $body;
}

function send_tags($email, $tags) {
    $query = http_build_query(['search' => 'email:' . $email, 'limit' => 100], '', '&', PHP_QUERY_RFC3986);
    $list = api('contacts?' . $query);
    if (!isset($list['contacts'], $list['total']) || !is_array($list['contacts']) || $list['total'] > count($list['contacts'])) {
        throw new \RuntimeException('Incomplete search results. Check the contacts in Mautic.');
    }
    $ids = [];
    foreach ($list['contacts'] as $contact) {
        $address = $contact['fields']['all']['email'] ?? $contact['fields']['core']['email']['value'] ?? null;
        if (!is_string($address)) {
            throw new \RuntimeException('The API did not return an email address for verification.');
        }
        if (strcasecmp($address, $email) === 0) {
            if (empty($contact['id'])) {
                throw new \RuntimeException('Contact ID is missing.');
            }
            $ids[] = (int) $contact['id'];
        }
    }
    if (count($ids) > 1) {
        throw new \RuntimeException('Multiple contacts have this email address.');
    }
    $saved = $ids
        ? api('contacts/' . $ids[0] . '/edit', 'PATCH', ['tags' => $tags])
        : api('contacts/new', 'POST', ['email' => $email, 'tags' => $tags]);
    if (empty($saved['contact']['id'])) {
        throw new \RuntimeException('Mautic did not confirm the contact ID.');
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
        $order->add_order_note('Mautic: check the billing email and product tags before retrying.');
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
            $order->add_order_note('Mautic: sync pending. ' . $error->getMessage());
        }
        return;
    }
    $order->update_meta_data(DONE, 'yes');
    $order->save_meta_data();
    $order->add_order_note('Mautic: purchase tags synced.');
}

add_action(JOB, __NAMESPACE__ . '\\run_job', 10, 2);
add_filter('woocommerce_order_actions', function ($actions) {
    $actions['wcmt_retry'] = 'Mautic: retry pending purchase tags';
    return $actions;
});
add_action('woocommerce_order_action_wcmt_retry', function ($order) {
    enqueue_order($order->get_id());
});
