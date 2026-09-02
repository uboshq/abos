<?php

declare(strict_types=1);

namespace App\Core\Module;

use App\Core\Contracts\ChecksItsOwnBooks;
use App\Core\Contracts\ContributesActivity;
use App\Core\Contracts\ContributesFacts;
use App\Core\Contracts\DashboardWidgets;
use App\Core\Contracts\Importer;
use App\Core\Contracts\ProvidesDashboard;
use App\Core\Contracts\ProvidesMetrics;
use App\Core\Contracts\ProvisionsCompany;
use App\Core\Events\DomainEvent;
use Illuminate\Contracts\Auth\UserProvider;
use InvalidArgumentException;

/**
 * একটা মডিউলের module.php ফাইলটা পড়ার পর যা দাঁড়ায়।
 *
 * মডিউল কোরকে বলে দেয় সে কী দেয় (মেনু, অনুমতি, ডকুমেন্ট টাইপ, ড্রিল-ডাউনের উৎস)
 * আর কী লাগে (কোন মডিউলগুলোর উপর নির্ভরশীল)। কোর সেটা পড়ে নিজেই সব নিবন্ধন করে —
 * কোথাও হাতে মডিউলের নাম লিখতে হয় না।
 *
 * অ্যারের বদলে এই ক্লাস, কারণ ভুল বানানো module.php বুট-টাইমেই ধরা পড়া দরকার,
 * ছয় মাস পরে একটা ফাঁকা মেনু দেখে নয়।
 */
final class ModuleDefinition
{
    /** সব মডিউলে একই ছয়-ভাগ প্যাটার্ন — প্ল্যান সেকশন ১৫.২ */
    public const MENU_GROUPS = ['dashboard', 'master', 'transactions', 'approval', 'reports', 'settings'];

    /**
     * সাইডবারের দলগুলো, উপর থেকে নিচে — মালিকের দেওয়া কাঠামো, ২ সেপ্টেম্বর ২০২৬।
     *
     * ── কেন এটা `depends_on`-এর ক্রম নয় ──────────────────────────────────
     * এতদিন সাইডবারের ক্রম আসত [[ModuleRegistry::sortByDependency()]] থেকে,
     * অর্থাৎ **কে কার আগে তৈরি হতে হবে** সেই ক্রম থেকে। ওটা মেশিনের
     * প্রশ্নের উত্তর, মানুষের নয়। ফল: হিসাব আর অর্থ দুই প্রান্তে ছিটকে
     * যেত, আর ক্রয় বসত বিক্রয়ের পরে — যদিও কাজের ক্রমে ক্রয় আগে।
     *
     * নির্ভরতার ক্রমটা **রয়ে গেছে, অক্ষত** — মাইগ্রেশন আর বুট ওটাতেই চলে।
     * এটা তার পাশে বসা দ্বিতীয় একটা ক্রম, শুধু চোখের জন্য।
     *
     * ── `top` মানে শিরোনামহীন ─────────────────────────────────────────
     * প্রথম দলটার কোনো শিরোনাম নেই। ড্যাশবোর্ড আর মাস্টার ডাটা কোনো
     * ব্যবসায়িক বিভাগের অধীন নয় — ওরা সবার উপরে, নাম ছাড়া।
     *
     * ── কেন কোরে মডিউলের নাম নেই ──────────────────────────────────────
     * এখানে `['accounts', 'finance', …]` লিখলে সহজ হত, কিন্তু তাহলে
     * ১৩তম মডিউল যোগ করতে কোর ফাইল খুলতে হত — সেকশন ১৯.৭ ঠিক সেটাই
     * নিষেধ করে। প্রতিটা মডিউল নিজে বলে সে কোথায় বসবে; কোর শুধু সাজায়।
     */
    public const NAV_SECTIONS = ['top', 'finance', 'business', 'people', 'system'];

    private function __construct(
        public readonly string $code,
        /** @var array{en: string, bn: string} */
        public readonly array $name,
        public readonly string $version,
        /** @var list<string> */
        public readonly array $dependsOn,
        /** @var array<string, list<array{label: string, route: string, icon?: string, permission?: string}>> */
        public readonly array $menu,
        /**
         * সাইডবারে এই মডিউলটা কোন দলে, আর সেই দলে কত নম্বরে।
         *
         * @var array{section: string, order: int}
         */
        public readonly array $nav,
        /** @var list<string> */
        public readonly array $permissions,
        /** @var array<string, string> prefix => label */
        public readonly array $docTypes,
        /** @var array<string, class-string> source_type => model */
        public readonly array $drillSources,
        /** @var list<array{key: string, label: string, type: string, default: mixed, group?: string}> */
        public readonly array $settings,
        /**
         * রিপোর্ট সরবরাহকারী ক্লাসগুলো — প্রতিটার একটা স্থির
         * registerAll(ReportEngine) পদ্ধতি থাকতে হবে।
         *
         * @var list<class-string>
         */
        public readonly array $reports,

        /**
         * হোম পর্দার উইজেট সরবরাহকারীরা — DashboardWidgets বাস্তবায়ন করে।
         *
         * কোর কোনো মডিউলের নাম জানে না, তাই হোম পর্দার সংখ্যাগুলো
         * মডিউলের দিক থেকেই আসে (সেকশন ১৯.৭)।
         *
         * @var list<class-string>
         */
        public readonly array $widgets,

        /**
         * সংখ্যার সংজ্ঞা সরবরাহকারীরা — ProvidesMetrics বাস্তবায়ন করে।
         *
         * ── কেন উইজেট থেকে আলাদা ────────────────────────────────────
         * উইজেট একটা **পর্দার ঘর**; মেট্রিক একটা **সংজ্ঞা**। একই সংখ্যা
         * হোম পর্দায়, কাউন্টারে, আর রিপোর্টে লাগে — উইজেটের ভেতরে
         * রাখলে বাকি দুই জায়গা নিজেরা আবার গুনত, আর গতবার ঠিক তাই
         * হয়ে দুইটা আলাদা উত্তর বেরিয়েছিল।
         *
         * @var list<class-string>
         */
        public readonly array $metrics,


        /**
         * খাতা যাচাইকারীরা — ChecksItsOwnBooks বাস্তবায়ন করে।
         *
         * ── কেন মডিউল বলে ───────────────────────────────────────────
         * কোর জানে না বিলের মোট কীভাবে তৈরি হয়, বা লটের যোগফল কীসের
         * সমান হওয়ার কথা। যে নিয়মটা লিখেছে সেই-ই কেবল বলতে পারে
         * নিয়মটা ভেঙেছে কি না (সেকশন ১৯.৭)।
         *
         * @var list<class-string>
         */
        public readonly array $integrity,

        /**
         * "সদ্য কী হয়েছে" বলার সরবরাহকারীরা — ContributesActivity।
         *
         * কোর যদি নিজে `audit_trails` পড়ে সাজাত, তাকে জানতে হত কোন
         * ক্লাসের সারি কোন চাবির পেছনে — অর্থাৎ প্রতিটা মডিউলের নাম
         * (সেকশন ১৯.৭)।
         *
         * @var list<class-string>
         */
        public readonly array $activity,

        /**
         * যে কাজগুলোয় এই মডিউল অনুমোদন চাইতে পারে — কাজ => লেবেল কী।
         *
         * ── কেন মডিউল বলে, অনুমোদনের পর্দা নয় ───────────────────────
         * অনুমোদনের ছক সাজানোর পর্দায় একটা ড্রপডাউন লাগে: কোন মডিউলের
         * কোন কাজে অনুমোদন বসবে। তালিকাটা ওই পর্দায় হাতে লিখলে কোর
         * মডিউলের নাম জেনে ফেলত, আর নতুন মডিউলের কাজ ওখানে যোগ করতে
         * কোর ফাইল খুলতে হত (সেকশন ১৯.৭)।
         *
         * @var array<string, string>
         */
        public readonly array $approvals,

        /**
         * যেসব রেকর্ডে কোম্পানি নিজের ঘর যোগ করতে পারে — drill source-এর নাম।
         *
         * ── কেন মডিউল বলে, সেটিংসের পর্দা নয় ────────────────────────
         * "গ্রাহকে নিজস্ব ঘর বসানো যায়, কিন্তু খতিয়ানের সারিতে নয়" —
         * এই সিদ্ধান্তটা মডিউলের, কারণ সে-ই জানে কোন রেকর্ডটা মানুষের
         * সম্পাদনা করা মাস্টার আর কোনটা যন্ত্রের লেখা। তালিকাটা সেটিংসে
         * হাতে লিখলে কোর মডিউলের নাম চিনে ফেলত (সেকশন ১৯.৭)।
         *
         * @var list<string>
         */
        public readonly array $customFields,

        /**
         * যে মডেলগুলো অডিটে যায় না — আর কেন।
         *
         * ── কেন মডিউল নিজে বলে ──────────────────────────────────────
         * তালিকাটা আগে কোরে ছিল, আর তাতে কোর জানত মজুদ নামে একটা
         * মডিউল আছে ও তার তিনটা মডেল কী কী (§১৯.৭ ভাঙত)। সবাই কোরের
         * উপর দাঁড়ায়; কোর কারও নাম জানলে তাকে ছাড়া কোর চলে না।
         *
         * মানটা একটা বাক্য, শুধু true নয় — ছয় মাস পর কেউ যখন জিজ্ঞেস
         * করবে "এই মডেলটা অডিটে নেই কেন", উত্তরটা পাশেই থাকা দরকার।
         *
         * @var array<class-string, string>
         */
        public readonly array $auditExempt,

        /**
         * যে ঘরগুলো সবাই দেখতে পাবে না — আর কোন অনুমতির পেছনে।
         *
         * ── কেন পর্দার অনুমতি যথেষ্ট নয় ─────────────────────────────
         * রুটের অনুমতি বলে **কে পাতাটা খুলতে পারবে**। কিন্তু বিক্রির
         * সময় প্রশ্নটা অন্য: *"আমার সেলসম্যান কি ক্রয়মূল্য দেখতে
         * পাবে?"* তিনি পণ্যের পাতা দেখতে পারেন — দেখতেই হবে, নাহলে
         * বিক্রি করবেন কী করে — কিন্তু ওই একটা ঘর তাঁর নয়।
         *
         * পাতাটাই বন্ধ করে দেওয়া উত্তর নয়। ঘরটা বন্ধ করা উত্তর।
         *
         * ── কেন মডিউল নিজে বলে ──────────────────────────────────────
         * অডিটের ব্যতিক্রমের মতোই কারণে: কোর যদি জানে "মজুদ মডিউলের
         * Product-এ purchase_price আছে", তবে ওই মডিউল ছাড়া কোর চলে না
         * (§১৯.৭)।
         *
         * ── অনুমতিটা এই মডিউলেরই হতে হয় ────────────────────────────
         * অন্য মডিউলের অনুমতির নাম লিখতে দিলে একটা টাইপো **নীরবে
         * পাহারাটা তুলে দিত** — অস্তিত্বহীন অনুমতি কারও থাকে না, তাই
         * ঘরটা সবার কাছে লুকানো থাকত, আর কেউ বলত না। উল্টোটা আরও
         * খারাপ হত যদি ডিফল্ট "দেখা যাবে" হত।
         *
         * @var array<class-string, array<string, string>>
         */
        public readonly array $sensitiveFields,

        /**
         * এই মডিউল যে ঘটনাগুলো ঘোষণা করে — একটা চুক্তি, একটা তালিকা নয়।
         *
         * এখানে যা লেখা, অন্য মডিউল তার উপর নির্ভর করতে পারে। যা লেখা
         * নেই, সেটা এই মডিউলের ভেতরের ব্যাপার — কাল বদলে যেতে পারে।
         *
         * @var list<class-string<DomainEvent>>
         */
        public readonly array $events,

        /**
         * এই মডিউল কার কথা শোনে — ঘটনা => শ্রোতারা।
         *
         * ── কেন এখানে, EventServiceProvider-এ নয় ────────────────────
         * কোরে লিখলে কোর প্রতিটা মডিউলের শ্রোতার নাম জানত (§১৯.৭), আর
         * নতুন মডিউল যোগ করতে কোর ফাইল খুলতে হত — ঠিক যেটা এড়ানোর
         * জন্য পুরো মডিউল ব্যবস্থাটা।
         *
         * @var array<class-string<DomainEvent>, list<class-string>>
         */
        public readonly array $listeners,

        /**
         * অন্য মডিউলের রেকর্ড সম্পর্কে এই মডিউলের যা বলার আছে।
         *
         * "শেষ কেনা কবে" গ্রাহকের পাতায় বসে, কিন্তু কথাটা বিক্রয়ের।
         * গ্রাহকের মডেল নিজে বিক্রয় খুঁজতে গেলে customer → sales →
         * customer চক্র তৈরি হত; উল্টো দিক থেকে দিলে হয় না।
         *
         * @var list<class-string<ContributesFacts>>
         */
        public readonly array $facts,

        /**
         * নতুন কোম্পানি তৈরি হলে যে সার্ভিসগুলোকে একবার ডাকতে হয়।
         *
         * ── কেন মডিউল বলে, CompanyProvisioner নয় ────────────────────
         * কোর আগে নিজেই দুইটা সার্ভিসের নাম জানত — হিসাবের প্রমিত ছক
         * আর মাস্টার তালিকা (§১৯.৭ ভাঙত)। তার চেয়ে বড় দাম ছিল এই:
         * HR-এর প্রমিত বেতন-খাতগুলো নতুন কোম্পানিতে বসত না, কারণ
         * বসাতে হলে কোরে আরেকটা লাইন লিখতে হত আর কেউ লেখেনি।
         *
         * ক্রমটা এখানে লেখা নেই — ModuleRegistry নির্ভরতার ক্রমে ফেরত
         * দেয়, তাই accounts সবসময় master_data-র আগে চলে।
         *
         * @var list<class-string<ProvisionsCompany>>
         */
        public readonly array $provisions,
        /**
         * এই মডিউলের নিজের লগইন-প্রোভাইডার — নাম => ক্লাস।
         *
         * ── কেন কোরে নয় ─────────────────────────────────────────────
         * ডিলারের গার্ডের একটা নিজস্ব প্রোভাইডার লাগে, কারণ সেশন থেকে
         * ডিলার তোলার কোয়েরিটা কোম্পানি বসার **আগে** চলে। ওটা
         * `AppServiceProvider`-এ নিবন্ধন করতে গিয়ে §১৯.৭ ভাঙল: কোর
         * Sales মডিউলের একটা ক্লাসের নাম জেনে ফেলল, আর সীমানার
         * পরীক্ষাটা সাথে সাথে ধরল।
         *
         * এখন মডিউল নিজে বলে, কোর কেবল নামটা পড়ে নিবন্ধন করে —
         * ঘটনা, রিপোর্ট বা ইমপোর্টের মতোই।
         *
         * @var array<string, class-string<UserProvider>>
         */
        public readonly array $authProviders,

        /**
         * পুরনো খাতা থেকে কী কী আনা যায়।
         *
         * প্রতিটা মডিউল নিজে বলে দেয় সে কোন সারিগুলো নিতে পারে, তাই
         * ইমপোর্টের পর্দাটা কোনো মডিউলের নাম জানে না (সেকশন ১৯.৭)।
         *
         * @var array<string, class-string>
         */
        public readonly array $imports,

        /**
         * খতিয়ানের সারিতে যাদের নাম বসতে পারে — গ্রাহক, সরবরাহকারী।
         *
         * ── কেন drill_sources-ই যথেষ্ট নয় ──────────────────────────
         * বিল, চালান, ভাউচারও drill source, কিন্তু ওগুলো **পক্ষ** নয়।
         * "কার কাছে পাওনা" প্রশ্নের উত্তর একটা বিল হতে পারে না। তাই
         * মডিউল আলাদা করে বলে দেয় তার কোন কোন উৎস পক্ষ হিসেবে গোনা
         * হবে, আর কোর নিজে থেকে কোনো মডিউলের নাম জানে না (সেকশন ১৯.৭)।
         *
         * চাবিটা drill source-এর নাম, মানটা লেবেলের অনুবাদ-কী।
         *
         * @var array<string, string>
         */
        public readonly array $parties,
        /**
         * এই মডিউল কোন ধরনের "দেখার সীমা" দিতে পারে — ভাগ চ (RLS)।
         *
         * ── কেন কোর নিজে জানতে পারে না ──────────────────────────────
         * শাখার সীমা কোরের নিজের, কারণ শাখা কোরের ধারণা। গুদামের সীমা
         * নয়: গুদাম মজুদ মডিউলের জিনিস।
         *
         * ব্যবহারকারীর পর্দাটা SystemAdmin-এ, আর সে গুদামের নামগুলো
         * দেখাতে চায়। সরাসরি `Warehouse::class` আমদানি করলে
         * system_admin চিরকাল Inventory ছাড়া চলত না — আর
         * `BoundariesTest` সাথে সাথেই ধরেছে (§১৯.৭)।
         *
         * `depends_on`-এ লিখে দেওয়া যেত, কিন্তু সেটা মিথ্যা হত:
         * ব্যবহারকারী ব্যবস্থাপনা মজুদ ছাড়াই চলে। তাই উল্টো দিক —
         * **মজুদ নিজে বলে** সে একটা সীমা দিতে পারে, আর পর্দাটা কেবল
         * তালিকাটা পড়ে। মজুদ মডিউল না থাকলে ঘরগুলোই বসে না।
         *
         * চাবিটা `UserDataScope`-এর ধরন (`warehouse`), মানটা মডেল ও
         * লেবেলের অনুবাদ-কী।
         *
         * @var array<string, array{model: class-string, label: string}>
         */
        public readonly array $dataScopes,
        public readonly string $path,
        public readonly string $namespace,
        /**
         * এই মডিউলের ড্যাশবোর্ড কে ঘোষণা করে — [[ProvidesDashboard]]।
         *
         * ── কেন একটা, তালিকা নয় ─────────────────────────────────────
         * উইজেট আর মেট্রিক তালিকা, কারণ একটা মডিউল বহু সংখ্যা দিতে
         * পারে। কিন্তু ড্যাশবোর্ড **একটা পর্দা** — দুইটা ঘোষণা করলে
         * কোনটা খুলবে সেই প্রশ্নের কোনো ভালো উত্তর নেই, আর কোর নিজে
         * বেছে নিলে সেটা হত কোরের ব্যবসায়িক সিদ্ধান্ত।
         *
         * `null` মানে এই মডিউলের কোনো ড্যাশবোর্ড নেই, আর সেটা বৈধ:
         * কিছু মডিউল (মাস্টার ডাটা) তালিকা ছাড়া কিছুই নয়।
         *
         * @var class-string|null
         */
        public readonly ?string $dashboard = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw  module.php যা ফেরত দিয়েছে
     */
    public static function fromArray(array $raw, string $path, string $namespace): self
    {
        $code = self::requireString($raw, 'code', $path);

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
            throw new InvalidArgumentException(
                "{$path}: code must be lowercase snake_case — got '{$code}'."
            );
        }

        $name = $raw['name'] ?? null;
        if (! is_array($name) || ! isset($name['en'], $name['bn'])) {
            throw new InvalidArgumentException(
                "{$path}: name needs both 'en' and 'bn'. দ্বিভাষিক বাধ্যতামূলক — প্ল্যান সেকশন ৩ নিয়ম ৯."
            );
        }

        $menu = $raw['menu'] ?? [];
        foreach ($menu as $group => $items) {
            if (! in_array($group, self::MENU_GROUPS, true)) {
                throw new InvalidArgumentException(
                    "{$path}: unknown menu group '{$group}'. Allowed: ".implode(', ', self::MENU_GROUPS).'.'
                );
            }

            foreach ($items as $item) {
                foreach (['label', 'route', 'permission'] as $key) {
                    if (! isset($item[$key])) {
                        throw new InvalidArgumentException(
                            "{$path}: every menu item needs '{$key}'. A menu item without a permission would show "
                            .'to everyone, and one without a label would render as an empty row.'
                        );
                    }
                }

                if (! in_array($item['permission'], $raw['permissions'] ?? [], true)) {
                    throw new InvalidArgumentException(
                        "{$path}: menu item '{$item['route']}' asks for permission '{$item['permission']}', which "
                        .'this module does not declare. Nobody would ever be granted it, so the item would be '
                        .'invisible to every user including the owner.'
                    );
                }

                /*
                 * সুইচের পেছনের সারি — সুইচটা ঘোষিত কি না তা এখানেই ধরা।
                 *
                 * অঘোষিত কী দিলে SettingsService সেটা চিনত না, আর সারিটা
                 * চিরকাল অদৃশ্য থাকত — কোনো ভুলের বার্তা ছাড়াই। টাইপো
                 * সবচেয়ে সম্ভাব্য কারণ, আর টাইপোর শাস্তি একটা হারিয়ে
                 * যাওয়া মেনু সারি হওয়া উচিত নয়।
                 */
                if (isset($item['setting'])) {
                    $declared = array_column($raw['settings'] ?? [], 'key');

                    if (! in_array($item['setting'], $declared, true)) {
                        throw new InvalidArgumentException(
                            "{$path}: menu item '{$item['route']}' is gated on setting '{$item['setting']}', which "
                            .'this module does not declare. The item would never appear, and nothing would say why.'
                        );
                    }
                }
            }
        }

        foreach ($raw['permissions'] ?? [] as $permission) {
            if (! str_starts_with((string) $permission, $code.'.')) {
                throw new InvalidArgumentException(
                    "{$path}: permission '{$permission}' must be prefixed with '{$code}.' so two modules can never "
                    .'collide on the same name.'
                );
            }
        }

        return new self(
            code: $code,
            name: ['en' => (string) $name['en'], 'bn' => (string) $name['bn']],
            version: (string) ($raw['version'] ?? '1.0.0'),
            dependsOn: array_values($raw['depends_on'] ?? []),
            menu: $menu,
            nav: self::validateNav($raw['nav'] ?? null, $path),
            permissions: array_values($raw['permissions'] ?? []),
            docTypes: $raw['doc_types'] ?? [],
            drillSources: $raw['drill_sources'] ?? [],
            settings: array_values($raw['settings'] ?? []),
            reports: self::validateReports($raw['reports'] ?? [], $path),
            widgets: self::validateWidgets($raw['widgets'] ?? [], $raw['permissions'] ?? [], $path),
            metrics: self::validateMetrics($raw['metrics'] ?? [], $path),
            dashboard: self::validateDashboard($raw['dashboard'] ?? null, $path),
            integrity: self::validateIntegrity($raw['integrity'] ?? [], $path),
            activity: self::validateActivity($raw['activity'] ?? [], $path),
            approvals: self::validateApprovals($raw['approvals'] ?? [], $path),
            customFields: self::validateCustomFields(
                $raw['custom_fields'] ?? [],
                $raw['drill_sources'] ?? [],
                $path,
            ),
            auditExempt: self::validateAuditExempt($raw['audit_exempt'] ?? [], $path),
            sensitiveFields: self::validateSensitiveFields(
                $raw['sensitive_fields'] ?? [],
                $raw['permissions'] ?? [],
                $path,
            ),
            events: self::validateEvents($raw['events'] ?? [], $path),
            listeners: self::validateListeners($raw['listeners'] ?? [], $path),
            facts: self::validateFacts($raw['facts'] ?? [], $path),
            provisions: self::validateProvisions($raw['provisions'] ?? [], $path),
            authProviders: self::validateAuthProviders($raw['auth_providers'] ?? [], $path),
            dataScopes: self::validateDataScopes($raw['data_scopes'] ?? [], $path),
            imports: self::validateImports($raw['imports'] ?? [], $path),
            parties: self::validateParties(
                $raw['parties'] ?? [],
                $raw['drill_sources'] ?? [],
                $path,
            ),
            path: $path,
            namespace: $namespace,
        );
    }

    /**
     * ইমপোর্টারগুলো সত্যিই আছে কি না — বুট-টাইমে।
     *
     * ভুল নাম ধরা না পড়লে ইমপোর্টের পর্দায় একটা সারি দেখাত, আর ক্লিক
     * করলে ৫০০ পড়ত — অর্থাৎ ভুলটা ধরা পড়ত ব্যবহারকারীর হাতে, প্রথম
     * গ্রাহকের ডেটা আনার দিনে।
     *
     * @param  array<string, mixed>  $imports
     * @return array<string, class-string>
     */
    /**
     * অডিটের ব্যতিক্রম — ক্লাসটা সত্যিই আছে কি না, আর কারণ লেখা আছে কি না।
     *
     * ── কারণটা বাধ্যতামূলক কেন ──────────────────────────────────────
     * `Model::class => true` লিখতে দিলে তালিকাটা এক বছরে এমন নামের
     * সংগ্রহ হত যার একটাও কেউ ব্যাখ্যা করতে পারত না, আর তখন "এটা কি
     * ইচ্ছাকৃত, নাকি কেউ অডিট বসাতে ভুলে গেছে" প্রশ্নের উত্তর থাকত না।
     * ব্যতিক্রম একটা সিদ্ধান্ত; সিদ্ধান্তের কারণ থাকে।
     *
     * @param  array<class-string, string>  $exempt
     * @return array<class-string, string>
     */
    /**
     * সাইডবারে কোথায় বসবে — বাধ্যতামূলক, ডিফল্ট নেই।
     *
     * ── কেন ডিফল্ট রাখা হয়নি ─────────────────────────────────────────
     * `'section' => 'business', 'order' => 999` জাতীয় একটা ডিফল্ট বসানো
     * সহজ ছিল, আর সেটাই ফাঁদ: তেরোতম মডিউলটা তখন **কোনো ভুলের বার্তা
     * ছাড়াই** BUSINESS-এর সবচেয়ে নিচে গিয়ে বসত। যে লিখেছে সে জানত না,
     * যে দেখেছে সে ভাবত ওটাই ঠিক জায়গা।
     *
     * এই ক্লাসের নিজের ভূমিকাতেই লেখা আছে কেন এটা ছুঁড়ে ফেলে: *"ভুল
     * বানানো module.php বুট-টাইমেই ধরা পড়া দরকার, ছয় মাস পরে একটা ফাঁকা
     * মেনু দেখে নয়।"* একটা ভুল জায়গায় বসা মেনু ফাঁকা মেনুর চেয়ে খারাপ,
     * কারণ ফাঁকাটা অন্তত চোখে পড়ে।
     *
     * ── `order` কেন ১০, ২০, ৩০ ────────────────────────────────────────
     * মাঝখানে নতুন মডিউল ঢোকাতে গিয়ে যেন বাকি সবগুলোর সংখ্যা বদলাতে না
     * হয়। ফাঁক না রাখলে একটা মডিউল যোগ করা মানে পাঁচটা ফাইলে হাত দেওয়া,
     * আর প্রতিটা হাত একটা করে সুযোগ ভুল করার।
     *
     * @return array{section: string, order: int}
     */
    private static function validateNav(mixed $nav, string $path): array
    {
        if (! is_array($nav) || ! isset($nav['section'], $nav['order'])) {
            throw new InvalidArgumentException(
                "{$path}: every module needs 'nav' => ['section' => …, 'order' => …]. Without it the sidebar "
                .'would fall back to dependency order, which is what the modules need to boot — not what a '
                .'person reading the menu needs. Sections: '.implode(', ', self::NAV_SECTIONS).'.'
            );
        }

        if (! in_array($nav['section'], self::NAV_SECTIONS, true)) {
            throw new InvalidArgumentException(
                "{$path}: unknown nav section '{$nav['section']}'. Allowed: ".implode(', ', self::NAV_SECTIONS)
                .'. A new section is a decision about how the whole product is grouped, so it belongs here, '
                .'not in one module.'
            );
        }

        if (! is_int($nav['order'])) {
            throw new InvalidArgumentException(
                "{$path}: nav order must be an integer — got ".get_debug_type($nav['order']).'. A string sorts '
                .'as text, so "10" would come before "9".'
            );
        }

        return ['section' => (string) $nav['section'], 'order' => $nav['order']];
    }

    private static function validateAuditExempt(array $exempt, string $path): array
    {
        foreach ($exempt as $class => $reason) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: audit_exempt names '".(is_string($class) ? $class : gettype($class))."', which does not exist."
                );
            }

            if (! is_string($reason) || trim($reason) === '') {
                throw new InvalidArgumentException(
                    "{$path}: audit_exempt {$class} needs a reason — a sentence saying why it is not audited."
                );
            }
        }

        return $exempt;
    }

    /**
     * সংবেদনশীল ঘরের ঘোষণা যাচাই।
     *
     * তিনটা জিনিস দেখা হয়, আর তিনটাই বুট-টাইমে — কারণ এই ভুলগুলোর
     * একটাও চলার সময় নিজেকে দেখায় না। ভুল অনুমতির নাম মানে ঘরটা
     * **সবার কাছে লুকানো**, আর কেউ অভিযোগ করে না; ভুল ক্লাসের নাম
     * মানে পাহারাটা কোনোদিন চলেই না, আর সেটাও নীরব।
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $permissions
     * @return array<class-string, array<string, string>>
     */
    private static function validateSensitiveFields(array $fields, array $permissions, string $path): array
    {
        foreach ($fields as $class => $map) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: sensitive_fields names '".(is_string($class) ? $class : gettype($class))."', which does not exist."
                );
            }

            if (! is_array($map) || $map === []) {
                throw new InvalidArgumentException(
                    "{$path}: sensitive_fields {$class} needs a field => permission map."
                );
            }

            foreach ($map as $field => $permission) {
                if (! is_string($field) || trim($field) === '') {
                    throw new InvalidArgumentException(
                        "{$path}: sensitive_fields {$class} has a field name that is not a string."
                    );
                }

                if (! is_string($permission) || ! in_array($permission, $permissions, true)) {
                    throw new InvalidArgumentException(
                        "{$path}: sensitive_fields {$class}.{$field} is guarded by '"
                        .(is_string($permission) ? $permission : gettype($permission))
                        ."', which this module does not declare. A permission nobody holds hides the "
                        .'field from everyone, silently.'
                    );
                }
            }
        }

        return $fields;
    }

    /**
     * ঘোষিত ঘটনাগুলো — ক্লাসটা আছে, DomainEvent, আর নামটা অতীত কালে।
     *
     * ── কেন নামের কালটাও যাচাই হয় ───────────────────────────────────
     * `ConfirmInvoice` নামের একটা ইভেন্ট পড়লে মনে হয় সেটা একটা আদেশ,
     * আর শ্রোতা ভাবতে শুরু করে সে "না" বলতে পারে। ইভেন্টে "না" বলা
     * যায় না — যা ঘটে গেছে তা ঘটেই গেছে। নামটা ভুল হলে ভুল ধারণাটা
     * ছয় মাস পর কোডে বসে যায়, আর তখন সরানো যায় না।
     *
     * পরীক্ষাটা সরল: নামের শেষে `ed` বা `en` (Confirmed, Taken)। সব
     * ইংরেজি অতীত কাল এতে ধরা পড়বে না, কিন্তু ভুলগুলোর প্রায় সবই ধরা
     * পড়ে — আর যেটা পড়ে না, সেটা অন্তত ইচ্ছাকৃত।
     *
     * @param  list<mixed>  $events
     * @return list<class-string<DomainEvent>>
     */
    private static function validateEvents(array $events, string $path): array
    {
        foreach ($events as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: event '".(is_string($class) ? $class : gettype($class))."' does not exist."
                );
            }

            if (! is_subclass_of($class, DomainEvent::class)) {
                throw new InvalidArgumentException(
                    "{$path}: event {$class} must extend DomainEvent."
                );
            }

            $name = class_basename($class);

            if (! str_ends_with($name, 'ed') && ! str_ends_with($name, 'en')) {
                throw new InvalidArgumentException(
                    "{$path}: event {$name} should be named in the past tense — something that has already "
                    .'happened (InvoiceConfirmed, StockTaken). A present-tense name reads like a command, and a '
                    .'listener cannot refuse an event.'
                );
            }
        }

        return array_values($events);
    }

    /**
     * শ্রোতারা — কোন ঘটনায় কে, আর দুইটাই সত্যিই আছে কি না।
     *
     * শ্রোতার ক্লাসে `handle()` থাকতে হবে। না থাকলে ইভেন্টটা ছোড়া হত,
     * কিছুই ঘটত না, আর কোথাও কোনো ভুলের বার্তা আসত না — অনুপস্থিত
     * প্রতিক্রিয়ার অনুপস্থিতি কেউ খেয়াল করে না।
     *
     * @param  array<string, mixed>  $listeners
     * @return array<class-string<DomainEvent>, list<class-string>>
     */
    private static function validateListeners(array $listeners, string $path): array
    {
        $validated = [];

        foreach ($listeners as $event => $handlers) {
            if (! is_string($event) || ! class_exists($event) || ! is_subclass_of($event, DomainEvent::class)) {
                throw new InvalidArgumentException(
                    "{$path}: listeners are declared for '".(is_string($event) ? $event : gettype($event))
                    ."', which is not a DomainEvent."
                );
            }

            foreach ((array) $handlers as $handler) {
                if (! is_string($handler) || ! class_exists($handler)) {
                    throw new InvalidArgumentException(
                        "{$path}: listener '".(is_string($handler) ? $handler : gettype($handler))
                        ."' for {$event} does not exist."
                    );
                }

                if (! method_exists($handler, 'handle')) {
                    throw new InvalidArgumentException(
                        "{$path}: listener {$handler} needs a handle() method. Without it the event would fire, "
                        .'nothing would happen, and nothing would say why.'
                    );
                }
            }

            $validated[$event] = array_values((array) $handlers);
        }

        return $validated;
    }

    /**
     * সংখ্যার সংজ্ঞা সরবরাহকারীরা — ক্লাসটা আছে কি না, চুক্তিটা মানে কি না।
     *
     * ভুল নাম ধরা না পড়লে সংজ্ঞাটা কোথাও নিবন্ধিত হত না, আর যে পর্দা
     * ওটা চাইত সে "No metric declared" পেত — বুট-টাইমের ভুল, ব্যবহারের
     * সময় নয়।
     *
     * @param  list<mixed>  $metrics
     * @return list<class-string<ProvidesMetrics>>
     */
    /**
     * ড্যাশবোর্ডের সরবরাহকারী — ক্লাসটা আছে কি না, চুক্তিটা মানে কি না।
     *
     * ── কেন বুট-টাইমে, চলার সময় নয় ──────────────────────────────────
     * ভুল বানানো একটা ক্লাসের নাম চলার সময় ধরা পড়লে সেটা ধরা পড়ত
     * তখনই যখন কেউ পর্দাটা খুলতেন — অর্থাৎ **গ্রাহকের সামনে**। এখানে
     * ধরা পড়লে অ্যাপ ওঠেই না, আর যিনি লিখেছেন তিনিই দেখেন।
     *
     * @return class-string<ProvidesDashboard>|null
     */
    private static function validateDashboard(mixed $class, string $path): ?string
    {
        if ($class === null) {
            return null;
        }

        if (! is_string($class) || ! class_exists($class)) {
            throw new InvalidArgumentException(
                "{$path}: dashboard provider '".(is_string($class) ? $class : gettype($class))."' does not exist."
            );
        }

        if (! is_subclass_of($class, ProvidesDashboard::class)) {
            throw new InvalidArgumentException(
                "{$path}: dashboard provider {$class} must implement the ProvidesDashboard contract."
            );
        }

        return $class;
    }

    private static function validateMetrics(array $metrics, string $path): array
    {
        foreach ($metrics as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: metric provider '".(is_string($class) ? $class : gettype($class))."' does not exist."
                );
            }

            if (! is_subclass_of($class, ProvidesMetrics::class)) {
                throw new InvalidArgumentException(
                    "{$path}: metric provider {$class} must implement the ProvidesMetrics contract."
                );
            }
        }

        return array_values($metrics);
    }

    /**
     * ঘটনা-সরবরাহকারীরা — ক্লাসটা আছে কি না, চুক্তিটা মানে কি না।
     *
     * ভুল নাম ধরা না পড়লে ওই মডিউলের ঘটনাগুলো কোনোদিন তালিকায় আসত
     * না, আর অনুপস্থিত একটা সারির অনুপস্থিতি কেউ খেয়াল করে না।
     *
     * @param  list<mixed>  $providers
     * @return list<class-string<ContributesActivity>>
     */
    private static function validateActivity(array $providers, string $path): array
    {
        foreach ($providers as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: activity provider '".(is_string($class) ? $class : gettype($class))."' does not exist."
                );
            }

            if (! is_subclass_of($class, ContributesActivity::class)) {
                throw new InvalidArgumentException(
                    "{$path}: activity provider {$class} must implement the ContributesActivity contract."
                );
            }
        }

        return array_values($providers);
    }

    /**
     * খাতা যাচাইকারীরা — ক্লাসটা আছে কি না, চুক্তিটা মানে কি না।
     *
     * ভুল নাম ধরা না পড়লে যাচাইটা কোনোদিন চলত না, আর পর্দাটা "সব
     * সবুজ" দেখাত — আর একটা যাচাই না-চলা আর তার পাশ করা পর্দায়
     * দেখতে হুবহু এক।
     *
     * @param  list<mixed>  $checks
     * @return list<class-string<ChecksItsOwnBooks>>
     */
    private static function validateIntegrity(array $checks, string $path): array
    {
        foreach ($checks as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: integrity provider '".(is_string($class) ? $class : gettype($class))."' does not exist."
                );
            }

            if (! is_subclass_of($class, ChecksItsOwnBooks::class)) {
                throw new InvalidArgumentException(
                    "{$path}: integrity provider {$class} must implement the ChecksItsOwnBooks contract."
                );
            }
        }

        return array_values($checks);
    }

    /**
     * তথ্য-সরবরাহকারীরা — ক্লাসটা আছে কি না, চুক্তিটা মানে কি না।
     *
     * ভুল নাম ধরা না পড়লে সারিটা কোনোদিন বসত না, আর অনুপস্থিত একটা
     * সারির অনুপস্থিতি কেউ খেয়াল করে না।
     *
     * @param  list<mixed>  $facts
     * @return list<class-string<ContributesFacts>>
     */
    private static function validateFacts(array $facts, string $path): array
    {
        foreach ($facts as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: fact provider '".(is_string($class) ? $class : gettype($class))."' does not exist."
                );
            }

            if (! is_subclass_of($class, ContributesFacts::class)) {
                throw new InvalidArgumentException(
                    "{$path}: fact provider {$class} must implement the ContributesFacts contract."
                );
            }
        }

        return array_values($facts);
    }

    /**
     * কোম্পানি চালুর সার্ভিসগুলো — ক্লাসটা আছে কি না, চুক্তিটা মানে কি না।
     *
     * ভুল নাম ধরা না পড়লে কোম্পানিটা তৈরি হত ঠিকই, শুধু ওই মডিউলের
     * ভিত্তি সারিগুলো ছাড়া — আর সেটা টের পাওয়া যেত অনেক পরে, প্রথম
     * লেনদেন লিখতে গিয়ে।
     *
     * @param  list<mixed>  $provisions
     * @return list<class-string<ProvisionsCompany>>
     */
    /**
     * লগইন-প্রোভাইডারগুলো সত্যিই আছে কি না — বুট-টাইমে।
     *
     * এখানে না ধরলে ভুলটা ধরা পড়ত কেবল যখন কেউ লগইন করার চেষ্টা
     * করতেন — অর্থাৎ ঠিক সেই মুহূর্তে যখন তিনি কিছুই করতে পারছেন না।
     *
     * @param  array<mixed, mixed>  $providers
     * @return array<string, class-string<UserProvider>>
     */
    /**
     * দেখার সীমার ঘোষণাগুলো — বুট-টাইমে যাচাই।
     *
     * @param  array<mixed, mixed>  $scopes
     * @return array<string, array{model: class-string, label: string}>
     */
    private static function validateDataScopes(array $scopes, string $path): array
    {
        foreach ($scopes as $type => $spec) {
            if (! is_string($type) || $type === '') {
                throw new InvalidArgumentException("{$path}: a data scope needs a type.");
            }

            if (! is_array($spec) || ! isset($spec['model'], $spec['label'])) {
                throw new InvalidArgumentException(
                    "{$path}: data scope '{$type}' needs a model and a label."
                );
            }

            if (! is_string($spec['model']) || ! class_exists($spec['model'])) {
                throw new InvalidArgumentException(
                    "{$path}: data scope '{$type}' names a model that does not exist."
                );
            }
        }

        return $scopes;
    }

    private static function validateAuthProviders(array $providers, string $path): array
    {
        foreach ($providers as $name => $class) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException(
                    "{$path}: an auth provider needs a name."
                );
            }

            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: auth provider '".(is_string($class) ? $class : gettype($class))."' does not exist."
                );
            }

            if (! is_subclass_of($class, UserProvider::class)) {
                throw new InvalidArgumentException(
                    "{$path}: auth provider {$class} must implement the UserProvider contract."
                );
            }
        }

        return $providers;
    }

    private static function validateProvisions(array $provisions, string $path): array
    {
        foreach ($provisions as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: company provisioner '".(is_string($class) ? $class : gettype($class))."' does not exist."
                );
            }

            if (! is_subclass_of($class, ProvisionsCompany::class)) {
                throw new InvalidArgumentException(
                    "{$path}: company provisioner {$class} must implement the ProvisionsCompany contract."
                );
            }
        }

        return array_values($provisions);
    }

    /**
     * পক্ষের ঘোষণা — বুট-টাইমেই যাচাই।
     *
     * ── কেন drill_sources-এর সাথে মেলানো হয় ─────────────────────────
     * পক্ষের নাম আর ছবি দুইটাই আসে drill source থেকে। নামটা ভুল লিখলে
     * (customers বনাম customer) ভাউচারের ফর্মে সারিটা দেখা যেত, বাছাও
     * যেত — কিন্তু সেভ করার সময় খতিয়ানে এমন একটা `party_type` বসত
     * যেটা কোনো রিপোর্ট চেনে না। **বকেয়াটা তখন কোথাও দেখা যেত না**,
     * অথচ ভাউচারটা দেখতে ঠিকই থাকত।
     *
     * @param  array<string, mixed>  $parties
     * @param  array<string, mixed>  $drillSources
     * @return array<string, string>
     */
    private static function validateParties(array $parties, array $drillSources, string $path): array
    {
        foreach ($parties as $key => $label) {
            if (! is_string($key) || ! array_key_exists($key, $drillSources)) {
                throw new InvalidArgumentException(
                    "{$path}: party '".(is_string($key) ? $key : gettype($key))
                    ."' is not one of this module's drill_sources, so the ledger could never name it."
                );
            }

            if (! is_string($label) || trim($label) === '') {
                throw new InvalidArgumentException(
                    "{$path}: party '{$key}' needs a label translation key — the voucher form has to call it something."
                );
            }
        }

        return $parties;
    }

    private static function validateImports(array $imports, string $path): array
    {
        foreach ($imports as $key => $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: importer '{$key}' points at '".(is_string($class) ? $class : gettype($class))."', which does not exist."
                );
            }

            if (! is_subclass_of($class, Importer::class)) {
                throw new InvalidArgumentException(
                    "{$path}: importer {$class} must implement the Importer contract."
                );
            }
        }

        return $imports;
    }

    /**
     * নিজস্ব ঘর যেসব রেকর্ডে বসে — বুট-টাইমেই যাচাই।
     *
     * ── কেন drill_sources-এর সাথে মেলানো হয় ─────────────────────────
     * নামটা ভুল লিখলে (customers বনাম customer) সেটিংসে সারিটা দেখা
     * যেত, ঘর বানানোও যেত — শুধু কোনো ফর্মে কোনোদিন দেখা যেত না।
     * আর ব্যবহারকারী ভাবতেন ব্যবস্থাটাই কাজ করে না।
     *
     * @param  list<mixed>  $entities
     * @param  array<string, mixed>  $drillSources
     * @return list<string>
     */
    private static function validateCustomFields(array $entities, array $drillSources, string $path): array
    {
        foreach ($entities as $entity) {
            if (! is_string($entity) || ! array_key_exists($entity, $drillSources)) {
                throw new InvalidArgumentException(
                    "{$path}: custom fields are declared for '"
                    .(is_string($entity) ? $entity : gettype($entity))
                    ."', which this module does not register as a drill source. Without that the form would "
                    .'never find the record, and the fields would be invisible everywhere.'
                );
            }
        }

        return array_values($entities);
    }

    /**
     * অনুমোদনযোগ্য কাজগুলো — নাম ও লেবেল, দুইটাই লেখা।
     *
     * লেবেল ছাড়া ছকের পর্দায় কাঁচা চাবি দেখা যেত ("discount"), আর
     * ব্যবহারকারী বুঝতেন না কোনটা বাছছেন।
     *
     * @param  array<string, mixed>  $approvals
     * @return array<string, string>
     */
    private static function validateApprovals(array $approvals, string $path): array
    {
        foreach ($approvals as $action => $label) {
            if (! is_string($action) || ! preg_match('/^[a-z][a-z0-9_]*$/', $action)) {
                throw new InvalidArgumentException(
                    "{$path}: approval action '".(is_string($action) ? $action : gettype($action))
                    ."' must be lowercase snake_case — it is stored in a column and matched exactly."
                );
            }

            if (! is_string($label) || ! str_contains($label, '::')) {
                throw new InvalidArgumentException(
                    "{$path}: approval action '{$action}' needs a translation key as its label, so the flow screen "
                    .'can name it in both languages.'
                );
            }
        }

        return $approvals;
    }

    /**
     * উইজেট সরবরাহকারীরা — বুট-টাইমেই যাচাই।
     *
     * ── কেন অনুমতিগুলোও এখানে মিলিয়ে দেখা হয় না ─────────────────────
     * দেখা হয়, আর কারণটা মেনুর মতোই: উইজেট এমন কোনো অনুমতি চাইলে যা
     * মডিউল ঘোষণাই করেনি, সেটা কাউকে কখনো দেওয়া হত না — ফলে সংখ্যাটা
     * চিরকাল অদৃশ্য থাকত, কোনো ভুলের বার্তা ছাড়াই। কিন্তু উইজেটের
     * অনুমতি জানা যায় কেবল widgets() ডাকলে, আর সেটা বুট-টাইমে ডাকা
     * চলে না (কোয়েরি চালাবে, তখনো ডাটাবেজ প্রসঙ্গ নেই)। তাই এখানে
     * কেবল ক্লাস ও চুক্তি, আর অনুমতির মিলটা DashboardTest ধরে।
     *
     * @param  list<mixed>  $widgets
     * @param  list<mixed>  $permissions
     * @return list<class-string>
     */
    private static function validateWidgets(array $widgets, array $permissions, string $path): array
    {
        foreach ($widgets as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: dashboard widget provider '".(is_string($class) ? $class : gettype($class))."' does not exist."
                );
            }

            if (! is_subclass_of($class, DashboardWidgets::class)) {
                throw new InvalidArgumentException(
                    "{$path}: widget provider {$class} must implement the DashboardWidgets contract."
                );
            }
        }

        if ($widgets !== [] && $permissions === []) {
            throw new InvalidArgumentException(
                "{$path}: a module offering dashboard widgets must declare permissions — every widget names one, "
                .'and a permission nobody can be granted makes the figure invisible to everyone.'
            );
        }

        return array_values($widgets);
    }

    /**
     * রিপোর্ট সরবরাহকারীরা সত্যিই নিবন্ধন করতে পারে কি না — বুট-টাইমে।
     *
     * ভুল ক্লাসের নাম বা পদ্ধতি না থাকা ধরা না পড়লে রিপোর্টগুলো নীরবে
     * অনুপস্থিত থাকত, আর কেউ খুঁজতে গিয়ে বুঝত মেনুতে আছে কিন্তু খোলে না।
     *
     * @param  list<mixed>  $reports
     * @return list<class-string>
     */
    private static function validateReports(array $reports, string $path): array
    {
        foreach ($reports as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: report provider '".(is_string($class) ? $class : gettype($class))."' does not exist."
                );
            }

            if (! method_exists($class, 'registerAll')) {
                throw new InvalidArgumentException(
                    "{$path}: report provider {$class} needs a static registerAll(ReportEngine) method."
                );
            }
        }

        return array_values($reports);
    }

    /** ব্যবহারকারীর ভাষায় মডিউলের নাম। */
    public function label(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return $this->name[$locale] ?? $this->name['en'];
    }

    public function dir(string ...$segments): string
    {
        return dirname($this->path).($segments ? DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments) : '');
    }

    private static function requireString(array $raw, string $key, string $path): string
    {
        if (! isset($raw[$key]) || ! is_string($raw[$key]) || $raw[$key] === '') {
            throw new InvalidArgumentException("{$path}: '{$key}' is required.");
        }

        return $raw[$key];
    }
}
