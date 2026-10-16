<?php
defined('ABSPATH') || exit;

final class EVH_Plugin {
    public const TAX_CLASS = 'evision-vehicle-hire';
    public const EXTRA_LABELS = array('insurance' => 'Vehicle insurance', 'tyre' => 'Tyre insurance', 'screen' => 'Screen insurance');
    public const LOCATIONS = array(
        'head_office' => 'EVision Head Office - ME2 4DZ',
        'cullompton' => 'Cullompton, Devon - EX15 2PE',
        'castleford' => 'Castleford, West Yorkshire - WF10 5NW',
        'langley_park' => 'Langley Park, Durham - DH7 9TT',
        'delivery' => 'Delivery/collection required (quoted at checkout)',
    );
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
        foreach (array('collection_location', 'return_location') as $field) {
            $value = $source['evh_' . $field] ?? '';
            if (!is_scalar($value)) { throw new RuntimeException('Invalid collection or return location.'); }
            $value = sanitize_text_field(wp_unslash((string) $value));
            if ($value !== '' && !array_key_exists($value, self::LOCATIONS)) { throw new RuntimeException('Choose a listed collection or return location.'); }
            $input[$field] = $value;
        }
        $same = $source['evh_return_same'] ?? '1';
        if (!is_scalar($same) || !in_array((string) $same, array('0', '1'), true)) { throw new RuntimeException('Invalid return location selection.'); }
        $input['return_same'] = (int) $same;
        if ($input['return_same']) { $input['return_location'] = $input['collection_location']; }
        return $input;
    }

    public static function quote(int $id, array $input, bool $availability = true, bool $require_eligible = false): array {
        self::ready();
        $product = wc_get_product($id);
        if (!self::enabled($product) || $product->get_status() !== 'publish' || !is_finite((float) $product->get_meta('_evh_capacity')) || (int) $product->get_meta('_evh_capacity') < 1) {
            throw new RuntimeException('This vehicle is not available for online hire.');
        }
        if (!empty($input['return_same'])) { $input['return_location'] = $input['collection_location'] ?? ''; }
        foreach (array('collection_location', 'return_location') as $field) {
            $location = $input[$field] ?? '';
            if (!is_string($location) || ($location !== '' && !array_key_exists($location, self::LOCATIONS))) { throw new RuntimeException('Choose a listed collection or return location.'); }
            if ($require_eligible && $location === '') { throw new RuntimeException('Please select collection and return locations before adding your hire to the basket.'); }
        }
        if (!empty($input['insurance']) && !empty($input['own_insurance'])) { throw new RuntimeException('Choose either EVision vehicle insurance or your own insurance.'); }
        if ($require_eligible && empty($input['insurance']) && empty($input['own_insurance'])) { throw new RuntimeException('Please choose EVision vehicle insurance or confirm you will provide your own.'); }
        if ($require_eligible && !empty($input['own_insurance']) && empty($input['own_ack'])) { throw new RuntimeException('Please acknowledge the requirements for providing your own insurance.'); }
        EVH_Rules::date($input['start'] ?? ''); EVH_Rules::date($input['end'] ?? '');
        self::ensure_calendar($input['start']); self::ensure_calendar($input['end']);
        $result = EVH_Rules::quote($input, self::rates($product), (int)$product->get_meta('_evh_notice'), self::now(), array(__CLASS__, 'closed'), (float) self::option('out_fee', 25));
        if ($require_eligible && $result['extras'] && empty($input['eligible'])) {
            throw new RuntimeException('Please confirm the driver eligibility and permitted use requirements for our insurance.');
        }
        if ($availability && EVH_Storage::available($id, $input['start'],$input['end']) < 1) {
            throw new RuntimeException('No vehicles are available for the whole selected period. Please choose other dates or another vehicle.');
        }
        return $result;
    }

    public static function eligibility($product): string {
        $age = max(21, (int) ($product->get_meta('_evh_min_age') ?: 30));
        $years = max(1, (int) ($product->get_meta('_evh_licence_years') ?: 5));
        return 'All drivers must be aged ' . $age . '+, have held a driving licence for a minimum of ' .$years . ' years and have no more than 6 points on their licence. Our insurance cannot be used for couriers, chauffeurs or hire companies hiring our car or van to a third party.';
    }

    public static function deposit_amount($product, bool $evision): ?float {$value = $product->get_meta($evision ? '_evh_deposit_evision' : '_evh_deposit_own');
        return is_numeric($value) && is_finite((float)$value) && (float) $value > 0 ? (float)$value : null;
    }
    public static function deposit_message($product, bool $evision): string {$amount = self::deposit_amount($product,$evision);
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
        if (!self::enabled($product)) { return; }$out_fee_formatted = html_entity_decode(wp_strip_all_tags(wc_price(self::option('out_fee', 25))), ENT_QUOTES, 'UTF-8');
        $rates = self::rates($product);

        $band_custom_labels = array(
            'daily'   => array('period' => '1 - 7 Days',   'title' => 'Daily Hire',         'unit_label' => 'Day'),
            'weekly'  => array('period' => '7 - 29 Days',  'title' => 'Weekly Hire',        'unit_label' => 'Week'),
            'monthly' => array('period' => '1 Month +',    'title' => 'Monthly Hire',       'unit_label' => 'Month'),
            'year1'   => array('period' => '1 Year +',     'title' => 'Over 1 Year Hire',   'unit_label' => 'Month'),
            'year2'   => array('period' => '2 Years +',    'title' => 'Over 2 Years Hire',  'unit_label' => 'Month'),
            'year3'   => array('period' => '3 Years +',    'title' => 'Over 3 Years Hire',  'unit_label' => 'Month'),
        );

        echo '<section class="evh-booking" data-product="' . esc_attr($product->get_id()) . '">';
        
        // --- EVision Collapsible Rate Accordion ---
        echo '<details class="evh-rate-accordion">';
        echo '<summary class="evh-rate-summary">View Rental Rates & Tiers</summary>';
        echo '<div class="evh-rate-table-wrapper">';
        
        echo '<div class="evh-rate-toggle-bar">';
        echo '<label class="evh-rate-toggle-label"><input type="checkbox" class="evh-toggle-daily-rate"> Show daily rate equivalents</label>';
        echo '</div>';

        echo '<div class="evh-tier-grid">';
        
        foreach (EVH_Rules::BANDS as $band) {$key = $band['key'];$value = $rates[$key] ?? null;
            if (!is_numeric($value) || (float)$value <= 0) { continue; }
            
            $ex_vat = (float)$value;
            $inc_vat = self::gross($ex_vat, $product);$ex_daily = $band['unit'] === 'day' ?$ex_vat : ($band['unit'] === 'week' ?$ex_vat / 7 : $ex_vat * 12 / 365);$inc_daily = self::gross($ex_daily,$product);

            $meta = $band_custom_labels[$key] ?? array('period' => $band['label'], 'title' => 'Hire Rate', 'unit_label' =>$band['unit']);
            
            $inc_formatted = html_entity_decode(wp_strip_all_tags(wc_price($inc_vat)), ENT_QUOTES, 'UTF-8');
            $ex_formatted  = html_entity_decode(wp_strip_all_tags(wc_price($ex_vat)), ENT_QUOTES, 'UTF-8');
            
            $inc_daily_formatted = html_entity_decode(wp_strip_all_tags(wc_price($inc_daily)), ENT_QUOTES, 'UTF-8');
            $ex_daily_formatted  = html_entity_decode(wp_strip_all_tags(wc_price($ex_daily)), ENT_QUOTES, 'UTF-8');

            echo '<div class="evh-tier-card" ' .
                 'data-inc-base="' . esc_attr($inc_formatted . ' / ' .$meta['unit_label']) . '" ' .
                 'data-bus-base="Business Rate ' . esc_attr($ex_formatted) . ' + VAT (20%) / ' . esc_attr($meta['unit_label']) . '" ' .
                 'data-inc-daily="' . esc_attr($inc_daily_formatted . ' / Day (equiv.)') . '" ' .
                 'data-bus-daily="Business Rate ' . esc_attr($ex_daily_formatted) . ' + VAT (20%) / Day (equiv.)">';
                 
            echo '<div class="evh-tier-period">' . esc_html($meta['period']) . '</div>';
            echo '<div class="evh-tier-title">' . esc_html($meta['title']) . '</div>';
            echo '<div class="evh-tier-price-inc">' . esc_html($inc_formatted) . ' / ' . esc_html($meta['unit_label']) . '</div>';
            echo '<div class="evh-tier-price-bus">Business Rate ' . esc_html($ex_formatted) . ' + VAT (20%) / ' . esc_html($meta['unit_label']) . '</div>';
            echo '</div>';
        }
        
        echo '</div>'; // .evh-tier-grid
        echo '</div>'; // .evh-rate-table-wrapper
        echo '</details>';
        // --- End Rate Accordion ---

        echo '<div class="evh-live-total" data-state="empty" role="status" aria-live="polite" aria-atomic="true"><span class="evh-total-label">Total payable today</span><strong class="evh-total-amount">Select your dates</strong><small class="evh-total-state">Your total will update as you select your options.</small></div>';
        echo '<p class="evh-form-note">Dates and times use UK local time.</p><p class="evh-delivery-note">Vehicle delivery is available to add at checkout.</p>';
        wp_nonce_field('evh_booking', 'evh_nonce');
        echo '<div class="evh-locations"><label for="evh_collection_location">Collect Vehicle from:</label>';
        self::location_select('collection_location');
        echo '<label class="evh-return-same"><input type="checkbox" name="evh_return_same" value="1" checked> Return to the same location</label><div class="evh-return-location" hidden><label for="evh_return_location">Return Vehicle to:</label>';
        self::location_select('return_location');
        echo '</div><p id="evh-location-error" class="evh-location-error" role="alert" hidden></p></div>';
        $min = self::now()->format('Y-m-d');
        foreach (array('start' => 'Collection', 'end' => 'Return') as $key =>$label) {
            echo '<div class="evh-fields"><p><label for="evh_' . esc_attr($key) . '">' . esc_html($label) . ' date</label><input type="date" id="evh_' . esc_attr($key) . '" name="evh_' . esc_attr($key) . '" min="' . esc_attr($min) . '" required></p>';
            echo '<p><label for="evh_' . esc_attr($key) . '_time">' . esc_html($label) . ' time</label><select id="evh_' . esc_attr($key) . '_time" name="evh_' . esc_attr($key) . '_time">';
            for ($minutes = 480; $minutes <= 1080; $minutes += 15) {$time = sprintf('%02d:%02d', intdiv($minutes, 60),$minutes % 60);
                echo '<option value="' . esc_attr($time) . '"' . selected($time, '09:00', false) . '>' . esc_html($time) . '</option>';
            }
            echo '<option value="out">Out of hours: team to arrange (+' . esc_html($out_fee_formatted) . ' ex VAT)</option></select></p></div>';
        }
        echo '<p class="evh-form-note"><small>Note: Hires are calculated in 24-hour daily periods. Returning a vehicle before your agreed return time is still charged as a full daily rental.</small></p>';
        echo '<p id="evh-payment"><label for="evh_payment">Payment option</label><select name="evh_payment" id="evh_payment"><option value="full">Pay full hire amount now</option><option value="first">Pay first month only (30+ day hires)</option></select></p>';
        echo '<fieldset class="evh-insurance"><legend>Insurance</legend><p>No VAT is added to these insurance options. International insurance doubles all selected insurance charges.</p>';
        echo '<p><label><input type="checkbox" name="evh_insurance" value="1"> EVision vehicle insurance: <span data-evh-offer="insurance">50% of the applicable rental rate</span></label></p>';
        echo '<p><label><input type="checkbox" name="evh_own_insurance" value="1"> I will provide my own insurance</label></p>';
        echo '<p class="evh-insurance-error" role="alert" hidden></p>';
        echo '<div class="evh-own-insurance-note" hidden><p>A refundable deposit is required before hire. The EVision team will contact you to arrange payment. Deposit return is processed approximately 7–10 days after hire ends, subject to satisfactory vehicle condition.</p><ul><li>The policy must be in the name of the person or company paying for the booking.</li><li>Cover must be fully comprehensive.</li><li>The policy must state the vehicle registration, which we will confirm once booked.</li><li>Your insurer must be aware that the vehicle is hired.</li></ul><label><input type="checkbox" name="evh_own_ack" value="1"> I have read and understand these own-insurance requirements.</label></div>';
        echo '<p class="evh-drivers" hidden><label for="evh_drivers">Number of insured drivers</label><input type="number" id="evh_drivers" name="evh_drivers" min="1" max="99" step="1" value="1"><small>Includes the first driver. Each additional driver costs the same as the first.</small></p>';
        echo '<p><label><input type="checkbox" name="evh_tyre" value="1"> Tyre insurance: <span data-evh-offer="tyre">£4.00 per hire day</span></label></p>';
        echo '<p><label><input type="checkbox" name="evh_screen" value="1"> Screen insurance: <span data-evh-offer="screen">£4.00 per hire day</span></label></p>';
        echo '<p><label><input type="checkbox" name="evh_overseas" value="1"> International Insurance Required</label></p><p class="evh-terms-note">Full Terms and Conditions can be found <a href="https://www.evisionevs.co.uk/terms-conditions/" target="_blank" rel="noopener noreferrer">here</a>.</p></fieldset>';
        echo '<aside class="evh-deposit-note" data-own="' . esc_attr(self::deposit_amount($product, false) ?? '') . '" data-evision="' . esc_attr(self::deposit_amount($product, true) ?? '') . '"><strong>Refundable damage deposit</strong><span class="evh-deposit-info">' . esc_html(self::deposit_message($product, false)) . '</span><small>Processed for return approximately 7–10 days after the hire ends, subject to satisfactory vehicle condition.</small></aside>';
        echo '<div class="evh-eligibility"><strong>Driver and insurance requirements</strong><p>' . esc_html(self::eligibility($product)) . '</p>';
