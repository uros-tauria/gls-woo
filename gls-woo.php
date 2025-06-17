<?php
/**
 * Plugin Name: MyGLS WooCommerce Integration
 * Description: Integrates MyGLS API with WooCommerce (Paketomat support).
 * Version: 1.0.4
 * Author: Tauria
 */

if (!defined('ABSPATH')) exit;

require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/uros-tauria/gls-woo/',
    __FILE__,
    'gls-woo'
);
$myUpdateChecker->setBranch('master');


// Load Paketomat handling on order placement
add_action('woocommerce_checkout_order_processed', 'mygls_create_parcel_for_order', 20, 1);

function mygls_create_parcel_for_order($order_id) {
    $order = wc_get_order($order_id);

    // Hardcoded pickup data (update with your real sender info)
    $pickup = [
        'Name' => 'Your Company',
        'Street' => 'Test Street',
        'HouseNumber' => '1',
        'City' => 'Ljubljana',
        'ZipCode' => '1000',
        'CountryIsoCode' => 'SI'
    ];

    // Delivery address
    $shipping = $order->get_address('shipping');
	
		$options = get_option('mygls_settings');
	$username = $options['api_username'] ?? '';
	$password = hash('sha512', $options['api_password'] ?? '', true);
	$clientNumber = (int) ($options['client_number'] ?? 0);

    $delivery = [
        'Name' => $shipping['first_name'] . ' ' . $shipping['last_name'],
        'Street' => $shipping['address_1'],
        'HouseNumber' => preg_replace('/\D/', '', $shipping['address_1']), // crude fallback
        'City' => $shipping['city'],
        'ZipCode' => $shipping['postcode'],
        'CountryIsoCode' => $shipping['country'],
        'ContactName' => $shipping['first_name'],
        'ContactPhone' => $order->get_billing_phone(),
        'ContactEmail' => $order->get_billing_email()
    ];
	$locker_id = $order->get_meta('gls_paketomat');
    // Paketomat - PSD
    $parcel = [
        'ClientNumber' => $clientNumber,
        'ClientReference' => 'ORDER-' . $order->get_id(),
        'PickupDate' => date('Y-m-d') . 'T00:00:00',
        'PickupAddress' => $pickup,
        'DeliveryAddress' => $delivery,
		'ServiceList' => [[
			'Code' => 'PSD',
			'PSDParameter' => [
				'StringValue' => $locker_id ?: '2351-CSOMAGPONT'
			]
		]]
    ];

    $payload = [
        'ParcelList' => [$parcel],
        'WebshopEngine' => 'WooCommerce',
        'TypeOfPrinter' => 'A4_2x2',
        'PrintPosition' => 1,
        'ShowPrintDialog' => false
    ];

    $json = json_encode($payload);


    $response = wp_remote_post('https://api.test.mygls.si/ParcelService.svc/json/PrintLabels', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ],
        'body' => $json,
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        error_log('MyGLS API error: ' . $response->get_error_message());
        return;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($body['Labels'])) {
        $pdf = base64_decode($body['Labels']);
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . "/gls-label-order-{$order_id}.pdf";
        file_put_contents($file_path, $pdf);

        // Save label path to order notes
        $order->add_order_note("GLS label generated and saved: {$file_path}");
    } else {
        $order->add_order_note('GLS API did not return a label.');
    }
}

// Add Paketomat dropdown to checkout
add_action('woocommerce_after_order_notes', 'mygls_paketomat_checkout_field');

function mygls_paketomat_checkout_field($checkout) {
    echo '<h3>' . __('Izberi Paketomat (GLS Locker)') . '</h3>';

    woocommerce_form_field('gls_paketomat', [
        'type'     => 'select',
        'class'    => ['form-row-wide'],
        'label'    => __('Paketomat lokacija'),
        'required' => true,
		'options'  => mygls_get_dynamic_lockers()
    ], $checkout->get_value('gls_paketomat'));
}

function mygls_get_paketomat_options() {
    return [
        '' => 'Izberi...',
        '2351-CSOMAGPONT' => 'Ljubljana - Trgovina XYZ',
        '2352-CSOMAGPONT' => 'Maribor - Mercator Center',
        '2353-CSOMAGPONT' => 'Celje - Petrol Cesta',
        // Add more real GLS Paketomat locations here
    ];
}

// Validate
add_action('woocommerce_checkout_process', function () {
    if (empty($_POST['gls_paketomat'])) {
        wc_add_notice(__('Prosimo, izberi paketomat.'), 'error');
    }
});

// Save to order
add_action('woocommerce_checkout_create_order', function ($order, $data) {
    if (!empty($_POST['gls_paketomat'])) {
        $order->update_meta_data('gls_paketomat', sanitize_text_field($_POST['gls_paketomat']));
    }
}, 10, 2);



add_action('woocommerce_admin_order_data_after_shipping_address', function ($order){
    $locker = $order->get_meta('gls_paketomat');
    if ($locker) {
        echo '<p><strong>GLS Paketomat:</strong> ' . esc_html($locker) . '</p>';
    }
});

function mygls_get_dynamic_lockers() {
    $options = get_option('mygls_settings');
    $url = $options['locker_url'] ?? '';

    if (!$url) return [];

    $response = wp_remote_get($url);
    if (is_wp_error($response)) return [];

    $data = json_decode(wp_remote_retrieve_body($response), true);

    $lockers = ['' => 'Izberi...'];
    foreach ($data as $locker) {
        $lockers[$locker['id']] = $locker['name'];
    }

    return $lockers;
}

/* REGISTER MENU PAGE */

// Create settings page
add_action('admin_menu', 'mygls_add_settings_page');
function mygls_add_settings_page() {
    add_menu_page(
        'MyGLS Settings',
        'MyGLS Settings',
        'manage_options',
        'mygls-settings',
        'mygls_render_settings_page',
        'dashicons-admin-generic'
    );
}

function mygls_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>MyGLS Nastavitve</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('mygls_settings_group');
            do_settings_sections('mygls-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Register settings
add_action('admin_init', 'mygls_register_settings');
function mygls_register_settings() {
    register_setting('mygls_settings_group', 'mygls_settings');

    add_settings_section('mygls_main', '', null, 'mygls-settings');

    add_settings_field(
        'api_username',
        'API Email',
        'mygls_render_text_field',
        'mygls-settings',
        'mygls_main',
        ['label_for' => 'api_username']
    );

    add_settings_field(
        'api_password',
        'API Geslo',
        'mygls_render_password_field',
        'mygls-settings',
        'mygls_main',
        ['label_for' => 'api_password']
    );

    add_settings_field(
        'client_number',
        'GLS Client Number',
        'mygls_render_text_field',
        'mygls-settings',
        'mygls_main',
        ['label_for' => 'client_number']
    );

    add_settings_field(
        'locker_url',
        'Locker Feed URL',
        'mygls_render_text_field',
        'mygls-settings',
        'mygls_main',
        ['label_for' => 'locker_url']
    );
}

function mygls_render_text_field($args) {
    $options = get_option('mygls_settings');
    $value = esc_attr($options[$args['label_for']] ?? '');
    echo "<input type='text' id='{$args['label_for']}' name='mygls_settings[{$args['label_for']}]' value='{$value}' class='regular-text'>";
}

function mygls_render_password_field($args) {
    $options = get_option('mygls_settings');
    $value = esc_attr($options[$args['label_for']] ?? '');
    echo "<input type='password' id='{$args['label_for']}' name='mygls_settings[{$args['label_for']}]' value='{$value}' class='regular-text'>";
}
