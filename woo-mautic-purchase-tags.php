<?php
/**
 * Plugin Name: Purchase Tags for WooCommerce and Mautic
 * Description: Apply Mautic tags when a customer buys a mapped WooCommerce product.
 * Version: 0.3.0
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

require_once __DIR__ . '/product-mappings.php';

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

function connection_settings() {
    if (defined('WCMT_URL') || defined('WCMT_USER') || defined('WCMT_PASSWORD')) {
        return [
            'url' => defined('WCMT_URL') ? WCMT_URL : '',
            'user' => defined('WCMT_USER') ? WCMT_USER : '',
            'password' => defined('WCMT_PASSWORD') ? WCMT_PASSWORD : '',
        ];
    }
    $saved = get_option('wcmt_connection', []);
    $password = '';
    if (!empty($saved['secret']) && function_exists('sodium_crypto_secretbox_open')) {
        $raw = base64_decode($saved['secret'], true);
        if ($raw !== false && strlen($raw) >= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $password = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), hash('sha256', wp_salt('auth'), true)) ?: '';
        }
    }
    return ['url' => $saved['url'] ?? '', 'user' => $saved['user'] ?? '', 'password' => $password];
}

function save_connection($url, $user, $password) {
    if (!wp_http_validate_url($url) || wp_parse_url($url, PHP_URL_SCHEME) !== 'https' ||
        wp_parse_url($url, PHP_URL_QUERY) !== null || wp_parse_url($url, PHP_URL_FRAGMENT) !== null ||
        !$user || strpos($user, ':') !== false) {
        throw new \RuntimeException('Enter a valid HTTPS base URL and API username.');
    }
    $saved = get_option('wcmt_connection', []);
    if ($password === '') {
        if (($saved['url'] ?? '') !== $url || ($saved['user'] ?? '') !== $user || empty($saved['secret'])) {
            throw new \RuntimeException('Enter the password when setting or changing the URL or username.');
        }
        return;
    }
    if (!function_exists('sodium_crypto_secretbox')) {
        throw new \RuntimeException('Enable PHP Sodium or use the wp-config.php settings.');
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $secret = sodium_crypto_secretbox($password, $nonce, hash('sha256', wp_salt('auth'), true));
    update_option('wcmt_connection', ['url' => $url, 'user' => $user, 'secret' => base64_encode($nonce . $secret)], false);
}

function connection_form() {
    $managed = defined('WCMT_URL') || defined('WCMT_USER') || defined('WCMT_PASSWORD');
    if (isset($_POST['wcmt_action'])) {
        check_admin_referer('wcmt_connection');
        try {
            if ($_POST['wcmt_action'] === 'save' && !$managed) {
                save_connection(rtrim(trim(wp_unslash($_POST['wcmt_url'] ?? '')), '/'), trim(wp_unslash($_POST['wcmt_user'] ?? '')), wp_unslash($_POST['wcmt_password'] ?? ''));
                $message = 'Connection settings saved.';
            } else {
                $result = api('contacts?limit=1');
                if (!isset($result['contacts'], $result['total']) || !is_array($result['contacts'])) {
                    throw new \RuntimeException('Unexpected contacts response.');
                }
                $message = 'Connection successful. The API can read contacts.';
            }
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        } catch (\RuntimeException $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
    $cfg = connection_settings();
    ?>
    <h2>Mautic API connection</h2>
    <form method="post"><?php wp_nonce_field('wcmt_connection'); ?>
    <?php if ($managed) : ?>
        <p>The connection is managed in wp-config.php.</p>
    <?php else : ?>
        <p><label>Mautic URL <input type="url" name="wcmt_url" value="<?php echo esc_attr($cfg['url']); ?>" placeholder="https://mautic.example.org"></label></p>
        <p><label>API username <input name="wcmt_user" value="<?php echo esc_attr($cfg['user']); ?>"></label></p>
        <p><label>API password <input type="password" name="wcmt_password" autocomplete="new-password"></label> Leave blank to keep the saved password.</p>
        <button class="button" name="wcmt_action" value="save">Save connection</button>
    <?php endif; ?>
    <button class="button" name="wcmt_action" value="test">Test saved connection</button>
    </form>
    <?php
}

add_action('admin_menu', function () {
    add_options_page('Purchase Tags', 'Purchase Tags', 'manage_options', 'wcmt', function () {
        ?>
        <div class="wrap"><h1>Mautic purchase tags</h1>
        <?php if (function_exists('wc_get_product')) { mapping_panel(); } ?>
        <?php connection_form(); ?>
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
    $cfg = connection_settings();
    if (!$cfg['user'] || !$cfg['password'] || wp_parse_url($cfg['url'], PHP_URL_SCHEME) !== 'https') {
        throw new \RuntimeException('Check the HTTPS connection settings.');
    }
    $options = [
        'method' => $method, 'timeout' => 20, 'redirection' => 0,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($cfg['user'] . ':' . $cfg['password']),
            'Accept' => 'application/json',
        ],
    ];
    if ($payload !== null) {
        $options['headers']['Content-Type'] = 'application/json';
        $options['body'] = wp_json_encode($payload);
    }
    $response = wp_safe_remote_request(rtrim($cfg['url'], '/') . '/api/' . $path, $options);
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
