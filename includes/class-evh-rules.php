<?php
/** Pure pricing and calendar rules. No browser-supplied prices are accepted. */
defined('ABSPATH') || exit;

final class EVH_Rules {
    public const BANDS = array(
        array('key' => 'daily', 'label' => '1–6 days: daily price', 'min' => 1, 'max' => 6, 'unit' => 'day'),
        array('key' => 'weekly', 'label' => '7–29 days: weekly price', 'min' => 7, 'max' => 29, 'unit' => 'week'),
        array('key' => 'monthly', 'label' => '30–364 days: monthly price', 'min' => 30, 'max' => 364, 'unit' => 'month'),
        array('key' => 'year1', 'label' => '365–729 days: monthly price', 'min' => 365, 'max' => 729, 'unit' => 'month'),
        array('key' => 'year2', 'label' => '730–1094 days: monthly price', 'min' => 730, 'max' => 1094, 'unit' => 'month'),
        array('key' => 'year3', 'label' => '1095+ days: monthly price', 'min' => 1095, 'max' => 3650, 'unit' => 'month'),
    );

    public static function date($value): DateTimeImmutable {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new RuntimeException('Please choose valid collection and return dates.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/London'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('Please choose valid collection and return dates.');
        }
        return $date;
    }

    public static function working(DateTimeImmutable $date, callable $closed): bool {
        return (int) $date->format('N') <= 5 && !$closed($date->format('Y-m-d'));
    }

    public static function office_open(DateTimeImmutable $now, callable $closed): bool {
        $time = $now->format('H:i');
        return self::working($now, $closed) && $time >= '08:00' && $time < '18:00';
    }

    public static function next_working(DateTimeImmutable $date, callable $closed): DateTimeImmutable {
        for ($i = 0; $i < 370; $i++) {
            if (self::working($date, $closed)) { return $date; }
            $date = $date->modify('+1 day');
        }
        throw new RuntimeException('No opening date is available. Please contact our team.');
    }

    /** Booking date is not counted. Requests made outside office hours start at the next office day. */
    public static function earliest(DateTimeImmutable $now, int $notice, callable $closed, bool $out = false): DateTimeImmutable {
        $now = $now->setTimezone(new DateTimeZone('Europe/London'));
        $base = self::date($now->format('Y-m-d'));
        if (!self::office_open($now, $closed)) {
            if (self::working($base, $closed) && $now->format('H:i') < '08:00') {
                $base = self::next_working($base, $closed);
            } else {
                $base = self::next_working($base->modify('+1 day'), $closed);
            }
            $notice = max(1, $notice);
        } elseif (!$out && $notice === 0 && $now->format('H:i') >= '12:00') {
            $notice = 1;
        }
        for ($i = 0; $i < $notice; $i++) {
            $base = self::next_working($base->modify('+1 day'), $closed);
        }
        return $base;
    }

    public static function price(int $days, array $rates, string $payment): array {
        if ($days < 1 || $days > 3650 || !in_array($payment, array('full', 'first'), true)) {
            throw new RuntimeException('Please choose a valid hire duration and payment option.');
        }
        foreach (self::BANDS as $band) {
            if ($days < $band['min'] || $days > $band['max']) { continue; }
            $value = $rates[$band['key']] ?? null;
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0 || (float) $value > 999999.99) {
                throw new RuntimeException('A price is not configured for this hire length. Please contact our team.');
            }
            $rate = (float) $value;
            $daily = $band['unit'] === 'day' ? $rate : ($band['unit'] === 'week' ? $rate / 7 : $rate * 12 / 365);
            $monthly = $daily * 365 / 12;
            $total = $daily * $days;
            if ($days < 30) { $payment = 'full'; }
            $pay = $payment === 'first' ? min($total, $monthly) : $total;
            return array('days' => $days, 'band' => $band['key'], 'daily' => $daily, 'monthly' => $monthly,
                'rental_total' => $total, 'rental_due' => $pay, 'remaining' => max(0, $total - $pay), 'payment' => $payment);
        }
        throw new RuntimeException('This hire duration is unavailable.');
    }

    public static function quote(array $input, array $rates, int $notice, DateTimeImmutable $now, callable $closed, float $fee): array {
        $start = self::date($input['start'] ?? '');
        $end = self::date($input['end'] ?? '');
        $today = self::date($now->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d'));
        if ($start < $today || $end < $start || $end > $today->modify('+10 years')) {
            throw new RuntimeException('Choose dates in order, within the next ten years.');
        }
        $days = max(1, (int) $start->diff($end)->format('%a'));
        $result = self::price($days, $rates, $input['payment'] ?? 'full');
        $out_count = 0;
        foreach (array('start' => 'Collection', 'end' => 'Return') as $key => $label) {
            $date = $key === 'start' ? $start : $end;
            if ((int) $date->format('N') === 7 || $closed($date->format('Y-m-d'))) {
                throw new RuntimeException($label . ' is unavailable on Sundays, bank holidays and closure dates. Choose another date.');
            }
            $time = $input[$key . '_time'] ?? '';
            $out = $time === 'out';
            if (!$out && (!is_string($time) || !preg_match('/^(?:0[89]|1[0-7]):(?:00|15|30|45)$|^18:00$/D', $time))) {
                throw new RuntimeException('Choose an opening-hours time or an out-of-hours appointment request.');
            }
            if ((int) $date->format('N') === 6 && !$out) {
                throw new RuntimeException('Saturday appointments must be requested as out of hours.');
            }
            if ($key === 'start' && $date < self::earliest($now, $notice, $closed)) {
                throw new RuntimeException('This vehicle needs more preparation notice. Earliest collection date: ' . self::earliest($now, $notice, $closed)->format('d/m/Y') . '.');
            }
            if ($out) {
                if ($date < self::earliest($now, 2, $closed, true)) {
                    throw new RuntimeException($label . ' out of hours requires two working days’ notice. Please select a later date or an opening-hours time.');
                }
                $out_count++;
            } elseif ($date->format('Y-m-d') === $now->format('Y-m-d') && $time <= $now->format('H:i')) {
                throw new RuntimeException($label . ' time must be later than the current time.');
            }
        }
        if ($start == $end && $input['start_time'] !== 'out' && $input['end_time'] !== 'out' && $input['end_time'] <= $input['start_time']) {
            throw new RuntimeException('Return time must be after collection time.');
        }
        $result['fees'] = $out_count * $fee;
        $result['fee_each'] = $fee;
        $result['out_count'] = $out_count;
        $multiplier = !empty($input['overseas']) ? 2 : 1;
        $drivers = $input['drivers'] ?? 1;
        if (!is_scalar($drivers) || !preg_match('/^[1-9][0-9]?$/', (string) $drivers)) { throw new RuntimeException('Choose a valid number of insured drivers (1–99).'); }
        $drivers = (int) $drivers;
        $insurance_daily = array(
            'insurance' => $result['daily'] * 0.5 * $drivers,
            'tyre' => 4.0,
            'screen' => 4.0,
        );
        $paid_days = $result['payment'] === 'first' ? min($days, 365 / 12) : $days;
        $result['extras'] = array();
        $result['extras_total'] = 0;
        $result['extras_due'] = 0;
        foreach ($insurance_daily as $key => $daily) {
            if (empty($input[$key])) { continue; }
            $daily *= $multiplier;
            $result['extras'][$key] = array('daily' => $daily, 'due' => $daily * $paid_days, 'total' => $daily * $days);
            $result['extras_total'] += $daily * $days;
            $result['extras_due'] += $daily * $paid_days;
        }
        $result['input'] = $input;
        return $result;
    }

    /** Inclusive collection/return dates: vehicles are never resold before a return time is agreed. */
    public static function peak(array $rows, string $start, string $end): int {
        $events = array();
        foreach ($rows as $row) {
            if ($row['end_date'] < $start || $row['start_date'] > $end) { continue; }
            $a = max($start, $row['start_date']);
            $b = self::date(min($end, $row['end_date']))->modify('+1 day')->format('Y-m-d');
            $q = (int) $row['quantity'];
            $events[$a] = ($events[$a] ?? 0) + $q;
            $events[$b] = ($events[$b] ?? 0) - $q;
        }
        ksort($events);
        $current = 0; $peak = 0;
        foreach ($events as $change) { $current += $change; $peak = max($peak, $current); }
        return $peak;
    }
}
