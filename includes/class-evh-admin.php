<?php
defined('ABSPATH') || exit;

final class EVH_Admin {
    public static function boot(): void {
        add_filter('woocommerce_product_data_tabs', function($tabs) {
            $tabs['evh'] = array('label' => 'EVision hire', 'target' => 'evh_product_data', 'class' => array('show_if_simple'), 'priority' => 65);
            return $tabs;
        });
        add_action('woocommerce_product_data_panels', array(__CLASS__, 'product_fields'));
        add_action('woocommerce_admin_process_product_object', array(__CLASS__, 'save_product'));
        add_action('admin_menu', function() {
            add_submenu_page('woocommerce', 'EVision hire', 'EVision hire', 'manage_woocommerce', 'evh', array(__CLASS__, 'page'));
        });
        add_action('admin_post_evh_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_evh_manual', array(__CLASS__, 'manual'));
        add_action('admin_post_evh_release', array(__CLASS__, 'release'));
        add_action('admin_post_evh_tax', array(__CLASS__, 'tax'));
        add_action('admin_post_evh_holidays', array(__CLASS__, 'holidays'));
        add_action('admin_notices', array(__CLASS__, 'notices'));
    }

    public static function product_fields(): void {
        echo '<div id="evh_product_data" class="panel woocommerce_options_panel hidden"><div class="options_group">';
        wp_nonce_field('evh_save_product', '_evh_admin_nonce');
        woocommerce_wp_checkbox(array('id' => '_evh_enabled', 'label' => 'Enable vehicle hire', 'description' => 'Simple products only. Booking stock is controlled by fleet quantity below.'));
        woocommerce_wp_text_input(array('id' => '_evh_capacity', 'label' => 'Fleet quantity', 'type' => 'number', 'custom_attributes' => array('min' => '1', 'max' => '10000', 'step' => '1'), 'description' => 'Number of interchangeable vehicles available for this product.'));
        woocommerce_wp_select(array('id' => '_evh_notice', 'label' => 'Collection notice', 'options' => array('0' => 'Same-day eligible (before midday)', '1' => '1 working day', '2' => '2 working days', '3' => '3 working days', '4' => '4 working days', '5' => '5 working days', '10' => '10 working days')));
        echo '<p class="form-field">Working days are Monday–Friday excluding closures. Requests outside office hours allow an office day for processing. Out-of-hours appointments always require at least two working days.</p></div><div class="options_group">';
        foreach (EVH_Rules::BANDS as $band) {
            woocommerce_wp_text_input(array('id' => '_evh_rate_' . $band['key'], 'label' => $band['label'], 'type' => 'text', 'data_type' => 'price', 'description' => 'Excluding VAT. Enter the full ' . $band['unit'] . ' price.'));
        }
        echo '</div><div class="options_group">';
        woocommerce_wp_text_input(array('id' => '_evh_deposit_own', 'label' => 'Deposit: own insurance (£)', 'type' => 'text', 'data_type' => 'price', 'description' => 'Refundable damage deposit requested before collection. Not added to the online hire payment.'));
        woocommerce_wp_text_input(array('id' => '_evh_deposit_evision', 'label' => 'Deposit: EVision insurance (£)', 'type' => 'text', 'data_type' => 'price', 'description' => 'Enter the amount required when vehicle insurance is supplied by EVision.'));
        woocommerce_wp_select(array('id' => '_evh_min_age', 'label' => 'Minimum driver age', 'options' => array('30' => '30+', '25' => '25+', '21' => '21+')));
        woocommerce_wp_select(array('id' => '_evh_licence_years', 'label' => 'Licence held for', 'options' => array('5' => '5 years', '3' => '3 years')));
        echo '<p class="form-field">Insurance has no VAT. Vehicle insurance is 50% of the applicable rental rate. Tyre and screen insurance are £4/day each. All selected insurance charges double for overseas use.</p></div></div>';
    }

    public static function scalar(string $key, $default = ''): string {
        $value = $_POST[$key] ?? $default;
        if (!is_scalar($value)) { throw new RuntimeException('An invalid setting was supplied.'); }
        return sanitize_text_field(wp_unslash((string) $value));
    }
    private static function decimal(string $key): string {
        $value = wc_format_decimal(self::scalar($key), 2);
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0 || (float) $value > 999999.99) { throw new RuntimeException('Enter a price greater than zero and no more than 999,999.99 for every bracket.'); }
        return $value;
    }
    public static function save_product($product): void {
        $nonce = $_POST['_evh_admin_nonce'] ?? '';
        if (!is_scalar($nonce) || !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), 'evh_save_product') || !current_user_can('edit_post', $product->get_id())) { return; }
        $enabled = isset($_POST['_evh_enabled']);
        $was_hire = $product->get_meta('_evh_enabled') === 'yes' || $product->get_meta('_evh_configured') === 'yes';
        // Save valid price fields independently of activation and other incomplete settings.
        // WooCommerce saves this object after the callback, including when we add an admin error.
        $rates = array(); $errors = array(); $has_prices = false;
        foreach (EVH_Rules::BANDS as $band) {
            $field = '_evh_rate_' . $band['key'];
            if (!array_key_exists($field, $_POST)) {
                $rates[$band['key']] = $product->get_meta($field);
                continue;
            }
            try {
                $raw = self::scalar($field);
                if ($raw !== '') { $has_prices = true; }
                if ($raw === '') {
                    $product->delete_meta_data($field);
                    $rates[$band['key']] = '';
                } else {
                    $rates[$band['key']] = self::decimal($field);
                    $product->update_meta_data($field, $rates[$band['key']]);
                }
            } catch (Throwable $e) {
                $has_prices = true;
                $rates[$band['key']] = $product->get_meta($field);
                $errors[] = $band['label'] . ': enter a positive numeric price up to 999,999.99. The previous price has been retained.';
            }
        }
        foreach (array('own' => 'Own insurance deposit', 'evision' => 'EVision insurance deposit') as $key => $label) {
            $field = '_evh_deposit_' . $key;
            if (!array_key_exists($field, $_POST)) { continue; }
            try {
                $raw = self::scalar($field);
                if ($raw === '') { $product->delete_meta_data($field); }
                else { $has_prices = true; $product->update_meta_data($field, self::decimal($field)); }
            } catch (Throwable $e) {
                $errors[] = $label . ': enter a positive amount up to 999,999.99 or leave blank. The previous amount has been retained.';
            }
        }
        if (!$enabled && !$was_hire && !$has_prices) { return; }
        $product->update_meta_data('_evh_configured', 'yes');
        $complete = true;
        foreach ($rates as $value) {
            if (!is_numeric($value) || (float) $value <= 0) { $complete = false; }
        }
        if (!$complete || $errors || !$enabled) { $product->update_meta_data('_evh_enabled', 'no'); }
        foreach ($errors as $error) { WC_Admin_Meta_Boxes::add_error('EVision hire: ' . $error); }
        try {
            if (!$product->is_type('simple')) { throw new RuntimeException('EVision hire supports simple products only.'); }
            $capacity_text = self::scalar('_evh_capacity', '1');
            if ($capacity_text === '') { $capacity_text = '1'; }
            if (!ctype_digit($capacity_text) || (int) $capacity_text < 1 || (int) $capacity_text > 10000) { throw new RuntimeException('Enter a fleet quantity between 1 and 10,000.'); }
            $notice = self::scalar('_evh_notice', '0');
            if ($notice === '') { $notice = '0'; }
            if (!in_array($notice, array('0', '1', '2', '3', '4', '5', '10'), true)) { throw new RuntimeException('Select a supported working-day notice period.'); }
            $age = self::scalar('_evh_min_age', '30'); $years = self::scalar('_evh_licence_years', '5');
            if ($age === '') { $age = '30'; }
            if ($years === '') { $years = '5'; }
            if (!in_array($age, array('21', '25', '30'), true) || !in_array($years, array('3', '5'), true)) { throw new RuntimeException('Choose the minimum age and licence duration.'); }
            EVH_Storage::locked(array($product->get_id()), function() use ($product, $capacity_text, $notice, $age, $years, $rates, $enabled, $complete, $errors) {
                if (EVH_Storage::max_reserved($product->get_id()) > (int) $capacity_text) { throw new RuntimeException('Fleet quantity cannot be reduced below existing reservations.'); }
                // Save the fleet quantity under the same lock used by checkout, so a concurrent booking sees it.
                update_post_meta($product->get_id(), '_evh_capacity', (int) $capacity_text);
                $product->update_meta_data('_evh_capacity', (int) $capacity_text);
                $product->update_meta_data('_evh_notice', (int) $notice);
                $product->update_meta_data('_evh_min_age', (int) $age);
                $product->update_meta_data('_evh_licence_years', (int) $years);
                $active = $enabled && $complete && !$errors;
                $product->update_meta_data('_evh_enabled', $active ? 'yes' : 'no');
                if ($active) {
                    $product->set_virtual(true);
                    $product->set_manage_stock(false);
                    $product->set_sold_individually(false);
                    $product->set_stock_status('instock');
                    $product->set_tax_status('taxable');
                    $product->set_tax_class(EVH_Plugin::TAX_CLASS);
                    $product->set_sale_price('');
                    $product->set_date_on_sale_from(null); $product->set_date_on_sale_to(null);
                    $product->set_regular_price(EVH_Plugin::base_price((float) $rates['daily']));
                    $product->set_price(EVH_Plugin::base_price((float) $rates['daily']));
                }
                $product->save();
            });
            if ($enabled && !$complete) { WC_Admin_Meta_Boxes::add_error('EVision hire: the prices you entered have been saved. Enter all six prices before enabling online hire.'); }
        } catch (Throwable $e) {
            // Keep draft prices, but never enable bookings with invalid setup or unavailable locking.
            $product->update_meta_data('_evh_enabled', 'no');
            WC_Admin_Meta_Boxes::add_error('EVision hire: valid prices have been retained, but online hire is disabled. ' . ($e instanceof RuntimeException ? $e->getMessage() : 'Other settings could not be saved.'));
        }
    }

    private static function permission(string $action): void {
        if (!current_user_can('manage_woocommerce')) { wp_die('You do not have permission to manage hire bookings.', '', array('response' => 403)); }
        check_admin_referer($action);
    }
    private static function redirect(string $message, bool $error = false): void {
        $token = wp_generate_password(20, false, false);
        set_transient('evh_notice_' . get_current_user_id() . '_' . $token, array('message' => $message, 'error' => $error), 60);
        wp_safe_redirect(add_query_arg(array('page' => 'evh', 'evh_notice' => $token), admin_url('admin.php'))); exit;
    }
    public static function save_settings(): void {
        self::permission('evh_settings');
        try {
            $fee = wc_format_decimal(self::scalar('out_fee'), 2);
            if (!is_numeric($fee) || !is_finite((float) $fee) || (float) $fee < 0 || (float) $fee > 10000) { throw new RuntimeException('Enter a valid out-of-hours fee.'); }
            $closures = preg_split('/\s+/', trim(self::scalar('closures')), -1, PREG_SPLIT_NO_EMPTY);
            if (count($closures) > 1000) { throw new RuntimeException('Enter no more than 1,000 closure dates.'); }
            foreach ($closures as $date) { EVH_Rules::date($date); }
            $years = preg_split('/\s+/', trim(self::scalar('reviewed_years')), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($years as $year) { if (!preg_match('/^20\d{2}$/D', $year)) { throw new RuntimeException('Reviewed years must be four-digit years, separated by spaces.'); } }
            update_option('evh_out_fee', $fee, false);
            update_option('evh_closures', implode("\n", array_unique($closures)), false);
            update_option('evh_reviewed_years', implode(' ', array_unique($years)), false);
            self::redirect('Hire settings saved.');
        } catch (Throwable $e) { self::redirect($e instanceof RuntimeException ? $e->getMessage() : 'Settings could not be saved.', true); }
    }
    public static function manual(): void {
        self::permission('evh_manual');
        try {
            $id = absint(self::scalar('product_id'));
            EVH_Storage::manual($id, self::scalar('start'), self::scalar('end'), absint(self::scalar('quantity')), substr(self::scalar('reference'), 0, 120));
            self::redirect('Manual reservation saved. It now reduces website availability.');
        } catch (Throwable $e) { self::redirect($e instanceof RuntimeException ? $e->getMessage() : 'Reservation could not be saved.', true); }
    }
    public static function release(): void {
        self::permission('evh_release');
        try { EVH_Storage::release_manual(absint(self::scalar('reservation_id'))); self::redirect('Manual reservation released.'); }
        catch (Throwable $e) { self::redirect($e instanceof RuntimeException ? $e->getMessage() : 'Reservation could not be released.', true); }
    }
    public static function tax(): void {
        self::permission('evh_tax');
        try {
            $class = WC_Tax::create_tax_class('EVision vehicle hire', EVH_Plugin::TAX_CLASS);
            $existing = WC_Tax::get_rates_for_tax_class(EVH_Plugin::TAX_CLASS);
            if (!$existing) {
                $id = WC_Tax::_insert_tax_rate(array('tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '20.0000',
                    'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => EVH_Plugin::TAX_CLASS));
                if (!$id) { throw new RuntimeException('The tax rate could not be created.'); }
            }
            self::redirect('Dedicated EVision 20% VAT class is configured. Enable taxes in WooCommerce settings if necessary. Existing tax classes have not been changed.');
        } catch (Throwable $e) { self::redirect($e instanceof RuntimeException ? $e->getMessage() : 'VAT configuration failed. Check WooCommerce tax settings.', true); }
    }
    public static function holidays(): void {
        self::permission('evh_holidays');
        $ok = EVH_Plugin::refresh_holidays();
        self::redirect($ok ? 'England and Wales bank holidays refreshed from GOV.UK.' : 'GOV.UK refresh failed. Existing dates are retained; check them before accepting bookings.', !$ok);
    }

    public static function notices(): void {
        if (!current_user_can('manage_woocommerce')) { return; }
        if (isset($_GET['evh_notice']) && is_scalar($_GET['evh_notice'])) {
            $token = sanitize_key(wp_unslash($_GET['evh_notice']));
            $key = 'evh_notice_' . get_current_user_id() . '_' . $token;
            $notice = get_transient($key);
            if ($notice) { delete_transient($key); echo '<div class="notice notice-' . ($notice['error'] ? 'error' : 'success') . '"><p>' . esc_html($notice['message']) . '</p></div>'; }
        }
        $screen = get_current_screen();
        if (!$screen || (strpos($screen->id, 'evh') === false && $screen->id !== 'product' && $screen->id !== 'plugins')) { return; }
        echo '<div class="notice notice-warning"><p><strong>EVision Vehicle Hire ' . esc_html(EVH_VERSION) . ' is a test release.</strong> Use staging first. This version requires classic WooCommerce basket/checkout pages. Check vehicle availability before accepting bookings.</p></div>';
        try { EVH_Plugin::ready(); } catch (Throwable $e) { echo '<div class="notice notice-error"><p>' . esc_html($e instanceof RuntimeException ? $e->getMessage() : 'Hire configuration requires attention.') . '</p></div>'; }
    }
    private static function action_form(string $action, string $label): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:12px"><input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action); submit_button($label, 'secondary', 'submit', false); echo '</form>';
    }
    public static function page(): void {
        if (!current_user_can('manage_woocommerce')) { return; }
        echo '<div class="wrap"><h1>EVision vehicle hire</h1><p>One product represents a vehicle type. Fleet quantity controls how many overlapping hires can be accepted. Each hire occupies its collection and return dates.</p>';
        echo '<h2>Setup</h2><p>Use simple products and the EVision hire tab. Rates are entered excluding VAT, even when other store prices include VAT. Insurance fees are non-taxable. Use classic <code>[woocommerce_cart]</code> and <code>[woocommerce_checkout]</code> pages.</p>';
        self::action_form('evh_tax', 'Create dedicated 20% hire VAT class'); self::action_form('evh_holidays', 'Refresh England bank holidays');
        echo '<p>Latest successful bank holiday refresh: ' . esc_html(get_option('evh_holidays_updated', 'Not yet refreshed; bundled official 2019–2028 dates are in use.')) . '</p>';
        echo '<h2>Hire settings</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="evh_settings">';
        wp_nonce_field('evh_settings');
        echo '<table class="form-table"><tr><th><label for="out_fee">Out-of-hours fee per appointment, ex VAT</label></th><td><input id="out_fee" name="out_fee" type="number" step="0.01" min="0" max="10000" value="' . esc_attr(EVH_Plugin::option('out_fee', 25)) . '"><p>Two working days’ notice is required. Same-day cut-off is 12:00 UK time.</p></td></tr>';
        echo '<tr><th><label for="closures">Additional closure dates</label></th><td><textarea id="closures" name="closures" rows="6" cols="35">' . esc_textarea(EVH_Plugin::option('closures', '')) . '</textarea><p>One YYYY-MM-DD date per line. Sundays and England bank holidays are already blocked. Add 25/26 December closures here if they fall at weekends and you are also closed on the original dates.</p></td></tr>';
        echo '<tr><th><label for="reviewed_years">Manually reviewed future calendar years</label></th><td><input id="reviewed_years" name="reviewed_years" value="' . esc_attr(EVH_Plugin::option('reviewed_years', '')) . '"><p>For years beyond the official feed, enter their bank holidays in closure dates first, then enter the reviewed years here, separated by spaces. Dates in unreviewed years are blocked.</p></td></tr></table>';
        submit_button('Save hire settings'); echo '</form>';
        $products = wc_get_products(array('limit' => -1, 'type' => 'simple', 'status' => array('publish', 'private', 'draft'), 'orderby' => 'title', 'order' => 'ASC'));
        $products = array_filter($products, array('EVH_Plugin', 'enabled'));
        self::calendar($products); self::ledger(); echo '</div>';
    }

    private static function calendar(array $products): void {
        $month = isset($_GET['evh_month']) && is_scalar($_GET['evh_month']) ? sanitize_text_field(wp_unslash($_GET['evh_month'])) : EVH_Plugin::now()->format('Y-m');
        try { $first = EVH_Rules::date($month . '-01'); } catch (Throwable $e) { $first = EVH_Rules::date(EVH_Plugin::now()->format('Y-m') . '-01'); }
        $id = isset($_GET['evh_product']) && is_scalar($_GET['evh_product']) ? absint($_GET['evh_product']) : 0;
        echo '<h2>Availability calendar</h2><form method="get"><input name="page" value="evh" type="hidden"><label>Month <input type="month" name="evh_month" value="' . esc_attr($first->format('Y-m')) . '"></label> <label>Vehicle <select name="evh_product"><option value="0">Choose a vehicle</option>';
        foreach ($products as $p) { echo '<option value="' . esc_attr($p->get_id()) . '"' . selected($id, $p->get_id(), false) . '>' . esc_html($p->get_name()) . '</option>'; }
        echo '</select></label> <button class="button">Show availability</button></form>';
        $product = wc_get_product($id);
        if (!EVH_Plugin::enabled($product)) { return; }
        try { $rows = EVH_Storage::active($id, $first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')); }
        catch (Throwable $e) { echo '<p>Calendar unavailable. Please check the reservation database.</p>'; return; }
        echo '<table class="widefat striped" style="margin-top:12px;table-layout:fixed"><caption>' . esc_html($product->get_name() . ' · ' . $first->format('F Y')) . '</caption><thead><tr>';
        foreach (array('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun') as $day) { echo '<th scope="col">' . esc_html($day) . '</th>'; }
        echo '</tr></thead><tbody><tr>';
        $offset = (int) $first->format('N') - 1;
        for ($i = 0; $i < $offset; $i++) { echo '<td></td>'; }
        $days = (int) $first->format('t'); $capacity = (int) $product->get_meta('_evh_capacity');
        for ($d = 1; $d <= $days; $d++) {
            $date = $first->modify('+' . ($d - 1) . ' days'); $key = $date->format('Y-m-d');
            $used = EVH_Rules::peak($rows, $key, $key);
            $closed = (int) $date->format('N') === 7 || EVH_Plugin::closed($key);
            echo '<td><strong>' . esc_html($d) . '</strong><br>' . esc_html(max(0, $capacity - $used) . '/' . $capacity . ' available') . ($closed ? '<br><small>No collection/return</small>' : '') . '</td>';
            if (($d + $offset) % 7 === 0 && $d !== $days) { echo '</tr><tr>'; }
        }
        $remaining = (7 - (($days + $offset) % 7)) % 7;
        for ($i = 0; $i < $remaining; $i++) { echo '<td></td>'; }
        echo '</tr></tbody></table>';
    }

    private static function ledger(): void {
        global $wpdb;
        $page = isset($_GET['evh_ledger']) && is_scalar($_GET['evh_ledger']) ? max(1, absint($_GET['evh_ledger'])) : 1;
        $table = EVH_Storage::table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT 100 OFFSET %d", ($page - 1) * 100));
        echo '<h2>Reservation ledger</h2><p>Pending online payments hold availability for 30 minutes. Paid and on-hold orders reserve the full period. Cancelled, failed and fully refunded orders release it. Partial refunds do not release vehicles.</p><table class="widefat striped"><thead><tr><th>ID</th><th>Vehicle</th><th>Dates</th><th>Qty</th><th>Status</th><th>Reference</th><th>Action</th></tr></thead><tbody>';
        foreach ($rows ?: array() as $row) {
            $product = wc_get_product($row->product_id);
            $state = $row->state;
            if ($state === 'held' && $row->expires_at && $row->expires_at <= gmdate('Y-m-d H:i:s')) { $state = 'expired'; }
            echo '<tr><td>' . esc_html($row->id) . '</td><td>' . esc_html($product ? $product->get_name() : 'Deleted product #' . $row->product_id) . '</td><td>' . esc_html($row->start_date . ' to ' . $row->end_date) . '</td><td>' . esc_html($row->quantity) . '</td><td>' . esc_html($state) . '</td><td>' . esc_html($row->reference) . '</td><td>';
            if ($row->order_id) {
                $order = wc_get_order($row->order_id);
                if ($order) { echo '<a href="' . esc_url($order->get_edit_order_url()) . '">View order</a>'; }
            } elseif ($state === 'manual') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="evh_release"><input type="hidden" name="reservation_id" value="' . esc_attr($row->id) . '">';
                wp_nonce_field('evh_release'); echo '<button class="button">Release</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table><p>';
        if ($page > 1) { echo '<a class="button" href="' . esc_url(add_query_arg(array('page' => 'evh', 'evh_ledger' => $page - 1), admin_url('admin.php'))) . '">Previous page</a> '; }
        if (count($rows ?: array()) === 100) { echo '<a class="button" href="' . esc_url(add_query_arg(array('page' => 'evh', 'evh_ledger' => $page + 1), admin_url('admin.php'))) . '">Next page</a>'; }
        echo '</p>';
    }
}
