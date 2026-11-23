<?php
namespace PurchaseTags;

defined('ABSPATH') || exit;

function mapping_tag($value) {
    if (!is_string($value) || trim($value) === '' || trim($value)[0] === '-' ||
        strpos($value, ',') !== false || trim($value) !== sanitize_text_field(trim($value))) {
        throw new \RuntimeException('Use a tag name without commas, markup, or a leading hyphen.');
    }
    return trim($value);
}

function catalog_tags($search, $page) {
    $response = api('tags?' . http_build_query([
        'search' => $search, 'start' => ($page - 1) * 20, 'limit' => 20, 'orderBy' => 'id', 'orderByDir' => 'ASC',
    ], '', '&', PHP_QUERY_RFC3986));
    if (!isset($response['tags'], $response['total']) || !is_array($response['tags'])) {
        throw new \RuntimeException('Unexpected tag list from Mautic.');
    }
    return $response;
}

function mapping_url($changes = []) {
    $args = ['page' => 'wcmt'];
    foreach (['product', 'products_page', 'tags_page'] as $key) {
        $args[$key] = absint($_GET[$key] ?? 0);
    }
    foreach (['products_search', 'tags_search'] as $key) {
        $args[$key] = sanitize_text_field(wp_unslash($_GET[$key] ?? ''));
    }
    return add_query_arg(array_merge($args, $changes), admin_url('options-general.php'));
}

function mapping_action() {
    if (!isset($_POST['wcmt_mapping_action']) || ($_GET['page'] ?? '') !== 'wcmt') {
        return;
    }
    if (!current_user_can('manage_options')) {
        wp_die('Access denied.');
    }
    check_admin_referer('wcmt_mapping');
    $action = sanitize_key($_POST['wcmt_mapping_action']);
    $next = [];
    try {
        if ($action === 'create') {
            $name = mapping_tag(wp_unslash($_POST['tag_name'] ?? ''));
            $created = api('tags/new', 'POST', ['tag' => $name]);
            if (empty($created['tag']['id'])) {
                throw new \RuntimeException('Mautic did not confirm creation. Search the catalog before retrying.');
            }
            $next = ['tags_search' => $name, 'tags_page' => 1];
            $message = 'Tag created in Mautic.';
        } else {
            $id = absint($_GET['product'] ?? 0);
            if (!$id || get_post_type($id) !== 'product' || !current_user_can('edit_post', $id) ||
                !in_array(get_post_status($id), ['publish', 'draft', 'private', 'pending', 'future'], true)) {
                throw new \RuntimeException('Select an editable product.');
            }
            $raw = (string) get_post_meta($id, PRODUCT_KEY, true);
            $tags = clean_tags(explode(',', $raw));
            if ($action === 'link') {
                $tag_id = absint($_POST['tag_id'] ?? 0);
                if (!$tag_id) {
                    throw new \RuntimeException('Select an existing tag.');
                }
                $tag = api('tags/' . $tag_id);
                $tags[] = mapping_tag($tag['tag']['tag'] ?? '');
            } elseif ($action === 'unlink') {
                $name = wp_unslash($_POST['tag_name'] ?? '');
                $tags = array_diff($tags, [$name]);
            } else {
                throw new \RuntimeException('Unknown mapping action.');
            }
            $value = implode(', ', array_unique($tags));
            if ($raw !== $value && !update_post_meta($id, PRODUCT_KEY, wp_slash($value), $raw)) {
                throw new \RuntimeException('The mapping changed or could not be saved. Reload the page.');
            }
            $message = 'Product mapping saved.';
        }
        $notice = ['success', $message];
    } catch (\RuntimeException $e) {
        $notice = ['error', $e->getMessage()];
    }
    set_transient('wcmt_notice_' . get_current_user_id(), $notice, 60);
    wp_safe_redirect(mapping_url($next));
    exit;
}
add_action('admin_init', __NAMESPACE__ . '\\mapping_action');

function mapping_search($key, $label) {
    $params = [];
    parse_str(wp_parse_url(mapping_url([$key . '_page' => 1]), PHP_URL_QUERY), $params);
    $field = $key . '_search';
    ?>
    <form method="get" class="wcmt-search">
    <?php foreach ($params as $k => $v) : if ($k === $field) { continue; } ?>
      <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>">
    <?php endforeach; ?>
      <input type="search" aria-label="<?php echo esc_attr($label); ?>" placeholder="<?php echo esc_attr($label); ?>" name="<?php echo esc_attr($field); ?>" value="<?php echo esc_attr($params[$field]); ?>">
      <button class="button">Search</button>
    </form>
    <?php
}

function mapping_pages($key, $page, $pages) {
    if ($pages < 2) { return; }
    echo '<p class="wcmt-pager">';
    if ($page > 1) {
        echo '<a href="' . esc_url(mapping_url([$key => $page - 1])) . '">Previous</a> · ';
    }
    echo esc_html(sprintf('Page %d of %d', $page, $pages));
    if ($page < $pages) {
        echo ' · <a href="' . esc_url(mapping_url([$key => $page + 1])) . '">Next</a>';
    }
    echo '</p>';
}

function mapping_panel() {
    $notice_key = 'wcmt_notice_' . get_current_user_id();
    $notice = get_transient($notice_key);
    if ($notice) {
        delete_transient($notice_key);
        echo '<div class="notice notice-' . esc_attr($notice[0]) . '"><p>' . esc_html($notice[1]) . '</p></div>';
    }
    $id = absint($_GET['product'] ?? 0);
    $product = $id && get_post_type($id) === 'product' && current_user_can('edit_post', $id) ? wc_get_product($id) : false;
    $pp = max(1, absint($_GET['products_page'] ?? 1));
    $tp = max(1, absint($_GET['tags_page'] ?? 1));
    $products = new \WP_Query([
        'post_type' => 'product', 'post_status' => ['publish','draft','private','pending','future'],
        'posts_per_page' => 20, 'paged' => $pp, 's' => sanitize_text_field(wp_unslash($_GET['products_search'] ?? '')),
        'orderby' => ['title' => 'ASC', 'ID' => 'ASC'],
    ]);
    ?>
    <style>
    .wcmt-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;max-width:1050px}.wcmt-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin:12px 0}.wcmt-card h2{margin-top:0}.wcmt-card ul{max-height:19rem;overflow:auto;margin:10px 0}.wcmt-card li{padding:6px 2px;border-bottom:1px solid #eee}.wcmt-card a[aria-current=true]{font-weight:bold}.wcmt-search,.wcmt-row{display:flex;gap:8px;align-items:center}.wcmt-search input{min-width:0;flex:1}.wcmt-row{justify-content:space-between}.wcmt-map{max-width:1008px}.wcmt-pager{margin-bottom:0}@media(max-width:760px){.wcmt-grid{grid-template-columns:1fr;gap:0}}
    </style>
    <div class="wcmt-grid"><section class="wcmt-card"><h2>WooCommerce products</h2>
    <?php mapping_search('products', 'Find a product'); ?>
    <ul><?php foreach ($products->posts as $p) : ?>
      <li><a aria-current="<?php echo $p->ID === $id ? 'true' : 'false'; ?>" href="<?php echo esc_url(mapping_url(['product' => $p->ID])); ?>"><?php echo esc_html(get_the_title($p) . ' · #' . $p->ID); ?></a></li>
    <?php endforeach; ?></ul>
    <?php if (!$products->posts) { echo '<p>No products found.</p>'; } mapping_pages('products_page', $pp, $products->max_num_pages); ?>
    </section><section class="wcmt-card"><h2>Existing Mautic tags</h2>
    <?php mapping_search('tags', 'Find a tag'); ?>
    <form method="post"><?php wp_nonce_field('wcmt_mapping'); ?>
    <input type="hidden" name="wcmt_mapping_action" value="link">
    <?php try {
        $catalog = catalog_tags(sanitize_text_field(wp_unslash($_GET['tags_search'] ?? '')), $tp);
        echo '<ul>';
        foreach ($catalog['tags'] as $t) {
            if (!isset($t['id'], $t['tag'])) { continue; }
            echo '<li class="wcmt-row"><span>' . esc_html($t['tag']) . '</span>';
            if ($product) {
                echo '<button class="button" name="tag_id" value="' . esc_attr($t['id']) . '">Link tag</button>';
            }
            echo '</li>';
        }
        echo '</ul>';
        if (!$catalog['tags']) { echo '<p>No matching tags.</p>'; }
        mapping_pages('tags_page', $tp, ceil($catalog['total'] / 20));
    } catch (\RuntimeException $e) {
        echo '<p role="alert">' . esc_html($e->getMessage()) . ' Check the connection and tag permissions.</p>';
    } ?>
    </form>
    <details><summary>Create a new tag</summary><form method="post" class="wcmt-search">
    <?php wp_nonce_field('wcmt_mapping'); ?><input name="tag_name" aria-label="New tag name" placeholder="New tag name" required>
    <button class="button" name="wcmt_mapping_action" value="create">Create tag</button></form></details>
    </section></div><section class="wcmt-card wcmt-map"><h2>Product mapping</h2>
    <?php if ($product) :
        echo '<strong>' . esc_html($product->get_name() . ' · #' . $id) . '</strong>';
        $tags = clean_tags(explode(',', (string) get_post_meta($id, PRODUCT_KEY, true))); ?>
        <form method="post"><?php wp_nonce_field('wcmt_mapping'); ?>
        <input type="hidden" name="wcmt_mapping_action" value="unlink"><ul>
        <?php foreach ($tags as $tag) : ?><li class="wcmt-row"><?php echo esc_html($tag); ?>
        <button class="button" name="tag_name" value="<?php echo esc_attr($tag); ?>">Unlink</button></li><?php endforeach; ?>
        </ul></form><?php if (!$tags) { echo '<p>No linked tags yet.</p>'; } ?>
    <?php else : ?><p>Select a product, then choose a tag to link.</p><?php endif; ?>
    </section>
    <?php
}
