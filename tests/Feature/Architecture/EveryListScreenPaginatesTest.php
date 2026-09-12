<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Tests\TestCase;

/**
 * প্রতিটা তালিকার পর্দায় পাতা ভাগ — নিরীক্ষা ১২ সেপ্টেম্বর ২০২৬।
 *
 * ── কেন এই পাহারাটা লাগল ─────────────────────────────────────────────
 * সাতাত্তরটা `index()`-এর চৌত্রিশটায় `->get()` লেখা ছিল — পুরো টেবিল
 * একবারে। আজ ডেটা কম বলে কিছুই দেখা যায় না; ছয় মাস পরে পাতা খুলতে
 * সময় লাগে, আর মেমরি শেষ হলে ৫০০ আসে।
 *
 * ⚠️ কিন্তু সবচেয়ে খারাপ দিকটা ধীরগতি নয়। পর্দাটা **সম্পূর্ণ দেখায়**
 * অথচ চুপচাপ ডেটা লুকিয়ে ফেলতে পারে — আর তখন কেউ একটা তালিকা দেখে
 * সংখ্যা বলেন, ছাপান, বা সিদ্ধান্ত নেন, না জেনে যে নিচে আরও ছিল।
 *
 * ── ⛔ এই পরীক্ষাটা "সবখানে paginate বসাও" বলে না ─────────────────────
 * কাজটা করতে গিয়ে সবচেয়ে বড় শিক্ষাটা এটাই: **যেখানে লাগে না সেখানে
 * পাতা ভাগ বসানো নিজেই একটা বাগ**, আর কয়েকটা ক্ষেত্রে ঠিক সেই নীরব
 * ক্ষতিটাই ঘটাত যেটা ঠেকাতে কাজটা শুরু হয়েছিল।
 *
 * তিনটা উদাহরণ, তিনটাই এই রিপোতে সত্যি:
 *
 *   হাজিরা    পর্দাটা একটাই POST — প্রতি কর্মীর একটা সারি। পাতা ভাগ
 *             করলে কেউ প্রথম পাতার বিশটা ঘর ভরে "পরের পাতা" চাপলে
 *             ভরা ঘরগুলো কোনো চিহ্ন ছাড়াই হারাত।
 *   লেবেল     প্রতি সারিতে একটা টিক-ঘর, নিচে একটাই "ছাপুন"। একই ভুল,
 *             একই নীরবতা।
 *   হাতে-ধার  উপরের তিনটা "মোট" আসে ঠিক নিচের তালিকা থেকেই। পাতা ভাগ
 *             করলে সংখ্যাগুলো এই পাতার হয়ে যেত, অথচ লেবেলে "মোট"।
 *
 * তাই এখানে **দুইটা তালিকা**, আর দুইটাই সমান জরুরি: কোথায় পাতা ভাগ
 * থাকতে হবে, আর কোথায় ইচ্ছাকৃতভাবে নেই — কারণসহ।
 *
 * ── পাতা ভাগ বসানোর দ্বিতীয় দাম, যেটা প্রথমে চোখে পড়ে না ────────────
 * `->get()` একটা Collection দেয়, `->paginate()` একটা paginator। দেখতে
 * এক, কিন্তু **`count()`, `isEmpty()`, `sum()`, `reduce()`, `first()`
 * সবগুলোর অর্থ বদলে যায়** — ওরা তখন কেবল এই পাতার কথা বলে।
 *
 * এই কাজেই তিনটা ধরা পড়েছে, আর তিনটাই সত্যিকারের বাগ হয়ে যেত:
 *
 *   বেতনের খাত    `$heads->isEmpty()` দিয়ে "প্রমিত খাত বসান" বোতামটা
 *                 দেখানো হত — `?page=9`-এ ভরা তালিকাতেও বোতামটা উঠত
 *                 ([[Tests\Feature\Modules\Hr\ThePageWasEmptySoItOfferedToFillTheListTest]])
 *   খোলা মজুদ     মোট মূল্যটা `$entered->reduce()` — আর ওই সংখ্যাটা
 *                 শুরুর দিনের অবশিষ্ট মুনাফায় বসে। পাতার যোগফল হলে
 *                 সেটা পর্দার ভুল নয়, **খাতার ভুল** হত।
 *   ছাপার সারি    `$jobs->isEmpty()` দেখে "সব বেরিয়ে গেছে" লেখা হত।
 *
 * এই পরীক্ষাটা ওগুলো ধরতে পারে না — ওগুলো ভিউয়ের ভেতরের কথা। কিন্তু
 * পরের জন যেন খোঁজে, সেজন্য কথাটা এখানেই লেখা থাকল।
 */
class EveryListScreenPaginatesTest extends TestCase
{
    /**
     * যে `index()`-গুলোয় ইচ্ছাকৃতভাবে পাতা ভাগ নেই — আর কেন।
     *
     * ⚠️ **প্রতিটা সারি একটা সিদ্ধান্ত, আর কারণটা পাশেই লেখা।** একটা
     * নাম নিঃশব্দে এখানে যোগ করা মানে পাহারাটা ওই পর্দার জন্য তুলে
     * নেওয়া, তাই কারণ ছাড়া কিছু এখানে বসে না।
     *
     * কারণগুলো চার ধরনের, আর ধরনটা কারণের শুরুতেই লেখা:
     *
     *   ফর্ম     সারিগুলো একটাই জমা দেওয়ার অংশ — পাতা বদলালে ভরা ঘর হারায়
     *   গাছ/বোর্ড কাটটা পড়ত কাঠামোর মাঝখানে, আর ভাঙা কাঠামো ভুল তথ্য দেয়
     *   মোট      উপরের সংখ্যাগুলো ঠিক এই তালিকা থেকেই গোনা
     *   বাঁধা    সারির সংখ্যা কোডে বা দিনে বাঁধা — ডেটা বাড়লে বাড়ে না
     */
    private const NOT_REALLY_A_LIST = [
        /* ── ফর্ম: পাতা বদলালে ভরা ঘর নীরবে হারায় ───────────────────── */
        'hr.attendance.index' => 'ফর্ম — একটাই POST, প্রতি কর্মীর একটা সারি; পাতা বদলালে ভরা ঘরগুলো নীরবে হারাত। সারি বাড়ে কর্মীসংখ্যায়, দিনে নয়',
        'inventory.label.index' => 'ফর্ম — প্রতি সারিতে টিক-ঘর, নিচে একটাই "ছাপুন"; পাতা বদলালে টিকগুলো হারাত। বড় ক্যাটালগের উত্তর খোঁজা, পাতা ভাগ নয়',
        'sales.pos.index' => 'ফর্ম — কাউন্টারের কার্ট একটাই; পণ্য ও গ্রাহক দুইটাই বাছার ঘর, দেখার তালিকা নয়। ক্যাটালগের সীমা আগে থেকেই আছে (INLINE_CATALOGUE_LIMIT)',

        /* ── গাছ ও বোর্ড: কাট পড়ত কাঠামোর মাঝখানে ──────────────────── */
        'inventory.warehouse.place.index' => 'গাছ — সন্তান বাবার নিচে বসে; কাটলে তাক দেখা যেত অথচ তার র‍্যাক আগের পাতায়। ভাঙা গাছ পুরো গাছের চেয়ে খারাপ, কারণ ভুল কাঠামো দেখায়',
        'master_data.location.index' => 'গাছ — আর বড় গাছের সীমা আগে থেকেই আছে: TREE_LIMIT ছাড়ালে গাছ আঁকাই হয় না, বদলে খোঁজা (ফল ১০০-তে বাঁধা)',
        'accounts.coa.index' => 'গাছ — হিসাবের ছক, আর master_data.location-এর হুবহু একই সমাধান: TREE_LIMIT ছাড়ালে গাছ আঁকা হয় না, বদলে খোঁজা (ফল ১০০-তে বাঁধা)',
        'inventory.stock.placement' => 'বোর্ড — সারি কাগজ ধরে দলে বাঁধা; কাট পড়ত একটা চালানের মাঝখানে আর ফর্মটা অর্ধেক কাগজ জমা দিত। বসানো শেষ হলে সারি নিজেই চলে যায়',
        'restaurant.kitchen.index' => 'বোর্ড — দেয়ালে ঝোলে, যিনি রাঁধছেন তিনি "পরের পাতা" চাপবেন না; দ্বিতীয় পাতার অর্ডার মানে সেটা কেউ রাঁধবে না',

        /* ── মোট: উপরের সংখ্যাগুলো ঠিক এই তালিকা থেকেই গোনা ────────── */
        'finance.hand_loan.index' => 'মোট — উপরের তিনটা সংখ্যা (মোট প্রাপ্য, মোট দেয়, কতজন) এই তালিকা থেকেই; পাতা ভাগ করলে "মোট" হত এই পাতার। open() চুকে যাওয়া সারি নিজেই বাদ দেয়',
        'finance.income.index' => 'মোট — "বিক্রয় কত, বিক্রয় ছাড়া কত" গোনা হয় ঠিক এই সারিগুলোর উপর, আর ওটাই পর্দার আসল প্রশ্ন। সারি ছকের খাত ধরে, ব্যবসার আকারে নয়',
        'accounts.till.index' => 'মোট — সব টিলের মোট ব্যালেন্স গোনা হয় ঠিক এই সারিগুলোর উপর (balancesFor একবারেই আনে); পাতা ভাগ করলে "মোট" লেবেলের নিচে পাতার যোগফল বসত। আর সারি বাড়ে কাউন্টারের সংখ্যায়, লেনদেনে নয়: টিল বসানো হয়, জমে না',

        /*
         * ⚠️ এটা সীমাহীন ছিল, আর ১২ সেপ্টেম্বর ২০২৬-এ সারানো হয়েছে —
         * কিন্তু পাতা ভাগ দিয়ে নয়, তাই সারিটা এখানে।
         *
         * উপরের মডিউল-চিপগুলো **পুরো তালিকার** সংখ্যা দেখায় (কোডে
         * কারণটা লেখা: চিপ দেখেই মানুষ বোঝেন কোথায় জট)। পাতা ভাগ
         * বসালে "পাতা ২-এ যান" আর "ক্রয় ১৩৭" — দুইটা আলাদা গল্প
         * একসাথে বলতে হত।
         *
         * বদলে ExpenseController::waiting()-এর ছাঁচ: INBOX_LIMIT + মোট
         * আলাদা group-by কোয়েরিতে + পর্দায় স্পষ্ট লেখা কতটা দেখা যাচ্ছে।
         */
        'approval.inbox.index' => 'সীমা — পাতা ভাগ নয়, INBOX_LIMIT; চিপের সংখ্যা পুরো তালিকার হতেই হয় বলে পাতা ভাগ চলত না। কাটা পড়লে পর্দায় "৫০টি দেখানো হচ্ছে, মোট ১৩৭টি" লেখা থাকে',

        /* ── বাঁধা: সারির সংখ্যা কোডে বা দিনে বাঁধা ──────────────────── */
        'finance.expense.index' => 'বাঁধা — heads ছকের 5200-এর নিচের খাত, recent আগে থেকেই limit(20), waiting-এ WAITING_LIMIT আর কাটা পড়লে পর্দায় লেখা থাকে',
        'finance.plan' => 'বাঁধা — FinancePlan-এর স্থির লেখা, ডাটাবেজই ছোঁয় না',
        'accounts.period.index' => 'বাঁধা — একটা অর্থবছরের বারোটা মাস, আর পুরো বছর পাশাপাশি দেখাই পর্দার কাজ',
        'accounts.year_end.index' => 'বাঁধা — বছরে একটা সারি; পঞ্চাশে পৌঁছাতে পঞ্চাশ বছর লাগবে',
        'approval.flow.index' => 'বাঁধা — একটা সারি মানে এক মডিউলের এক কাজের নিয়ম, আর কাজের তালিকা সোর্স কোডে',
        'master_data.series.index' => 'বাঁধা — একটা সারি মানে এক মডিউলের এক ডকুমেন্টের ধরন; তালিকাটা ডাটাবেজ নয়, সোর্স কোড ঠিক করে',
        'backup.index' => 'বাঁধা — files ডিস্ক থেকে আর keep_days দিন পরে নিজেই মুছে যায়, runs আগে থেকেই limit(20)। "শেষ ব্যাকআপ কবে" উত্তরটা উপরের সারি, তাই পাতা ওল্টানো উল্টো ক্ষতি',
        'backup.destination.index' => 'বাঁধা — গন্তব্য বসানো হয়, জমে না; একটা প্রতিষ্ঠান দুই-তিনটা বসিয়ে বছরের পর বছর ছোঁয় না',
        'governance.session.index' => 'বাঁধা — কেবল নিজের সেশন, আর মেয়াদ শেষে ফ্রেমওয়ার্কের ঝাড়ু মুছে দেয়। সবগুলো একসাথে দেখাই পর্দার কাজ',
        'sales.shift.index' => 'বাঁধা — tills কাউন্টারের সংখ্যায়, closed কেবল আজকের (whereDate); কাল আবার শূন্য থেকে',
        'sales.target.index' => 'বাঁধা — একটা সারি মানে একজন বিক্রয়কর্মী; আর স্কোরবোর্ডের কাজই তুলনা, অর্ধেক দল পরের পাতায় গেলে র‍্যাঙ্কিং অর্থ হারায়',
        'inventory.stock.overview' => 'বাঁধা — ড্যাশবোর্ড; সবই সংখ্যা, নয়তো আগে থেকেই ৮-এ বাঁধা (lowStock, recentMovements)',
    ];

    /**
     * ⏳ এখনো ভাঙা — আর এই তালিকাটা **খালি হওয়ার কথা**।
     *
     * ⚠️ উপরেরটা থেকে এটা সম্পূর্ণ আলাদা জিনিস, আর দুইটা মেশানো চলবে না।
     * উপরের প্রতিটা সারি একটা **সিদ্ধান্ত** — ওখানে নাম থাকা মানে পর্দাটা
     * ঠিক আছে। এখানে নাম থাকা মানে পর্দাটা **এখনো ভাঙা**, আর সারিটা
     * কেবল একটা রসিদ: জানা আছে, ভোলা হয়নি।
     *
     * ⛔ **নিয়ম: এখানে কোনো নতুন নাম যোগ হবে না।** নতুন পর্দা লিখে এখানে
     * নাম বসানো মানে পাহারাটাকে ফাঁকি দেওয়া — তালিকাটা কেবল ছোট হয়,
     * কখনো বড় নয়। নিচের `test_nothing_is_still_waiting_to_be_done`
     * সেটাই দেখে, আর তালিকা খালি হলে ওই পরীক্ষা মনে করিয়ে দেবে ধ্রুবকটা
     * মুছে ফেলতে।
     */
    private const STILL_BEING_DONE = [
        /* SystemAdmin-এর পর্দাগুলো abos-5b-র ভাগে — কাজ চলছে। */
        'system_admin.company.index' => 'abos-5b-র ভাগে (১২ সেপ্টেম্বর ২০২৬)',
        'system_admin.custom_field.index' => 'abos-5b-র ভাগে (১২ সেপ্টেম্বর ২০২৬)',
        'system_admin.import.index' => 'abos-5b-র ভাগে (১২ সেপ্টেম্বর ২০২৬)',
        'system_admin.look.index' => 'abos-5b-র ভাগে (১২ সেপ্টেম্বর ২০২৬)',
        'system_admin.reports.schedule.index' => 'abos-5b-র ভাগে (১২ সেপ্টেম্বর ২০২৬)',
        'system_admin.role.index' => 'abos-5b-র ভাগে (১২ সেপ্টেম্বর ২০২৬)',
    ];

    /**
     * প্রতিটা তালিকার পর্দা হয় পাতা ভাগ করে, নয় কারণসহ ছাড় পেয়েছে।
     */
    public function test_every_list_screen_paginates(): void
    {
        $unbounded = [];

        foreach ($this->indexScreens() as $name => $route) {
            if (array_key_exists($name, self::NOT_REALLY_A_LIST)
                || array_key_exists($name, self::STILL_BEING_DONE)) {
                continue;
            }

            if ($this->paginates($route)) {
                continue;
            }

            $unbounded[] = $name.'  ['.$this->actionOf($route).']';
        }

        sort($unbounded);

        $this->assertSame([], $unbounded, implode("\n", [
            'এই পর্দাগুলো পুরো টেবিল একবারে আনে — ছয় মাস পরে পাতা খুলতে সময়',
            'লাগবে, আর মেমরি শেষ হলে ৫০০ আসবে।',
            '',
            'হয় ->get() কে ->paginate(50)->withQueryString() করুন আর ভিউতে',
            '<x-ui.pager :rows="$…" /> যোগ করুন, নয় NOT_REALLY_A_LIST-এ কারণসহ লিখুন।',
            '',
            '⚠️ paginate বসানোর পর ভিউটা আবার পড়ুন: count(), isEmpty(), sum(),',
            'reduce(), first() — paginator-এ ওগুলো কেবল এই পাতার কথা বলে।',
            '',
            ...$unbounded,
        ]));
    }

    /**
     * ছাড়ের তালিকায় মৃত নাম জমে থাকে না।
     *
     * ── কেন এটা আলাদা করে দেখা দরকার ────────────────────────────────
     * একটা পর্দা মুছে গেলে বা তার রুটের নাম বদলালে ছাড়ের সারিটা পড়ে
     * থাকত, আর পরের জন সেটা পড়ে ভাবতেন সিদ্ধান্তটা এখনো কারো কাজে
     * লাগছে। বাসি ছাড় কেবল আবর্জনা নয় — ওটা **ভুল ইতিহাস**।
     */
    public function test_the_exemption_list_names_only_screens_that_exist(): void
    {
        $known = array_keys($this->indexScreens());

        $stale = array_values(array_diff(
            [...array_keys(self::NOT_REALLY_A_LIST), ...array_keys(self::STILL_BEING_DONE)],
            $known,
        ));

        sort($stale);

        $this->assertSame([], $stale, implode("\n", [
            'ছাড়ের তালিকায় এমন পর্দার নাম আছে যা আর নেই — মুছে দিন:',
            ...$stale,
        ]));
    }

    /**
     * ছাড় পাওয়া পর্দা সত্যিই পাতা ভাগ করে না।
     *
     * ── কেন এই উল্টো দিকটাও দেখা হয় ─────────────────────────────────
     * কেউ একটা ছাড় পাওয়া পর্দায় পরে `paginate()` বসালে সারিটা এখানে
     * পড়ে থাকত, আর তাতে **কোনো পরীক্ষা ভাঙত না** — ছাড়ের তালিকা তো
     * কেবল বাদ দেয়। ফল: ফাইলটা বলত "এখানে ইচ্ছাকৃতভাবে পাতা ভাগ নেই",
     * অথচ কোডে আছে।
     *
     * একটা ছাড়ের তালিকা যদি নিজের দাবিটা যাচাই না করে, তবে সেটা
     * ডকুমেন্টেশন — আর ডকুমেন্টেশন পুরনো হয়, নিঃশব্দে।
     */
    public function test_no_exempt_screen_quietly_started_paginating(): void
    {
        $screens = $this->indexScreens();
        $contradicting = [];

        foreach (array_keys(self::NOT_REALLY_A_LIST) as $name) {
            if (isset($screens[$name]) && $this->paginates($screens[$name])) {
                $contradicting[] = $name;
            }
        }

        sort($contradicting);

        $this->assertSame([], $contradicting, implode("\n", [
            'এই পর্দাগুলো NOT_REALLY_A_LIST-এ আছে অথচ সত্যিই পাতা ভাগ করে।',
            'ছাড়টা আর সত্যি নয় — সারিটা মুছে দিন (আর কারণটা কোডের মন্তব্য',
            'থেকেও সরান, নাহলে মন্তব্যটা মিথ্যা বলবে):',
            ...$contradicting,
        ]));
    }

    /**
     * ⏳ তালিকাটা কেবল ছোট হয় — আর একদিন খালি।
     */
    public function test_nothing_is_still_waiting_to_be_done(): void
    {
        $screens = $this->indexScreens();
        $done = [];

        foreach (array_keys(self::STILL_BEING_DONE) as $name) {
            if (isset($screens[$name]) && $this->paginates($screens[$name])) {
                $done[] = $name;
            }
        }

        sort($done);

        $this->assertSame([], $done, implode("\n", [
            'এই পর্দাগুলোয় এখন পাতা ভাগ আছে — STILL_BEING_DONE থেকে নামগুলো',
            'মুছে দিন। তালিকাটা খালি হলে ধ্রুবকটাও মুছে ফেলুন, নাহলে পরের জন',
            'ভাববেন এখনো কাজ বাকি:',
            ...$done,
        ]));
    }

    /**
     * তালিকার পর্দাগুলো — রুট ধরে, ফাইল ধরে নয়।
     *
     * ── কেন রুট ────────────────────────────────────────────────────
     * যে `index()` কোনো রুটে বাঁধা নেই সেটা পর্দাই নয় — কেউ ওটা খুলতে
     * পারেন না। ফাইল ঘেঁটে ক্লাস খুঁজলে মৃত কোডও এই পাহারার আওতায় চলে
     * আসত, আর তখন ছাড়ের তালিকায় এমন নাম বসত যা কোনো ব্যবহারকারী
     * কোনোদিন দেখবেন না।
     *
     * @return array<string, Route>
     */
    private function indexScreens(): array
    {
        $screens = [];

        foreach (Router::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_ends_with($action, '@index')) {
                continue;
            }

            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            $screens[$name] = $route;
        }

        /*
         * ⚠️ এই দাবিটা বাকি সব দাবির শর্ত।
         *
         * উপরের পরীক্ষাগুলো "খারাপ কিছু পাওয়া গেল না" দেখে পাশ করে।
         * রুট কোনো কারণে লোড না হলে তালিকাটা খালি আসত, আর **সবগুলোই
         * পাশ করত — অথচ একটা পর্দাও দেখা হয়নি**। যে পরীক্ষা জিনিসটা
         * অনুপস্থিত থাকলেও সবুজ, সেটা পরীক্ষা নয়।
         *
         * সংখ্যাটা আলগা (৫০), কারণ প্রশ্নটা "কয়টা পর্দা আছে" নয়,
         * "পর্দাগুলো আদৌ লোড হয়েছে কি না" — শক্ত সংখ্যা ধরলে প্রতিটা
         * নতুন পর্দায় এই টেস্ট ভাঙত, আর অগ্রগতিতে ভাঙা পাহারা কেউ রাখে না।
         */
        $this->assertGreaterThan(50, count($screens),
            'তালিকার পর্দাই খুঁজে পাওয়া যায়নি — এই ফাইলের পাহারাগুলো তখন কিছুই দেখছে না।');

        return $screens;
    }

    /**
     * এই পর্দাটা পাতা ভাগ করে কি না।
     *
     * ── তিন ধাপ, আর তিনটাই দরকার হয়েছে ─────────────────────────────
     * ১। `index()`-এর শরীরেই `paginate(` — সাধারণ ক্ষেত্র।
     * ২। একই ক্লাসের সহায়ক পদ্ধতিতে — যেমন খোলা মজুদের `entered()`,
     *    যেখানে কোয়েরিটা আলাদা করা হয়েছে যাতে যোগফলও একই ভিত্তি পায়।
     * ৩। ইনজেক্ট করা সেবার পদ্ধতিতে — যেমন ছাপার সারির
     *    `$this->queue->pending()`, যেখানে কোয়েরিটা সেবার নিজের।
     *
     * ⛔ আর এখানেই থামা, ইচ্ছাকৃতভাবে। চতুর্থ ধাপে গেলে টেস্টটা এমন
     * কিছু "প্রমাণ" করতে শুরু করত যা সে আসলে বোঝে না — একটা `paginate`
     * খুঁজে পাওয়া মানে **এই পর্দার তালিকাটা** পাতা ভাগ করছে, এমন নিশ্চয়তা
     * যত গভীরে যাওয়া যায় তত কমে।
     */
    private function paginates(Route $route): bool
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return false;
        }

        [$class, $method] = explode('@', $action, 2);

        $body = $this->bodyOf($class, $method);

        if ($body === null) {
            return false;
        }

        if ($this->mentionsPaginate($body)) {
            return true;
        }

        // ২। একই ক্লাসের সহায়ক — `$this->entered()`
        preg_match_all('/\$this->(\w+)\s*\(/', $body, $ownCalls);

        foreach ($ownCalls[1] as $helper) {
            $inner = $this->bodyOf($class, $helper);

            if ($inner !== null && $this->mentionsPaginate($inner)) {
                return true;
            }
        }

        // ৩। ইনজেক্ট করা সেবা — `$this->queue->pending()`
        preg_match_all('/\$this->(\w+)->(\w+)\s*\(/', $body, $serviceCalls, PREG_SET_ORDER);

        foreach ($serviceCalls as [, $property, $called]) {
            $service = $this->typeOfProperty($class, $property);

            if ($service === null) {
                continue;
            }

            $inner = $this->bodyOf($service, $called);

            if ($inner !== null && $this->mentionsPaginate($inner)) {
                return true;
            }
        }

        return false;
    }

    /**
     * লেখাটায় সত্যিকারের `paginate(` আছে কি না — মন্তব্যের ভেতরেরটা নয়।
     *
     * ⚠️ এটা ছাড়া এই ফাইলের কাজটাই উল্টে যেত: ছাড় পাওয়া পর্দাগুলোর
     * মন্তব্যে **কেন পাতা ভাগ নেই** তা লেখা আছে, আর সেই ব্যাখ্যায়
     * `paginate` শব্দটা থাকে। তখন প্রতিটা ছাড় "পাতা ভাগ করে" বলে ধরা
     * পড়ত — অর্থাৎ ব্যাখ্যা লেখাটাই পাহারাটাকে অন্ধ করে দিত।
     */
    private function mentionsPaginate(string $body): bool
    {
        $code = preg_replace(
            ['#/\*.*?\*/#s', '#//[^\n]*#'],
            '',
            $body,
        );

        return str_contains((string) $code, 'paginate(');
    }

    /**
     * ইনজেক্ট করা একটা ঘরের ধরন — না জানা গেলে null।
     */
    private function typeOfProperty(string $class, string $property): ?string
    {
        if (! class_exists($class) || ! property_exists($class, $property)) {
            return null;
        }

        $type = (new ReflectionProperty($class, $property))->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        return $type->getName();
    }

    /**
     * একটা পদ্ধতির শরীর, সোর্স ফাইল থেকে — না পেলে null।
     */
    private function bodyOf(string $class, string $method): ?string
    {
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();

        if ($file === false || ! is_readable($file)) {
            return null;
        }

        $lines = file($file);

        if ($lines === false) {
            return null;
        }

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }

    /** ভুলের বার্তায় পর্দাটা চেনানোর জন্য — `Controller@index`। */
    private function actionOf(Route $route): string
    {
        $action = $route->getActionName();
        $slash = strrpos($action, '\\');

        return $slash === false ? $action : substr($action, $slash + 1);
    }
}
