<?php
/**
 * Plugin Name: MyGLS WooCommerce Integration
 * Description: Integrates MyGLS API with WooCommerce (Paketomat support).
 * Version: 1.0.26
 * Author: Tauria
 */

if (!defined('ABSPATH')) exit;

require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';


add_action('wp_enqueue_scripts', function () {
    if (is_checkout()) {
        wp_enqueue_style(
            'mygls-paketomat-css',
            plugin_dir_url(__FILE__) . 'assets/css/paketomat.css',
            [],
            '1.0'
        );

        wp_enqueue_script(
            'mygls-paketomat-js',
            plugin_dir_url(__FILE__) . 'assets/js/paketomat.js',
            ['jquery'],
            '1.0',
            true
        );
    }
});


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
        'Name' => 'Parnad',
        'Street' => 'Test',
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
    $url = 'https://map.gls-slovenia.com/data/deliveryPoints/si.json';
    $response = wp_remote_get($url);
    if (is_wp_error($response)) return [];

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['items'])) return [];

    $lockers = ['' => 'Izberi...'];
    foreach ($data['items'] as $locker) {
        if ($locker['type'] === 'parcel-locker') {
            $label = $locker['name'] . ' – ' . $locker['contact']['address'] . ', ' . $locker['contact']['postalCode'] . ' ' . $locker['contact']['city'];
            $lockers[$locker['id']] = $label;
        }
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
        plugins_url( "assets/icon.png", __FILE__ )
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
    'shipping_cost',
    'GLS Paketomat Cena Dostave',
    'mygls_render_text_field',
    'mygls-settings',
    'mygls_main',
    ['label_for' => 'shipping_cost']
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

/* GLS PAKETOMAT SHIPPING METHOD */




   function mygls_shipping_method_init() {
    if (!class_exists('WC_MyGLS_Paketomat_Shipping_Method')) {

        class WC_MyGLS_Paketomat_Shipping_Method extends WC_Shipping_Method {

            public function __construct() {
                $this->id                 = 'mygls_paketomat';
                $this->method_title       = __('GLS Paketomat', 'woocommerce');
                $this->method_description = __('Paketomat dostava prek GLS', 'woocommerce');

                $this->enabled            = "yes";
                $this->title              = "GLS Paketomat";

                $this->init();
            }

            public function init() {
                $this->init_form_fields();
                $this->init_settings();

                $this->enabled = $this->get_option('enabled');
                $this->title   = $this->get_option('title');

                add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
            }

            public function init_form_fields() {
                $this->form_fields = [
                    'enabled' => [
                        'title'       => __('Enable', 'woocommerce'),
                        'type'        => 'checkbox',
                        'description' => __('Enable this shipping method.', 'woocommerce'),
                        'default'     => 'yes'
                    ],
                    'title' => [
                        'title'       => __('Title', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                        'default'     => __('GLS Paketomat', 'woocommerce')
                    ],
                    'cost' => [
                        'title'       => __('Cost', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('Shipping cost.', 'woocommerce'),
                        'default'     => '4.99'
                    ]
                ];
            }

            public function calculate_shipping($package = []) {
                $rate = [
                    'id'    => $this->id,
                    'label' => $this->title,
                    'cost'  => $this->get_option('cost'),
                    'calc_tax' => 'per_order'
                ];
                $this->add_rate($rate);
            }
        }
    }
}

add_action('woocommerce_shipping_init', 'mygls_shipping_method_init');

function mygls_add_shipping_method($methods) {
    $methods['mygls_paketomat'] = 'WC_MyGLS_Paketomat_Shipping_Method';
    return $methods;
}

add_filter('woocommerce_shipping_methods', 'mygls_add_shipping_method');


add_action('woocommerce_checkout_process', function () {
    if (WC()->session->get('chosen_shipping_methods')[0] === 'mygls_paketomat' && empty($_POST['gls_paketomat'])) {
        error_log('Selected GLS locker: ' . ($_POST['gls_paketomat'] ?? 'EMPTY'));
        wc_add_notice(__('Prosimo, izberi GLS Paketomat.'), 'error');
        echo "Selected: " . ($_POST['gls_paketomat'] ?? 'EMPTY');
    }
});

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


/* POPUP */

add_action('woocommerce_after_checkout_form', 'mygls_add_locker_modal');
function mygls_add_locker_modal() {
    $lockers = mygls_get_dynamic_lockers();
    unset($lockers['']); // remove "Izberi" from modal
?>


    <div id="gls-paketomat-modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3>Izberi GLS Paketomat</h3>
            <select id="gls-paketomat-select" class="form-control" style="width:100%">
                <option value="">Izberi...</option>
                <?php foreach ($lockers as $id => $label): ?>
                    <option value="<?= esc_attr($id) ?>"><?= esc_html($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="gls-paketomat-confirm" class="button alt" style="margin-top:15px;">Potrdi izbiro</button>
        </div>
    </div>
    <div id="gls-paketomat-summary" style="margin-top: 10px; display:none;"><strong>Paketomat:</strong> <span></span></div>
    <?php
}
add_action('woocommerce_checkout_after_order_notes', 'mygls_show_picker_trigger');
function mygls_show_picker_trigger() {
    ?>
    <div id="gls-paketomat-trigger-container" style="margin-top: 15px;">
        <input type="hidden" name="gls_paketomat" id="gls-paketomat-hidden" value="">
        <div id="gls-paketomat-summary" style="margin-top: 10px; display:none;">
            <strong>Paketomat:</strong> <span></span> 
            <button type="button" class="button" id="edit-paketomat" style="margin-left: 10px;">Uredi</button>
        </div>
    </div>
    <?php
}


add_filter('woocommerce_email_order_meta_fields', 'mygls_add_paketomat_to_email_meta', 10, 3);
function mygls_add_paketomat_to_email_meta($fields, $sent_to_admin, $order) {
    $locker = $order->get_meta('gls_paketomat');
    if ($locker) {
        $fields['gls_paketomat'] = [
            'label' => __('GLS Paketomat', 'woocommerce'),
            'value' => $locker
        ];
    }
    return $fields;
}



