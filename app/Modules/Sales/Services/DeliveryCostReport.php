<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Supplier\Services\LabourCost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ ডেলিভারি খরচ — চালান অনুযায়ী (মালিক, ২১ নভেম্বর ২০২৬; নমুনা v6, খ — "ok")।
 *
 * প্রতিটা পাকা চালানের মূল্য, গাড়ি ভাড়া, লোডিং, অন্যান্য, মোট খরচ আর খরচ % (মোট খরচ ÷ মূল্য)। হিসাব সব এখানে — পাতা কেবল দেখায়।
 *
 * ── কোন অঙ্ক কোথা থেকে (অনুমোদিত সিদ্ধান্ত) ──────────────────────────────────
 *  - মূল্য: `sal_challans.total`। ⛔ কেবল পাকা চালান ([[DocumentStatus::POSTED]]) — খসড়া, অনুমোদনের অপেক্ষা, বাতিল নয়
 *    (নিয়ম: মোটে কেবল চূড়ান্ত কাগজ)। চালানের কোয়েরি ডাকার জন দেয় (শাখার দেয়াল আর ছাঁকনিসহ)।
 *  - ভাড়া (ক): ট্রাকের ভাড়া (`sal_shipments.transport_cost`) রওনা হওয়া (পাকা), বাতিল নয় এমন ট্রিপে — তার সব পাকা চালানের মধ্যে
 *    মূল্যের অনুপাতে ভাগ ([[split()]]: bcmath-এ দুই দশমিক, বাকিটা শেষ চালানে — ভাগগুলোর যোগ ঠিক ভাড়ার সমান)। ⓘ ভাগ হয় ট্রিপের
 *    **সব** চালানে, ছাঁকনি বা শাখা যা-ই হোক — নইলে ছাঁকনি বদলালে একই চালানের ভাড়া বদলাত। এমন ট্রিপে না থাকলে চালানের নিজের
 *    ভাড়া, [[FarePayment::isOurs()]]-এর নিয়মে। ⓘ ভাড়াওয়ালা পাকা ট্রিপে থাকা চালানের নিজের ভাড়া ধরা হয় না — একই ট্রাক দুবার নয়।
 *  - (খ) ক্রেতা ভাড়া দেন বা ভাড়া নেই (`fare_paid_by` = customer · none) → ভাড়া ০ ([[FarePayment::isOurs()]])।
 *  - (গ) "বিলে যোগ" (`us_add_to_bill`) — ক্রেতার পাকা বিলে `sal_invoices.freight_charge`; বিলের সারি → চালানের সারি ধরে চালান চেনা,
 *    এক বিলে কয়েকটা চালান হলে মূল্যের অনুপাতে ভাগ। চালানের ভাড়া থেকে বাদ — নিট ভাড়া; ⛔ ০-এর নিচে নয় (বিলে বেশি আদায় হলে
 *    সেটা ভাড়ার আয়, ডেলিভারির খরচ ঋণাত্মক নয়)।
 *  - লোডিং: `loading_amount`, কেবল যখন পথটা আমাদের টাকা নেয় ([[LabourCost::costsUs()]] — নগদ বা ঠিকাদারের কাছে বাকি)।
 *  - অন্যান্য: কাউন্টারের `expense_amount` (চালানে লেখা) + চালানের বিপরীতে পাকা খরচ ভাউচার (EV, against = চালান), ⛔ চালানের নিজের
 *    ভাড়ার ভাউচার (`fare_voucher_id`) বাদ — ওটা ভাড়ায় আছে। ট্রিপের বিপরীতে পাকা খরচ ভাউচার (ট্রিপের ভাড়ার ভাউচার বাদে) ভাড়ার
 *    মতোই চালানগুলোয় ভাগ। ⓘ লোডিং খাতায় বসে দাখিলায়, ভাউচারে নয় ([[ChallanLoading]]) — তাই দুবার আসে না।
 *  - পয়েন্ট: চালানের নিজের পয়েন্ট নেই — গ্রাহকের পয়েন্ট ([[Customer::pointName()]], [[CostNarration]]-এর একই নিয়ম), না থাকলে
 *    ট্রিপের পথ; তাও না থাকলে পার্টির নাম দেখায়।
 *  - গাড়ি: চালানের গাড়ি ([[DeliveryChallan::vehiclePlate()]]), না থাকলে ট্রিপের গাড়ি।
 *  - মোটের % মোট থেকেই (মোট খরচ ÷ মোট মূল্য) — সারির %-এর গড় নয়।
 */
final class DeliveryCostReport
{
    public const GROUPS = ['challan', 'vehicle', 'point', 'month'];

    public const THRESHOLD_KEY = 'sales.delivery_cost.threshold_percent';

    public const DEFAULT_THRESHOLD = '3';

    /** চার্টে সবচেয়ে বেশি এতগুলো দণ্ড — বেশি % আগে বাছা, তারপর ছোট থেকে বড় */
    public const CHART_BARS = 15;

    public function __construct(private readonly FarePayment $fares) {}

    /**
     * ⭐ ভাগ — অঙ্কটা ওজনের অনুপাতে, দুই দশমিকে, সবচেয়ে-বড়-ভগ্নাংশ নিয়মে (largest remainder): প্রতিটা ভাগ আগে পয়সায় নিচে
     *   নামানো, তারপর বাকি পয়সাগুলো একটা করে সবচেয়ে বড় ভগ্নাংশের ঘরে (সমান হলে আগের ঘরে)। ⓘ তাই ভাগগুলোর যোগ ঠিক অঙ্কের
     *   সমান, আর কোনো ভাগ কখনো ঋণাত্মক নয়।
     * ⛔ আগে বাকিটা শেষ ঘরে যেত — ১০০০.০১ তিন ঘরে [৫০০০, ৫০০০, ০] ওজনে দিত ৫০০.০১ + ৫০০.০১ + (−০.০১) (স্বাধীন রিভিউ, ২১ নভেম্বর ২০২৬)।
     * ⓘ সব ওজন শূন্য হলে সমান ভাগ। ক্রম চাবির ক্রমে (ডাকার জন সাজিয়ে দেয়)।
     *
     * @param  array<int|string, string|int|float>  $weights
     * @return array<int|string, string>
     */
    public static function split(string|int|float $amount, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $amount = Money::round((string) $amount, 2);
        $negative = bccomp($amount, '0', 2) < 0;
        $cents = bcmul($negative ? bcmul($amount, '-1', 2) : $amount, '100', 0);

        $weights = array_map(fn ($w) => is_numeric($w) && bccomp((string) $w, '0', 4) > 0 ? (string) $w : '0', $weights);
        $sum = array_reduce($weights, fn (string $carry, string $w) => bcadd($carry, $w, 8), '0');

        if (bccomp($sum, '0', 8) <= 0) {
            $weights = array_fill_keys(array_keys($weights), '1');
            $sum = (string) count($weights);
        }

        $floors = [];
        $remainders = [];
        $given = '0';

        foreach ($weights as $key => $weight) {
            $exact = bcdiv(bcmul($cents, $weight, 8), $sum, 8);
            $floor = bcadd($exact, '0', 0);
            $floors[$key] = $floor;
            $remainders[$key] = bcsub($exact, $floor, 8);
            $given = bcadd($given, $floor, 0);
        }

        $left = (int) bcsub($cents, $given, 0);
        $order = array_keys($weights);
        $rank = array_flip($order);
        usort($order, fn ($a, $b) => bccomp($remainders[$b], $remainders[$a], 8) ?: $rank[$a] <=> $rank[$b]);

        for ($i = 0; $i < $left; $i++) {
            $key = $order[$i % count($order)];
            $floors[$key] = bcadd($floors[$key], '1', 0);
        }

        $parts = [];
        foreach ($floors as $key => $floor) {
            $part = bcdiv($floor, '100', 2);
            $parts[$key] = $negative && bccomp($part, '0', 2) !== 0 ? bcmul($part, '-1', 2) : $part;
        }

        return $parts;
    }

    /** খরচ % — দুই দশমিকে; মূল্য শূন্য হলে null */
    public static function percent(string $cost, string $value): ?string
    {
        if (bccomp($value, '0', 4) <= 0) {
            return null;
        }

        return Money::round(bcdiv(bcmul($cost, '100', 8), $value, 8), 2);
    }

    /** সীমা ছাড়িয়েছে কি না — সমান হলে নয় */
    public static function over(?string $percent, string $threshold): bool
    {
        return $percent !== null && bccomp($percent, $threshold, 2) > 0;
    }

    /**
     * প্রতিটা চালানের সারি — তারিখ আর নম্বরের ক্রমে।
     *
     * @param  Collection<int, DeliveryChallan>  $challans  পাকা চালান, `customer.location.parent` আর `vehicle` সহ
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $challans, string $threshold): array
    {
        $challans = $challans->filter(fn (DeliveryChallan $c) => in_array($c->status, DocumentStatus::POSTED, true))
            ->sortBy(fn (DeliveryChallan $c) => [$c->trx_date?->format('Y-m-d'), $c->document_no])->values();

        if ($challans->isEmpty()) {
            return [];
        }

        $ids = $challans->pluck('id')->map(fn ($id) => (int) $id)->all();
        $trips = $this->tripsOf($ids);
        [$tripFare, $faredOn] = $this->tripFares($trips);
        $tripOther = $this->tripOthers($trips);
        $freight = $this->freightBilled($ids);
        $own = $this->ownVouchers($challans);
        $routes = $this->routeNames($trips);

        $rows = [];

        foreach ($challans as $challan) {
            $id = (int) $challan->id;
            $value = Money::round((string) $challan->total, 2);

            // ভাড়া — পাকা ভাড়াওয়ালা ট্রিপের ভাগ, নইলে নিজের (আমাদের হলে)
            $fare = isset($faredOn[$id])
                ? ($tripFare[$id] ?? '0')
                : ($this->fares->isOurs($challan) ? Money::round((string) $challan->transport_cost, 2) : '0');
            $fare = bcsub($fare, $freight[$id] ?? '0', 2);
            $fare = bccomp($fare, '0', 2) < 0 ? '0.00' : $fare;

            $loading = LabourCost::costsUs($challan->loading_mode) ? Money::round((string) $challan->loading_amount, 2) : '0.00';

            $other = bcadd(Money::round((string) ($challan->expense_amount ?? '0'), 2), $own[$id] ?? '0', 2);
            $other = bcadd($other, $tripOther[$id] ?? '0', 2);

            $total = bcadd(bcadd($fare, $loading, 2), $other, 2);
            $percent = self::percent($total, $value);

            [$pointId, $pointName] = $this->pointOf($challan->customer);
            $trip = $trips['byChallan'][$id][0] ?? null;

            if ($pointName === null && $trip !== null && $trip->route_location_id !== null) {
                $pointId = (int) $trip->route_location_id;
                $pointName = $routes[$pointId] ?? null;
            }

            $plate = $challan->vehiclePlate();
            $vehicleId = $challan->vehicle_id;

            if ($plate === '' && $trip !== null) {
                $plate = (string) ($trip->registration_no ?? $trip->vehicle_no ?? '');
                $vehicleId = $trip->vehicle_id;
            }

            $date = $challan->trx_date instanceof Carbon ? $challan->trx_date : Carbon::parse((string) $challan->trx_date);

            $rows[] = [
                'id' => $id,
                'trx_date' => $date->toDateString(),
                'document_no' => (string) $challan->document_no,
                'customer_id' => $challan->customer_id === null ? null : (int) $challan->customer_id,
                'customer' => $challan->customer?->name() ?? '',
                'point_id' => $pointId,
                'point' => $pointName,
                'place' => $pointName ?? ($challan->customer?->name() ?? '—'),
                'vehicle_key' => $vehicleId !== null ? 'v'.$vehicleId : ($plate !== '' ? 'p'.$plate : 'none'),
                'vehicle' => $plate,
                'month' => $date->format('Y-m'),
                'value' => $value,
                'fare' => $fare,
                'freight' => Money::round($freight[$id] ?? '0', 2),
                'loading' => $loading,
                'other' => $other,
                'total' => $total,
                'percent' => $percent,
                'over' => self::over($percent, $threshold),
            ];
        }

        return $rows;
    }

    /**
     * ভাগ করে — গাড়ি, পয়েন্ট বা মাস; প্রতিটা ভাগে চালানের সংখ্যা, একই টাকার কলাম, আর % ভাগের মোট থেকে।
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function group(array $rows, string $by, string $threshold): array
    {
        if ($by === 'challan' || ! in_array($by, self::GROUPS, true)) {
            return $rows;
        }

        $groups = [];

        foreach ($rows as $row) {
            [$key, $label] = match ($by) {
                'vehicle' => [$row['vehicle_key'], $row['vehicle'] !== '' ? $row['vehicle'] : __('sales::delivery_cost.no_vehicle')],
                'point' => [$row['point_id'] !== null ? 'l'.$row['point_id'] : 'c'.($row['customer_id'] ?? 0), $row['place']],
                default => [$row['month'], Carbon::parse($row['month'].'-01')->translatedFormat('F Y')],
            };

            $groups[$key] ??= ['key' => (string) $key, 'label' => $label, 'count' => 0,
                'value' => '0', 'fare' => '0', 'loading' => '0', 'other' => '0', 'total' => '0'];
            $groups[$key]['count']++;

            foreach (['value', 'fare', 'loading', 'other', 'total'] as $money) {
                $groups[$key][$money] = bcadd($groups[$key][$money], (string) $row[$money], 2);
            }
        }

        foreach ($groups as $key => $group) {
            $groups[$key]['percent'] = self::percent($group['total'], $group['value']);
            $groups[$key]['over'] = self::over($groups[$key]['percent'], $threshold);
        }

        $groups = array_values($groups);

        // ⓘ মাস সময়ের ক্রমে; বাকি বেশি খরচ % আগে
        usort($groups, $by === 'month'
            ? fn (array $a, array $b) => strcmp($a['key'], $b['key'])
            : fn (array $a, array $b) => bccomp((string) ($b['percent'] ?? '-1'), (string) ($a['percent'] ?? '-1'), 2) ?: strcmp($a['label'], $b['label']));

        return $groups;
    }

    /**
     * মোট — টাকার যোগ, আর % মোট থেকে।
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{count: int, value: string, fare: string, loading: string, other: string, total: string, percent: ?string, over: bool}
     */
    public function totals(array $rows, string $threshold): array
    {
        $totals = ['count' => count($rows), 'value' => '0.00', 'fare' => '0.00', 'loading' => '0.00', 'other' => '0.00', 'total' => '0.00'];

        foreach ($rows as $row) {
            foreach (['value', 'fare', 'loading', 'other', 'total'] as $money) {
                $totals[$money] = bcadd($totals[$money], (string) $row[$money], 2);
            }
        }

        $totals['percent'] = self::percent($totals['total'], $totals['value']);
        $totals['over'] = self::over($totals['percent'], $threshold);

        return $totals;
    }

    /**
     * উপরের চার কার্ড — মোট মূল্য, মোট খরচ (ভাগসহ), গড় %, সবচেয়ে বেশি %।
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     * @return list<array{key: string, label: string, value: string, sub: string, gradient: array{0: string, 1: string}}>
     */
    public function cards(array $rows, array $totals, int $decimals): array
    {
        $top = null;

        foreach ($rows as $row) {
            if ($row['percent'] !== null && ($top === null || bccomp($row['percent'], $top['percent'], 2) > 0)) {
                $top = $row;
            }
        }

        $taka = fn (string $v) => '৳'.Money::format($v, $decimals);

        return [
            ['key' => 'value', 'label' => __('sales::delivery_cost.card_value'), 'value' => $taka($totals['value']),
                'sub' => __('sales::delivery_cost.n_challans', ['n' => $totals['count']]), 'gradient' => ['#2346d8', '#3b82f6']],
            ['key' => 'cost', 'label' => __('sales::delivery_cost.card_cost'), 'value' => $taka($totals['total']),
                'sub' => __('sales::delivery_cost.card_cost_sub', [
                    'fare' => Money::format($totals['fare'], $decimals),
                    'loading' => Money::format($totals['loading'], $decimals),
                    'other' => Money::format($totals['other'], $decimals),
                ]), 'gradient' => ['#7c3aed', '#a855f7']],
            ['key' => 'average', 'label' => __('sales::delivery_cost.card_average'),
                'value' => $totals['percent'] === null ? '—' : $totals['percent'].'%',
                'sub' => __('sales::delivery_cost.card_average_sub'), 'gradient' => ['#059669', '#10b981']],
            ['key' => 'top', 'label' => __('sales::delivery_cost.card_top'),
                'value' => $top === null ? '—' : $top['percent'].'%',
                'sub' => $top === null ? '' : $top['document_no'].' · '.$top['place'], 'gradient' => ['#e11d48', '#f43f5e']],
        ];
    }

    /**
     * চার্ট — গাড়ি অনুযায়ী খরচ % (চালান বা গাড়ি ধরে দেখালে), পয়েন্ট অনুযায়ী (পয়েন্ট ধরে), মাস অনুযায়ী (মাস ধরে)।
     * ⓘ দণ্ডের চওড়া আর সীমার দাগ অক্ষের সর্বোচ্চের % — অক্ষ পূর্ণ সংখ্যায়, সীমার উপরে অন্তত এক ধাপ।
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{by: string, title: string, max: int, ticks: list<int>, line: string, bars: list<array<string, mixed>>}
     */
    public function chart(array $rows, string $by, string $threshold): array
    {
        $chartBy = in_array($by, ['point', 'month'], true) ? $by : 'vehicle';
        $groups = array_values(array_filter($this->group($rows, $chartBy, $threshold), fn (array $g) => $g['percent'] !== null));

        if ($chartBy !== 'month') {
            // সবচেয়ে বেশি %-এর কয়েকটা, তারপর ছোট থেকে বড় (নমুনার ক্রম)
            usort($groups, fn (array $a, array $b) => bccomp((string) $b['percent'], (string) $a['percent'], 2));
            $groups = array_slice($groups, 0, self::CHART_BARS);
            usort($groups, fn (array $a, array $b) => bccomp((string) $a['percent'], (string) $b['percent'], 2));
        }

        // ⓘ অক্ষের মাপ bc-তে, float-এ নয় ([[MoneyIsNeverAFloatTest]]) — সবচেয়ে বড় % আর সীমা + ১-এর বড়টা, উপরে পূর্ণ সংখ্যায়
        $highest = bcadd($threshold, '1', 4);
        foreach ($groups as $g) {
            if (bccomp((string) $g['percent'], $highest, 4) > 0) {
                $highest = (string) $g['percent'];
            }
        }
        $max = max(1, self::wholeAbove($highest));
        $step = $max <= 10 ? 1 : intdiv($max + 4, 5);
        $max = intdiv($max + $step - 1, $step) * $step;
        // ⓘ চওড়া আর দাগ ০–১০০-এ আটকানো — পাতা কোনো cast ছাড়াই বসায়
        $width = function (string $v) use ($max): string {
            $w = Money::round(bcdiv(bcmul($v, '100', 8), (string) $max, 8), 2);

            return bccomp($w, '100', 2) > 0 ? '100.00' : (bccomp($w, '0', 2) < 0 ? '0.00' : $w);
        };

        return [
            'by' => $chartBy,
            'title' => __('sales::delivery_cost.chart_'.$chartBy),
            'max' => $max,
            'ticks' => range(0, $max, $step),
            'line' => $width($threshold),
            'bars' => array_map(fn (array $g) => [
                'label' => $g['label'],
                'percent' => $g['percent'],
                'width' => $width((string) $g['percent']),
                'over' => $g['over'],
                'value' => $g['value'],
                'total' => $g['total'],
            ], $groups),
        ];
    }

    /** ধনাত্মক সংখ্যার উপরের পূর্ণ সংখ্যা (ceil), bc-তে */
    private static function wholeAbove(string $value): int
    {
        $whole = bcadd($value, '0', 0);

        return (int) (bccomp($value, $whole, 8) > 0 ? bcadd($whole, '1', 0) : $whole);
    }

    /**
     * সব টাকা পূর্ণ টাকা হলে দশমিক নয় (নমুনার মতো "3,85,000"), কোথাও পয়সা থাকলে সব ঘরে দুই দশমিক — কলাম সোজা থাকে।
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function decimals(array $rows): int
    {
        foreach ($rows as $row) {
            foreach (['value', 'fare', 'loading', 'other', 'total'] as $money) {
                if (bccomp((string) $row[$money], bcadd((string) $row[$money], '0', 0), 2) !== 0) {
                    return 2;
                }
            }
        }

        return 0;
    }

    // ── ভিতরের কাজ ─────────────────────────────────────────────────────────────

    /**
     * চালানগুলোর বাতিল-নয় ট্রিপ — দুই দিক থেকে: চালান → ট্রিপ, ট্রিপ → তার সব পাকা চালান (মূল্যসহ)।
     * ⓘ শাখা বা দেয়ালের স্কোপ ছাড়া — ভাগের ভিত্তি ট্রিপের সব চালান; চালানের আইডিগুলো আগেই দেয়াল-মানা।
     *
     * @param  list<int>  $ids
     * @return array{byChallan: array<int, list<object>>, trips: array<int, object>, members: array<int, array<int, string>>}
     */
    private function tripsOf(array $ids): array
    {
        $links = DB::table('sal_shipment_lines as l')
            ->join('sal_shipments as s', 's.id', '=', 'l.shipment_id')
            ->leftJoin('mdm_vehicles as v', 'v.id', '=', 's.vehicle_id')
            // ⛔ কোম্পানির দেয়াল কাঁচা কোয়েরিতেও — আইডিগুলো দেয়াল-মানা, তবু দ্বিতীয় তালা (defence in depth)
            ->where('s.company_id', CompanyContext::id())
            ->whereIn('l.delivery_challan_id', $ids)
            ->where('s.status', '<>', DocumentStatus::CANCELLED)
            ->whereNull('s.deleted_at')
            ->orderBy('s.id')
            ->get(['l.delivery_challan_id', 's.id', 's.status', 's.transport_cost', 's.fare_voucher_id', 's.vehicle_id',
                's.vehicle_no', 's.route_location_id', 'v.registration_no']);

        $byChallan = [];
        $trips = [];

        foreach ($links as $link) {
            $byChallan[(int) $link->delivery_challan_id][] = $link;
            $trips[(int) $link->id] = $link;
        }

        $members = [];

        if ($trips !== []) {
            $rows = DB::table('sal_shipment_lines as l')
                ->join('sal_challans as c', 'c.id', '=', 'l.delivery_challan_id')
                ->whereIn('l.shipment_id', array_keys($trips))
                ->where('c.company_id', CompanyContext::id())
                ->whereIn('c.status', DocumentStatus::POSTED)
                ->whereNull('c.deleted_at')
                ->orderBy('c.id')
                ->get(['l.shipment_id', 'c.id', 'c.total']);

            foreach ($rows as $row) {
                $members[(int) $row->shipment_id][(int) $row->id] = (string) $row->total;
            }
        }

        return ['byChallan' => $byChallan, 'trips' => $trips, 'members' => $members];
    }

    /**
     * পাকা, ভাড়াওয়ালা ট্রিপের ভাড়া চালানগুলোয় ভাগ — আর কোন চালান এমন ট্রিপে আছে।
     *
     * @param  array{trips: array<int, object>, members: array<int, array<int, string>>}  $trips
     * @return array{0: array<int, string>, 1: array<int, true>}
     */
    private function tripFares(array $trips): array
    {
        $fare = [];
        $faredOn = [];

        foreach ($trips['trips'] as $tripId => $trip) {
            $cost = (string) ($trip->transport_cost ?? '0');

            if (! in_array($trip->status, DocumentStatus::POSTED, true) || ! is_numeric($cost) || bccomp($cost, '0', 4) <= 0) {
                continue;
            }

            foreach (self::split($cost, $trips['members'][$tripId] ?? []) as $challanId => $part) {
                $fare[$challanId] = bcadd($fare[$challanId] ?? '0', $part, 2);
                $faredOn[$challanId] = true;
            }
        }

        return [$fare, $faredOn];
    }

    /**
     * ট্রিপের বিপরীতে পাকা খরচ ভাউচার (ঘাট, টোল …) — ট্রিপের ভাড়ার ভাউচার বাদে — চালানগুলোয় মূল্যের অনুপাতে।
     *
     * @param  array{trips: array<int, object>, members: array<int, array<int, string>>}  $trips
     * @return array<int, string>
     */
    private function tripOthers(array $trips): array
    {
        $posted = array_filter($trips['trips'], fn (object $t) => in_array($t->status, DocumentStatus::POSTED, true));

        if ($posted === []) {
            return [];
        }

        $fareVouchers = array_values(array_filter(array_map(fn (object $t) => $t->fare_voucher_id === null ? null : (int) $t->fare_voucher_id, $posted)));

        $sums = Voucher::acrossBranches()
            ->where('type', Voucher::EXPENSE)
            // ⓘ পাকা মানে পাকা বা বন্ধ — বন্ধ মাসের ভাউচারও গোনা ([[DocumentStatus::POSTED]])
            ->whereIn('status', DocumentStatus::POSTED)
            ->where('against_type', Shipment::drillSourceType())
            ->whereIn('against_id', array_keys($posted))
            ->when($fareVouchers !== [], fn ($q) => $q->whereNotIn('id', $fareVouchers))
            ->groupBy('against_id')
            ->selectRaw('against_id, SUM(amount) as total')
            ->pluck('total', 'against_id');

        $other = [];

        foreach ($sums as $tripId => $amount) {
            foreach (self::split((string) $amount, $trips['members'][(int) $tripId] ?? []) as $challanId => $part) {
                $other[$challanId] = bcadd($other[$challanId] ?? '0', $part, 2);
            }
        }

        return $other;
    }

    /**
     * চালানের বিপরীতে পাকা খরচ ভাউচার — চালানের নিজের ভাড়ার ভাউচার বাদে।
     *
     * @param  Collection<int, DeliveryChallan>  $challans
     * @return array<int, string>
     */
    private function ownVouchers(Collection $challans): array
    {
        $fareVouchers = $challans->pluck('fare_voucher_id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        return Voucher::acrossBranches()
            ->where('type', Voucher::EXPENSE)
            // ⓘ পাকা মানে পাকা বা বন্ধ — বন্ধ মাসের ভাউচারও গোনা ([[DocumentStatus::POSTED]])
            ->whereIn('status', DocumentStatus::POSTED)
            ->where('against_type', DeliveryChallan::drillSourceType())
            ->whereIn('against_id', $challans->pluck('id')->all())
            ->when($fareVouchers !== [], fn ($q) => $q->whereNotIn('id', $fareVouchers))
            ->groupBy('against_id')
            ->selectRaw('against_id, SUM(amount) as total')
            ->pluck('total', 'against_id')
            ->map(fn ($v) => Money::round((string) $v, 2))
            ->all();
    }

    /**
     * ⭐ বিলে যোগ করা ভাড়া (`freight_charge`) — পাকা বিল থেকে, চালান ধরে; এক বিলে কয়েকটা চালান হলে মূল্যের অনুপাতে।
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function freightBilled(array $ids): array
    {
        $invoices = DB::table('sal_invoices as i')
            ->join('sal_invoice_lines as il', 'il.sales_invoice_id', '=', 'i.id')
            ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
            ->whereIn('cl.delivery_challan_id', $ids)
            ->where('i.company_id', CompanyContext::id())
            ->whereIn('i.status', DocumentStatus::POSTED)
            ->whereNull('i.deleted_at')
            ->where('i.freight_charge', '>', 0)
            ->distinct()
            ->get(['i.id', 'i.document_no', 'i.customer_id', 'i.total', 'i.freight_charge'])
            ->keyBy('id')
            ->map(fn (object $i) => $this->freightKept($i))
            ->filter(fn (string $amount) => bccomp($amount, '0', 2) > 0);

        if ($invoices->isEmpty()) {
            return [];
        }

        $members = [];

        foreach (DB::table('sal_invoice_lines as il')
            ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
            ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
            ->whereIn('il.sales_invoice_id', $invoices->keys()->all())
            ->where('c.company_id', CompanyContext::id())
            // ⓘ ভাগের ভিত্তি কেবল পাকা চালান — খসড়া বা বাতিল চালান ভাড়ার ভাগ নেয় না
            ->whereIn('c.status', DocumentStatus::POSTED)
            ->whereNull('c.deleted_at')
            ->distinct()
            ->orderBy('c.id')
            ->get(['il.sales_invoice_id', 'c.id', 'c.total']) as $row) {
            $members[(int) $row->sales_invoice_id][(int) $row->id] = (string) $row->total;
        }

        $freight = [];

        foreach ($invoices as $invoiceId => $amount) {
            foreach (self::split((string) $amount, $members[(int) $invoiceId] ?? []) as $challanId => $part) {
                $freight[$challanId] = bcadd($freight[$challanId] ?? '0', $part, 2);
            }
        }

        return $freight;
    }

    /**
     * ⭐ বিলে যোগ করা ভাড়ার যতটা সত্যিই থাকল — ফেরত (বাতিল নয়) আর পাকা ক্রেডিট নোট বাদ দিয়ে, বিলের মোটের অনুপাতে
     *   (IFRS 15: ফেরত ও ক্রেডিটে আয় কমে, ভাড়ার আয়ও সেই অনুপাতে)। পুরো ফেরত বা পুরো ক্রেডিট হলে ০ — তখন কিছুই কাটা হয় না।
     * ⓘ একই মাপ বিলের নিজের [[SalesInvoice::noteCreditRoom()]]-এর।
     */
    private function freightKept(object $invoice): string
    {
        $freight = Money::round((string) $invoice->freight_charge, 2);
        $total = (string) ($invoice->total ?? '0');

        if (bccomp($total, '0', 4) <= 0) {
            return '0.00';
        }

        $returned = (string) \App\Modules\Sales\Models\SalesReturn::query()
            ->where('sales_invoice_id', $invoice->id)
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->sum('total');

        $credited = (string) \App\Modules\Accounts\Models\Note::query()
            ->where('direction', \App\Modules\Accounts\Models\Note::CREDIT)
            ->where('party_type', 'customer')->where('party_id', $invoice->customer_id)
            ->where('against_no', $invoice->document_no)
            ->whereIn('status', DocumentStatus::POSTED)
            ->sum('total');

        $kept = bcsub(bcsub($total, $returned, 4), $credited, 4);

        if (bccomp($kept, '0', 4) <= 0) {
            return '0.00';
        }

        if (bccomp($kept, $total, 4) >= 0) {
            return $freight;
        }

        return Money::round(bcdiv(bcmul($freight, $kept, 8), $total, 8), 2);
    }

    /**
     * @param  array{trips: array<int, object>}  $trips
     * @return array<int, string>
     */
    private function routeNames(array $trips): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (object $t) => $t->route_location_id === null ? null : (int) $t->route_location_id, $trips['trips']))));

        if ($ids === []) {
            return [];
        }

        return Location::query()->whereIn('id', $ids)->get(['id', 'name_en', 'name_bn'])
            ->mapWithKeys(fn (Location $l) => [(int) $l->id => trim((string) ($l->name_bn ?: $l->name_en))])
            ->filter(fn (string $name) => $name !== '')
            ->all();
    }

    /**
     * গ্রাহকের পয়েন্ট — আইডি আর নাম ([[Customer::pointName()]]-এর একই নিয়ম: নিজের জায়গা, নইলে তার উপরের, যেটা পয়েন্ট স্তরের)।
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function pointOf(?Customer $customer): array
    {
        if ($customer === null) {
            return [null, null];
        }

        foreach ([$customer->location, $customer->location?->parent] as $place) {
            if ($place !== null && $place->level === Location::POINT) {
                $name = $customer->pointName();

                return $name === null ? [null, null] : [(int) $place->id, $name];
            }
        }

        return [null, null];
    }
}
