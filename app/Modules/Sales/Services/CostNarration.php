<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\Money;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Models\ShipmentLine;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ খরচের ভাউচারের বিবরণ — মালিক, ২১ নভেম্বর ২০২৬ (নমুনা v6, ক; "ok")।
 *
 * ক্রম: **খাত — চালান নং, পয়েন্ট (সব), গাড়ির নম্বর (ড্রাইভার: নাম)**। যেমন
 *   "গাড়ি ভাড়া — চালান CH-0142, পয়েন্ট: উত্তরা, সাভার, গাড়ি ঢাকা মেট্রো-ট 14-6789 (ড্রাইভার: রফিক)"।
 *
 * ── নিয়ম ─────────────────────────────────────────────────────────────────────
 *  - ⛔ পয়েন্ট সব — কাটা নয়, "+n" নয়। একই পয়েন্ট দুবার নয়; প্রথম যেখানে দেখা, সেই ক্রমে।
 *  - পয়েন্ট আসে গ্রাহকের জায়গা থেকে ([[Customer::pointName()]] — চালানের নিজের পয়েন্ট নেই)। ট্রিপে তার সব চালানের
 *    গ্রাহকের পয়েন্ট; কোনো চালানে পয়েন্ট না থাকলে ট্রিপের পথ ([[Shipment::route()]])।
 *  - পয়েন্ট না থাকলে গ্রাহকের নাম আর ছোট ঠিকানা ([[shortAddress()]]) — "রহমান এন্টারপ্রাইজ, কামরাঙ্গীরচর"।
 *  - গাড়ির নম্বর না থাকলে "গাড়ি …" অংশটাই নেই; ড্রাইভারের নাম না থাকলে "(ড্রাইভার: …)" নেই।
 *    ⓘ বাহকের নাম ড্রাইভারের জায়গায় বসে না — বাহক কোম্পানি চালক নয়, আর বাহকের নাম ভাউচারে প্রাপক হিসেবে আগেই থাকে
 *    (`payee_name`); নামটা "ড্রাইভার" লেবেলে বসলে মিথ্যা বলত।
 *  - লোডিং: "লোডিং CH-0134, পয়েন্ট: কেরানীগঞ্জ" — গাড়ি বা ড্রাইভার নয়।
 *
 * ⓘ পুরনো লেখা (`narration_v1`, `trip_narration_v1`) এখানেই আবার বানানো যায় — কেবল পুরনো ভাউচার চেনার জন্য
 * ([[\App\Console\Commands\FareNarrations]])। ⓘ ভাষা বাইরে থেকে দেওয়া যায়, যাতে কমান্ড দুই ভাষার লেখাই মেলাতে পারে।
 */
final class CostNarration
{
    /** চালানের পয়েন্ট আর গ্রাহক একবারে — সারি-প্রতি প্রশ্ন নয় */
    private const CHALLAN_WITH = ['customer.location.parent', 'vehicle.vehicleType', 'carrier'];

    /** ট্রিপের চালান, তাদের গ্রাহকের পয়েন্ট, পথ, গাড়ি আর বাহক */
    private const TRIP_WITH = ['lines.challan.customer.location.parent', 'route', 'vehicle.vehicleType', 'carrier'];

    // ── নতুন বিবরণ ────────────────────────────────────────────────────────────

    /** চালানের ভাড়ার ভাউচারের বিবরণ */
    public function challanNarration(DeliveryChallan $challan, ?string $locale = null): string
    {
        $challan->loadMissing(self::CHALLAN_WITH);

        return $this->compose(
            __('sales::fare.narration_head', ['challans' => $challan->document_no], $locale),
            $this->placeParts([$challan], null, $locale),
            $challan->vehiclePlate(),
            $this->driverOf($challan),
            $locale,
        );
    }

    /** ট্রিপের ভাড়ার ভাউচারের বিবরণ — সব চালান, তারপর সব পয়েন্ট */
    public function tripNarration(Shipment $trip, ?string $locale = null): string
    {
        $trip->loadMissing(self::TRIP_WITH);
        $challans = $this->challansOf($trip);

        return $this->compose(
            __('sales::fare.trip_narration_head', ['challans' => $challans->pluck('document_no')->implode(', ') ?: '—'], $locale),
            $this->placeParts($challans, $this->routeName($trip), $locale),
            $trip->vehiclePlate(),
            $this->driverOf($trip),
            $locale,
        );
    }

    /** লোডিংয়ের বিবরণ — চালান আর পয়েন্ট, গাড়ি বা ড্রাইভার নয় */
    public function loadingNarration(DeliveryChallan $challan, ?string $locale = null): string
    {
        $challan->loadMissing(self::CHALLAN_WITH);

        return $this->compose(
            __('sales::fare.loading_head', ['challans' => $challan->document_no], $locale),
            $this->placeParts([$challan], null, $locale),
            '',
            '',
            $locale,
        );
    }

    /** কাগজ ধরে — চালান বা ট্রিপ */
    public function fareNarration(DeliveryChallan|Shipment $paper, ?string $locale = null): string
    {
        return $paper instanceof Shipment ? $this->tripNarration($paper, $locale) : $this->challanNarration($paper, $locale);
    }

    /**
     * সব পয়েন্ট — প্রথম দেখার ক্রমে, একবার করে।
     *
     * @return list<string>
     */
    public function pointsOf(DeliveryChallan|Shipment $paper): array
    {
        if ($paper instanceof Shipment) {
            $paper->loadMissing(self::TRIP_WITH);
            $points = $this->pointNames($this->challansOf($paper));

            if ($points === [] && ($route = $this->routeName($paper)) !== null) {
                $points = [$route];
            }

            return $points;
        }

        $paper->loadMissing(self::CHALLAN_WITH);

        return $this->pointNames([$paper]);
    }

    // ── পুরনো বিবরণ (চেনার জন্য) ───────────────────────────────────────────────

    /**
     * ⓘ ২০ নভেম্বর ২০২৬ পর্যন্ত [[FarePayment]] ঠিক যা লিখত — হুবহু একই উৎস থেকে (চালানে লেখা নম্বর আগে, তারপর বহরের;
     * ট্রিপে বহরের আগে), যাতে না-ছোঁয়া পুরনো বিবরণ অক্ষরে অক্ষরে মেলে।
     */
    public function legacyNarration(DeliveryChallan|Shipment $paper, ?string $locale = null): string
    {
        if ($paper instanceof Shipment) {
            $paper->loadMissing('lines.challan');

            return __('sales::fare.trip_narration_v1', [
                'trip' => $paper->document_no,
                'challans' => $paper->lines->map(fn (ShipmentLine $l) => $l->challan?->document_no)->filter()->implode(', ') ?: '—',
                'vehicle' => $paper->vehiclePlate() ?: '—',
                'by' => $this->legacyPayee($paper, $locale) ?? '—',
            ], $locale);
        }

        return __('sales::fare.narration_v1', [
            'challan' => $paper->document_no,
            'vehicle' => $paper->vehicle_no ?: ($paper->vehicle?->registration_no ?? '—'),
            'by' => $this->legacyPayee($paper, $locale) ?? '—',
        ], $locale);
    }

    // ── ভাউচার খুললে ──────────────────────────────────────────────────────────

    /**
     * ভাড়া, মাধ্যম, ড্রাইভার, মোবাইল, গাড়ির নম্বর, সব চালান, সব পয়েন্ট — নমুনা v6-এর পাশের ঘরের লেবেলে।
     * ⓘ বিবরণটা ভাউচারের পাতায় আগেই থাকে, তাই এখানে আবার নয় (এক অঙ্ক দুই জায়গায় নয়)।
     *
     * @return array{title: string, rows: list<array{key: string, label: string, value: string, numeric?: bool, links?: list<array{label: string, url: ?string}>}>}
     */
    public function facts(DeliveryChallan|Shipment $paper): array
    {
        $paper->loadMissing($paper instanceof Shipment ? self::TRIP_WITH : self::CHALLAN_WITH);

        $challans = $paper instanceof Shipment ? $this->challansOf($paper) : collect([$paper]);
        $points = $this->pointsOf($paper);
        $phone = $paper instanceof Shipment
            ? (filled($paper->driver_phone) ? (string) $paper->driver_phone : (string) $paper->vehicle?->driver_phone)
            : (string) ($paper->transportFacts()['driver_phone'] ?? '');

        $rows = [
            ['key' => 'fare', 'label' => __('sales::fare.facts_fare'), 'value' => $this->taka($paper->transport_cost), 'numeric' => true],
            ['key' => 'medium', 'label' => __('sales::fare.facts_medium'), 'value' => $this->medium($paper)],
            ['key' => 'driver', 'label' => __('sales::fare.facts_driver'), 'value' => $this->driverOf($paper)],
            ['key' => 'phone', 'label' => __('sales::fare.facts_phone'), 'value' => trim($phone), 'numeric' => true],
            ['key' => 'vehicle', 'label' => __('sales::fare.facts_vehicle'), 'value' => $paper->vehiclePlate(), 'numeric' => true],
            ['key' => 'challans', 'label' => __('sales::fare.facts_challans', ['count' => $challans->count()]),
                'value' => $challans->pluck('document_no')->implode(', '),
                'links' => $challans->map(fn (DeliveryChallan $c) => ['label' => (string) $c->document_no, 'url' => $this->urlOf($c)])->values()->all()],
            ['key' => 'points', 'label' => __('sales::fare.facts_points', ['count' => count($points)]), 'value' => implode(', ', $points)],
        ];

        return [
            'title' => __('sales::fare.facts_title'),
            'rows' => array_values(array_filter($rows, fn (array $row) => trim($row['value']) !== '')),
        ];
    }

    // ── ভিতরের কাজ ─────────────────────────────────────────────────────────────

    /**
     * মাথা, জায়গা, "গাড়ি …" — কমা দিয়ে; ড্রাইভার শেষে ব্র্যাকেটে, ফাঁকা দিয়ে।
     *
     * @param  list<string>  $places
     */
    private function compose(string $head, array $places, string $plate, string $driver, ?string $locale): string
    {
        $parts = [$head, ...$places];

        if (trim($plate) !== '') {
            $parts[] = __('sales::fare.narration_vehicle', ['vehicle' => trim($plate)], $locale);
        }

        $text = implode(', ', $parts);

        if (trim($driver) !== '') {
            $text .= ' '.__('sales::fare.narration_driver', ['driver' => trim($driver)], $locale);
        }

        return $text;
    }

    /**
     * "পয়েন্ট: a, b" — আর পয়েন্টহীন চালানের গ্রাহক "নাম, ছোট ঠিকানা" করে, প্রথম দেখার ক্রমে।
     *
     * @param  iterable<DeliveryChallan>  $challans
     * @return list<string>
     */
    private function placeParts(iterable $challans, ?string $fallbackPoint, ?string $locale): array
    {
        $points = [];
        $others = [];

        foreach ($challans as $challan) {
            $point = $challan->customer?->pointName();

            if ($point !== null) {
                $points[$point] = $point;

                continue;
            }

            $label = $this->customerLabel($challan->customer, $locale);

            if ($label !== null) {
                $others[$label] = $label;
            }
        }

        if ($points === [] && $others === [] && $fallbackPoint !== null) {
            $points[$fallbackPoint] = $fallbackPoint;
        }

        $parts = $points === [] ? [] : [__('sales::fare.narration_points', ['points' => implode(', ', $points)], $locale)];

        return [...$parts, ...array_values($others)];
    }

    /**
     * @param  iterable<DeliveryChallan>  $challans
     * @return list<string>
     */
    private function pointNames(iterable $challans): array
    {
        $points = [];

        foreach ($challans as $challan) {
            $point = $challan->customer?->pointName();

            if ($point !== null) {
                $points[$point] = $point;
            }
        }

        return array_values($points);
    }

    /** "নাম, ছোট ঠিকানা" — ঠিকানা না থাকলে কেবল নাম; গ্রাহকই না থাকলে null */
    private function customerLabel(?Customer $customer, ?string $locale): ?string
    {
        if ($customer === null) {
            return null;
        }

        $label = implode(', ', array_filter([trim($customer->name($locale)), $this->shortAddress($customer, $locale)]));

        return $label === '' ? null : $label;
    }

    /**
     * ⓘ ছোট ঠিকানা — গ্রাহক গাছের যে ধাপে বসে তার নাম (পয়েন্ট নয় এমন ধাপ, যেমন এরিয়া), নইলে লেখা ঠিকানার প্রথম অংশ
     * (প্রথম কমার আগে)। পুরো ঠিকানা নয় — বিবরণটা এক নজরে পড়ার জন্য।
     */
    private function shortAddress(Customer $customer, ?string $locale): ?string
    {
        $place = $customer->location;

        if ($place instanceof Location) {
            $name = trim((string) ($place->name_bn ?: $place->name_en));

            if ($name !== '') {
                return $name;
            }
        }

        $address = trim((string) $customer->address($locale));

        if ($address === '') {
            return null;
        }

        $first = trim((string) preg_split('/[,،\n]/u', $address)[0]);

        return $first === '' ? null : $first;
    }

    /** ট্রিপের চালানগুলো, সারির ক্রমে, একবার করে */
    private function challansOf(Shipment $trip): \Illuminate\Support\Collection
    {
        return $trip->lines->map(fn (ShipmentLine $l) => $l->challan)->filter()->unique('id')->values();
    }

    private function routeName(Shipment $trip): ?string
    {
        $route = $trip->route;

        if (! $route instanceof Location) {
            return null;
        }

        $name = trim((string) ($route->name_bn ?: $route->name_en));

        return $name === '' ? null : $name;
    }

    /** চালানে লেখা নাম, নইলে বহরের গাড়ির চালক ([[DeliveryChallan::transportFacts()]]-এর একই নিয়ম) */
    private function driverOf(DeliveryChallan|Shipment $paper): string
    {
        if (filled($paper->driver_name)) {
            return trim((string) $paper->driver_name);
        }

        return trim((string) $paper->vehicle?->driver_name);
    }

    /**
     * মাধ্যম — কার গাড়ি (নিজেদের, ভাড়া করা …), গাড়ির ধরন আর বাহক, "·" দিয়ে।
     * ⓘ চালানে লেখা `vehicle_owner`; ট্রিপে ঘরটা নেই — বহরের গাড়ির `owner_type` (own → নিজেদের), নইলে ভাড়া করা
     * (ট্রিপের ভাড়া সবসময় ভাড়ার ট্রাকের, [[FarePayment]])।
     */
    private function medium(DeliveryChallan|Shipment $paper): string
    {
        $owner = $paper instanceof Shipment
            ? ($paper->vehicle?->owner_type === 'own' ? 'own' : 'hired')
            : (filled($paper->vehicle_owner) ? (string) $paper->vehicle_owner : null);

        $carrier = $paper->carrier?->name() ?? (filled($paper->carrier_name) ? (string) $paper->carrier_name : null);

        return implode(' · ', array_filter([
            $owner !== null ? __('sales::field.owner_'.$owner) : null,
            $paper->vehicle?->vehicleType?->name(),
            $owner === 'own' ? null : $carrier,
        ], fn ($v) => filled($v)));
    }

    /** ৳ আর লাখের কমা, ইংরেজি অঙ্ক — পয়সা না থাকলে দশমিক নয় ("৳12,000") */
    private function taka(mixed $amount): string
    {
        if (! is_numeric($amount) || bccomp((string) $amount, '0', 4) <= 0) {
            return '';
        }

        $whole = bccomp((string) $amount, bcadd((string) $amount, '0', 0), 4) === 0;

        return '৳'.Money::format((string) $amount, $whole ? 0 : 2);
    }

    private function urlOf(DeliveryChallan $challan): ?string
    {
        [$route, $params] = $challan->drillRoute() + [null, []];

        return $route !== null && Route::has($route) ? route($route, $params) : null;
    }

    /** [[FarePayment]]-এর পুরনো `payee()` হুবহু — বাহক, নইলে লেখা বাহক, নইলে চালক */
    private function legacyPayee(DeliveryChallan|Shipment $paper, ?string $locale): ?string
    {
        return $paper->carrier?->name($locale) ?? ($paper->carrier_name ?: ($paper->driver_name ?: null));
    }
}
