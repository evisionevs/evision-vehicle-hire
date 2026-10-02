<?php
defined('ABSPATH') || exit;

final class EVH_Storage {
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'evh_reservations';
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint unsigned NOT NULL,
            order_id bigint unsigned NOT NULL DEFAULT 0,
            item_id bigint unsigned NOT NULL DEFAULT 0,
            start_date date NOT NULL,
            end_date date NOT NULL,
            quantity int unsigned NOT NULL DEFAULT 1,
            state varchar(20) NOT NULL DEFAULT 'held',
            expires_at datetime DEFAULT NULL,
            reference varchar(120) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY availability (product_id,state,start_date,end_date),
            KEY orders (order_id,item_id)
        ) ENGINE=InnoDB $charset;");
        update_option('evh_schema_version', '0.1.0', false);
    }

    public static function ready(): void {
        global $wpdb;
        static $checked = false;
        if ($checked) { return; }
        $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like(self::table())), ARRAY_A);
        if (!$row || strtoupper($row['Engine'] ?? '') !== 'INNODB') {
            throw new RuntimeException('Hire reservations require an InnoDB database table. Please contact our team.');
        }
        $version = (string) $wpdb->get_var('SELECT VERSION()');
        if (stripos($version, 'MariaDB') !== false) {
            // Some MariaDB servers report a 5.5.5 compatibility prefix.
            $version = preg_replace('/^5\.5\.5-/', '', $version);
            if (version_compare($version, '10.1', '<')) { throw new RuntimeException('Hire reservations require MariaDB 10.1 or newer.'); }
        } elseif (!$version || version_compare($version, '5.7.5', '<')) {
            throw new RuntimeException('Hire reservations require MySQL 5.7.5 or newer.');
        }
        $checked = true;
    }
    /** Read uncached capacity while holding the reservation lock. */
    public static function capacity(int $id): int {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_evh_capacity' ORDER BY meta_id DESC LIMIT 1", $id));
        if ($wpdb->last_error) { throw new RuntimeException('Vehicle capacity cannot be verified.'); }
        return (int) $value;
    }

    /** Advisory locks are shared by all reservation writers and fail closed if unsupported. */
    public static function locked(array $ids, callable $callback) {
        global $wpdb;
        $ids = array_unique(array_map('absint', $ids)); sort($ids, SORT_NUMERIC);
        $locks = array();
        try {
            foreach ($ids as $id) {
                $name = 'evh_' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $id), 0, 48);
                if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name)) !== 1) {
                    throw new RuntimeException('Availability is being updated. Please try again in a moment.');
                }
                $locks[] = $name;
            }
            return $callback();
        } finally {
            foreach (array_reverse($locks) as $name) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        }
    }

    public static function active(int $product_id, string $start, string $end, int $exclude_order = 0): array {
        global $wpdb;
        $table = self::table();
        $sql = "SELECT start_date,end_date,quantity FROM $table
            WHERE product_id=%d AND state IN ('held','confirmed','manual')
            AND (expires_at IS NULL OR expires_at > %s)
            AND start_date <= %s AND end_date >= %s";
        $args = array($product_id, gmdate('Y-m-d H:i:s'), $end, $start);
        if ($exclude_order) { $sql .= ' AND order_id != %d'; $args[] = $exclude_order; }
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        if ($wpdb->last_error) { throw new RuntimeException('Availability cannot be confirmed. Please contact our team.'); }
        return $rows ?: array();
    }

    public static function available(int $id, string $start, string $end): int {
        $capacity = self::capacity($id);
        return max(0, $capacity - EVH_Rules::peak(self::active($id, $start, $end), $start, $end));
    }

    public static function max_reserved(int $id): int {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/London')))->format('Y-m-d');
        return EVH_Rules::peak(self::active($id, $today, '9999-12-31'), $today, '9998-12-31');
    }

    public static function reserve_order($order, bool $confirmed = false): void {
        global $wpdb;
        $entries = array();
        foreach ($order->get_items() as $item_id => $item) {
            $q = $item->get_meta('_evh_quote', true);
            if (!$q) { continue; }
            $product_id = (int) $item->get_product_id();
            if ((float) $item->get_quantity() !== 1.0) { throw new RuntimeException('Each hire selection must reserve exactly one vehicle.'); }
            EVH_Rules::date($q['input']['start'] ?? ''); EVH_Rules::date($q['input']['end'] ?? '');
            if ($q['input']['end'] < $q['input']['start']) { throw new RuntimeException('The reservation dates are invalid.'); }
            $entries[] = array('product_id' => $product_id, 'item_id' => $item_id, 'quantity' => (int) $item->get_quantity(),
                'start_date' => $q['input']['start'], 'end_date' => $q['input']['end']);
        }
        if (!$entries) { return; }
        self::locked(array_column($entries, 'product_id'), function() use ($order, $confirmed, $entries, $wpdb) {
            self::ready();
            $order_id = $order->get_id();
            $table = self::table();
            $grouped = array();
            foreach ($entries as $entry) { $grouped[$entry['product_id']][] = $entry; }
            foreach ($grouped as $id => $new) {
                $product = wc_get_product($id);
                if (!$product || $product->get_meta('_evh_enabled') !== 'yes') {
                    throw new RuntimeException('This vehicle is no longer bookable. Please contact our team.');
                }
                $start = min(array_column($new, 'start_date')); $end = max(array_column($new, 'end_date'));
                $rows = array_merge(self::active($id, $start, $end, $order_id), $new);
                if (EVH_Rules::peak($rows, $start, $end) > self::capacity((int) $id)) {
                    throw new RuntimeException('A vehicle has just become unavailable. Please choose another vehicle or dates.');
                }
            }
            if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('The reservation could not be saved.'); }
            try {
                if ($wpdb->delete($table, array('order_id' => $order_id), array('%d')) === false) {
                    throw new RuntimeException('The reservation could not be saved. Please try again.');
                }
                foreach ($entries as $entry) {
                    $entry['order_id'] = $order_id;
                    $entry['state'] = $confirmed ? 'confirmed' : 'held';
                    $entry['expires_at'] = $confirmed ? null : gmdate('Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS);
                    $entry['created_at'] = gmdate('Y-m-d H:i:s');
                    $entry['reference'] = 'WooCommerce order ' . $order->get_order_number();
                    if ($wpdb->insert($table, $entry) === false) {
                        throw new RuntimeException('The reservation could not be saved. Please try again.');
                    }
                }
                if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('The reservation could not be committed.'); }
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                throw $e;
            }
        });
    }

    public static function release_order(int $order_id): void {
        global $wpdb;
        $table = self::table();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT product_id FROM $table WHERE order_id=%d", $order_id));
        if ($ids) {
            self::locked($ids, function() use ($wpdb, $table, $order_id) {
                if ($wpdb->update($table, array('state' => 'released', 'expires_at' => null), array('order_id' => $order_id)) === false) {
                    throw new RuntimeException('Reservation release failed. Please check the booking ledger.');
                }
            });
        }
    }

    public static function manual(int $id, string $start, string $end, int $quantity, string $reference): void {
        global $wpdb;
        self::ready();
        $a = EVH_Rules::date($start); $b = EVH_Rules::date($end);
        if ($b < $a || $quantity < 1 || $quantity > 10000) { throw new RuntimeException('Check the dates and quantity.'); }
        $product = wc_get_product($id);
        if (!$product || $product->get_meta('_evh_enabled') !== 'yes') { throw new RuntimeException('Choose an enabled hire product.'); }
        self::locked(array($id), function() use ($wpdb, $id, $start, $end, $quantity, $reference) {
            if (self::available($id, $start, $end) < $quantity) { throw new RuntimeException('Not enough vehicles are available for that period.'); }
            if (!$wpdb->insert(self::table(), array('product_id' => $id, 'start_date' => $start, 'end_date' => $end,
                'quantity' => $quantity, 'state' => 'manual', 'reference' => $reference, 'created_at' => gmdate('Y-m-d H:i:s')))) {
                throw new RuntimeException('The manual reservation could not be saved.');
            }
        });
    }

    public static function release_manual(int $reservation_id): void {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND order_id=0 AND state='manual'", $reservation_id));
        if (!$row) { throw new RuntimeException('Manual reservation not found.'); }
        self::locked(array($row->product_id), function() use ($wpdb, $table, $reservation_id) {
            if ($wpdb->update($table, array('state' => 'released'), array('id' => $reservation_id, 'order_id' => 0)) === false) {
                throw new RuntimeException('The reservation could not be released.');
            }
        });
    }
}
