<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Module\ModuleRegistry;

/**
 * কী কী ধরনের খবর পাঠানো হয় — সেটিংসের পর্দা এই তালিকাটাই দেখায়।
 *
 * ── কেন একটা লেখা তালিকা, ডাটাবেজ থেকে গোনা নয় ──────────────────────
 * পাঠানো খবরগুলো থেকে ধরন গোনা যেত (`select distinct type`)। ⛔ কিন্তু
 * তাতে **যে খবর এখনো কেউ পাননি সেটা সেটিংসে থাকত না** — অর্থাৎ যে খবরটা
 * আপনি আগেভাগে বন্ধ করতে চান, ঠিক সেটাই প্রথমবার এসে পড়ত।
 *
 * ⓘ তালিকায় না থাকা ধরন বন্ধ করা যায় না, কিন্তু পাঠানো আটকায়ও না —
 * নতুন কোনো খবর যোগ করে এখানে সারি লিখতে ভুলে গেলে সেটা সবাই পাবেন,
 * আর সেটাই নিরাপদ দিক ([[NotificationChoice]])।
 */
final class NotificationKinds
{
    /**
     * ধরন → ভাষার চাবি, পর্দায় যে ক্রমে দেখানো হয়।
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'approval.approved' => 'core.notify.kind.approval_approved',
            'approval.rejected' => 'core.notify.kind.approval_rejected',
            // ⓘ সময় ফুরিয়ে আসা আর ওপরে যাওয়া — ভাষার সারি আগে থেকেই ছিল, তালিকায় ছিল না, তাই বন্ধ করা যেত না (ধাপ ১)
            'approval.reminder' => 'core.notify.kind.approval_reminder',
            'approval.escalated' => 'core.notify.kind.approval_escalated',
            'report_ready' => 'core.notify.kind.report_ready',

            /*
             * ⭐ তারিখের আগাম খবর — ২১ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ নাম দুইটা চিহ্নমাত্র — কোর কোনো মডিউলের ক্লাস চেনে না,
             * শুধু পাঠানো খবরের ধরনটা জানে — অনুমোদনের দুইটা সারিও
             * ঠিক তাই। ⚠️ তালিকায় না বসালে খবরটা যেত, কিন্তু কেউ সেটা
             * বন্ধ করতে পারতেন না — আর যে খবর বন্ধ করা যায় না, সেটা
             * একদিন সবাই না-দেখা শিখে যান।
             */
            'finance.deposit_maturing' => 'core.notify.kind.deposit_maturing',
            'finance.hand_loan_due' => 'core.notify.kind.hand_loan_due',

            /*
             * ⛔ ব্যাকআপ ব্যর্থ হলে — ২২ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ এতদিন ব্যর্থতাটা কেবল `backup_runs`-এ একটা লাল সারি
             * ছিল। ⓘ রাত দুইটায় ব্যর্থ হলে জানার একমাত্র উপায় ছিল
             * কারো নিজে থেকে পর্দাটা খুলে দেখা — আর ব্যাকআপের পর্দা
             * মানুষ খোলে **ঠিক ঐ দিনটায়**, যেদিন ওটা লাগে।
             */
            'backup.failed' => 'core.notify.kind.backup_failed',

            /*
             * ⛔ সই হলো, অথচ চালান পাকা হলো না (বাকির দেয়াল, মজুদ…) — ২৯ সেপ্টেম্বর ২০২৬।
             * ⓘ পায় কেবল চালান যিনি বানিয়েছিলেন ([[SignedChallanConfirmer]])।
             */
            'sales.signed_challan_stuck' => 'core.notify.kind.signed_challan_stuck',
            // ⭐ সই হলো, অথচ কাউন্টারের বিক্রি শেষ হলো না — পান বানানেওয়ালা আর সইকারী (লাইভ DRF-0008; [[HeldCounterSaleFinisher]])
            'sales.signed_sale_stuck' => 'core.notify.kind.signed_sale_stuck',

            /*
             * ⭐ ডেলিভারির ধাপ বদলাল — ২ অক্টোবর ২০২৬ ([[TrackingNotices]])।
             * ⓘ পান দোকানের এলাকার SR/ASM/DSM/RSM আর মালিক; কেউ চাইলে বন্ধ রাখতে পারেন।
             */
            'sales.delivery_stage' => 'core.notify.kind.delivery_stage',

            /*
             * ⭐ নতুন ধারার বিক্রয় আদেশ — DO+SO মেশানো, ধাপ ১১ (৫ অক্টোবর ২০২৬; [[TrackingNotices]])।
             * ⓘ সীমায় আটকে: লেখক আর মালিক; সইয়ের অপেক্ষা: এখনকার স্তরের অনুমোদনকারীরা।
             */
            'sales.order_credit_held' => 'core.notify.kind.order_credit_held',
            'sales.order_awaits_you' => 'core.notify.kind.order_awaits_you',

            // ⭐ বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩ — সূচিমতো খবর আর টেমপ্লেটের পরীক্ষা ([[ScheduleRunner]], টেমপ্লেট স্টুডিও)
            'notification.scheduled' => 'core.notify.kind.notification_scheduled',
            'notification.template_test' => 'core.notify.kind.notification_template_test',

            // ⛔ সমন্বয় জাবেদা নিজের তারিখে উল্টাতে পারল না (প্রায়ই মাস বন্ধ) — একবারই ([[AdjustingReversals]], ৯ অক্টোবর ২০২৬)
            'accounts.adjusting_reversal_stuck' => 'core.notify.kind.adjusting_reversal_stuck',
        ];
    }

    public static function knows(string $type): bool
    {
        return array_key_exists($type, self::all());
    }

    /*
     * ── ⭐ গুরুত্ব আর শ্রেণি — বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১ (মালিকের স্পেক §৫, §৯ক, ১০ অক্টোবর ২০২৬) ──────────────
     *
     * গুরুত্ব (Priority): critical — নিরাপত্তা, গুরুতর সিস্টেম সমস্যা · high — সইয়ের অপেক্ষা, বাকির সীমা পেরোনো, আটকে
     * থাকা কাজ, বকেয়া ভাড়া · normal — সাধারণ অনুমোদন, তারিখের আগাম খবর · low — অবস্থা বদল, রিপোর্ট তৈরি।
     * ⓘ গুরুত্ব আর মাধ্যম আলাদা জিনিস (§৫): critical মানেই SMS নয় — কোন মাধ্যমে যাবে সেটা নিয়ম ঠিক করে।
     *
     * শ্রেণি (Category) — ঘণ্টার ছাঁকনি: approval (অনুমোদন) · task (কাজ) · system (ব্যবস্থা) · update (অবস্থা বদল)।
     */

    /** @var list<string> সবচেয়ে জরুরি আগে — সাজানোয় এই ক্রম */
    public const PRIORITIES = ['critical', 'high', 'normal', 'low'];

    /** @var list<string> */
    public const CATEGORIES = ['approval', 'task', 'system', 'update'];

    /**
     * ধরন থেকে মডিউল, শ্রেণি আর গুরুত্ব — ডাকা জায়গা গুরুত্ব নিজে দিলে সেটাই খাটে ([[NotificationService::send()]])।
     *
     * ⓘ অচেনা ধরন → মডিউল = বিন্দুর আগের অংশ, শ্রেণি "কাজ", গুরুত্ব "সাধারণ" — নতুন খবর হারায় না, বাড়তি চেঁচায়ও না।
     * ⚠️ পুরনো সারিগুলো একই নিয়মে একবার বসানো হয়েছিল (মাইগ্রেশন `the_bell_kept_no_record_of_what_it_said`)।
     *
     * @return array{module: string, category: string, priority: string}
     */
    public static function classify(string $type): array
    {
        $module = str_contains($type, '.') ? (string) strstr($type, '.', true) : 'system';

        [$category, $priority] = match (true) {
            $type === 'approval.approved' => ['approval', 'low'],
            $type === 'approval.rejected' => ['approval', 'normal'],
            str_starts_with($type, 'approval.') => ['approval', 'high'],
            $type === 'sales.order_awaits_you' => ['approval', 'high'],
            in_array($type, ['sales.order_credit_held', 'sales.signed_challan_stuck', 'sales.signed_sale_stuck', 'finance.rent_overdue'], true) => ['task', 'high'],
            $type === 'sales.delivery_stage', $type === 'report_ready' => ['update', 'low'],
            $type === 'notification.scheduled' => ['update', 'normal'],
            $type === 'notification.template_test' => ['system', 'low'],
            $type === 'backup.failed' => ['system', 'critical'],
            default => ['task', 'normal'],
        };

        return ['module' => $module, 'category' => $category, 'priority' => $priority];
    }

    /**
     * খবরটা কোথা থেকে — মডিউলের নিজের নাম, পর্দার ভাষায় (ঘণ্টার "Source", স্পেক §৯ক)। ⓘ অচেনা বা মডিউলহীন → "ব্যবস্থা"।
     */
    public static function sourceLabel(?string $module): string
    {
        $definition = $module === null ? null : app(ModuleRegistry::class)->get($module);

        if ($definition === null) {
            return (string) __('core.notify.source_system');
        }

        return (string) ($definition->name[app()->getLocale()] ?? $definition->name['en'] ?? $module);
    }

    public static function isPriority(?string $priority): bool
    {
        return in_array($priority, self::PRIORITIES, true);
    }

    /**
     * ⭐ কোন খবরগুলো ইনবক্সেও যায়, যদি মানুষটা অন্য কিছু না বলে থাকেন।
     *
     * ── ⓘ একটা খবর চিঠির যোগ্য হয় একটাই শর্তে ───────────────────────
     * **জিনিসটা তাঁর উপর অপেক্ষা করছে, আর অন্য কিছু তাঁকে সেটা বলবে না।**
     *
     * ⚠️ সব খবর চিঠিতে পাঠালে মানুষ এক সপ্তাহে ABOS-এর ঠিকানা স্প্যামে
     * ফেলেন — তারপর যেটা সত্যিই জরুরি সেটাও স্প্যামে পড়ে। ⛔ তখন
     * ব্যবস্থাটা কেবল অকেজো নয়, **মিথ্যা আশ্বাস** দেয়।
     *
     * ── ⭐ কেন `approval.approved` এই তালিকায় নেই ───────────────────
     * অনুমোদিত হওয়া মানে কাগজটা **এগিয়ে গেছে** — কারো জন্য থেমে নেই।
     * ⓘ যিনি পাঠিয়েছিলেন তিনি পরের ধাপেই দেখতে পান (চালান কাটা যায়,
     * বিল ছাড়া যায়)। ⚠️ উল্টোদিকে `approval.rejected` মানে কাগজটা
     * **তাঁর টেবিলেই ফিরে এসেছে**, আর তিনি না ধরা পর্যন্ত কিছুই এগোবে
     * না — তাই ওটা চিঠি পায়।
     *
     * ⓘ এটা ছাঁচে ঢালা নয়: যে কেউ নিজের সেটিংসে উল্টে দিতে পারেন,
     * দুই দিকেই ([[NotificationChoice]]-র `by_email`)।
     *
     * @var list<string>
     */
    private const MAIL_BY_DEFAULT = [
        /* ⓘ টেমপ্লেট লেখক নিজের কাছে পরীক্ষা পাঠান — চিঠিটা কেমন দেখায় সেটাই তো দেখতে চান (ধাপ ৩) */
        'notification.template_test',

        /* কাগজটা তাঁর টেবিলে ফেরত — তিনি না ধরলে কিছুই এগোয় না */
        'approval.rejected',

        /* ⓘ একই কারণ: সই হয়ে গেছে, তবু চালান তাঁর টেবিলে আটকে — তিনি না ধরলে মাল নড়ে না */
        'sales.signed_challan_stuck',
        /* ⓘ একই কারণ: সই হয়ে গেছে, তবু বিক্রি থেমে — কেউ না ধরলে বিল কোথাও দেখা যায় না */
        'sales.signed_sale_stuck',

        /*
         * ⭐ সূচির গোটা মানেই "না চাইতেই এসে পৌঁছাবে"।
         *
         * ⛔ চিঠি না গেলে সূচিমতো রিপোর্ট আর হাতে চালানো রিপোর্টের
         * মধ্যে কোনো পার্থক্যই থাকে না — দুইটাতেই কাউকে খুঁজতে আসতে হয়।
         */
        'report_ready',

        /* ⚠️ তারিখ পেরিয়ে গেলে ব্যাংক নিজে থেকেই নতুন মেয়াদে বসিয়ে দেয় */
        'finance.deposit_maturing',

        /* টাকাটা ফেরত চাইতে হবে, আর তারিখটা একবার গেলে আর ফেরে না */
        'finance.hand_loan_due',

        /*
         * ⛔ এটাই তালিকার সবচেয়ে পরিষ্কার সারি।
         *
         * ⓘ ব্যাকআপ ব্যর্থ হয় রাত দুইটায়, আর কেউ তাকিয়ে থাকে না।
         * ⚠️ পরদিন সকালে কিছুই আলাদা দেখায় না — অ্যাপ স্বাভাবিক চলে,
         * কাজ হয়, আর ঐ রাতের কপিটা নেই। ⛔ জানা যায় কেবল সেদিন, যেদিন
         * ব্যাকআপটা ফেরানোর দরকার পড়ে, আর তখন **অনেক দেরি হয়ে গেছে**।
         */
        'backup.failed',

        /* ⓘ উল্টো আটকে — মাস না খোলা পর্যন্ত বকেয়াটা পরের মাসে দুইবার গোনা থাকে, আর খবরটা একবারই যায় */
        'accounts.adjusting_reversal_stuck',
    ];

    /**
     * এই ধরনের খবর কেউ কিছু না বললে ইনবক্সেও যাবে কি না।
     *
     * ⓘ অচেনা ধরন → না। ⚠️ উল্টোটা করলে কোথাও একটা নতুন `send()` লিখে
     * তালিকায় বসাতে ভুলে গেলে সেটা **চুপচাপ সবাইকে চিঠি পাঠাতে** শুরু
     * করত, আর কেউ বন্ধও করতে পারতেন না (`all()`-এ নেই মানে সেটিংসের
     * পর্দায়ও নেই)। ⭐ ঘণ্টার বেলায় নিরাপদ দিক ছিল "পাঠাও", চিঠির
     * বেলায় নিরাপদ দিক "পাঠিও না" — কারণ চিঠি ফেরানো যায় না।
     */
    public static function mailedByDefault(string $type): bool
    {
        return in_array($type, self::MAIL_BY_DEFAULT, true);
    }
}
