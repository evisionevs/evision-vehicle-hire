<?php
defined('ABSPATH') || exit;

final class EVH_Plugin {
    public const TAX_CLASS = 'evision-vehicle-hire';
    public const EXTRA_LABELS = array('insurance' => 'Vehicle insurance', 'tyre' => 'Tyre insurance', 'screen' => 'Screen insurance');
    private static bool $status_guard = false;

    public static function boot(): void {
        add_action('woocommerce_before_add_to_cart_button', array(__CLASS__, 'form'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_ajax_evh_quote', array(__CLASS__, 'ajax_quote'));
        add_action('wp_ajax_nopriv_evh_quote', array(__CLASS__, 'ajax_quote'));
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'add_validation'), 20, 6);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'cart_data'), 20, 4);
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'prices'), 30);
        add_action('woocommerce_cart_calculate_fees', array(__CLASS__, 'fees'), 30);
        add_action('woocommerce_check_cart_items', array(__CLASS__, 'check_cart'));
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'item_data'), 20, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'order_item'), 20, 4);
        add_action('woocommerce_checkout_order_created', array(__CLASS__, 'order_created'));
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'status_changed'), 20, 4);
        // Reserve before the corresponding transactional emails are triggered.
        foreach (array('processing', 'completed', 'on-hold') as $state) {
            add_action('woocommerce_order_status_' . $state, function($id, $order) use ($state) {
                self::status_changed($id, '', $state, $order);
            }, 1, 2);
        }
        add_action('woocommerce_before_pay_action', array(__CLASS__, 'before_pay'));
        add_filter('woocommerce_quantity_input_args', array(__CLASS__, 'quantity'), 20, 2);
        add_filter('woocommerce_update_cart_validation', array(__CLASS__, 'update_quantity'), 20, 4);
        add_filter('woocommerce_coupon_is_valid', array(__CLASS__, 'coupon'), 20, 2);
        add_filter('woocommerce_is_purchasable', array(__CLASS__, 'purchasable'), 20, 2);
        add_filter('woocommerce_get_price_html', array(__CLASS__, 'price_html'), 20, 2);
        add_action('woocommerce_email_after_order_table', array(__CLASS__, 'order_message'), 20, 4);
        foreach (array('customer_processing_order', 'customer_completed_order') as $email_id) {
            add_filter('woocommerce_email_enabled_' . $email_id, function($enabled, $order) {
                return $order instanceof WC_Order && $order->get_meta('_evh_allocation_conflict') ? false : $enabled;
            }, 20, 2);
        }
        add_action('woocommerce_order_details_after_order_table', function($order) { self::order_message($order, false, false, null); });
        add_filter('woocommerce_payment_complete_order_status', function($status, $id, $order) {
            return self::has_hire($order) ? 'processing' : $status;
        }, 20, 3);
        add_action('evh_refresh_holidays', array(__CLASS__, 'refresh_holidays'));
        if (!wp_next_scheduled('evh_refresh_holidays')) { wp_schedule_event(time() + 60, 'daily', 'evh_refresh_holidays'); }
        // No rental checkout via the Store API in this first version: it lacks the classic reservation lifecycle.
        add_filter('rest_pre_dispatch', array(__CLASS__, 'block_store_api'), 10, 3);
    }

    public static function now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('Europe/London')); }
    public static function enabled($p): bool { return $p && $p->is_type('simple') && $p->get_meta('_evh_enabled') === 'yes'; }
    public static function has_hire($order): bool {
        if (!$order) { return false; }
        foreach ($order->get_items() as $item) { if ($item->get_meta('_evh_quote')) { return true; } }
        return false;
    }
    public static function option(string $name, $default) { return get_option('evh_' . $name, $default); }
    public static function rates($product): array {
        $rates = array();
        foreach (EVH_Rules::BANDS as $band) { $rates[$band['key']] = $product->get_meta('_evh_rate_' . $band['key']); }
        return $rates;
    }

    public static function holidays(): array {
        static $result;
        if ($result !== null) { return $result; }
        $bundled = json_decode(file_get_contents(EVH_PATH . 'data/england-holidays.json'), true);
        $dates = array_column($bundled['events'] ?? array(), 'date');
        $live = get_option('evh_holidays', array());
        if (is_array($live)) { $dates = array_merge($dates, $live); }
        $result = array_values(array_unique(array_filter($dates, function($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d); })));
        return $result;
    }
    public static function closed(string $date): bool {
        $extra = preg_split('/\s+/', trim((string) self::option('closures', '')), -1, PREG_SPLIT_NO_EMPTY);
        return in_array($date, self::holidays(), true) || in_array($date, $extra, true);
    }
    public static function ensure_calendar(string $date): void {
        $year = substr($date, 0, 4);
        foreach (self::holidays() as $holiday) { if (substr($holiday, 0, 4) === $year) { return; } }
        $reviewed = preg_split('/\s+/', trim((string) self::option('reviewed_years', '')), -1, PREG_SPLIT_NO_EMPTY);
        if (!in_array($year, $reviewed, true)) {
            throw new RuntimeException('Our closure calendar does not yet cover ' . $year . '. Please contact our team to book these dates.');
        }
    }
    public static function refresh_holidays(): bool {
        $response = wp_safe_remote_get('https://www.gov.uk/bank-holidays.json', array('timeout' => 10, 'limit_response_size' => 200000));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return false; }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        $events = $json['england-and-wales']['events'] ?? null;
        if (!is_array($events) || count($events) < 8) { return false; }
        $dates = array();
        foreach ($events as $event) {
            try { $dates[] = EVH_Rules::date($event['date'] ?? '')->format('Y-m-d'); } catch (Throwable $e) { return false; }
        }
        update_option('evh_holidays', $dates, false);
        update_option('evh_holidays_updated', gmdate('Y-m-d H:i:s'), false);
        return true;
    }

    public static function ready(): void {
        $plugins = array_merge((array) get_option('active_plugins', array()), array_keys((array) get_site_option('active_sitewide_plugins', array())));
        foreach ($plugins as $plugin) {
            if (strpos($plugin, 'woocommerce-easy-booking-system/') === 0) {
                throw new RuntimeException('Hire booking is paused while Easy Booking is active. Please contact our team.');
            }
        }
        if (!wc_tax_enabled()) { throw new RuntimeException('Hire VAT is not configured. Please contact our team.'); }
        $rates = WC_Tax::get_base_tax_rates(self::TAX_CLASS);
        $rate = count($rates) === 1 ? reset($rates) : array();
        if (count($rates) !== 1 || abs((float) ($rate['rate'] ?? 0) - 20.0) > 0.001 || ($rate['compound'] ?? 'no') === 'yes') {
            throw new RuntimeException('Hire VAT must be configured at 20%. Please contact our team.');
        }
        $checkout = get_post(wc_get_page_id('checkout'));
        if ($checkout && has_block('woocommerce/checkout', $checkout)) {
            throw new RuntimeException('Hire bookings require the classic WooCommerce checkout. Please contact our team.');
        }
        $basket = get_post(wc_get_page_id('cart'));
        if ($basket && has_block('woocommerce/cart', $basket)) {
            throw new RuntimeException('Hire bookings require the classic WooCommerce basket. Please contact our team.');
        }
        if (get_woocommerce_currency() !== 'GBP') { throw new RuntimeException('This test version requires GBP store currency.'); }
        if (WC()->customer && WC()->customer->get_is_vat_exempt()) { throw new RuntimeException('Please contact our team to arrange a VAT-exempt hire.'); }
        EVH_Storage::ready();
    }

    /** Parse scalar fields only and use a closed set of flags. */
    public static function input(array $source): array {
        $input = array();
        foreach (array('start', 'end', 'start_time', 'end_time', 'payment') as $field) {
            $value = $source['evh_' . $field] ?? '';
            if (!is_scalar($value)) { throw new RuntimeException('Invalid hire details.'); }
            $input[$field] = sanitize_text_field(wp_unslash((string) $value));
        }
        foreach (array('insurance', 'tyre', 'screen', 'overseas', 'eligible', 'own_insurance', 'own_ack') as $field) {
            $value = $source['evh_' . $field] ?? '0';
            if (!is_scalar($value) || !in_array((string) $value, array('0', '1'), true)) { throw new RuntimeException('Invalid insurance selection.'); }
            $input[$field] = (string) $value === '1' ? 1 : 0;
        }
        $drivers = $source['evh_drivers'] ?? '1';
        if (!is_scalar($drivers) || !preg_match('/^[1-9][0-9]?$/', (string) $drivers)) { throw new RuntimeException('Choose a valid number of insured drivers (1–99).'); }
        $input['drivers'] = (int) $drivers;
        return $input;
    }

    public static function quote(int $id, array $input, bool $availability = true, bool $require_eligible = false): array {
        self::ready();
        $product = wc_get_product($id);
        if (!self::enabled($product) || $product->get_status() !== 'publish' || !is_finite((float) $product->get_meta('_evh_capacity')) || (int) $product->get_meta('_evh_capacity') < 1) {
            throw new RuntimeException('This vehicle is not available for online hire.');
        }
        if (!empty($input['insurance']) && !empty($input['own_insurance'])) { throw new RuntimeException('Choose either EVision vehicle insurance or your own insurance.'); }
        if ($require_eligible && empty($input['insurance']) && empty($input['own_insurance'])) { throw new RuntimeException('Please choose EVision vehicle insurance or confirm you will provide your own.'); }
        if ($require_eligible && !empty($input['own_insurance']) && empty($input['own_ack'])) { throw new RuntimeException('Please acknowledge the requirements for providing your own insurance.'); }
        EVH_Rules::date($input['start'] ?? ''); EVH_Rules::date($input['end'] ?? '');
        self::ensure_calendar($input['start']); self::ensure_calendar($input['end']);
        $result = EVH_Rules::quote($input, self::rates($product), (int) $product->get_meta('_evh_notice'), self::now(), array(__CLASS__, 'closed'), (float) self::option('out_fee', 25));
        if ($require_eligible && $result['extras'] && empty($input['eligible'])) {
            throw new RuntimeException('Please confirm the driver eligibility and permitted use requirements for our insurance.');
        }
        if ($availability && EVH_Storage::available($id, $input['start'], $input['end']) < 1) {
            throw new RuntimeException('No vehicles are available for the whole selected period. Please choose other dates or another vehicle.');
        }
        return $result;
    }

    public static function eligibility($product): string {
        $age = max(21, (int) ($product->get_meta('_evh_min_age') ?: 30));
        $years = max(1, (int) ($product->get_meta('_evh_licence_years') ?: 5));
        return 'All drivers must be aged ' . $age . '+, have held a driving licence for a minimum of ' . $years . ' years and have no more than 6 points on their licence. Our insurance cannot be used for couriers, chauffeurs or hire companies hiring our car or van to a third party.';
    }

    public static function deposit_amount($product, bool $evision): ?float {
        $value = $product->get_meta($evision ? '_evh_deposit_evision' : '_evh_deposit_own');
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0 ? (float) $value : null;
    }
    public static function deposit_message($product, bool $evision): string {
        $amount = self::deposit_amount($product, $evision);
        if ($amount === null) { return 'A refundable damage deposit is required before collection, separately from today’s payment. See Insurance Information on this vehicle’s page for the deposit and excess amounts.'; }
        $money = html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8');
        return 'A refundable damage deposit of ' . $money . ' is required before collection, separately from today’s payment.';
    }

    public static function assets(): void {
        if (!is_product()) { return; }
        $p = wc_get_product(get_queried_object_id());
        if (!self::enabled($p)) { return; }
        wp_enqueue_style('evh', EVH_URL . 'assets/hire.css', array(), EVH_VERSION);
        wp_enqueue_script('evh', EVH_URL . 'assets/hire.js', array(), EVH_VERSION, true);
        wp_localize_script('evh', 'EVH', array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('evh_quote')));
    }

    public static function form(): void {
        global $product;
        if (!self::enabled($product)) { return; }
        echo '<section class="evh-booking" data-product="' . esc_attr($product->get_id()) . '">';
        echo '<div class="evh-live-total" data-state="empty" role="status" aria-live="polite" aria-atomic="true"><span class="evh-total-label">Total payable today</span><strong class="evh-total-amount">Select your dates</strong><small class="evh-total-state">Your total will update as you select your options.</small></div>';
        echo '<p class="evh-form-note">Dates and times use UK local time.</p><p class="evh-delivery-note">Vehicle delivery is available to add at checkout.</p>';
        wp_nonce_field('evh_booking', 'evh_nonce');
        $min = self::now()->format('Y-m-d');
        foreach (array('start' => 'Collection', 'end' => 'Return') as $key => $label) {
            echo '<div class="evh-fields"><p><label for="evh_' . esc_attr($key) . '">' . esc_html($label) . ' date</label><input type="date" id="evh_' . esc_attr($key) . '" name="evh_' . esc_attr($key) . '" min="' . esc_attr($min) . '" required></p>';
            echo '<p><label for="evh_' . esc_attr($key) . '_time">' . esc_html($label) . ' time</label><select id="evh_' . esc_attr($key) . '_time" name="evh_' . esc_attr($key) . '_time">';
            for ($minutes = 480; $minutes <= 1080; $minutes += 15) {
                $time = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
                echo '<option value="' . esc_attr($time) . '"' . selected($time, '09:00', false) . '>' . esc_html($time) . '</option>';
            }
            echo '<option value="out">Out of hours: team to arrange</option></select></p></div>';
        }
        echo '<p id="evh-payment"><label for="evh_payment">Payment option</label><select name="evh_payment" id="evh_payment"><option value="full">Pay full hire amount now</option><option value="first">Pay first month only (30+ day hires)</option></select></p>';
        echo '<fieldset class="evh-insurance"><legend>Insurance</legend><p>No VAT is added to these insurance options. International insurance doubles all selected insurance charges.</p>';
        echo '<p><label><input type="checkbox" name="evh_insurance" value="1"> EVision vehicle insurance: <span data-evh-offer="insurance">50% of the applicable rental rate</span></label></p>';
        echo '<p><label><input type="checkbox" name="evh_own_insurance" value="1"> I will provide my own insurance</label></p>';
        echo '<div class="evh-own-insurance-note" hidden><p>A refundable deposit is required before hire. The EVision team will contact you to arrange payment. Deposit return is processed approximately 7–10 days after hire ends, subject to satisfactory vehicle condition.</p><ul><li>The policy must be in the name of the person or company paying for the booking.</li><li>Cover must be fully comprehensive.</li><li>The policy must state the vehicle registration, which we will confirm once booked.</li><li>Your insurer must be aware that the vehicle is hired.</li></ul><label><input type="checkbox" name="evh_own_ack" value="1"> I have read and understand these own-insurance requirements.</label></div>';
        echo '<p class="evh-drivers" hidden><label for="evh_drivers">Number of insured drivers</label><input type="number" id="evh_drivers" name="evh_drivers" min="1" max="99" step="1" value="1"><small>Includes the first driver. Each additional driver costs the same as the first.</small></p>';
        echo '<p><label><input type="checkbox" name="evh_tyre" value="1"> Tyre insurance: <span data-evh-offer="tyre">£4.00 per hire day</span></label></p>';
        echo '<p><label><input type="checkbox" name="evh_screen" value="1"> Screen insurance: <span data-evh-offer="screen">£4.00 per hire day</span></label></p>';
        echo '<p><label><input type="checkbox" name="evh_overseas" value="1"> International Insurance Required</label></p><p class="evh-terms-note">Full Terms and Conditions can be found <a href="https://www.evisionevs.co.uk/terms-conditions/" target="_blank" rel="noopener noreferrer">here</a>.</p></fieldset>';
        echo '<aside class="evh-deposit-note" data-own="' . esc_attr(self::deposit_amount($product, false) ?? '') . '" data-evision="' . esc_attr(self::deposit_amount($product, true) ?? '') . '"><strong>Refundable damage deposit</strong><span class="evh-deposit-info">' . esc_html(self::deposit_message($product, false)) . '</span><small>Processed for return approximately 7–10 working days after the hire ends, subject to satisfactory vehicle condition.</small></aside>';
        echo '<div class="evh-eligibility"><strong>Driver and insurance requirements</strong><p>' . esc_html(self::eligibility($product)) . '</p>';
        echo '<label><input type="checkbox" name="evh_eligible" value="1"> I confirm these requirements are met if I select your insurance.</label></div>';
        echo '<div class="evh-quote" aria-live="polite" aria-atomic="true">Choose your dates to see your quote.</div>';
        echo '<p class="evh-status" role="status"></p><noscript><p>Enable JavaScript to preview your quote. Your selection is always checked again at checkout.</p></noscript></section>';
    }

    public static function money_pair(float $ex, $p): string {
        $gross = wc_get_price_including_tax($p, array('qty' => 1, 'price' => self::base_price($ex)));
        return wp_kses_post(wc_price($gross)) . ' inc VAT <small>(' . wp_kses_post(wc_price($ex)) . ' ex VAT)</small>';
    }
    public static function plain_money(float $amount): string {
        return html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8');
    }
    public static function base_price(float $ex): float { return wc_prices_include_tax() ? $ex * 1.2 : $ex; }
    public static function gross(float $ex, $p): float { return (float) wc_get_price_including_tax($p, array('price' => self::base_price($ex), 'qty' => 1)); }

    public static function total_today(array $q, $p): float {
        return self::gross($q['rental_due'] + $q['fees'], $p) + $q['extras_due'];
    }
    public static function total_today_html(array $q, $p): string {
        $ex = $q['rental_due'] + $q['fees'] + $q['extras_due'];
        return '<span class="evh-total-inc">' . wp_kses_post(wc_price(self::total_today($q, $p))) . ' inc VAT</span><small class="evh-total-ex">' . wp_kses_post(wc_price($ex)) . ' ex VAT</small>';
    }
    private static function quote_row(string $label, string $amount, string $detail = ''): string {
        return '<div class="evh-quote-row"><div class="evh-row-description"><strong>' . esc_html($label) . '</strong>' . ($detail !== '' ? '<span class="evh-row-detail">' . $detail . '</span>' : '') . '</div><div class="evh-row-amount">' . $amount . '</div></div>';
    }

    public static function quote_html(array $q, $p): string {
        $days = $q['days'];
        $html = '<h4>Your hire quotation</h4><p class="evh-insurance-summary">Vehicle insurance: ' . (!empty($q['input']['insurance']) ? 'EVision insurance selected' : (!empty($q['input']['own_insurance']) ? 'You will provide your own insurance' : 'Please choose your insurance cover')) . '</p><p>' . esc_html($days . ' chargeable day' . ($days === 1 ? '' : 's')) . '</p>';
        if ($days >= 30) { $html .= '<p><strong>' . self::money_pair($q['monthly'], $p) . ' per month</strong></p>'; }
        $html .= '<p>' . self::money_pair($q['daily'], $p) . ' per day</p>';
        $first = $q['payment'] === 'first';
        $detail = $first ? 'First month of your hire' : esc_html($days . ' × ') . wp_kses_post(wc_price($q['daily'])) . ' per day, ex VAT';
        $html .= '<div class="evh-breakdown">' . self::quote_row($first ? 'Vehicle hire: first payment' : 'Vehicle hire', self::money_pair($q['rental_due'], $p), $detail);
        foreach ($q['extras'] as $key => $extra) {
            $detail = $first && $q['remaining'] > 0 ? 'First month at ' . wp_kses_post(wc_price($extra['daily'])) . ' per day' : esc_html($days . ' × ') . wp_kses_post(wc_price($extra['daily'])) . ' per day';
            if ($key === 'insurance') { $detail = esc_html(($q['input']['drivers'] ?? 1) . ' insured driver(s), combined cost: ') . $detail; }
            $detail .= '<span class="evh-row-detail">No VAT</span>';
            if ($first && $q['remaining'] > 0) {
                $detail .= '<span class="evh-row-detail">Full hire: ' . esc_html($days . ' × ') . wp_kses_post(wc_price($extra['daily'])) . ' = ' . wp_kses_post(wc_price($extra['total'])) . '</span>';
            }
            $html .= self::quote_row(self::EXTRA_LABELS[$key], wp_kses_post(wc_price($extra['due'])), $detail);
        }
        foreach (array('start' => 'Collection', 'end' => 'Return') as $key => $label) {
            if ($q['input'][$key . '_time'] !== 'out') { continue; }
            $html .= self::quote_row('Out-of-hours ' . strtolower($label), self::money_pair($q['fee_each'], $p), 'Our team will contact you to arrange the appointment time.');
        }
        $contract = self::gross($q['rental_total'] + $q['fees'], $p) + $q['extras_total'];
        $html .= '</div><div class="evh-quote-total"><span>Total payable today</span><strong>' . self::total_today_html($q, $p) . '</strong><small>Includes VAT where applicable. Insurance has no VAT.</small></div>';
        if ($q['payment'] === 'first' && $q['remaining'] > 0) {
            $remaining = self::gross($q['remaining'], $p) + $q['extras_total'] - $q['extras_due'];
            $html .= '<div class="evh-contract-summary">' . self::quote_row('Full contract value including selected extras', wp_kses_post(wc_price($contract))) . self::quote_row('Remaining contract balance', wp_kses_post(wc_price($remaining))) . '</div><p>You are paying for the first month of your hire today. Our accounts department will contact you to arrange Direct Debit payments for the remaining term of your hire contract. Final payments will be adjusted to the agreed contract total.</p>';
        }
        return $html;
    }

    public static function ajax_quote(): void {
        nocache_headers();
        if (!check_ajax_referer('evh_quote', 'nonce', false)) { wp_send_json_error(array('message' => 'Please refresh the page and try again.'), 403); }
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_scalar($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $bucket = 'evh_q_' . substr(hash_hmac('sha256', $ip . ':' . intdiv(time(), 60), wp_salt('nonce')), 0, 32);
        $requests = (int) get_transient($bucket);
        if ($requests >= 60) { wp_send_json_error(array('message' => 'Please wait a minute before requesting another quote.'), 429); }
        set_transient($bucket, $requests + 1, 120);
        try {
            $id = isset($_POST['product_id']) && is_scalar($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            $input = self::input($_POST);
            $q = self::quote($id, $input);
            $multiplier = $input['overseas'] ? 2 : 1;
            $offer = self::plain_money($q['daily'] * 0.5 * $multiplier) . ' per driver per day (no VAT)';
            if ($q['days'] >= 30) { $offer = self::plain_money($q['monthly'] * 0.5 * $multiplier) . ' per driver per month; ' . $offer; }
            wp_send_json_success(array('html' => self::quote_html($q, wc_get_product($id)), 'days' => $q['days'], 'available' => EVH_Storage::available($id, $input['start'], $input['end']),
                'total_today_html' => self::total_today_html($q, wc_get_product($id)), 'payment' => $q['payment'],
                'offers' => array('insurance' => $offer, 'tyre' => self::plain_money(4 * $multiplier) . ' per day (no VAT)', 'screen' => self::plain_money(4 * $multiplier) . ' per day (no VAT)')));
        } catch (Throwable $e) { wp_send_json_error(array('message' => $e instanceof RuntimeException ? $e->getMessage() : 'The quotation could not be calculated. Please contact our team.'), 400); }
    }

    public static function add_validation($passed, $id, $quantity, $variation_id = 0, $variations = array(), $data = array()) {
        $p = wc_get_product($id);
        if (!self::enabled($p)) { return $passed; }
        try {
            if ((float) $quantity !== 1.0 || $variation_id) { throw new RuntimeException('Add one vehicle per hire selection.'); }
            $nonce = $_POST['evh_nonce'] ?? '';
            if (!is_scalar($nonce) || !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), 'evh_booking')) { throw new RuntimeException('Please select your hire details on the vehicle page.'); }
            self::quote($id, self::input($_POST), true, true);
        } catch (Throwable $e) {
            wc_add_notice($e instanceof RuntimeException ? $e->getMessage() : 'Your booking could not be checked. Please try again.', 'error');
            return false;
        }
        return $passed;
    }
    public static function cart_data($data, $id, $variation_id, $quantity): array {
        if (!self::enabled(wc_get_product($id))) { return $data; }
        $data['evh_input'] = self::input($_POST);
        $data['evh_unique'] = wp_generate_uuid4();
        return $data;
    }

    public static function prices($cart): void {
        if (is_admin() && !wp_doing_ajax()) { return; }
        foreach ($cart->get_cart() as $key => $item) {
            if (!isset($item['evh_input'])) { continue; }
            try {
                $q = self::quote((int) $item['product_id'], $item['evh_input'], false, true);
                $cart->cart_contents[$key]['evh_quote'] = $q;
                unset($cart->cart_contents[$key]['evh_error']);
                $cart->cart_contents[$key]['data'] = clone $item['data'];
                $cart->cart_contents[$key]['data']->set_price(self::base_price($q['rental_due']));
                $cart->cart_contents[$key]['data']->set_tax_status('taxable');
                $cart->cart_contents[$key]['data']->set_tax_class(self::TAX_CLASS);
            } catch (Throwable $e) {
                $cart->cart_contents[$key]['evh_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Hire quotation is unavailable.';
                unset($cart->cart_contents[$key]['evh_quote']);
            }
        }
    }
    public static function fees($cart): void {
        $number = 0;
        foreach ($cart->get_cart() as $key => $item) {
            $q = $item['evh_quote'] ?? null;
            if (!$q) { continue; }
            $suffix = ' · ' . $item['data']->get_name() . ' · ' . $q['input']['start'] . ' · Hire ' . (++$number);
            foreach (array('start' => 'Out-of-hours collection', 'end' => 'Out-of-hours return') as $endpoint => $label) {
                if ($q['input'][$endpoint . '_time'] === 'out') { $cart->add_fee($label . $suffix, $q['fee_each'], true, self::TAX_CLASS); }
            }
            foreach ($q['extras'] as $extra_key => $extra) { $cart->add_fee(self::EXTRA_LABELS[$extra_key] . ' (no VAT)' . $suffix, $extra['due'], false); }
        }
    }
    public static function check_cart(): void {
        if (!WC()->cart) { return; }
        $groups = array();
        try {
            foreach (WC()->cart->get_cart() as $item) {
                if (!self::enabled($item['data']) && !isset($item['evh_input'])) { continue; }
                if (!isset($item['evh_input']) || (int) $item['quantity'] !== 1 || (float) $item['quantity'] !== 1.0) { throw new RuntimeException('Please remove this vehicle and select its hire details again.'); }
                self::quote((int) $item['product_id'], $item['evh_input'], false, true);
                $groups[$item['product_id']][] = array('start_date' => $item['evh_input']['start'], 'end_date' => $item['evh_input']['end'], 'quantity' => 1);
            }
            foreach ($groups as $id => $rows) {
                $start = min(array_column($rows, 'start_date')); $end = max(array_column($rows, 'end_date'));
                $all = array_merge(EVH_Storage::active((int) $id, $start, $end), $rows);
                if (EVH_Rules::peak($all, $start, $end) > (int) get_post_meta($id, '_evh_capacity', true)) { throw new RuntimeException('There are not enough vehicles for all the hires in your basket. Please remove a hire or change dates.'); }
            }
        } catch (Throwable $e) { wc_add_notice($e instanceof RuntimeException ? $e->getMessage() : 'Hire availability could not be verified.', 'error'); }
    }

    public static function item_data($data, $item): array {
        if (empty($item['evh_input'])) { return $data; }
        $input = $item['evh_input'];
        foreach (array('start' => 'Collection', 'end' => 'Return') as $key => $label) {
            $data[] = array('key' => $label, 'value' => $input[$key] . ' ' . ($input[$key . '_time'] === 'out' ? '(out of hours, time to be agreed)' : $input[$key . '_time']));
        }
        $q = $item['evh_quote'] ?? null;
        if ($q) {
            $data[] = array('key' => 'Refundable damage deposit', 'value' => self::deposit_message($item['data'], !empty($input['insurance'])));
            $data[] = array('key' => 'Vehicle insurance', 'value' => !empty($input['insurance']) ? 'EVision insurance selected' : (!empty($input['own_insurance']) ? 'Customer will provide own insurance' : 'Not selected'));
            if (!empty($input['own_insurance'])) { $data[] = array('key' => 'Own-insurance requirements acknowledged', 'value' => !empty($input['own_ack']) ? 'Yes' : 'No'); }
            if (!empty($input['insurance'])) { $data[] = array('key' => 'Insured drivers', 'value' => (string) ($input['drivers'] ?? 1)); }
            $data[] = array('key' => 'Hire length', 'value' => $q['days'] . ' days');
            $data[] = array('key' => 'Payment', 'value' => $q['payment'] === 'first' ? 'First month only; accounts team will arrange Direct Debit' : 'Full hire amount');
            $data[] = array('key' => 'Daily rental rate', 'value' => wp_strip_all_tags(self::money_pair($q['daily'], $item['data'])));
            if ($q['days'] >= 30) { $data[] = array('key' => 'Monthly rental rate', 'value' => wp_strip_all_tags(self::money_pair($q['monthly'], $item['data']))); }
            $data[] = array('key' => 'Full contract value incl VAT and extras', 'value' => wp_strip_all_tags(wc_price(self::gross($q['rental_total'] + $q['fees'], $item['data']) + $q['extras_total'])));
            $data[] = array('key' => 'International Insurance Required', 'value' => $input['overseas'] ? 'Yes; insurance charges doubled' : 'No');
        }
        return $data;
    }

    public static function order_item($item, $key, $values, $order): void {
        if (empty($values['evh_input'])) { return; }
        $q = self::quote((int) $values['product_id'], $values['evh_input'], false, true);
        $item->add_meta_data('_evh_quote', $q, true);
        foreach (self::item_data(array(), array_merge($values, array('evh_quote' => $q))) as $row) { $item->add_meta_data($row['key'], $row['value'], true); }
        $item->add_meta_data('Driver requirements', self::eligibility($values['data']), true);
        $item->add_meta_data('Insurance eligibility confirmed', $q['input']['eligible'] ? 'Yes' : 'No insurance selected', true);
        if ($q['extras']) {
            $item->add_meta_data('Insurance contract total (no VAT)', wc_format_decimal($q['extras_total'], 2), true);
            $item->add_meta_data('Insurance payable today (no VAT)', wc_format_decimal($q['extras_due'], 2), true);
        }
    }

    public static function order_created($order): void {
        if (!self::has_hire($order)) { return; }
        try { EVH_Storage::reserve_order($order); }
        catch (Throwable $e) {
            $order->update_status('failed', 'EVision reservation could not be secured. No payment should be initiated.');
            throw new Exception($e instanceof RuntimeException ? $e->getMessage() : 'Reservation could not be secured. Please try again.');
        }
    }
    public static function before_pay($order): void {
        if (!self::has_hire($order)) { return; }
        self::ready();
        foreach ($order->get_items() as $item) {
            $q = $item->get_meta('_evh_quote');
            if ($q) { self::quote((int) $item->get_product_id(), $q['input'], false, true); }
        }
        EVH_Storage::reserve_order($order);
    }
    public static function status_changed($id, $from, $to, $order): void {
        if (self::$status_guard || !self::has_hire($order)) { return; }
        if ($from !== '' && in_array($to, array('processing', 'completed', 'on-hold'), true)) { return; }
        // An earlier status hook may already have moved a failed allocation to on-hold.
        if ($order->get_status() !== $to) { return; }
        try {
            if (in_array($to, array('cancelled', 'failed', 'refunded'), true)) { EVH_Storage::release_order((int) $id); }
            elseif (in_array($to, array('processing', 'completed', 'on-hold'), true)) {
                EVH_Storage::reserve_order($order, true);
                $order->delete_meta_data('_evh_allocation_conflict'); $order->save();
            }
        } catch (Throwable $e) {
            self::$status_guard = true;
            $order->update_meta_data('_evh_allocation_conflict', 'yes');
            $order->save();
            $order->update_status('on-hold', 'URGENT: EVision availability could not be reserved. Do not confirm collection. Review payment and allocate a vehicle or refund manually. ' . ($e instanceof RuntimeException ? $e->getMessage() : 'Storage error.'));
            self::$status_guard = false;
            // Operational alert to the store administrator, part of the authorised booking workflow.
            $mailer = WC()->mailer();
            $mailer->send(get_option('admin_email'), 'EVision booking allocation requires attention: order ' . $order->get_order_number(), 'Order ' . $order->get_order_number() . ' requires manual review. Availability could not be secured. Check the order notes before confirming collection.');
        }
    }

    public static function quantity($args, $p): array { if (self::enabled($p)) { $args['min_value'] = 1; $args['max_value'] = 1; } return $args; }
    public static function update_quantity($passed, $key, $values, $quantity) {
        if (isset($values['evh_input']) && (float) $quantity !== 0.0 && (float) $quantity !== 1.0) { wc_add_notice('Each hire selection reserves one vehicle. Add another selection for an additional vehicle.', 'error'); return false; }
        return $passed;
    }
    public static function coupon($valid, $coupon) {
        if (WC()->cart) { foreach (WC()->cart->get_cart() as $item) { if (isset($item['evh_input'])) { throw new Exception('Coupons are unavailable for vehicle-hire bookings in this version.'); } } }
        return $valid;
    }
    public static function purchasable($valid, $p) {
        if ($p->get_meta('_evh_configured') === 'yes' && !self::enabled($p)) { return false; }
        return self::enabled($p) ? ($p->get_status() === 'publish' && (int) $p->get_meta('_evh_capacity') > 0) : $valid;
    }
    public static function price_html($html, $p): string {
        if ($p->get_meta('_evh_configured') === 'yes' && !self::enabled($p)) { return 'Online hire is unavailable. Please contact our team.'; }
        if (!self::enabled($p)) { return $html; }
        $daily = array();
        foreach (EVH_Rules::BANDS as $b) {
            try { $q = EVH_Rules::price($b['min'], self::rates($p), 'full'); $daily[] = $q['daily']; } catch (Throwable $e) { continue; }
        }
        return $daily ? 'From ' . self::money_pair(min($daily), $p) . ' per day.<small class="evh-rate-hint">Select dates for your rate.</small>' : 'Select dates or contact us for a quotation.';
    }
    public static function order_message($order, $sent_to_admin = false, $plain = false, $email = null): void {
        if (!self::has_hire($order)) { return; }
        $messages = array();
        if ($order->get_meta('_evh_allocation_conflict')) { $messages[] = 'Your booking is awaiting manual availability review. Please contact our team before travelling to collect.'; }
        foreach ($order->get_items() as $item) {
            $q = $item->get_meta('_evh_quote');
            if (!$q) { continue; }
            if ($q['payment'] === 'first' && $q['remaining'] > 0) { $messages[] = 'For ' . $item->get_name() . ', your online payment covers the first month. Our accounts department will contact you to arrange Direct Debit for the remaining contract term. Selected insurance follows the same payment period; final payments are reconciled to the agreed contract total.'; }
            if ($q['out_count']) { $messages[] = 'Out-of-hours appointments require agreement. Our team will contact you to arrange the time. Please do not travel until the appointment is agreed.'; }
        }
        foreach (array_unique($messages) as $message) { echo $plain ? esc_html($message) . "\n\n" : '<p>' . esc_html($message) . '</p>'; }
    }
    public static function block_store_api($result, $server, $request) {
        if (strpos($request->get_route(), '/wc/store/') !== 0) { return $result; }
        $id = absint($request->get_param('id'));
        $rental = $id && self::enabled(wc_get_product($id));
        if (WC()->cart) { foreach (WC()->cart->get_cart() as $item) { if (isset($item['evh_input'])) { $rental = true; break; } } }
        if ($rental) { return new WP_Error('evh_classic_checkout_required', 'Please use the classic basket and checkout for vehicle hire.', array('status' => 400)); }
        return $result;
    }
}
