<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Models\AuditTrail;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use Illuminate\Support\Str;

/**
 * মাস্টার ডাটা মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন এখানে কোনো "আজকের" সংখ্যা নেই ────────────────────────────────
 * বাকি মডিউলের ড্যাশবোর্ড বলে **আজ কী হলো**। মাস্টার ডাটায় "আজ" বলে
 * কিছু নেই — একক, কর, ব্র্যান্ড বছরে দুই-চারবার বদলায়। এখানে "আজকের
 * একক" ধরনের সংখ্যা বসালে সেটা প্রায় সবসময় শূন্য দেখাত, আর পর্দাটা
 * অকেজো মনে হত।
 *
 * ── তাহলে এই পর্দার প্রশ্নটা কী ──────────────────────────────────────
 * **তালিকাগুলো ভরা আছে তো?** একটা খালি একক-তালিকা বা কর-তালিকা মানে
 * পণ্য বানানোই আটকে যাবে, আর ভুলটা ধরা পড়বে অনেক দূরে — পণ্যের ফর্মে,
 * একটা খালি ড্রপডাউন হিসেবে, যেখান থেকে কারণটা বোঝা যায় না।
 *
 * ── ⏸ "আজ আর এ মাসে কত নতুন" কেন এখনো নেই (৬ অক্টোবর ২০২৬) ─────────────
 * ⓘ নকশা চায় পাঁচটা তালিকার (গ্রাহক, সরবরাহকারী, পণ্য, কর্মী, গুদাম) নতুন সারির গোনা। ⛔ এই মডিউল ঐ মডিউলগুলো
 * চেনে না, আর তালিকার কোয়েরি (Builder) থাকে কেবল প্রতিটা মডিউলের নিজের `health()`-এ ([[MasterHealth::widget()]])।
 * ⚠️ সংখ্যাটা আনতে হলে প্রতিটা মডিউলের `health()`-কে ঐ একই Builder একটা নতুন কোরের সহায়কে দিতে হবে (যেমন
 * `created_at` ধরে `new_today`/`new_month` গোনা, আর `parts`-এ বসানো) — অর্থাৎ পাঁচটা অন্য মডিউলের ফাইল বদলানো।
 * ⏸ তাই এই ধাপে বাদ; ঐ পাঁচটা ফাইল যিনি দেখেন, তাঁর সাথে একসাথে বসবে।
 */
final class MasterDataDashboard implements ProvidesDashboard
{
    /**
     * ⓘ অন্য মডিউলের মাস্টার তালিকা — ক্লাসের **শেষ নাম** দিয়ে চেনা, পুরো namespace দিয়ে নয় (৬ অক্টোবর ২০২৬)।
     *
     * ⛔ এই মডিউল গ্রাহক, সরবরাহকারী, পণ্য, কর্মী বা গুদামের মডিউল চেনে না (`depends_on`) — `use` বা পুরো ক্লাসের নাম
     * লিখলে সীমারেখার পাহারা ([[BoundariesTest]]) ঠিকই লাল হত। ⓘ নিরীক্ষার খাতায় (`audit_trails.auditable_type`) যা লেখা
     * আছে, তার শেষ অংশ মিলিয়ে দেখা হয় — মডিউলটা বন্ধ বা মুছে গেলেও পর্দা ভাঙে না, কেবল সারিটা আসে না।
     *
     * @var list<string>
     */
    private const OTHER_LISTS = ['Customer', 'Supplier', 'Product', 'Employee', 'Warehouse'];

    public static function dashboard(): DashboardDefinition
    {
        $health = self::health();

        return new DashboardDefinition(
            title: __('master_data::dashboard.title'),
            subtitle: __('master_data::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('master_data::menu.units'), href: route('master_data.unit.index'),
                    permission: 'master_data.view', icon: 'settings'),
                new Tile(label: __('master_data::menu.taxes'), href: route('master_data.tax.index'),
                    permission: 'master_data.view', icon: 'scale'),
                new Tile(label: __('master_data::menu.product_categories'), href: route('master_data.product_category.index'),
                    permission: 'master_data.view', icon: 'list'),
                new Tile(label: __('master_data::menu.locations'), href: route('master_data.location.index'),
                    permission: 'master_data.view', icon: 'globe'),
            ],

            stats: [
                /*
                 * ⚠️ শূন্য হলে `BAD` — আর সেটাই এই পর্দার আসল কাজ।
                 *
                 * একক ছাড়া পণ্য বানানো যায় না। সংখ্যাটা লাল দেখলে
                 * কারণটা এখানেই বোঝা যায়; নাহলে পণ্যের ফর্মে একটা
                 * খালি ড্রপডাউন দেখে কেউ ভাবতেন ফর্মটাই ভাঙা।
                 */
                new Stat(
                    label: __('master_data::menu.units'),
                    value: (string) Unit::query()->count(),
                    hint: __('master_data::dashboard.units_hint'),
                    href: route('master_data.unit.index'),
                    tone: Unit::query()->count() === 0 ? Stat::BAD : Stat::NEUTRAL,
                ),

                new Stat(
                    label: __('master_data::menu.taxes'),
                    value: (string) Tax::query()->count(),
                    hint: __('master_data::dashboard.taxes_hint'),
                    href: route('master_data.tax.index'),
                    tone: Tax::query()->count() === 0 ? Stat::BAD : Stat::NEUTRAL,
                ),

                new Stat(
                    label: __('master_data::menu.product_categories'),
                    value: (string) ProductCategory::query()->count(),
                    hint: __('master_data::dashboard.categories_hint'),
                    href: route('master_data.product_category.index'),
                ),

                new Stat(
                    label: __('master_data::menu.locations'),
                    value: (string) Location::query()->inViewedBranch()->count(),
                    hint: __('master_data::dashboard.locations_hint'),
                    href: route('master_data.location.index'),
                ),

                ...$health['stats'],
            ],

            panels: [
                new Breakdown(
                    label: __('master_data::dashboard.how_full'),
                    parts: [
                        ['label' => __('master_data::menu.units'), 'value' => (string) Unit::query()->count()],
                        ['label' => __('master_data::menu.product_categories'), 'value' => (string) ProductCategory::query()->count()],
                        ['label' => __('master_data::menu.brands'), 'value' => (string) Brand::query()->count()],
                        ['label' => __('master_data::menu.taxes'), 'value' => (string) Tax::query()->count()],
                    ],
                    hint: __('master_data::dashboard.how_full_hint'),
                    // ⓘ তালিকা ধরে গোনা — খাড়া স্তম্ভ (মালিক, ৪ অক্টোবর ২০২৬: "vino rokomer graph")
                    chart: 'columns',
                ),

                ...$health['panels'],
            ],

            listings: self::recentlyChanged(),
        );
    }

    /**
     * ⭐ সদ্য বদলানো মাস্টার রেকর্ড — শেষ দশটা (মালিকের ড্যাশবোর্ড নকশা §১২, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ উৎস কোরের নিরীক্ষার খাতা ([[AuditTrail]]) — মডেলটা নিজেই কোম্পানির দেয়ালে বসা, তাই অন্য কোম্পানির দাগ আসে না।
     * ⓘ "মাস্টার" মানে এই মডিউলের নিজের সব তালিকা (একক, কর, ব্র্যান্ড, এলাকা…) আর [[self::OTHER_LISTS]]-এর পাঁচটা।
     * ⚠️ খাতায় কোন কোন ধরন আছে, আগে সেটা পড়া হয় (একটা DISTINCT), তারপর কেবল মিলে যাওয়াগুলো — অন্য মডিউলের নাম
     * এখানে লেখা থাকে না।
     * ⛔ কেবল `governance.audit.view` — নিরীক্ষার খাতা যে চাবিতে খোলে; নাহলে মাস্টার ডেটা দেখার চাবি দিয়েই অন্যের
     * গ্রাহকের নাম আর কে কবে বদলাল, তা পড়া যেত। ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Listing>
     */
    private static function recentlyChanged(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('governance.audit.view')) {
            return [];
        }

        $own = Str::beforeLast(Unit::class, '\\').'\\';

        $types = AuditTrail::query()->distinct()->pluck('auditable_type')
            ->filter(fn ($type) => str_starts_with((string) $type, $own)
                || (str_contains((string) $type, '\\Models\\') && in_array(class_basename((string) $type), self::OTHER_LISTS, true)))
            ->values()->all();

        return [new Listing(
            label: __('master_data::dashboard.recently_changed'),
            columns: [
                ['key' => 'when', 'label' => __('master_data::dashboard.col_when'), 'width' => '11rem',
                    'render' => fn (AuditTrail $t) => $t->created_at?->format('d M Y, H:i') ?? '—'],
                ['key' => 'who', 'label' => __('master_data::dashboard.col_who'), 'width' => '10rem',
                    'render' => fn (AuditTrail $t) => $t->user?->name ?? __('master_data::dashboard.system')],
                ['key' => 'list', 'label' => __('master_data::dashboard.col_list'), 'width' => '9rem',
                    'render' => fn (AuditTrail $t) => $t->moduleLabel() ?? '—'],
                ['key' => 'what', 'label' => __('master_data::dashboard.col_what'), 'width' => '9rem',
                    'render' => fn (AuditTrail $t) => AuditTrail::actionInWords($t->action)],
                ['key' => 'record', 'label' => __('master_data::dashboard.col_record'),
                    'render' => fn (AuditTrail $t) => $t->title()],
            ],
            rows: $types === []
                ? collect()
                : AuditTrail::query()->with('user')->whereIn('auditable_type', $types)->latest('id')->limit(10)->get(),
            empty: __('master_data::dashboard.recently_changed_empty'),
        )];
    }

    /**
     * ⭐ ডেটার মান — মালিকের ড্যাশবোর্ড নকশা §১২ ("Data Governance & Data Quality", ৪ অক্টোবর ২০২৬)।
     *
     * ⓘ প্রতিটা তালিকার মান সেই মডিউলই গোনে ([[MasterHealth]], [[DashboardRegistry::health()]]) — মাস্টার ডেটা
     * গ্রাহক বা পণ্য চেনে না। ⓘ মোট মান = সব চালু সারির মধ্যে সম্পূর্ণ সারির ভাগ (সারি ধরে ওজন, তালিকা ধরে গড় নয় —
     * দশটা গুদাম আর দশ হাজার গ্রাহক সমান ভারী নয়)। ⓘ কেবল নতুন ড্যাশবোর্ডে (config abos.dashboards_v2)।
     *
     * @return array{stats: list<Stat>, panels: list<Breakdown>}
     */
    private static function health(): array
    {
        if (! config('abos.dashboards_v2')) {
            return ['stats' => [], 'panels' => []];
        }

        $lists = app(\App\Core\Dashboard\DashboardRegistry::class)->health(auth()->user());

        if ($lists === []) {
            return ['stats' => [], 'panels' => []];
        }

        $active = array_sum(array_map(fn ($w) => (int) $w->parts['active'], $lists));
        $complete = array_sum(array_map(fn ($w) => (int) $w->parts['complete'], $lists));
        $score = $active === 0 ? 100 : intdiv(100 * $complete, $active);

        $stats = [new Stat(
            label: __('master_data::dashboard.quality_score'),
            value: $score.'%',
            hint: __('master_data::dashboard.quality_score_hint', ['complete' => $complete, 'active' => $active]),
            tone: $score >= 95 ? Stat::GOOD : ($score >= 80 ? Stat::WARN : Stat::BAD),
        )];

        // ⭐ আজ কত নতুন রেকর্ড খোলা হলো — সব তালিকা মিলে (মালিকের নকশা §১২, ৬ অক্টোবর ২০২৬)
        $newToday = array_sum(array_map(fn ($w) => (int) $w->parts['new_today'], $lists));
        $stats[] = new Stat(
            label: __('master_data::dashboard.new_today'),
            value: (string) $newToday,
            hint: __('master_data::dashboard.new_today_hint'),
        );

        foreach ($lists as $list) {
            $stats[] = new Stat(
                label: $list->label,
                value: $list->value,
                hint: (string) $list->hint,
                href: $list->href,
                tone: $list->tone === 'good' ? Stat::GOOD : Stat::WARN,
            );
        }

        $part = fn (string $key) => array_map(fn ($w) => ['label' => $w->label, 'value' => $w->parts[$key]], $lists);

        return ['stats' => $stats, 'panels' => [
            new Breakdown(label: __('master_data::dashboard.missing'), parts: $part('missing'),
                hint: __('master_data::dashboard.missing_hint'), chart: 'hbars'),
            new Breakdown(label: __('master_data::dashboard.same'), parts: $part('same'),
                hint: __('master_data::dashboard.same_hint')),
            new Breakdown(label: __('master_data::dashboard.inactive'), parts: $part('inactive'),
                hint: __('master_data::dashboard.inactive_hint')),
            new Breakdown(label: __('master_data::dashboard.new_month'), parts: $part('new_month'),
                hint: __('master_data::dashboard.new_month_hint'), chart: 'columns',
                range: DateRange::label(now()->startOfMonth(), now())),
        ]];
    }
}
