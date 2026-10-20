<?php

declare(strict_types=1);

namespace App\Modules\Sales\Widgets;

use App\Core\Support\DocumentStatus;
use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Services\DeliveryStage;
use Illuminate\Support\Carbon;

/**
 * ⭐ ফোনের "আজকের চালান" উইজেট (৪×২) — আজকের চালান কোন ধাপে: গেট পাস বাকি, পথে, পৌঁছেছে, ফেরত (মালিকের উইজেট-নকশা ৬,
 * ২০ অক্টোবর ২০২৬; দলের চুক্তি E:\ABOS\app-widgets-ভিত্তি.md; ফোনের অংশ `challans_today.dart`)।
 *
 * ⭐ ধাপ আসল ধাপ-যন্ত্র থেকে ([[DeliveryStage]], `sal_delivery_states`, এক চালান এক ধাপ) — ওয়েবের ডেলিভারির পাতার
 * (`/sales/deliveries?stage=…`) একই সারি। চার ঘর মিলে মোট, কোনো চালান দুই ঘরে নয়, কোনোটা বাদ নয়:
 *  - গেট পাস বাকি: ধাপ নেই, অপেক্ষায়, বরাদ্দ, তোলা, প্যাক — গেট পাস জন্মায় রওনায় ([[GatePassService::issueFor()]]);
 *  - পথে আছে: রওনা হয়েছে, আংশিক পৌঁছেছে (বাকিটা এখনো গাড়িতে);
 *  - পৌঁছেছে: পৌঁছেছে — ওয়েবের তালিকার "ডেলিভার্ড" ট্যাবের একই শর্ত ([[DeliveryOrderTabs]]);
 *  - ফেরত এসেছে: পৌঁছায়নি (`failed`) — মাল গ্রাহকের কাছে না গিয়ে ফিরেছে।
 *
 * ⓘ "আজকের" = চালানের তারিখ আজ, নিশ্চিত বা বন্ধ ([[DocumentStatus::POSTED]]); খসড়া আর বাতিল নয় — ওয়েবের "অপেক্ষায়" ট্যাবের
 * একই অবস্থা। ⛔ দেয়াল মডেলের নিজের: শাখা ([[ScopedToUserBranch]]) আর বিক্রয়কর্মীর কেবল নিজের ডিলার ([[ScopedToUserDealers]])।
 * চাবি `sales.challan.view` — ওয়েবের চালানের তালিকার চাবি।
 */
final class ChallansTodaySummary implements WidgetSummary
{
    /** @var array<string, list<?string>> ঘর → ধাপ (null = ধাপের সারি এখনো নেই) */
    public const BOXES = [
        'gate_pending' => [null, DeliveryStage::PENDING, DeliveryStage::ALLOCATED, DeliveryStage::PICKING, DeliveryStage::PACKED],
        'moving' => [DeliveryStage::DISPATCHED, DeliveryStage::PARTIALLY_DELIVERED],
        'delivered' => [DeliveryStage::DELIVERED],
        'returned' => [DeliveryStage::FAILED],
    ];

    public function key(): string
    {
        return 'challans_today';
    }

    public function module(): string
    {
        return 'sales';
    }

    public function permission(): string
    {
        return 'sales.challan.view';
    }

    /**
     * @return array{link: string, total: int, gate_pending: int, moving: int, delivered: int, returned: int, latest: ?array{customer: string, no: string, vehicle: ?string, link: string}}
     */
    public function data(User $user): array
    {
        $today = fn () => DeliveryChallan::query()
            ->whereDate('sal_challans.trx_date', Carbon::today()->toDateString())
            ->whereIn('sal_challans.status', DocumentStatus::POSTED);

        // ⓘ ধাপ উপ-প্রশ্নে, join নয় — দুই টেবিলেরই company_id, আর মডেলের দেয়াল অযোগ্য নামে লেখে
        $stages = $today()
            ->addSelect(['stage' => DeliveryState::query()->select('stage')
                ->whereColumn('delivery_challan_id', 'sal_challans.id')->limit(1)])
            ->pluck('stage')
            ->all();

        $counts = [];
        foreach (self::BOXES as $box => $members) {
            $counts[$box] = count(array_filter($stages, fn ($s) => in_array($s, $members, true)));
        }

        $latest = $today()
            ->with([...self::customerPlace(), 'vehicle'])
            ->orderByDesc('sal_challans.id')
            ->first();

        return [
            // ⓘ পুরো উইজেটে চাপলে — ডেলিভারির পাতা; মালিকের পূর্ণ পর্দার "চালান পথে" বোতামও এটা পড়ে ([[OwnerBoardSummary]])
            'link' => '/sales/deliveries?stage=dispatched',
            'total' => array_sum($counts),
            ...$counts,
            'latest' => $latest === null ? null : [
                'customer' => self::customerLine($latest),
                'no' => (string) $latest->document_no,
                'vehicle' => $latest->vehiclePlate() !== '' ? $latest->vehiclePlate() : null,
                'link' => '/sales/challans/'.$latest->id,
            ],
        ];
    }

    /** @return list<string> */
    private static function customerPlace(): array
    {
        return ['customer.location.parent', 'customer.branch'];
    }

    /**
     * "নাম · পয়েন্ট · শাখা" — যেটা নেই সেটা বাদ (মালিক, ১৯ অক্টোবর ২০২৬)। ⓘ নিজে জোড়া, যাতে a5-এর
     * `nameWithPoint()`-এ শাখা যোগ হলেও এখানে শাখা দুইবার না আসে।
     */
    private static function customerLine(DeliveryChallan $challan): string
    {
        $customer = $challan->customer;
        if ($customer === null) {
            return '—';
        }

        return implode(' · ', array_filter([
            $customer->name(),
            $customer->pointName(),
            $customer->branch?->name(),
        ], fn ($part) => filled($part)));
    }
}
