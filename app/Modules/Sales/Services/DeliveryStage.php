<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\ShipmentLine;

/**
 * ডেলিভারির ধাপ — মাল কোথায় আছে, এক শব্দে (NEXUS §২১–২২)।
 *
 * ── ⓘ কেন চালানের `status` যথেষ্ট নয় ──────────────────────────────────
 * চালানের অবস্থা খাতার প্রশ্নের উত্তর দেয়: খসড়া, নিশ্চিত (স্টক নেমেছে),
 * বাতিল। ⚠️ কিন্তু ডিপোর দিনের প্রশ্নটা আলাদা — *"মালটা এখন কোথায়?"*
 * তাকে, প্যাক হয়ে গেটে, গাড়িতে, না ক্রেতার দোকানে। নিশ্চিত চালানের
 * কাছে এর কোনো উত্তর নেই।
 *
 * ── ⛔ এই ধাপগুলো স্টকে হাত দেয় না ────────────────────────────────────
 * মাল তাক থেকে নামে চালান নিশ্চিত হওয়ার মুহূর্তে, [[StockService]]-এ।
 * ফেরে একটাই পথে — চালান বাতিল বা বিক্রয় ফেরত। ⓘ ধাপ বদলানো কেবল
 * **বলা**, করা নয়; এখানে স্টক নড়লে একই মাল দুইবার নামত বা উঠত।
 *
 * ── ⭐ তালিকাটা এখানে বাঁধা, সেটিংসে নয় ─────────────────────────────
 * [[ShipmentLine::OUTCOMES]]-এর যুক্তিই: প্রতিটা ধাপের সাথে একটা নিয়ম
 * জোড়া, আর নতুন ধাপ মানে নতুন নিয়ম লেখা।
 */
final class DeliveryStage
{
    public const PENDING = 'pending';

    public const ALLOCATED = 'allocated';

    public const PICKING = 'picking';

    public const PACKED = 'packed';

    public const DISPATCHED = 'dispatched';

    public const PARTIALLY_DELIVERED = 'partially_delivered';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** ক্রমটা পর্দার ট্যাবের ক্রম — মালের পথের ক্রম। */
    public const ALL = [
        self::PENDING, self::ALLOCATED, self::PICKING, self::PACKED, self::DISPATCHED,
        self::PARTIALLY_DELIVERED, self::DELIVERED, self::FAILED, self::CANCELLED,
    ];

    /** এখনো হাতে থাকা কাজ — তালিকার প্রথম ট্যাব। */
    public const OPEN = [
        self::PENDING, self::ALLOCATED, self::PICKING, self::PACKED, self::DISPATCHED,
    ];

    /** যেগুলোতে ক্রেতার হাতে মাল গেছে — প্রমাণ (কে নিলেন) লাগে। */
    public const NEEDS_RECEIVER = [self::DELIVERED, self::PARTIALLY_DELIVERED];

    /**
     * গাড়ির ট্রিপ যে ধাপগুলো নিজে বলে।
     *
     * ⚠️ চালান চলতি ট্রিপে থাকলে এগুলো হাতে বসানো যায় না — নাহলে ট্রিপের
     * সারি বলত "ফেরত", আর চালানের ধাপ বলত "পৌঁছেছে"; দুইটা উৎস, দুই সত্য।
     */
    public const TRIP_OWNED = [
        self::DISPATCHED, self::PARTIALLY_DELIVERED, self::DELIVERED, self::FAILED,
    ];

    /**
     * যে ধাপ থেকে চালান গাড়িতে উঠতে পারে — ট্রিপ বেরোনোর মুহূর্তে।
     *
     * ⚠️ TRANSITIONS-এ "পৌঁছেছে → রওনা" ট্রিপের জন্য খোলা, কিন্তু সেটা কেবল
     * চালকের কথা শোধরানোর পথ (চলতি ট্রিপের সারি "অপেক্ষায়" ফেরা)। ⛔ নতুন
     * ট্রিপে হাতে "পৌঁছেছে" বা "আংশিক" লেখা চালান তুললে একই মাল দুইবার
     * পাঠানো হত — তাই বেরোনোর সময় এই তালিকাই মাপা হয়। "পৌঁছায়নি" থেকে
     * পরদিন আবার পাঠানো স্বাভাবিক।
     */
    public const DISPATCHABLE = [self::ALLOCATED, self::PICKING, self::PACKED, self::FAILED];

    /** কে ধাপটা বদলাল। */
    public const BY_HAND = 'manual';

    public const BY_CHALLAN = 'challan';

    public const BY_SHIPMENT = 'shipment';

    /** পুরনো চালানের প্রথম সারি — মাইগ্রেশন বা প্রথম দেখায় তথ্য থেকে গোনা। */
    public const BY_BACKFILL = 'backfill';

    public const SOURCES = [self::BY_HAND, self::BY_CHALLAN, self::BY_SHIPMENT, self::BY_BACKFILL];

    /**
     * ⭐ একমাত্র তালিকা — কোন ধাপ থেকে কোন ধাপে, আর কে নিতে পারে।
     *
     * `[from => [to => [sources…]]]`। তালিকায় না থাকা মানেই নিষেধ।
     *
     * ⓘ হাতের ধাপ (manual) কেবল সামনের দিকে। পেছনের দিকের কয়েকটা পথ
     * আছে, কিন্তু কেবল ট্রিপের — ট্রিপ বাতিল হলে মাল আর পথে নেই, আর
     * চালক ভুল বললে সন্ধ্যায় সারিটা শোধরানো হয়। ⛔ সেগুলো হাতে খোলা
     * থাকলে "পৌঁছেছে" লেখা চালান যে কেউ আবার "পথে" করে দিতে পারতেন।
     *
     * ⚠️ বাতিল ও পৌঁছেছে — শেষ ধাপ। আংশিকও শেষ: বাকি মাল ফেরে বিক্রয়
     * ফেরতের কাগজে, এই ধাপে নয়।
     */
    public const TRANSITIONS = [
        self::PENDING => [
            self::ALLOCATED => [self::BY_CHALLAN],
            self::CANCELLED => [self::BY_CHALLAN],
        ],
        self::ALLOCATED => [
            self::PICKING => [self::BY_HAND],
            self::PACKED => [self::BY_HAND],
            self::DISPATCHED => [self::BY_HAND, self::BY_SHIPMENT],
            /*
             * ⭐ সরাসরি পৌঁছেছে — ডিপো থেকে হাতে দেওয়া, বা ক্রেতার নিজের গাড়ি
             * (মালিকের পরিকল্পনা, ধাপ ৪, ২৮ সেপ্টেম্বর ২০২৬)। ⓘ আগে এখানে একটা
             * মিথ্যা "রওনা" লিখতে হত। ট্রিপে থাকলে ট্রিপই বলে — [[move()]]-এর পাহারা।
             */
            self::DELIVERED => [self::BY_HAND],
            self::CANCELLED => [self::BY_CHALLAN],
        ],
        self::PICKING => [
            self::PACKED => [self::BY_HAND],
            self::DISPATCHED => [self::BY_HAND, self::BY_SHIPMENT],
            self::DELIVERED => [self::BY_HAND],
            self::CANCELLED => [self::BY_CHALLAN],
        ],
        self::PACKED => [
            self::DISPATCHED => [self::BY_HAND, self::BY_SHIPMENT],
            self::DELIVERED => [self::BY_HAND],
            self::CANCELLED => [self::BY_CHALLAN],
        ],
        self::DISPATCHED => [
            self::DELIVERED => [self::BY_HAND, self::BY_SHIPMENT],
            self::PARTIALLY_DELIVERED => [self::BY_HAND, self::BY_SHIPMENT],
            self::FAILED => [self::BY_HAND, self::BY_SHIPMENT],

            // ট্রিপ বাতিল — মাল আর পথে নেই, গাড়িতে ওঠার আগের ধাপে ফেরে
            self::ALLOCATED => [self::BY_SHIPMENT],
            self::PICKING => [self::BY_SHIPMENT],
            self::PACKED => [self::BY_SHIPMENT],
            self::CANCELLED => [self::BY_CHALLAN],
        ],
        self::PARTIALLY_DELIVERED => [
            // চালকের কথা সন্ধ্যায় শোধরানো — ট্রিপ তখনো খোলা
            self::DISPATCHED => [self::BY_SHIPMENT],
            self::DELIVERED => [self::BY_SHIPMENT],
            self::FAILED => [self::BY_SHIPMENT],
            self::CANCELLED => [self::BY_CHALLAN],
        ],
        self::DELIVERED => [
            self::DISPATCHED => [self::BY_SHIPMENT],
            self::PARTIALLY_DELIVERED => [self::BY_SHIPMENT],
            self::FAILED => [self::BY_SHIPMENT],
            self::CANCELLED => [self::BY_CHALLAN],
        ],
        self::FAILED => [
            // ফেরত আসা মাল পরদিন আবার পাঠানো স্বাভাবিক — [[ShipmentService]]
            self::DISPATCHED => [self::BY_HAND, self::BY_SHIPMENT],
            self::DELIVERED => [self::BY_SHIPMENT],
            self::PARTIALLY_DELIVERED => [self::BY_SHIPMENT],
            self::CANCELLED => [self::BY_CHALLAN],
        ],
        self::CANCELLED => [],
    ];

    public static function allows(string $from, string $to, string $source): bool
    {
        return in_array($source, self::TRANSITIONS[$from][$to] ?? [], true);
    }

    /**
     * হাতে যে ধাপগুলোতে যাওয়া যায় — পর্দার বোতামের তালিকা।
     *
     * @return list<string>
     */
    public static function manualNext(string $from): array
    {
        return array_values(array_keys(array_filter(
            self::TRANSITIONS[$from] ?? [],
            fn (array $sources) => in_array(self::BY_HAND, $sources, true),
        )));
    }

    /**
     * এক কথায় — মালিকের দুই শব্দ: "ডেলিভারির অপেক্ষায়" বা "ডেলিভার্ড" (ধাপ ৪, ২৮ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ তালিকাগুলো ন'টা ধাপ দেখায় না — কেবল মাল ক্রেতার কাছে পৌঁছেছে কি না।
     * আংশিক আর পৌঁছায়নি নিজের নামে থাকে, কারণ দুইটাতেই কারো কিছু করার আছে।
     * খসড়া চালানের (অপেক্ষায়) কোনো সারাংশ নেই — মাল এখনো তাকে, কেউ পাঠায়নি।
     */
    public static function summary(?string $stage): ?string
    {
        return match ($stage) {
            null, self::PENDING => null,
            self::DELIVERED => 'delivered',
            self::PARTIALLY_DELIVERED => 'partial',
            self::FAILED => 'failed',
            self::CANCELLED => 'cancelled',
            default => 'awaiting',
        };
    }

    /** সারাংশের ব্যাজের রং — [[badge()]]-এর মতোই পুরো ক্লাস। */
    public static function summaryBadge(string $summary): string
    {
        return match ($summary) {
            'delivered' => self::badge(self::DELIVERED),
            'partial' => self::badge(self::PARTIALLY_DELIVERED),
            'failed', 'cancelled' => self::badge(self::FAILED),
            default => self::badge(self::ALLOCATED),
        };
    }

    public static function label(string $stage): string
    {
        return __('sales::delivery.stage.'.$stage);
    }

    /**
     * ব্যাজের রং — পুরো ক্লাসের নাম, জোড়া লাগানো নয়।
     *
     * ⚠️ `bg-(--color-badge-{$tone}-bg)` লিখলে Tailwind নামটা দেখতেই
     * পেত না, আর ব্যাজটা নীরবে রংহীন থাকত। ⓘ ছয়টাই পাশের পর্দাগুলোতে
     * আক্ষরিকভাবে আছে, তাই বান্ডলে আছে।
     */
    public static function badge(string $stage): string
    {
        return match ($stage) {
            self::DELIVERED => 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)',
            self::PARTIALLY_DELIVERED => 'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)',
            self::FAILED, self::CANCELLED => 'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)',
            self::DISPATCHED => 'bg-(--color-badge-info-bg) text-(--color-badge-info-ink)',
            self::PENDING => 'bg-(--color-badge-draft-bg) text-(--color-badge-draft-ink)',
            default => 'bg-(--color-badge-pending-bg) text-(--color-badge-pending-ink)',
        };
    }

    /**
     * ট্রিপের সারির ফল → ধাপ।
     *
     * ⓘ `pending` মানে চালক এখনো বলেননি — মাল তখনো পথে।
     */
    public static function fromOutcome(string $outcome): string
    {
        return match ($outcome) {
            ShipmentLine::DELIVERED => self::DELIVERED,
            ShipmentLine::RETURNED => self::FAILED,
            ShipmentLine::SHORT => self::PARTIALLY_DELIVERED,
            default => self::DISPATCHED,
        };
    }

    /**
     * তথ্য থেকে ধাপ — যে চালানের কোনো ধাপ-সারি নেই তার জন্য।
     *
     * ⭐ মাইগ্রেশনের ব্যাকফিল আর সেবার প্রথম-দেখা দুইটাই এটাই ডাকে —
     * দুই জায়গায় দুইটা নিয়ম লেখা থাকলে একদিন আলাদা হয়ে যেত।
     *
     * @param  string|null  $tripStatus  চালানের সবচেয়ে সাম্প্রতিক বেরোনো (নিশ্চিত বা সম্পন্ন) ট্রিপের অবস্থা
     * @param  string|null  $outcome  সেই ট্রিপে চালানের সারির ফল
     */
    public static function derive(string $challanStatus, ?string $tripStatus = null, ?string $outcome = null): string
    {
        if ($challanStatus === DocumentStatus::CANCELLED) {
            return self::CANCELLED;
        }

        if ($challanStatus !== DocumentStatus::CONFIRMED) {
            return self::PENDING;
        }

        // ⓘ বেরোনো ট্রিপ = খাতায় বসা ট্রিপ (পথে বা সম্পন্ন) — তালিকাটা একটাই, DocumentStatus::POSTED
        if (in_array($tripStatus, DocumentStatus::POSTED, true)) {
            return self::fromOutcome((string) $outcome);
        }

        return self::ALLOCATED;
    }
}
