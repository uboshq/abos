<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Services\BudgetGuard;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * অফারের রিপোর্ট — স্পেক §১৭, কেবল যেগুলো অফারের নিজের খাতা থেকেই হয়।
 *
 * ── ⭐ কেন নতুন পর্দা নেই ───────────────────────────────────────────
 * ⓘ আটটা মডিউল একটাই পর্দা দেখায় (`accounts::report.show`) — ছাঁকনি,
 * খোঁজা, পাতা, যোগফল, রপ্তানি, ছাপা সবই সেখানে। ⛔ অফারের জন্য দ্বিতীয়
 * একটা পর্দা লিখলে একদিন একটায় রপ্তানি ঠিক হত আর অন্যটায় নয়।
 *
 * ── ⚠️ সংখ্যাগুলো **জমে থাকা সারি** থেকে ─────────────────────────────
 * ⓘ `promotion_applications.worth` বসানোর মুহূর্তে জমে যায় — [[BudgetGuard]]
 * এই একই সারি গোনে। ⛔ অফারের কাগজ থেকে নতুন করে কষলে হার বা মেয়াদ
 * বদলালেই পুরনো মাসের রিপোর্ট বদলে যেত, আর খাতা বলত এক কথা, রিপোর্ট আরেক।
 *
 * ── ⛔ বাতিল বিলের সুবিধা ব্যবহারে গোনা হয় না ──────────────────────
 * ⓘ `reversed_at` বসা সারি মানে বিলটাই বাতিল — ক্রেতা কিছুই নেননি।
 * ⚠️ ওগুলো কেবল বাতিলের রিপোর্টে আসে; ব্যবহারে গুনলে অফারটা যতটা
 * খরচ হয়েছে তার চেয়ে বেশি দেখাত — [[BudgetGuard]]-এর একই নিয়ম।
 *
 * ── ⚠️ নামগুলো কোয়েরির **ভিতরে** তৈরি হয়, নিবন্ধনের সময় নয় ─────────
 * ⓘ নিবন্ধন হয় বুটে, একবার — তখন ভাষা যেটাই থাকুক। ⛔ অবস্থার নাম
 * বুটে বানালে বাংলার মানুষ ইংরেজি নাম দেখতেন। ⭐ তাই প্রতিটা CASE
 * কোয়েরির Closure-এর ভিতরে, অনুরোধের নিজের ভাষায়।
 *
 * ── ⚠️ SELECT-এ কোনো `?` নেই ─────────────────────────────────────────
 * ⓘ ইঞ্জিন যোগফলের জন্য কোয়েরিটা মোড়কে ঢোকায়, আর SELECT-এর বাঁধন
 * তখন এক ঘর সরে যায় ([[ApprovalReports]]-এর ধরা)। ⭐ তাই লেবেলগুলো
 * PDO-র উদ্ধৃতিতে বসে, আর তারিখ বসে কেবল Carbon দিয়ে ছাঁকার পরে।
 *
 * ── ⛔ যা এখানে নেই, আর কেন ─────────────────────────────────────────
 * ⓘ এলাকা ও বিক্রয়কর্মী ধরে, কার্যকারিতা/ROI/বাড়তি বিক্রয়, আর
 * প্রণোদনার নিষ্পত্তি — চারটাই বিক্রয়ের খাতা চায়। ⚠️ এই মডিউল
 * বিক্রয়ের উপর নির্ভর করে না (`module.php`), আর স্পেক বলে অনুমান কখনো
 * আসল বিক্রয় হিসেবে দেখানো যাবে না — পদ্ধতি লেখা ছাড়া সংখ্যা নয়।
 */
final class PromotionReports
{
    /** ⓘ প্রতিটা রিপোর্টের একই চাবি — কন্ট্রোলার আর নির্ধারিত রিপোর্ট দুইজনেই এটা পড়ে */
    public const PERMISSION = 'promotion.report';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::register());
        $engine->register(self::active());
        $engine->register(self::expired());
        $engine->register(self::utilization());
        $engine->register(self::byCustomer());
        $engine->register(self::byProduct());
        $engine->register(self::discounts());
        $engine->register(self::gifts());
        $engine->register(self::giftStock());
        $engine->register(self::budgets());
        $engine->register(self::overrides());
        $engine->register(self::reversals());
        $engine->register(self::cancelledOffers());
    }

    /**
     * ⭐ অফারের খাতা — পরিসরের সাথে যাদের মেয়াদ কোথাও মেলে।
     *
     * ⓘ "মেলে" মানে শুরু পরিসরের শেষের আগে আর শেষ পরিসরের শুরুর পরে।
     * ⚠️ কেবল শুরুর তারিখ ধরলে গত মাসে শুরু হয়ে এই মাসেও চলা অফারটা
     * এই মাসের খাতায় থাকত না।
     */
    public static function register(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'promotion.register',
            title: 'promotion::report.title_register',
            filters: ['date_range'],
            permission: self::PERMISSION,
            query: fn (array $f) => DB::table('promotions as p')
                ->leftJoin('users as cu', 'cu.id', '=', 'p.created_by')
                ->leftJoin('users as au', 'au.id', '=', 'p.approved_by')
                ->where('p.company_id', $f['company_id'])
                ->whereNull('p.deleted_at')
                ->where('p.starts_on', '<=', $f['to'])
                ->where('p.ends_on', '>=', $f['from'])
                ->orderByDesc('p.starts_on')
                ->orderByDesc('p.id')
                ->select([
                    'p.id as promotion_id',
                    'p.code as promotion_code',
                    self::offerName('p'),
                    self::typeLabel('p.type'),
                    self::statusLabel('p.status'),
                    'p.starts_on',
                    'p.ends_on',
                    DB::raw('cu.name as created_by_name'),
                    DB::raw('au.name as approved_by_name'),
                    'p.approved_at',
                ]),
            columns: [
                ['key' => 'promotion_code', 'label' => 'promotion::report.code', 'width' => '9rem'],
                ['key' => 'promotion_name', 'label' => 'promotion::report.name'],
                ['key' => 'type_label', 'label' => 'promotion::report.type'],
                ['key' => 'status_label', 'label' => 'promotion::report.status', 'width' => '9rem'],
                ['key' => 'starts_on', 'label' => 'promotion::report.starts_on', 'type' => ReportColumn::DATE],
                ['key' => 'ends_on', 'label' => 'promotion::report.ends_on', 'type' => ReportColumn::DATE],
                ['key' => 'created_by_name', 'label' => 'promotion::report.created_by'],
                ['key' => 'approved_by_name', 'label' => 'promotion::report.approved_by'],
                ['key' => 'approved_at', 'label' => 'promotion::report.approved_at', 'type' => ReportColumn::DATE],
            ],
        );
    }

    /**
     * ⭐ আজ যা সত্যিই চলছে — মডেলের নিজের নিয়মে।
     *
     * ⓘ নিয়মটা একবারই লেখা: [[Promotion::scopeLiveOn()]]। ⛔ এখানে হাতে
     * `status = active` লিখলে মেয়াদ পেরোনো অথচ অবস্থা না বদলানো অফারও
     * "চলছে" দেখাত — ঠিক যে ভুলটা মডেল আটকায়।
     *
     * ⚠️ তারিখের ছাঁকনি নেই, ইচ্ছা করে: প্রশ্নটা "এখন", পরিসর নয়। ⓘ ঘরটা
     * রাখলে ব্যবহারকারী ভাবতেন গত মাসে কী চলছিল তাও দেখা যায় — যায় না,
     * কারণ অবস্থা তখন কী ছিল তা খাতায় নেই, আছে নিরীক্ষায়।
     */
    public static function active(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'promotion.active',
            title: 'promotion::report.title_active',
            filters: [],
            permission: self::PERMISSION,
            query: function (array $f) {
                $today = Carbon::today()->toDateString();

                return Promotion::query()
                    ->liveOn(now())
                    ->where('promotions.company_id', $f['company_id'])
                    ->orderBy('promotions.ends_on')
                    ->toBase()
                    ->select([
                        'promotions.id as promotion_id',
                        'promotions.code as promotion_code',
                        self::offerName('promotions'),
                        self::typeLabel('promotions.type'),
                        'promotions.starts_on',
                        'promotions.ends_on',

                        /*
                         * ⓘ আর কত দিন — "৩০ সেপ্টেম্বর" দেখে কেউ মাথায় বিয়োগ করেন না।
                         * ⚠️ আজকের তারিখ PHP থেকে, Carbon-এ ছাঁকা — ব্যবহারকারীর ইনপুট
                         * নয়, আর ডাটাবেসের ঘড়িও নয়।
                         */
                        DB::raw("DATEDIFF(promotions.ends_on, '".$today."') as days_left"),
                        self::givenSoFar('promotions'),
                    ]);
            },
            columns: [
                ['key' => 'promotion_code', 'label' => 'promotion::report.code', 'width' => '9rem'],
                ['key' => 'promotion_name', 'label' => 'promotion::report.name'],
                ['key' => 'type_label', 'label' => 'promotion::report.type'],
                ['key' => 'starts_on', 'label' => 'promotion::report.starts_on', 'type' => ReportColumn::DATE],
                ['key' => 'ends_on', 'label' => 'promotion::report.ends_on', 'type' => ReportColumn::DATE],
                ['key' => 'days_left', 'label' => 'promotion::report.days_left',
                    'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '7rem'],
                ['key' => 'given_worth', 'label' => 'promotion::report.given_worth', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ⛔ যাদের মেয়াদ শেষ — লেখা হোক বা না হোক।
     *
     * ⓘ দুই রকম: অবস্থা `expired`, আর অবস্থা এখনো "চলছে" অথচ শেষ তারিখ
     * পেরিয়ে গেছে ([[Promotion::scopeLapsed()]])। ⚠️ দ্বিতীয়টা বাদ দিলে
     * যে নির্ধারিত কাজ অবস্থা বদলায় সে একদিন না চললে রিপোর্টটা খালি দেখাত।
     * ⭐ তাই দ্বিতীয়টার নাম আলাদা — "মেয়াদ শেষ" নয়, "শেষ তারিখ পেরিয়েছে"।
     */
    public static function expired(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'promotion.expired',
            title: 'promotion::report.title_expired',
            filters: ['date_range'],
            permission: self::PERMISSION,
            query: function (array $f) {
                $lapsed = self::quote(__('promotion::report.lapsed'));
                $expired = self::quote(PromotionStatus::EXPIRED->label());

                return Promotion::query()
                    ->where('promotions.company_id', $f['company_id'])
                    ->where(fn ($q) => $q
                        ->where('status', PromotionStatus::EXPIRED->value)
                        ->orWhere(fn ($lapsedOnes) => $lapsedOnes->lapsed()))
                    ->whereBetween('promotions.ends_on', [$f['from'], $f['to']])
                    ->orderByDesc('promotions.ends_on')
                    ->toBase()
                    ->select([
                        'promotions.id as promotion_id',
                        'promotions.code as promotion_code',
                        self::offerName('promotions'),
                        self::typeLabel('promotions.type'),
                        DB::raw("CASE WHEN promotions.status = 'active' THEN {$lapsed} ELSE {$expired} END as status_label"),
                        'promotions.starts_on',
                        'promotions.ends_on',
                        self::givenSoFar('promotions'),
                    ]);
            },
            columns: [
                ['key' => 'promotion_code', 'label' => 'promotion::report.code', 'width' => '9rem'],
                ['key' => 'promotion_name', 'label' => 'promotion::report.name'],
                ['key' => 'type_label', 'label' => 'promotion::report.type'],
                ['key' => 'status_label', 'label' => 'promotion::report.status'],
                ['key' => 'starts_on', 'label' => 'promotion::report.starts_on', 'type' => ReportColumn::DATE],
                ['key' => 'ends_on', 'label' => 'promotion::report.ends_on', 'type' => ReportColumn::DATE],
                ['key' => 'given_worth', 'label' => 'promotion::report.given_worth', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ⭐ কোন অফার কতবার বসল, আর কত দিল — বাতিল বিল বাদে।
     *
     * ⓘ বিলের সংখ্যা আলাদা, কারণ একটা বিলে একই অফার পাঁচ লাইনে বসতে
     * পারে। ⚠️ সারির সংখ্যাকে বিলের সংখ্যা বললে অফারটা পাঁচগুণ জনপ্রিয়
     * দেখাত।
     *
     * ⛔ বিল ও ক্রেতার গণনার যোগফল নেই: একটা বিলে দুইটা অফার থাকলে
     * দুই সারিতেই গোনা হয়, আর যোগফলটা বিলের সংখ্যার চেয়ে বেশি বলত।
     */
    public static function utilization(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'promotion.utilization',
            title: 'promotion::report.title_utilization',
            filters: ['date_range'],
            groupBy: 'promotion_id',
            rankBy: 'worth',
            permission: self::PERMISSION,
            query: fn (array $f) => DB::table('promotion_applications as pa')
                ->join('promotions as p', 'p.id', '=', 'pa.promotion_id')
                ->where('pa.company_id', $f['company_id'])
                ->whereNull('pa.reversed_at')
                ->whereBetween('pa.created_at', self::day($f))
                ->groupBy('pa.promotion_id', 'p.code', 'p.name_en', 'p.name_bn', 'p.status')
                ->orderByRaw('SUM(pa.worth) desc')
                ->select([
                    'pa.promotion_id',
                    DB::raw('p.code as promotion_code'),
                    self::offerName('p'),
                    self::statusLabel('p.status'),
                    DB::raw('COUNT(*) as application_count'),
                    DB::raw('COUNT(DISTINCT pa.source_type, pa.source_id) as bill_count'),
                    DB::raw('COUNT(DISTINCT pa.customer_id) as customer_count'),
                    self::discountWorthSum(),
                    self::giftWorthSum(),
                    DB::raw('SUM(pa.worth) as worth'),
                ]),
            columns: [
                ['key' => 'promotion_code', 'label' => 'promotion::report.code', 'width' => '9rem'],
                ['key' => 'promotion_name', 'label' => 'promotion::report.name'],
                ['key' => 'status_label', 'label' => 'promotion::report.status', 'width' => '9rem'],
                ['key' => 'application_count', 'label' => 'promotion::report.application_count',
                    'type' => ReportColumn::QUANTITY, 'width' => '7rem'],
                ['key' => 'bill_count', 'label' => 'promotion::report.bill_count',
                    'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '7rem'],
                ['key' => 'customer_count', 'label' => 'promotion::report.customer_count',
                    'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '7rem'],
                ['key' => 'discount_worth', 'label' => 'promotion::report.discount_worth', 'type' => ReportColumn::MONEY],
                ['key' => 'gift_worth', 'label' => 'promotion::report.gift_worth', 'type' => ReportColumn::MONEY],
                ['key' => 'worth', 'label' => 'promotion::report.worth', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ⭐ কোন ক্রেতা কত সুবিধা পেলেন।
     *
     * ⓘ ক্রেতাহীন বিল (নগদ কাউন্টার) একটা সারিতে — ⚠️ বাদ দিলে যোগফলটা
     * ব্যবহারের রিপোর্টের যোগফলের সাথে মিলত না, আর কেউ জানত না কেন।
     */
    public static function byCustomer(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'promotion.by_customer',
            title: 'promotion::report.title_by_customer',
            filters: ['date_range'],
            groupBy: 'customer_id',
            rankBy: 'worth',
            permission: self::PERMISSION,
            query: function (array $f) {
                $walkIn = self::quote(__('promotion::report.walk_in'));

                return DB::table('promotion_applications as pa')
                    ->leftJoin('customers as c', 'c.id', '=', 'pa.customer_id')
                    ->where('pa.company_id', $f['company_id'])
                    ->whereNull('pa.reversed_at')
                    ->whereBetween('pa.created_at', self::day($f))
                    ->groupBy('pa.customer_id', 'c.code', 'c.name_en', 'c.name_bn')
                    ->orderByRaw('SUM(pa.worth) desc')
                    ->select([
                        'pa.customer_id',
                        DB::raw('CASE WHEN pa.customer_id IS NULL THEN '.$walkIn
                            ." ELSE CONCAT(c.code, ' - ', ".self::nameSql('c').') END as customer_name'),
                        DB::raw('COUNT(DISTINCT pa.promotion_id) as offer_count'),
                        DB::raw('COUNT(DISTINCT pa.source_type, pa.source_id) as bill_count'),
                        DB::raw('COUNT(*) as application_count'),
                        self::discountWorthSum(),
                        self::giftWorthSum(),
                        DB::raw('SUM(pa.worth) as worth'),
                    ]);
            },
            columns: [
                ['key' => 'customer_name', 'label' => 'promotion::report.customer'],
                ['key' => 'offer_count', 'label' => 'promotion::report.offer_count',
                    'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '7rem'],
                ['key' => 'bill_count', 'label' => 'promotion::report.bill_count',
                    'type' => ReportColumn::QUANTITY, 'width' => '7rem'],
                ['key' => 'application_count', 'label' => 'promotion::report.application_count',
                    'type' => ReportColumn::QUANTITY, 'width' => '7rem'],
                ['key' => 'discount_worth', 'label' => 'promotion::report.discount_worth', 'type' => ReportColumn::MONEY],
                ['key' => 'gift_worth', 'label' => 'promotion::report.gift_worth', 'type' => ReportColumn::MONEY],
                ['key' => 'worth', 'label' => 'promotion::report.worth', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ⭐ কোন পণ্যের উপর কত সুবিধা গেল।
     *
     * ⚠️ পণ্য এখানে **যে লাইনে অফারটা বসেছে** — উপহারের পণ্য নয়।
     * ⓘ উপহারের পণ্য ধরে গোনা [[giftStock]]-এর কাজ। ⓘ গোটা বিলে বসা
     * অফারের (যেমন মূল্য-স্ল্যাব) কোনো লাইন নেই — ওগুলো "গোটা বিল" সারিতে।
     */
    public static function byProduct(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'promotion.by_product',
            title: 'promotion::report.title_by_product',
            filters: ['date_range'],
            groupBy: 'product_id',
            rankBy: 'worth',
            permission: self::PERMISSION,
            query: function (array $f) {
                $wholeBill = self::quote(__('promotion::report.whole_bill'));

                return DB::table('promotion_applications as pa')
                    ->leftJoin('inv_products as pr', 'pr.id', '=', 'pa.product_id')
                    ->where('pa.company_id', $f['company_id'])
                    ->whereNull('pa.reversed_at')
                    ->whereBetween('pa.created_at', self::day($f))
                    ->groupBy('pa.product_id', 'pr.code', 'pr.name_en', 'pr.name_bn')
                    ->orderByRaw('SUM(pa.worth) desc')
                    ->select([
                        'pa.product_id',
                        DB::raw('CASE WHEN pa.product_id IS NULL THEN '.$wholeBill
                            ." ELSE CONCAT(pr.code, ' - ', ".self::nameSql('pr').') END as product_name'),
                        DB::raw('COUNT(DISTINCT pa.promotion_id) as offer_count'),
                        DB::raw('COUNT(DISTINCT pa.source_type, pa.source_id) as bill_count'),
                        DB::raw('COUNT(*) as application_count'),
                        self::discountWorthSum(),
                        self::giftWorthSum(),
                        DB::raw('SUM(pa.worth) as worth'),
                    ]);
            },
            columns: [
                ['key' => 'product_name', 'label' => 'promotion::report.product'],
                ['key' => 'offer_count', 'label' => 'promotion::report.offer_count',
                    'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '7rem'],
                ['key' => 'bill_count', 'label' => 'promotion::report.bill_count',
                    'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '7rem'],
                ['key' => 'application_count', 'label' => 'promotion::report.application_count',
                    'type' => ReportColumn::QUANTITY, 'width' => '7rem'],
                ['key' => 'discount_worth', 'label' => 'promotion::report.discount_worth', 'type' => ReportColumn::MONEY],
                ['key' => 'gift_worth', 'label' => 'promotion::report.gift_worth', 'type' => ReportColumn::MONEY],
                ['key' => 'worth', 'label' => 'promotion::report.worth', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ⭐ প্রতিটা ছাড় — শতাংশ ও নির্দিষ্ট অঙ্ক, বিল ধরে।
     *
     * ⓘ বিলের নম্বরে ক্লিক করলে বিলটাই খোলে (নিয়ম ১) — `source_type`
     * বিক্রয়ের নিজের drill নাম, তাই এখানে কোনো রুট লেখা নেই।
     *
     * ⚠️ "হার" কলামের যোগফল নেই: ১০% আর ৫০ টাকা এক কলামে বসে, আর
     * দুইটা যোগ করলে একটা অর্থহীন সংখ্যা হত।
     */
    public static function discounts(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'promotion.discounts',
            title: 'promotion::report.title_discounts',
            filters: ['date_range'],
            permission: self::PERMISSION,
            query: function (array $f) {
                $changed = self::quote(__('promotion::report.overridden_yes'));

                return self::applications($f)
                    ->whereNull('pa.reversed_at')
                    ->whereIn('pa.benefit_kind', [BenefitKind::PERCENT->value, BenefitKind::AMOUNT->value])
                    ->whereBetween('pa.created_at', self::day($f))
                    ->orderByDesc('pa.created_at')
                    ->orderByDesc('pa.id')
                    ->select([
                        ...self::applicationColumns(),
                        DB::raw('pa.created_at as applied_on'),
                        DB::raw('pa.benefit_amount as rate'),
                        DB::raw("CASE WHEN pa.was_overridden = 1 THEN {$changed} ELSE '' END as overridden_note"),
                        'pa.worth',
                    ]);
            },
            columns: [
                ['key' => 'applied_on', 'label' => 'promotion::report.applied_on', 'type' => ReportColumn::DATE],
                ['key' => 'promotion_code', 'label' => 'promotion::report.code', 'width' => '9rem'],
                ['key' => 'bill', 'label' => 'promotion::report.bill', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'bill_source', 'source_id' => 'bill_id'],
                ['key' => 'customer_name', 'label' => 'promotion::report.customer'],
                ['key' => 'product_name', 'label' => 'promotion::report.product'],
                ['key' => 'kind_label', 'label' => 'promotion::report.kind'],
                ['key' => 'rate', 'label' => 'promotion::report.rate',
                    'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '7rem'],
                ['key' => 'overridden_note', 'label' => 'promotion::report.overridden'],
                ['key' => 'worth', 'label' => 'promotion::report.worth', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ⭐ প্রতিটা উপহার — কে পেলেন, কোন গুদাম থেকে, মালিকের কত খরচ।
     *
     * ⛔ খরচ **জমে থাকা** একক-খরচে (`unit_cost`) — বিক্রয়মূল্যে নয়, আজকের
     * খরচেও নয়। ⓘ [[PromotionGiftIssue::cost()]]-এর একই নিয়ম: বের হওয়ার
     * মুহূর্তের খরচ, ফেরত বাদে। ⚠️ আজকের খরচ ধরলে গত বছরের উপহারের দাম
     * প্রতিবার দাম বাড়লে বদলে যেত।
     *
     * ⓘ SQL-এর DECIMAL গুণ নির্ভুল (float নয়), তাই এখানে bcmath লাগে না।
     */
    public static function gifts(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'promotion.gifts',
            title: 'promotion::report.title_gifts',
            filters: ['date_range'],
            permission: self::PERMISSION,
            query: function (array $f) {
                $reversed = self::quote(__('promotion::report.bill_reversed'));
                $walkIn = self::quote(__('promotion::report.walk_in'));

                return DB::table('promotion_gift_issues as gi')
                    ->join('promotion_applications as pa', 'pa.id', '=', 'gi.promotion_application_id')
                    ->join('promotions as p', 'p.id', '=', 'pa.promotion_id')
                    ->join('inv_products as pr', 'pr.id', '=', 'gi.product_id')
                    ->join('inv_warehouses as w', 'w.id', '=', 'gi.warehouse_id')
                    ->leftJoin('customers as c', 'c.id', '=', 'pa.customer_id')
                    ->where('gi.company_id', $f['company_id'])
                    ->whereBetween('gi.issued_at', self::day($f))
                    ->orderByDesc('gi.issued_at')
                    ->orderByDesc('gi.id')
                    ->select([
                        DB::raw('gi.issued_at as issued_on'),
                        DB::raw('gi.code as gift_code'),
                        DB::raw('p.code as promotion_code'),
                        DB::raw('pa.source_type as bill_source'),
                        DB::raw('pa.source_id as bill_id'),
                        DB::raw("CONCAT(pa.source_type, ' #', pa.source_id) as bill"),
                        DB::raw('CASE WHEN pa.customer_id IS NULL THEN '.$walkIn
                            ." ELSE CONCAT(c.code, ' - ', ".self::nameSql('c').') END as customer_name'),
                        DB::raw("CONCAT(pr.code, ' - ', ".self::nameSql('pr').') as product_name'),
                        DB::raw(self::nameSql('w').' as warehouse_name'),
                        DB::raw('gi.qty as qty_issued'),
                        DB::raw('gi.returned_qty as qty_returned'),
                        DB::raw('(gi.qty - gi.returned_qty) as qty_net'),
                        'gi.unit_cost',
                        DB::raw('(gi.qty - gi.returned_qty) * gi.unit_cost as cost'),
                        DB::raw("CASE WHEN pa.reversed_at IS NOT NULL THEN {$reversed} ELSE '' END as reversed_note"),
                    ]);
            },
            columns: [
                ['key' => 'issued_on', 'label' => 'promotion::report.issued_on', 'type' => ReportColumn::DATE],
                ['key' => 'gift_code', 'label' => 'promotion::report.gift_code', 'width' => '9rem'],
                ['key' => 'promotion_code', 'label' => 'promotion::report.code', 'width' => '9rem'],
                ['key' => 'bill', 'label' => 'promotion::report.bill', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'bill_source', 'source_id' => 'bill_id'],
                ['key' => 'customer_name', 'label' => 'promotion::report.customer'],
                ['key' => 'product_name', 'label' => 'promotion::report.product'],
                ['key' => 'warehouse_name', 'label' => 'promotion::report.warehouse'],
                ['key' => 'qty_issued', 'label' => 'promotion::report.qty_issued', 'type' => ReportColumn::QUANTITY],
                ['key' => 'qty_returned', 'label' => 'promotion::report.qty_returned', 'type' => ReportColumn::QUANTITY],
                ['key' => 'qty_net', 'label' => 'promotion::report.qty_net', 'type' => ReportColumn::QUANTITY],
                ['key' => 'unit_cost', 'label' => 'promotion::report.unit_cost',
                    'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'cost', 'label' => 'promotion::report.cost', 'type' => ReportColumn::MONEY],
                ['key' => 'reversed_note', 'label' => 'promotion::report.note'],
            ],
        );
    }

    /**
     * ⭐ উপহারে কোন পণ্য কোন গুদাম থেকে কতটা গেল — মজুদের চোখে।
     *
     * ⓘ সারিটা পণ্য × গুদাম, কারণ গুদামরক্ষক প্রশ্ন করেন "আমার গুদাম থেকে
     * উপহারে কত গেল"। ⚠️ কেবল পণ্য ধরলে তিন গুদামের হিসাব এক সংখ্যায়
     * মিশে যেত, আর কোন গুদামের গণনা মিলছে না তা বোঝা যেত না।
     */
    public static function giftStock(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'promotion.gift_stock',
            title: 'promotion::report.title_gift_stock',
            filters: ['date_range'],
            groupBy: 'stock_key',
            rankBy: 'cost',
            permission: self::PERMISSION,
            query: fn (array $f) => DB::table('promotion_gift_issues as gi')
                ->join('inv_products as pr', 'pr.id', '=', 'gi.product_id')
                ->join('inv_warehouses as w', 'w.id', '=', 'gi.warehouse_id')
                ->where('gi.company_id', $f['company_id'])
                ->whereBetween('gi.issued_at', self::day($f))
                ->groupBy('gi.product_id', 'gi.warehouse_id', 'pr.code', 'pr.name_en', 'pr.name_bn',
                    'w.name_en', 'w.name_bn')
                ->orderByRaw('SUM((gi.qty - gi.returned_qty) * gi.unit_cost) desc')
                ->select([
                    DB::raw("CONCAT(gi.product_id, '-', gi.warehouse_id) as stock_key"),
                    DB::raw("CONCAT(pr.code, ' - ', ".self::nameSql('pr').') as product_name'),
                    DB::raw(self::nameSql('w').' as warehouse_name'),
                    DB::raw('COUNT(*) as issue_count'),
                    DB::raw('SUM(gi.qty) as qty_issued'),
                    DB::raw('SUM(gi.returned_qty) as qty_returned'),
                    DB::raw('SUM(gi.qty - gi.returned_qty) as qty_net'),
                    DB::raw('SUM((gi.qty - gi.returned_qty) * gi.unit_cost) as cost'),
                ]),
            columns: [
                ['key' => 'product_name', 'label' => 'promotion::report.product'],
                ['key' => 'warehouse_name', 'label' => 'promotion::report.warehouse'],
                ['key' => 'issue_count', 'label' => 'promotion::report.issue_count',
                    'type' => ReportColumn::QUANTITY, 'width' => '7rem'],
                ['key' => 'qty_issued', 'label' => 'promotion::report.qty_issued', 'type' => ReportColumn::QUANTITY],
                ['key' => 'qty_returned', 'label' => 'promotion::report.qty_returned', 'type' => ReportColumn::QUANTITY],
                ['key' => 'qty_net', 'label' => 'promotion::report.qty_net', 'type' => ReportColumn::QUANTITY],
                ['key' => 'cost', 'label' => 'promotion::report.cost', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ⭐ প্রতিটা ছাদ, আর তার কতটা গেছে — **পাহারার একই হিসাবে**।
     *
     * ── ⛔ কেন এখানে SQL-এ নতুন করে গোনা হয় না ──────────────────────
     * ⓘ "কতটা গেছে" [[BudgetGuard::usage()]] বলে, আর সে-ই বিল থামায়।
     * ⚠️ রিপোর্টের জন্য আলাদা SUM লিখলে একদিন রিপোর্ট বলত "৪০% বাকি"
     * অথচ পাহারা বিল আটকাত — ঠিক যে ভুলের কথা পাহারার নিজের মাথায় লেখা।
     *
     * ── ⓘ তবু কেন একটা কোয়েরি ──────────────────────────────────────
     * ইঞ্জিন একটা কোয়েরি চায় — পাতা, খোঁজা, যোগফল সব তার উপর। ⭐ তাই
     * পাহারার উত্তরগুলো একটা ছোট উৎপন্ন-টেবিলে বসে (`u`), আর বাকিটা
     * সাধারণ JOIN। ⚠️ ঐ টেবিলে কেবল সংখ্যা বসে — id আর bcmath-এ ছাঁকা
     * অঙ্ক — কোনো লেখা নয়, তাই উদ্ধৃতি ভাঙার কোনো পথ নেই।
     *
     * ⚠️ ছাদের অঙ্কগুলোর যোগফল নেই: টাকার ছাদ আর পরিমাণের ছাদ একই কলামে
     * বসে, আর দুইটা যোগ করা অর্থহীন।
     */
    public static function budgets(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'promotion.budgets',
            title: 'promotion::report.title_budgets',
            filters: ['date_range'],
            permission: self::PERMISSION,
            query: function (array $f) {
                $near = self::quote(__('promotion::report.near_ceiling'));

                return DB::table('promotion_budgets as b')
                    ->join('promotions as p', 'p.id', '=', 'b.promotion_id')
                    ->leftJoin(DB::raw('('.self::usageTable($f).') as u'), 'u.budget_id', '=', 'b.id')
                    ->where('b.company_id', $f['company_id'])
                    ->whereNull('p.deleted_at')
                    ->where('p.starts_on', '<=', $f['to'])
                    ->where('p.ends_on', '>=', $f['from'])
                    ->orderByRaw('COALESCE(u.used_percent, 0) desc')
                    ->orderBy('b.id')
                    ->select([
                        DB::raw('p.code as promotion_code'),
                        self::offerName('p'),
                        self::statusLabel('p.status'),
                        self::labelCase('b.kind', [
                            PromotionBudget::TOTAL => __('promotion::budget.total'),
                            PromotionBudget::DISCOUNT => __('promotion::budget.discount'),
                            PromotionBudget::GIFT => __('promotion::budget.gift'),
                            PromotionBudget::QUANTITY => __('promotion::budget.quantity'),
                        ], 'kind_label'),
                        self::labelCase('b.per', [
                            PromotionBudget::PER_OFFER => __('promotion::budget.per_offer'),
                            PromotionBudget::PER_BILL => __('promotion::budget.per_bill'),
                            PromotionBudget::PER_CUSTOMER => __('promotion::budget.per_customer'),
                            PromotionBudget::PER_DAY => __('promotion::budget.per_day'),
                            PromotionBudget::PER_MONTH => __('promotion::budget.per_month'),
                        ], 'per_label'),
                        'b.ceiling',
                        /*
                         * ⓘ জানালার ছাদে (প্রতি বিল, ক্রেতা, দিন, মাস) একটা মোট খরচ নেই —
                         * ⚠️ শূন্য দেখালে "বাকি = পুরো ছাদ" পড়া যেত, যা মিথ্যা। তাই খালি।
                         */
                        DB::raw("CASE WHEN b.per = 'offer' THEN COALESCE(u.used, 0) END as used"),
                        DB::raw("CASE WHEN b.per = 'offer' THEN b.ceiling - COALESCE(u.used, 0) END as remaining"),
                        DB::raw('COALESCE(u.used_percent, 0) as used_percent'),
                        DB::raw('b.warn_at_percent as warn_at'),
                        DB::raw("CASE WHEN COALESCE(u.used_percent, 0) >= b.warn_at_percent THEN {$near} ELSE '' END as near_note"),
                    ]);
            },
            columns: [
                ['key' => 'promotion_code', 'label' => 'promotion::report.code', 'width' => '9rem'],
                ['key' => 'promotion_name', 'label' => 'promotion::report.name'],
                ['key' => 'status_label', 'label' => 'promotion::report.status', 'width' => '9rem'],
                ['key' => 'kind_label', 'label' => 'promotion::report.budget_kind'],
                ['key' => 'per_label', 'label' => 'promotion::field.budget_per', 'width' => '9rem'],
                ['key' => 'ceiling', 'label' => 'promotion::report.ceiling', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'used', 'label' => 'promotion::report.used', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'remaining', 'label' => 'promotion::report.remaining', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'used_percent', 'label' => 'promotion::report.used_percent', 'type' => ReportColumn::PERCENT],
                ['key' => 'warn_at', 'label' => 'promotion::report.warn_at', 'type' => ReportColumn::PERCENT],
                ['key' => 'near_note', 'label' => 'promotion::report.note'],
            ],
        );
    }

    /**
     * ⭐ হাতে বদলানো সুবিধা — আগে কত ছিল, পরে কত, কে, কবে, কেন।
     *
     * ⓘ স্পেক §১৬: নিয়ম ভাঙা হলে তার পুরো দাগ থাকতে হবে। ⚠️ কেবল নতুন
     * অঙ্কটা দেখালে কত টাকা বাড়তি গেল কেউ জানত না — তাই পার্থক্যের
     * কলাম, আর তার যোগফল: "এই মাসে হাতে বদলে কত বাড়তি গেল"।
     */
    public static function overrides(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'promotion.overrides',
            title: 'promotion::report.title_overrides',
            filters: ['date_range'],
            permission: self::PERMISSION,
            query: fn (array $f) => self::applications($f)
                ->leftJoin('users as ou', 'ou.id', '=', 'pa.overridden_by')
                ->where('pa.was_overridden', true)
                ->whereBetween('pa.overridden_at', self::day($f))
                ->orderByDesc('pa.overridden_at')
                ->orderByDesc('pa.id')
                ->select([
                    ...self::applicationColumns(),
                    DB::raw('pa.overridden_at as changed_on'),
                    'pa.original_worth',
                    DB::raw('pa.worth as worth_after'),
                    DB::raw('pa.worth - COALESCE(pa.original_worth, pa.worth) as change_amount'),
                    DB::raw('pa.override_reason as reason'),
                    DB::raw('ou.name as changed_by_name'),
                ]),
            columns: [
                ['key' => 'changed_on', 'label' => 'promotion::report.changed_on', 'type' => ReportColumn::DATE],
                ['key' => 'promotion_code', 'label' => 'promotion::report.code', 'width' => '9rem'],
                ['key' => 'bill', 'label' => 'promotion::report.bill', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'bill_source', 'source_id' => 'bill_id'],
                ['key' => 'customer_name', 'label' => 'promotion::report.customer'],
                ['key' => 'kind_label', 'label' => 'promotion::report.kind'],
                ['key' => 'original_worth', 'label' => 'promotion::report.original_worth', 'type' => ReportColumn::MONEY],
                ['key' => 'worth_after', 'label' => 'promotion::report.worth_after', 'type' => ReportColumn::MONEY],
                ['key' => 'change_amount', 'label' => 'promotion::report.change', 'type' => ReportColumn::MONEY],
                ['key' => 'reason', 'label' => 'promotion::report.reason'],
                ['key' => 'changed_by_name', 'label' => 'promotion::report.changed_by'],
            ],
        );
    }

    /**
     * ⛔ বাতিল বিলের সুবিধা — যা দেওয়া হয়েছিল আর ফিরিয়ে নেওয়া হলো।
     *
     * ⓘ ব্যবহারের রিপোর্ট এগুলো বাদ দেয়; ⚠️ এখানে না থাকলে সেগুলো কোথাও
     * দেখা যেত না, আর "বিল বাতিল করে অফার নেওয়া" ধরা পড়ত না।
     */
    public static function reversals(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'promotion.reversals',
            title: 'promotion::report.title_reversals',
            filters: ['date_range'],
            permission: self::PERMISSION,
            query: fn (array $f) => self::applications($f)
                ->leftJoin('users as ru', 'ru.id', '=', 'pa.reversed_by')
                ->whereNotNull('pa.reversed_at')
                ->whereBetween('pa.reversed_at', self::day($f))
                ->orderByDesc('pa.reversed_at')
                ->orderByDesc('pa.id')
                ->select([
                    ...self::applicationColumns(),
                    DB::raw('pa.reversed_at as reversed_on'),
                    DB::raw('pa.created_at as applied_on'),
                    'pa.worth',
                    DB::raw('ru.name as reversed_by_name'),
                ]),
            columns: [
                ['key' => 'reversed_on', 'label' => 'promotion::report.reversed_on', 'type' => ReportColumn::DATE],
                ['key' => 'applied_on', 'label' => 'promotion::report.applied_on', 'type' => ReportColumn::DATE],
                ['key' => 'promotion_code', 'label' => 'promotion::report.code', 'width' => '9rem'],
                ['key' => 'bill', 'label' => 'promotion::report.bill', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'bill_source', 'source_id' => 'bill_id'],
                ['key' => 'customer_name', 'label' => 'promotion::report.customer'],
                ['key' => 'kind_label', 'label' => 'promotion::report.kind'],
                ['key' => 'worth', 'label' => 'promotion::report.worth', 'type' => ReportColumn::MONEY],
                ['key' => 'reversed_by_name', 'label' => 'promotion::report.reversed_by'],
            ],
        );
    }

    /**
     * ⛔ বাতিল করা অফার — আর বাতিলের আগে কত দিয়ে গেছে।
     *
     * ── ⚠️ "কবে বাতিল" কলামটা কেন নেই ─────────────────────────────────
     * ⓘ `promotions` টেবিলে `cancelled_at` নেই। ⛔ `updated_at`-কে বাতিলের
     * তারিখ বলা মিথ্যা হত — পরের যেকোনো বদল ওটা সরিয়ে দেয়। ⭐ তাই কলামটার
     * নাম সৎ: "শেষ বদল"। আসল মুহূর্তটা নিরীক্ষার পাতায় আছে।
     *
     * ⓘ ছাঁকনি মেয়াদ ধরে — পরিসরের সাথে যাদের মেয়াদ মেলে।
     */
    public static function cancelledOffers(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'promotion.cancelled_offers',
            title: 'promotion::report.title_cancelled_offers',
            filters: ['date_range'],
            permission: self::PERMISSION,
            query: fn (array $f) => DB::table('promotions as p')
                ->where('p.company_id', $f['company_id'])
                ->whereNull('p.deleted_at')
                ->where('p.status', PromotionStatus::CANCELLED->value)
                ->where('p.starts_on', '<=', $f['to'])
                ->where('p.ends_on', '>=', $f['from'])
                ->orderByDesc('p.updated_at')
                ->select([
                    'p.id as promotion_id',
                    'p.code as promotion_code',
                    self::offerName('p'),
                    self::typeLabel('p.type'),
                    'p.starts_on',
                    'p.ends_on',
                    DB::raw('p.updated_at as last_changed'),
                    DB::raw('(SELECT COUNT(*) FROM promotion_applications x '
                        .'WHERE x.promotion_id = p.id AND x.reversed_at IS NULL) as application_count'),
                    self::givenSoFar('p'),
                ]),
            columns: [
                ['key' => 'promotion_code', 'label' => 'promotion::report.code', 'width' => '9rem'],
                ['key' => 'promotion_name', 'label' => 'promotion::report.name'],
                ['key' => 'type_label', 'label' => 'promotion::report.type'],
                ['key' => 'starts_on', 'label' => 'promotion::report.starts_on', 'type' => ReportColumn::DATE],
                ['key' => 'ends_on', 'label' => 'promotion::report.ends_on', 'type' => ReportColumn::DATE],
                ['key' => 'last_changed', 'label' => 'promotion::report.last_changed', 'type' => ReportColumn::DATE],
                ['key' => 'application_count', 'label' => 'promotion::report.application_count',
                    'type' => ReportColumn::QUANTITY, 'width' => '7rem'],
                ['key' => 'given_worth', 'label' => 'promotion::report.given_worth', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    // ── যন্ত্রপাতি ───────────────────────────────────────────────────────

    /**
     * সুবিধার সারির অভিন্ন শুরু — অফার, ক্রেতা, পণ্য জোড়া।
     *
     * ⓘ ক্রেতা ও পণ্য LEFT JOIN: নগদ বিলে ক্রেতা নেই, গোটা-বিলের অফারে
     * পণ্য নেই। ⛔ সাধারণ JOIN হলে ঐ সারিগুলো নীরবে উধাও হত।
     */
    private static function applications(array $f): Builder
    {
        return DB::table('promotion_applications as pa')
            ->join('promotions as p', 'p.id', '=', 'pa.promotion_id')
            ->leftJoin('customers as c', 'c.id', '=', 'pa.customer_id')
            ->leftJoin('inv_products as pr', 'pr.id', '=', 'pa.product_id')
            ->where('pa.company_id', $f['company_id']);
    }

    /**
     * [[applications()]]-এর সাথে যে কলামগুলো সবসময় যায়।
     *
     * @return list<Expression>
     */
    private static function applicationColumns(): array
    {
        $walkIn = self::quote(__('promotion::report.walk_in'));
        $wholeBill = self::quote(__('promotion::report.whole_bill'));

        return [
            DB::raw('p.code as promotion_code'),
            DB::raw('pa.source_type as bill_source'),
            DB::raw('pa.source_id as bill_id'),

            /*
             * ⓘ বিলের নম্বর বিক্রয়ের টেবিলে — ⛔ এই মডিউল সেখানে JOIN করে না
             * (বিক্রয়ের উপর নির্ভরতা নেই)। ⭐ ক্লিক করলে [[x-ui.drill]] আসল
             * নম্বরসহ বিলটা খোলে; লেখাটা কেবল রপ্তানিতে চেনার জন্য।
             */
            DB::raw("CONCAT(pa.source_type, ' #', pa.source_id) as bill"),
            DB::raw('CASE WHEN pa.customer_id IS NULL THEN '.$walkIn
                ." ELSE CONCAT(c.code, ' - ', ".self::nameSql('c').') END as customer_name'),
            DB::raw('CASE WHEN pa.product_id IS NULL THEN '.$wholeBill
                ." ELSE CONCAT(pr.code, ' - ', ".self::nameSql('pr').') END as product_name'),
            self::labelCase('pa.benefit_kind', self::labelsOf(BenefitKind::cases()), 'kind_label'),
        ];
    }

    /**
     * ⭐ পাহারার উত্তরগুলো একটা উৎপন্ন-টেবিলে — কেবল সংখ্যা।
     *
     * ⓘ পরিসরের অফারগুলোর প্রতিটার জন্য [[BudgetGuard::usage()]] একবার।
     * ⚠️ অফার-প্রতি কয়েকটা কোয়েরি — ছাদ-ওয়ালা অফার সাধারণত কয়েক ডজন,
     * তাই এটা সহনীয়; হাজার ছাড়ালে এখানে একটা ব্যাচ-হিসাব লাগবে, আর
     * সেটাও পাহারার ভিতরেই, এখানে নয়।
     */
    private static function usageTable(array $f): string
    {
        $guard = app(BudgetGuard::class);

        $offers = Promotion::query()
            ->whereIn('id', PromotionBudget::query()->select('promotion_id'))
            ->where('starts_on', '<=', $f['to'])
            ->where('ends_on', '>=', $f['from'])
            ->get();

        $rows = [];

        foreach ($offers as $offer) {
            foreach ($guard->usage($offer) as $usage) {
                $rows[] = sprintf(
                    'SELECT %d AS budget_id, %s AS used, %d AS used_percent',
                    (int) $usage['budget']->id,
                    self::decimal($usage['used']),
                    (int) $usage['percent'],
                );
            }
        }

        /*
         * ⓘ ছাদ-ওয়ালা অফার না থাকলে একটা খালি টেবিল — ⚠️ খালি স্ট্রিং দিলে
         * JOIN-টাই অবৈধ SQL হত আর পাতা ৫০০ দিত।
         */
        return $rows === []
            ? 'SELECT 0 AS budget_id, 0 AS used, 0 AS used_percent FROM DUAL WHERE 1 = 0'
            : implode(' UNION ALL ', $rows);
    }

    /**
     * ⛔ কেবল সংখ্যা — নাহলে শূন্য।
     *
     * ⓘ মানটা পাহারা থেকে আসে, ব্যবহারকারীর কাছ থেকে নয়। ⚠️ তবু SQL-এ
     * সরাসরি বসে বলে দ্বিতীয়বার ছাঁকা হয়: কোনোদিন পাহারা `null` বা
     * অন্য কিছু ফেরত দিলে SQL ভাঙার বদলে শূন্য বসবে।
     */
    private static function decimal(mixed $value): string
    {
        $value = trim((string) ($value ?? '0'));

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            return '0';
        }

        return bcadd($value, '0', 4);
    }

    /**
     * ⓘ অফারটা এখন পর্যন্ত কত দিল — বাতিল বিল বাদে।
     *
     * ⚠️ `promotion_id` ধরে, আর অফারটা বাইরের কোয়েরিতেই কোম্পানি ধরে বাছা।
     */
    private static function givenSoFar(string $alias): Expression
    {
        return DB::raw('(SELECT COALESCE(SUM(g.worth), 0) FROM promotion_applications g '
            ."WHERE g.promotion_id = {$alias}.id AND g.reversed_at IS NULL) as given_worth");
    }

    private static function discountWorthSum(): Expression
    {
        return DB::raw("SUM(CASE WHEN pa.benefit_kind IN ('"
            .BenefitKind::PERCENT->value."', '".BenefitKind::AMOUNT->value
            ."') THEN pa.worth ELSE 0 END) as discount_worth");
    }

    private static function giftWorthSum(): Expression
    {
        return DB::raw("SUM(CASE WHEN pa.benefit_kind = '".BenefitKind::GOODS->value
            ."' THEN pa.worth ELSE 0 END) as gift_worth");
    }

    /** ⓘ দিনের শুরু থেকে শেষ — সময়ের কলামে কেবল তারিখ দিলে শেষ দিনটা বাদ পড়ত। */
    private static function day(array $f): array
    {
        return [$f['from'].' 00:00:00', $f['to'].' 23:59:59'];
    }

    /** ব্যবহারকারীর ভাষায় নাম — বাংলা না থাকলে ইংরেজি (সেকশন ১৮.৩)। */
    private static function nameSql(string $alias): string
    {
        return app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF({$alias}.name_bn, ''), {$alias}.name_en)"
            : "{$alias}.name_en";
    }

    private static function offerName(string $alias): Expression
    {
        return DB::raw(self::nameSql($alias).' as promotion_name');
    }

    private static function statusLabel(string $column): Expression
    {
        return self::labelCase($column, self::labelsOf(PromotionStatus::cases()), 'status_label');
    }

    private static function typeLabel(string $column): Expression
    {
        return self::labelCase($column, self::labelsOf(PromotionType::cases()), 'type_label');
    }

    /**
     * ⓘ enum-এর নিজের `label()` থেকে — দ্বিতীয় কোনো তালিকা লেখা হয় না।
     *
     * @param  list<\BackedEnum>  $cases
     * @return array<string, string>
     */
    private static function labelsOf(array $cases): array
    {
        $labels = [];

        foreach ($cases as $case) {
            $labels[(string) $case->value] = $case->label();
        }

        return $labels;
    }

    /**
     * ⭐ কাঁচা মান থেকে মানুষের নাম — SQL-এর ভিতরেই।
     *
     * ⓘ পর্দা, খোঁজা আর রপ্তানি তিনজনই সারির মানটা পড়ে। ⚠️ নামটা ভিউতে
     * বসালে খোঁজা "চলছে" টাইপ করলে কিছুই পেত না (কলামে `active` লেখা),
     * আর CSV-তে কাঁচা মান যেত।
     *
     * ⛔ মানগুলো `?` দিয়ে নয়, PDO-র উদ্ধৃতিতে — SELECT-এর বাঁধন ইঞ্জিনের
     * মোড়কে সরে যায়; আর অনুবাদে একটা apostrophe থাকলে হাতে লেখা উদ্ধৃতি
     * ভাঙত।
     *
     * @param  array<string, string>  $labels
     */
    private static function labelCase(string $column, array $labels, string $alias): Expression
    {
        if ($labels === []) {
            return DB::raw("{$column} as {$alias}");
        }

        $case = 'CASE '.$column;

        foreach ($labels as $value => $label) {
            $case .= ' WHEN '.self::quote((string) $value).' THEN '.self::quote($label);
        }

        return DB::raw($case." ELSE {$column} END as {$alias}");
    }

    private static function quote(string $value): string
    {
        return DB::getPdo()->quote($value);
    }
}
