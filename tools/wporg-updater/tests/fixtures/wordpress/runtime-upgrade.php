<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/support/WordpressSmokeRunner.php';

$root = getenv('WP_CORE_BASE_SMOKE_RUNTIME');
$runId = getenv('WP_CORE_BASE_SMOKE_RUN_ID');
$storage = getenv('WP_CORE_BASE_UPGRADE_STORAGE');
$phase = $argv[1] ?? '';
if (! is_string($root) || ! is_file($root . '/wp-config.php')
    || ! is_string($runId) || preg_match('/^[a-f0-9]{12}$/D', $runId) !== 1
    || ! in_array($storage, ['hpos', 'posts'], true)
    || ! in_array($phase, ['configure', 'seed', 'verify-baseline', 'migrate', 'verify-upgraded', 'verify-checkout', 'verify-rollback'], true)) {
    throw new RuntimeException('Invalid isolated WordPress upgrade fixture configuration.');
}
$_SERVER['HTTP_HOST'] = 'wp-core-base.invalid';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
require $root . '/wp-load.php';

function upgradeAssert(bool $condition, string $description): void
{
    if (! $condition) {
        throw new RuntimeException('Upgrade assertion failed: ' . $description);
    }
}

function upgradeMoney(mixed $actual, string $expected, string $description): void
{
    upgradeAssert(wc_format_decimal($actual, 2) === $expected, $description . ' (actual ' . (string) $actual . ')');
}

if ($phase === 'configure') {
    upgradeAssert($wp_version === '6.9.9' && WC_VERSION === '11.0.1', 'baseline must be the exact previous release');
    update_option('woocommerce_custom_orders_table_enabled', $storage === 'hpos' ? 'yes' : 'no');
    update_option('woocommerce_custom_orders_table_data_sync_enabled', 'no');
    update_option('woocommerce_currency', 'CHF');
    update_option('woocommerce_price_num_decimals', '2');
    update_option('woocommerce_calc_taxes', 'yes');
    update_option('woocommerce_prices_include_tax', 'no');
    update_option('woocommerce_tax_based_on', 'billing');
    update_option('woocommerce_default_country', 'CH');
    update_option('woocommerce_coming_soon', 'no');
    update_option('woocommerce_task_list_completed_lists', ['setup']);
} elseif ($phase === 'seed') {
    // Exercise the identifier boundary that previously hid WooCommerce table errors.
    $longTable = $wpdb->prefix . str_repeat('z', 64 - strlen($wpdb->prefix));
    upgradeAssert($wpdb->query('CREATE TABLE `' . $longTable . '` (id bigint PRIMARY KEY, value varchar(64) NOT NULL)') !== false, 'create maximum-length fixture table');
    upgradeAssert($wpdb->insert($longTable, ['id' => 1, 'value' => 'preserved-table-boundary']) === 1, 'seed maximum-length fixture table');
    $download = new WC_Customer_Download();
    $download->set_download_id(md5('fixture-download'));
    $download->set_product_id(0);
    $download->set_user_email('fixture@example.invalid');
    $download->set_downloads_remaining('3');
    $download->save();
    upgradeAssert($download->get_id() > 0, 'WooCommerce downloadable-product permissions table is usable');
    $rateId = WC_Tax::_insert_tax_rate([
        'tax_rate_country' => 'CH', 'tax_rate_state' => '', 'tax_rate' => '10.0000',
        'tax_rate_name' => 'Fixture tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0,
        'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => '',
    ]);
    upgradeAssert($rateId > 0, 'create tax rate');
    $simple = new WC_Product_Simple();
    $simple->set_name('Upgrade fixture simple');
    $simple->set_status('publish');
    $simple->set_sku('fixture-simple');
    $simple->set_regular_price('20.00');
    $simple->set_virtual(true);
    $simple->set_manage_stock(true);
    $simple->set_stock_quantity(12);
    $simple->save();
    $attribute = new WC_Product_Attribute();
    $attribute->set_name('Size');
    $attribute->set_options(['small', 'large']);
    $attribute->set_variation(true);
    $attribute->set_visible(true);
    $variable = new WC_Product_Variable();
    $variable->set_name('Upgrade fixture variable');
    $variable->set_status('publish');
    $variable->set_attributes([$attribute]);
    $variable->save();
    $variation = new WC_Product_Variation();
    $variation->set_parent_id($variable->get_id());
    $variation->set_attributes(['size' => 'small']);
    $variation->set_regular_price('30.00');
    $variation->set_virtual(true);
    $variation->set_manage_stock(true);
    $variation->set_stock_quantity(8);
    $variation->save();
    WC_Product_Variable::sync($variable->get_id());
    $coupon = new WC_Coupon();
    $coupon->set_code('fixture-five');
    $coupon->set_discount_type('fixed_cart');
    $coupon->set_amount('5.00');
    $coupon->save();
    $customerId = wp_create_user('fixture-customer', bin2hex(random_bytes(24)), 'fixture@example.invalid');
    upgradeAssert(! is_wp_error($customerId), 'create customer');
    $customer = new WC_Customer($customerId);
    $customer->set_billing_country('CH');
    $customer->set_shipping_country('CH');
    $customer->set_billing_postcode('8000');
    $customer->save();
    $order = wc_create_order(['customer_id' => $customerId]);
    upgradeAssert($order instanceof WC_Order, 'create processing order');
    $order->set_billing_country('CH');
    $order->set_billing_email('fixture@example.invalid');
    $order->add_product($simple, 2);
    $order->add_product($variation, 1);
    $order->apply_coupon('fixture-five');
    $order->calculate_totals();
    $order->update_meta_data('_fixture_preserved', ['reference' => 'before-upgrade', 'number' => 42]);
    $order->set_status('processing');
    $order->save();
    wc_reduce_stock_levels($order->get_id());
    $pending = wc_create_order(['customer_id' => $customerId]);
    upgradeAssert($pending instanceof WC_Order, 'create pending order');
    $pending->set_billing_country('CH');
    $pending->add_product($simple, 1);
    $pending->calculate_totals();
    $pending->save();
    $download->set_product_id($simple->get_id());
    $download->set_order_id($order->get_id());
    $download->set_order_key($order->get_order_key());
    $download->set_user_id($customerId);
    $download->save();
    upgradeMoney($order->get_total(), '71.50', 'seed order total');
    upgradeMoney($pending->get_total(), '22.00', 'seed pending total');
    $defaultEmail = wp_insert_post(['post_type' => 'woo_email', 'post_status' => 'publish', 'post_title' => 'Default fixture email', 'post_content' => '<p>Untouched source email</p>'], true);
    $customEmail = wp_insert_post(['post_type' => 'woo_email', 'post_status' => 'publish', 'post_title' => 'Custom fixture email', 'post_content' => '<p>Merchant customized email</p>'], true);
    upgradeAssert(is_int($defaultEmail) && $defaultEmail > 0 && is_int($customEmail) && $customEmail > 0, 'create email migration cases');
    update_post_meta($defaultEmail, '_wc_email_template_source_hash', sha1('<p>Untouched source email</p>'));
    update_post_meta($customEmail, '_wc_email_template_source_hash', sha1('<p>Original source email</p>'));
    update_option('woocommerce_email_templates_customer_new_account_post_id', $defaultEmail);
    update_option('woocommerce_email_templates_customer_processing_order_post_id', $customEmail);
    set_transient('wc_email_editor_initial_templates_generated', 'fixture-legacy', DAY_IN_SECONDS);
    set_transient('wc_outofstock_count', 12345, DAY_IN_SECONDS);
    update_option('wc_feature_woocommerce_additional_variation_images_enabled', 'yes');
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $pluginVersions = [];
    foreach (get_option('active_plugins') as $plugin) {
        $pluginVersions[$plugin] = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin, false, false)['Version'];
    }
    $state = [
        'core' => $wp_version, 'core_db' => get_option('db_version'), 'woo' => WC_VERSION, 'woo_db' => get_option('woocommerce_db_version'),
        'simple' => $simple->get_id(), 'variable' => $variable->get_id(), 'variation' => $variation->get_id(),
        'order' => $order->get_id(), 'pending_order' => $pending->get_id(), 'customer' => $customerId,
        'default_email' => $defaultEmail, 'custom_email' => $customEmail,
        'plugins' => get_option('active_plugins'), 'plugin_versions' => $pluginVersions, 'storage' => $storage,
        'long_table' => $longTable, 'download' => $download->get_id(),
    ];
    update_option('wp_core_base_upgrade_fixture', $state, false);
    fwrite(STDOUT, sprintf("Seeded %s orders, simple/variable products, coupon, tax, customer and email migration cases on WordPress %s / WooCommerce %s.\n", $storage, $wp_version, WC_VERSION));
} elseif ($phase === 'migrate') {
    $state = get_option('wp_core_base_upgrade_fixture');
    upgradeAssert(is_array($state), 'persisted baseline fixture');
    upgradeAssert(version_compare($wp_version, $state['core'], '>') && version_compare(WC_VERSION, $state['woo'], '>'), 'candidate must actually advance core and WooCommerce');
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    wp_upgrade();
    WC_Install::check_version();
    $store = ActionScheduler::store();
    $runner = ActionScheduler::runner();
    $executed = [];
    for ($iteration = 0; $iteration < 100; ++$iteration) {
        $actions = $store->query_actions(['group' => 'woocommerce-db-updates', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50, 'orderby' => 'date', 'order' => 'ASC']);
        if ($actions === []) { break; }
        foreach ($actions as $actionId) {
            $action = $store->fetch_action($actionId);
            $executed[] = ['hook' => $action->get_hook(), 'args' => $action->get_args()];
            $runner->process_action($actionId, 'wp-core-base disposable migration');
            upgradeAssert($store->get_status($actionId) === ActionScheduler_Store::STATUS_COMPLETE, 'scheduled WooCommerce migration action must complete');
        }
    }
    upgradeAssert($executed !== [], 'at least one real scheduled WooCommerce migration must execute');
    $executedCallbacks = [];
    foreach ($executed as $action) {
        if ($action['hook'] === 'woocommerce_run_update_callback') {
            $executedCallbacks[] = $action['args']['update_callback'];
        }
    }
    foreach (WC_Install::get_db_update_callbacks() as $version => $callbacks) {
        if (version_compare($state['woo_db'], $version, '<')) {
            foreach ($callbacks as $callback) {
                upgradeAssert(in_array($callback, $executedCallbacks, true), 'required database callback executed: ' . wp_json_encode($callback));
            }
        }
    }
    upgradeAssert($store->query_actions(['group' => 'woocommerce-db-updates', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1]) === [], 'migration queue must drain within the bounded budget');
    upgradeAssert($store->query_actions(['group' => 'woocommerce-db-updates', 'status' => ActionScheduler_Store::STATUS_FAILED, 'per_page' => 1]) === [], 'no failed WooCommerce migrations');
    upgradeAssert(! WC_Install::needs_db_update(), 'WooCommerce database schema up to date');
    upgradeAssert((string) get_option('db_version') === (string) $wp_db_version, 'WordPress database schema up to date');
    update_option('wp_core_base_upgrade_executed', $executed, false);
    fwrite(STDOUT, sprintf("Migrated WordPress %s → %s and WooCommerce %s → %s through %d real queued actions: %s\n", $state['core'], $wp_version, $state['woo'], WC_VERSION, count($executed), wp_json_encode($executed)));
} elseif ($phase === 'verify-checkout') {
    $state = get_option('wp_core_base_upgrade_fixture');
    $orderId = get_option('wp_core_base_upgrade_checkout_order');
    $order = is_numeric($orderId) ? wc_get_order((int) $orderId) : false;
    upgradeAssert($order instanceof WC_Order && $order->get_id() !== $state['order'], 'new checkout order persisted across a fresh process');
    upgradeAssert($order->get_customer_id() === $state['customer'] && $order->get_status() === 'pending' && count($order->get_items()) === 2, 'new checkout order ownership, status and items');
    upgradeMoney($order->get_total(), '71.50', 'new checkout persisted total');
    upgradeMoney($order->get_total_tax(), '6.50', 'new checkout persisted tax');
    upgradeMoney($order->get_discount_total(), '5.00', 'new checkout persisted discount');
    upgradeAssert(str_contains($order->get_data_store()->get_current_class_name(), 'OrdersTableDataStore') === ($storage === 'hpos'), 'new checkout uses expected storage');
    fwrite(STDOUT, "Verified fresh-process persistence of a new checkout order created after migration, without payment.\n");
} else {
    $state = get_option('wp_core_base_upgrade_fixture');
    upgradeAssert(is_array($state), 'fixture persisted across process/runtime changes');
    upgradeAssert(get_option('active_plugins') === $state['plugins'], 'active plugin inventory preserved');
    upgradeAssert($wpdb->get_var('SELECT value FROM `' . $state['long_table'] . '` WHERE id = 1') === 'preserved-table-boundary', 'maximum-length table retained/restored');
    $download = new WC_Customer_Download($state['download']);
    upgradeAssert($download->get_downloads_remaining() === 3 && $download->get_user_email() === 'fixture@example.invalid'
        && $download->get_product_id() === $state['simple'] && $download->get_order_id() === $state['order'], 'download permissions retained/restored');
    upgradeAssert(in_array(WPMU_PLUGIN_DIR . '/wp-core-base-admin-governance.php', wp_get_mu_plugins(), true), 'governance MU plugin loaded');
    upgradeAssert(wp_get_theme()->exists(), 'configured theme is present');
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $expectedPluginVersions = $phase === 'verify-upgraded'
        ? json_decode((string) getenv('WP_CORE_BASE_UPGRADE_PLUGIN_VERSIONS'), true, 512, JSON_THROW_ON_ERROR)
        : $state['plugin_versions'];
    foreach ($expectedPluginVersions as $plugin => $version) {
        upgradeAssert(get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin, false, false)['Version'] === $version, 'correct plugin version loaded: ' . $plugin);
    }
    upgradeAssert(get_option('woocommerce_currency') === 'CHF', 'currency preserved');
    upgradeAssert(get_option('woocommerce_custom_orders_table_enabled') === ($storage === 'hpos' ? 'yes' : 'no'), 'order storage selection preserved');
    $dataStore = WC_Data_Store::load('order')->get_current_class_name();
    upgradeAssert(str_contains($dataStore, 'OrdersTableDataStore') === ($storage === 'hpos'), 'actual order datastore matches requested mode');
    $simple = wc_get_product($state['simple']);
    $variable = wc_get_product($state['variable']);
    $variation = wc_get_product($state['variation']);
    upgradeAssert($simple instanceof WC_Product_Simple && $variable instanceof WC_Product_Variable && $variation instanceof WC_Product_Variation, 'product types preserved');
    upgradeAssert($simple->get_sku() === 'fixture-simple' && $simple->get_stock_quantity() === 10 && $variation->get_stock_quantity() === 7, 'SKU and reduced inventory preserved without duplicate reduction');
    upgradeAssert($variation->get_parent_id() === $variable->get_id() && $variation->get_attributes() === ['size' => 'small'], 'variation relationships preserved');
    upgradeMoney($simple->get_price(), '20.00', 'simple price preserved');
    upgradeMoney($variation->get_price(), '30.00', 'variation price preserved');
    $order = wc_get_order($state['order']);
    $pending = wc_get_order($state['pending_order']);
    upgradeAssert($order instanceof WC_Order && $pending instanceof WC_Order, 'both orders preserved');
    upgradeAssert($order->get_status() === 'processing' && $pending->get_status() === 'pending', 'order statuses preserved');
    upgradeAssert($order->get_customer_id() === $state['customer'] && $order->get_currency() === 'CHF' && count($order->get_items()) === 2, 'customer, currency and order items preserved');
    upgradeAssert($order->get_meta('_fixture_preserved') === ['reference' => 'before-upgrade', 'number' => 42], 'structured order metadata preserved');
    upgradeMoney($order->get_total(), '71.50', 'order total preserved');
    upgradeMoney($order->get_total_tax(), '6.50', 'order tax preserved');
    upgradeMoney($order->get_discount_total(), '5.00', 'order discount preserved');
    upgradeMoney($pending->get_total(), '22.00', 'pending order preserved');
    $customer = new WC_Customer($state['customer']);
    upgradeAssert($customer->get_email() === 'fixture@example.invalid' && $customer->get_billing_country() === 'CH', 'customer data preserved');
    WC()->customer = $customer;
    WC()->session = new WC_Session_Handler();
    WC()->session->init();
    WC()->cart = new WC_Cart();
    upgradeAssert(WC()->cart->add_to_cart($simple->get_id(), 2) !== false, 'add simple product to cart');
    upgradeAssert(WC()->cart->add_to_cart($variable->get_id(), 1, $variation->get_id(), ['attribute_size' => 'small']) !== false, 'add variation to cart');
    upgradeAssert(WC()->cart->apply_coupon('fixture-five'), 'apply existing coupon');
    WC()->cart->calculate_totals();
    upgradeMoney(WC()->cart->get_subtotal(), '70.00', 'cart subtotal');
    upgradeMoney(WC()->cart->get_discount_total(), '5.00', 'cart discount');
    upgradeMoney(WC()->cart->get_total_tax(), '6.50', 'cart tax');
    upgradeMoney(WC()->cart->get_total('edit'), '71.50', 'cart payable total');
    wp_set_current_user(0);
    upgradeAssert(rest_do_request(new WP_REST_Request('GET', '/wp/v2/types'))->get_status() === 200, 'public WordPress REST works');
    upgradeAssert(rest_do_request(new WP_REST_Request('GET', '/wc/v3/orders/' . $state['order']))->get_status() === 401, 'anonymous order REST is denied');
    wp_set_current_user($state['customer']);
    upgradeAssert(rest_do_request(new WP_REST_Request('GET', '/wc/v3/orders/' . $state['order']))->get_status() === 403, 'customer cannot use privileged order REST');
    $admin = get_user_by('login', 'smoke-admin');
    upgradeAssert($admin instanceof WP_User, 'administrator retained');
    wp_set_current_user($admin->ID);
    $response = rest_do_request(new WP_REST_Request('GET', '/wc/v3/orders/' . $state['order']));
    upgradeAssert($response->get_status() === 200 && $response->get_data()['id'] === $state['order'], 'authenticated admin order REST works');
    upgradeMoney($response->get_data()['total'], '71.50', 'REST order total preserved');
    $customEmail = get_post($state['custom_email']);
    upgradeAssert($customEmail instanceof WP_Post && $customEmail->post_content === '<p>Merchant customized email</p>', 'merchant email customization preserved');
    upgradeAssert((int) get_option('woocommerce_email_templates_customer_processing_order_post_id') === $state['custom_email'], 'custom email mapping preserved');
    if ($phase === 'verify-upgraded') {
        upgradeAssert($wp_version === getenv('WP_CORE_BASE_UPGRADE_CORE_VERSION'), 'exact staged candidate core version loaded');
        upgradeAssert((string) get_option('db_version') === (string) $wp_db_version && ! WC_Install::needs_db_update(), 'upgraded database versions persist after a fresh boot');
        upgradeAssert(get_post($state['default_email']) === null && get_option('woocommerce_email_templates_customer_new_account_post_id') === false, 'untouched generated email and mapping removed by migration');
        upgradeAssert(get_post_meta($state['custom_email'], '_wc_email_type', true) === 'customer_processing_order', 'customized email tagged by migration');
        upgradeAssert(get_transient('wc_email_editor_initial_templates_generated') === false, 'obsolete email generation cache removed');
        upgradeAssert(get_transient('wc_outofstock_count') !== 12345, 'obsolete out-of-stock cache removed');
        upgradeAssert(get_option('wc_feature_woocommerce_additional_variation_images_enabled') === false, 'obsolete variation gallery option removed');
        upgradeAssert(is_array(get_option('wp_core_base_upgrade_executed')), 'completed migration execution receipt persisted');
        wp_set_current_user($state['customer']);
        $checkoutOrderId = WC()->checkout()->create_order([
            'billing_first_name' => 'Fixture', 'billing_last_name' => 'Customer',
            'billing_country' => 'CH', 'billing_postcode' => '8000', 'billing_city' => 'Zurich',
            'billing_address_1' => 'Fixture street 1', 'billing_email' => 'fixture@example.invalid',
            'shipping_country' => 'CH', 'payment_method' => '',
        ]);
        upgradeAssert(is_int($checkoutOrderId) && $checkoutOrderId > 0, 'checkout creates an order after migration without processing payment');
        update_option('wp_core_base_upgrade_checkout_order', $checkoutOrderId, false);
    } else {
        upgradeAssert($wp_version === $state['core'] && WC_VERSION === $state['woo'], 'original baseline files used');
        upgradeAssert(get_option('db_version') === $state['core_db'] && get_option('woocommerce_db_version') === $state['woo_db'], 'original database versions retained/restored');
        upgradeAssert(get_post($state['default_email']) instanceof WP_Post, 'old default email retained/restored');
        upgradeAssert(get_option('wc_feature_woocommerce_additional_variation_images_enabled') === 'yes', 'original variation gallery option retained/restored');
        upgradeAssert(get_option('wp_core_base_upgrade_executed') === false, 'upgraded-only receipt absent from baseline/restored database');
        upgradeAssert(get_option('wp_core_base_upgrade_checkout_order') === false, 'upgraded-only checkout receipt absent from restored database');
    }
    fwrite(STDOUT, sprintf("Verified %s: %s storage, products, variation, customer, orders, inventory, taxes, coupon, cart, REST permissions and migration email invariants.\n", $phase, $storage));
}
WordpressSmokeRunner::complete($phase);
