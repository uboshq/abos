<?php

declare(strict_types=1);

use App\Core\Engines\Print\PaperSize;
use App\Modules\Purchase\Dashboard\PurchaseDashboard;
use App\Modules\Purchase\Dashboard\PurchaseWidgets;
use App\Modules\Purchase\Integrity\PurchaseChecks;
use App\Modules\Purchase\Events\GoodsReceived;
use App\Modules\Purchase\Listeners\OpenInspectionsForGoodsThatNeedThem;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Reports\PurchaseReports;
use App\Modules\Purchase\Reports\ReturnOnCapitalReport;
use App\Modules\Purchase\Reports\SettlementReport;
use App\Modules\Purchase\Services\TaggableBillsOnTheVoucherForm;

/**
 * Purchase — প্ল্যান Phase 7।
 *
 * তিনটা ডকুমেন্ট, আর তিনটা আলাদা প্রশ্নের উত্তর:
 *
 *     ক্রয় আদেশ (PO)  — কী আনতে বলেছি          → খাতা নড়ে না, স্টকও নড়ে না
 *     মাল বুঝে নেওয়া (GRN) — কী সত্যিই এসেছে   → স্টক বাড়ে, দায় জন্মায়
 *     ক্রয় বিল          — কী দিতে হবে           → দায় সরবরাহকারীর নামে বসে
 *
 * ── কেন তিনটা, দুইটা নয় ────────────────────────────────────────────────
 * মাল আসা আর বিল আসা একই দিনে হয় না। ট্রাক আসে সোমবার, বিল আসে বৃহস্পতিবার।
 * ওই তিন দিন মালটা গুদামে আছে — বেচা যাচ্ছে, তার দাম আছে — অথচ কাগজে কোনো
 * দায় নেই। শুধু বিলের সময় হিসাব বসালে ওই তিন দিন ব্যালেন্স শিট মিথ্যা বলত:
 * সম্পদ বেড়েছে, দায় বাড়েনি, আর পার্থক্যটা নীরবে মুনাফা হয়ে দেখাত।
 *
 * তাই মাল বুঝে নেওয়ার দিনই হিসাব বসে, একটা অপেক্ষমাণ দায়ে:
 *
 *     মাল বুঝে নেওয়া:  Dr মজুদ পণ্য (1120)      Cr প্রাপ্ত মাল, বিল আসেনি (2160)
 *     বিল এল:          Dr প্রাপ্ত মাল, বিল আসেনি  Cr প্রদেয় হিসাব (2110, সরবরাহকারী)
 *
 * ২১৬০ খাতটা তাই একটা প্রশ্নের উত্তর দেয় যেটা আর কোথাও পাওয়া যায় না:
 * "কত টাকার মাল ঢুকেছে যার বিল এখনো আসেনি"। খাতটা শূন্য না হলে হয় বিল
 * বাকি, নয় কেউ বিল ছাড়াই মাল নামিয়েছে — দুটোই জানা দরকার।
 *
 * ── স্টক ও হিসাব একই ট্রানজেকশনে ───────────────────────────────────────
 * ইভেন্টে নয় (প্ল্যান WP-0.3)। ইভেন্টে করলে একটা বসে অন্যটা ব্যর্থ হতে
 * পারত, আর তখন গুদামে মাল থাকত কিন্তু খাতায় থাকত না — কোনো ভুল বার্তা
 * ছাড়াই।
 */
return [
    'code' => 'purchase',

    'name' => [
        'en' => 'Purchase',
        'bn' => 'ক্রয়',
    ],

    'version' => '1.0.0',

    /*
     * সাইডবারে কোথায় — নির্ভরতার ক্রম নয়, মানুষের ক্রম।
     *
     * কাজের ক্রমে ক্রয় আগে — মাল আসে, তবেই মজুদ হয়, তবেই বিক্রি।
     *
     * দলগুলোর তালিকা আর কেন এটা `depends_on`-এর থেকে আলাদা:
     * [[ModuleDefinition::NAV_SECTIONS]].
     */
    'nav' => ['section' => 'business', 'order' => 30],

    // মাল স্টকে বসে, দায় খাতায় বসে, আর সরবরাহকারী ছাড়া ক্রয় হয় না
    /*
     * `sales`-টা যোগ হয়েছে নিষ্পত্তি ও পুঁজির রিপোর্টের জন্য।
     *
     * ── কেন রিপোর্ট দুইটা এখানে, Supplier-এ নয় ──────────────────────
     * দুইটাই ক্রয়ের নথি (`pur_receipts`, `pur_bills`), ব্যয়-স্তর আর
     * বিক্রয়-চালান একসাথে জোড় লাগায় — "এই মিলের কত টাকার মাল এল, তার
     * কতটা বিক্রি হলো, মার্জিন কত"।
     *
     * প্রথমে ওগুলো Supplier-এ লেখা হয়েছিল, আর `BoundariesTest` ধরল:
     * supplier → purchase একটা **চক্র**, কারণ প্রতিটা ক্রয়ই সরবরাহকারীর
     * নাম ধরে — purchase → supplier সরানোর কোনো উপায় নেই। চক্র হলে
     * `ModuleRegistry` বুট-টাইমেই থেমে যায়, তাই ঘোষণা করাও যেত না।
     *
     * উল্টো দিকটায় কোনো চক্র নেই: sales ক্রয়ের নাম জানে না। তাই
     * চার মডিউলের নামই যে জানতে পারে, রিপোর্ট দুইটা তারই।
     */
    'depends_on' => ['master_data', 'accounts', 'inventory', 'supplier', 'sales'],

    'menu' => [
        'dashboard' => [
            ['label' => 'purchase::dashboard.title', 'icon' => 'dashboard', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'purchase'], 'permission' => 'purchase.bill.view'],
        ],

        'transactions' => [
            /*
             * ⭐ চাহিদা — ডিফল্টে **চালু**, ২৫ সেপ্টেম্বর ২০২৬।
             *
             * ── ⓘ সিদ্ধান্তটা বদলেছে, আর কার সিদ্ধান্তে ─────────────
             * ২৪ সেপ্টেম্বর এটা বন্ধ রাখা হয়েছিল এই যুক্তিতে যে ছোট
             * দোকান চাহিদাপত্র লেখে না। ⭐ মালিক পরদিন সরাসরি বলেছেন:
             * *"সব চালু করো, পরে আমি বন্ধ করব"*।
             *
             * ⚠️ যুক্তিটা ভুল ছিল না, কিন্তু সিদ্ধান্তটা আমাদের নয় —
             * ⛔ বন্ধ রাখলে পর্দাটা থাকত অথচ কেউ জানত না ওটা আছে, আর
             * "নেই" আর "লুকানো" ব্যবহারকারীর কাছে একই জিনিস।
             *
             * ⓘ বন্ধ করা এক ক্লিকের কাজ (কন্ট্রোল প্যানেল), আর সেই
             * সারিটা এই ঘোষিত ডিফল্টকে হারায়।
             *
             * ⓘ সারিটা সবার আগে, কারণ কাগজের গল্পে এটাই প্রথম:
             * চাওয়া → আদেশ → গ্রহণ → বিল।
             */
            ['label' => 'purchase::menu.requisitions', 'icon' => 'list', 'route' => 'purchase.requisition.index',
                'permission' => 'purchase.requisition.view', 'setting' => 'purchase.screen_requisitions'],

            /*
             * ⭐ দরপত্র — চাহিদার সাথেই ডিফল্টে চালু, ২৫ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ চাহিদার সাথে একই সুইচে, কারণ দুইটা একই কাজের দুই
             * ধাপ: যে প্রতিষ্ঠান চাহিদাপত্র লেখে না, সে দরপত্রও
             * ডাকে না। ⚠️ আলাদা সুইচ দিলে কেউ একটা চালু আর
             * অন্যটা বন্ধ রেখে অর্ধেক পথ পেত।
             */
            ['label' => 'purchase::menu.rfqs', 'icon' => 'megaphone', 'route' => 'purchase.rfq.index',
                'permission' => 'purchase.rfq.view', 'setting' => 'purchase.screen_requisitions'],

            /*
             * ⭐ চুক্তি — ২৪ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ সুইচ ছাড়া, ইচ্ছাকৃতভাবে: ⚠️ চুক্তি দরপত্রের
             * অংশ নয়। ⛔ যে ছোট দোকান দরপত্র ডাকে না, সে-ও
             * বছরের শুরুতে সরবরাহকারীর সাথে দর ঠিক করে — আর
             * ওটা লিখে রাখার জায়গা তারও লাগে।
             */
            ['label' => 'purchase::menu.contracts', 'icon' => 'handover', 'route' => 'purchase.contract.index',
                'permission' => 'purchase.contract.view'],

            ['label' => 'purchase::menu.direct', 'icon' => 'purchase', 'route' => 'purchase.direct.create', 'permission' => 'purchase.bill.create',
                'setting' => 'purchase.screen_direct'],
            ['label' => 'purchase::menu.orders', 'icon' => 'book', 'route' => 'purchase.order.index', 'permission' => 'purchase.order.view',
                'setting' => 'purchase.screen_orders'],
            /*
             * ⛔ "মাল গ্রহণ" এখান থেকে তোলা হলো — মালিকের নির্দেশ,
             * ২৫ সেপ্টেম্বর ২০২৬: *"মাল বুঝে নেওয়া purches theke tule
             * dite hobe"*।
             *
             * ── ⓘ কেন, তাঁর নিজের ভাষায় ─────────────────────────────
             * *"eta inventory menu, ekhane keno? ক্রয় চালান toiri hole
             * inventory ta buje nibe tar por ক্রয় বিল toiri hobe auto"* —
             * অর্থাৎ ক্রেতার দিন শেষ হয় ক্রয় আদেশে; মাল নামানো, গোনা,
             * বসানো — সবই গুদামের কাজ।
             *
             * ── ⚠️ পর্দাটা হারায়নি, দরজাটা সরেছে ───────────────────
             * সারিটা ২৪ সেপ্টেম্বর থেকেই মজুদ ▸ লেনদেন-এ আছে
             * ([[Inventory/module.php]]-র অতিথি সারি, `'from' => 'purchase'`)।
             * ⛔ এতদিন **দুইটা দরজা, আর দুইটা আলাদা নাম** ছিল — ক্রয়ে
             * "মাল বুঝে নেওয়া", মজুদে "মাল গ্রহণ" — তাই দেখতে দুইটা
             * আলাদা কাজ মনে হত।
             *
             * ⓘ রুট, চাবি (`purchase.receipt.*`) ও সুইচ
             * (`purchase.screen_receipts`) কিছুই নড়েনি — কেবল মেনুর
             * সারিটা এক জায়গায় হলো। ⚠️ তাই কারো অ্যাকসেস কমেনি বাড়েনি।
             *
             * ⓘ `purchase::menu.receipts` চাবিটা থাকল — পর্দার শিরোনাম
             * ও ক্রয় আদেশের পাতা এখনো ওটাই পড়ে।
             */
            ['label' => 'purchase::menu.bills', 'icon' => 'receipt', 'route' => 'purchase.bill.index', 'permission' => 'purchase.bill.view'],
            /*
             * ⭐ "পরিশোধ" বোতাম নেই — মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬:
             * *"পরিশোধ বোতাম দরকার নেই, যেহেতু accounts-এর কাজ।"* ⓘ সরবরাহকারীকে
             * টাকা দেওয়া এখন হিসাবের পরিশোধ ভাউচার, আর বাইরের সবার খাতা
             * ব্যাংকের মতো Dr/Cr। ⓘ পুরনো পরিশোধের পাতা ও রুট থাকল — ইতিহাস
             * আর বিলের পাতার লিংক ওখানেই খোলে।
             */
            ['label' => 'purchase::menu.returns', 'icon' => 'refresh', 'route' => 'purchase.return.index', 'permission' => 'purchase.return.view'],
        ],
        'reports' => [
            ['label' => 'purchase::menu.pending_orders', 'icon' => 'clock', 'route' => 'purchase.report.show',
                'route_params' => ['slug' => 'pending-orders'], 'permission' => 'purchase.report'],
            /*
             * ⭐ মিলকরণের ব্যতিক্রম — ২৪ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ *"কোন বিলগুলো মেলেনি"* — মালিকের স্পেকের ৩-মুখী
             * মিলকরণের ফল। ⚠️ সারিটা বিনা-বিলের ঠিক পাশে, কারণ
             * দুইটাই একই প্রশ্নের দুই দিক: কাগজ তিনটা মিলছে কি না।
             */
            ['label' => 'purchase::menu.match_exceptions', 'icon' => 'alert-triangle',
                'route' => 'purchase.report.show',
                'route_params' => ['slug' => 'match-exceptions'], 'permission' => 'purchase.report'],

            ['label' => 'purchase::menu.uninvoiced', 'icon' => 'alert-triangle', 'route' => 'purchase.report.show',
                'route_params' => ['slug' => 'uninvoiced'], 'permission' => 'purchase.report'],
            /*
             * ⭐ দরের ইতিহাস — ২৪ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ সরবরাহকারীর পাশে, কারণ প্রশ্নটা একই সারির: *"এঁর কাছ
             * থেকে কত দরে কিনছি, আর দরটা কোনদিকে যাচ্ছে"*।
             */
            /*
             * ⭐ সরবরাহকারীর কার্যক্ষমতা — ২৪ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ দরের ইতিহাসের পাশে: দাম আর সময়, দুইটাই এক সরবরাহকারীর
             * সম্পর্কে দুইটা আলাদা প্রশ্ন, আর দুইটাই দরাদরির কাজে লাগে।
             */
            ['label' => 'purchase::menu.supplier_performance', 'icon' => 'clock',
                'route' => 'purchase.report.show',
                'route_params' => ['slug' => 'supplier-performance'], 'permission' => 'purchase.report'],

            ['label' => 'purchase::menu.price_history', 'icon' => 'scale',
                'route' => 'purchase.report.show',
                'route_params' => ['slug' => 'price-history'], 'permission' => 'purchase.report'],

            ['label' => 'purchase::menu.by_supplier', 'icon' => 'supplier', 'route' => 'purchase.report.show',
                'route_params' => ['slug' => 'by-supplier'], 'permission' => 'purchase.report'],

            /*
             * মাসের নিষ্পত্তি — পরিবেশক ডিপোর সবচেয়ে দরকারি কাগজ।
             *
             * ── কেন নিজের চাবি, `purchase.report` নয় ────────────────
             * এই রিপোর্টের **প্রতিটা কলামই ক্রয়মূল্য বহন করে** — কত
             * টাকার মাল এল, তার খরচ কত ছিল, মার্জিন কত। বকেয়ার তালিকা
             * দেখতে পারা আর নিজের মার্জিন দেখতে পারা এক জিনিস নয়, তাই
             * চাবিটাও আলাদা।
             */
            ['label' => 'supplier::menu.settlement', 'icon' => 'check-circle', 'route' => 'purchase.report.show',
                'route_params' => ['slug' => 'settlement'], 'permission' => 'purchase.settlement.view'],

            /*
             * পুঁজির উপর ফেরত — নিষ্পত্তির ঠিক পাশে, আর একই চাবিতে।
             *
             * দুইটাই একই প্রশ্নের দুই অর্ধেক: নিষ্পত্তি বলে "এই মাসে কত
             * এল", আর এটা বলে "ওই টাকা খেটে বছরে কত আনছে"।
             */
            ['label' => 'supplier::menu.return_on_capital', 'icon' => 'scale', 'route' => 'purchase.report.show',
                'route_params' => ['slug' => 'return-on-capital'], 'permission' => 'purchase.settlement.view'],
        ],
    ],

    /*
     * ⭐ ভাউচারের ফর্মে এই মডিউলের তালিকা — ২১ সেপ্টেম্বর ২০২৬।
     *
     * ⚠️ আগে Accounts-এর কন্ট্রোলার এই মডিউলের মডেল সরাসরি ডাকত,
     * অথচ নিচের `depends_on`-এ লেখা আছে এই মডিউল accounts চেনে —
     * উল্টোটা নয়। ⛔ ঘোষণা করলে চক্র হত।
     *
     * ⓘ ঘরটা ভাউচারের পর্দায় বসে, কিন্তু তালিকাটা যার, সে-ই দেয়
     * ([[App\Core\Contracts\OffersChoicesOnAForm]])।
     */
    'form_choices' => [
        TaggableBillsOnTheVoucherForm::class,
    ],

    'permissions' => [
        /*
         * ⭐ চাহিদার তিনটা চাবি — ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ── ⚠️ চাওয়া আর মঞ্জুর করা এক অধিকার নয় ─────────────────
         * ⓘ চাওয়া প্রায় সবার কাজ: গুদামের লোক, দোকানের লোক,
         * অফিসের যে কেউ। ⛔ মঞ্জুর করা একটা **সিদ্ধান্ত** — ঐ
         * মুহূর্তে প্রতিষ্ঠান টাকা খরচের পথে এক ধাপ এগোয়।
         *
         * ⚠️ এক চাবিতে রাখলে যিনি চান তিনিই নিজের চাওয়া মঞ্জুর
         * করতেন, আর অনুমোদনের ধাপটার কোনো মানেই থাকত না।
         *
         * ⓘ আদেশে রূপান্তরের আলাদা চাবি নেই — ওটা `order.create`,
         * কারণ কাজটা সত্যিই সেটাই।
         */
        'purchase.requisition.view',
        'purchase.requisition.create',
        'purchase.requisition.approve',

        /*
         * ⭐ দরপত্রের তিনটা চাবি — ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ── ⚠️ পাঠানো আর দর লেখা এক অধিকার নয় ───────────────
         * ⓘ অনুরোধ পাঠানো ক্রয় বিভাগের কাজ। ⛔ দর **লেখা** অন্য
         * কাজ: ওটা সরবরাহকারীর কাগজ থেকে টুকে বসানো, আর ঐ
         * সংখ্যাগুলোই পরে সিদ্ধান্তের ভিত্তি।
         *
         * ⚠️ এক চাবিতে রাখলে যিনি অনুরোধ পাঠান তিনিই দর বসাতে
         * পারতেন — আর তখন *"তিনজনের দর নিয়ে তুলনা করা হয়েছে"*
         * কথাটার কোনো স্বাধীন সাক্ষী থাকত না।
         */
        /*
         * ⭐ চুক্তির তিনটা চাবি — ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ── ⚠️ লেখা আর চালু করা এক অধিকার নয় ────────────────
         * ⓘ খসড়া লেখা কেরানির কাজও হতে পারে। ⛔ **চালু করা**
         * মানে ঐ দরটাকে প্রতিষ্ঠানের কথা বানিয়ে দেওয়া —
         * এরপর থেকে প্রতিটা আদেশ ঐ সংখ্যার বিরুদ্ধে মেলানো হবে।
         */
        'purchase.contract.view',
        'purchase.contract.create',
        'purchase.contract.activate',

        'purchase.rfq.view',
        'purchase.rfq.create',
        'purchase.quotation.create',

        'purchase.order.view',
        'purchase.order.create',
        'purchase.order.update',
        'purchase.order.cancel',
        'purchase.receipt.view',
        'purchase.receipt.create',
        'purchase.receipt.cancel',
        'purchase.bill.view',
        'purchase.bill.create',
        'purchase.bill.cancel',

        /*
         * পরিশোধের চাবি আলাদা তিনটা।
         *
         * যিনি বিল তোলেন আর যিনি টাকা দেন — বেশিরভাগ ডিপোতে দুইজন
         * আলাদা মানুষ, আর ইচ্ছাকৃতভাবে: একজনেই দুইটা করলে ভুয়া বিল
         * তুলে নিজেই তার টাকা দিয়ে দেওয়া যেত, আর কাগজে সবই মিলত।
         */
        'purchase.payment.view',
        'purchase.payment.create',
        'purchase.payment.cancel',

        /*
         * ফেরতের চাবিও আলাদা।
         *
         * ফেরত মানে প্রদেয় কমিয়ে দেওয়া — টাকা না দিয়েই দায় থেকে অঙ্ক
         * সরানো। যে মাল বুঝে নেয় তার হাতে এই ক্ষমতা থাকলে ভুয়া ফেরত
         * দেখিয়ে ঘাটতি ঢাকা যেত।
         */
        'purchase.return.view',
        'purchase.return.create',
        'purchase.return.cancel',

        'purchase.report',

        /*
         * মার্জিন দেখার চাবি — বকেয়া দেখার চাবির চেয়ে আলাদা।
         *
         * নিষ্পত্তি ও পুঁজির রিপোর্ট দুইটাই ক্রয়মূল্য খুলে দেখায়। যিনি
         * বকেয়ার তালিকা দেখেন তিনি ডিপোর মার্জিনও দেখে ফেলবেন — এমনটা
         * হওয়ার কথা নয়।
         */
        'purchase.settlement.view',

        'purchase.manage',
    ],

    /* নতুন ইনস্টলে (§৫): Manager ক্রয় দেখা ও রিপোর্ট (বানানো কেরানির)। */
    'role_templates' => [
        'Manager' => [
            'purchase.order.view', 'purchase.receipt.view', 'purchase.bill.view',
            'purchase.return.view', 'purchase.report',
        ],
    ],

    'doc_types' => [
        /*
         * ⭐ চাহিদা — PR-2026-2027-0001, ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ নিজের সিরিজ, কারণ চাহিদাটা আদেশের **আগের** কাগজ, আর
         * ⚠️ দুইটা একই সিরিজে থাকলে নম্বর দেখে বোঝা যেত না কোনটা
         * চাওয়া আর কোনটা প্রতিশ্রুতি।
         */
        'PR' => 'purchase::doc.requisition',

        /*
         * ⭐ দরপত্র ও দর — ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ দুইটা আলাদা সিরিজ, কারণ দুইটা আলাদা কাগজ: একটা
         * আমাদের প্রশ্ন, অন্যটা তাঁদের উত্তর। ⚠️ এক সিরিজে
         * রাখলে নম্বর দেখে বোঝা যেত না কোনটা কার কাগজ।
         */
        /*
         * ⭐ চুক্তি — PC-2026-2027-0001, ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ নিজের সিরিজ, কারণ চুক্তি কোনো লেনদেন নয় — একটা
         * **নিয়ম**, আর সে বছরের পর বছর টেকে।
         */
        'PC' => 'purchase::doc.contract',
        'RFQ' => 'purchase::doc.rfq',
        'QT' => 'purchase::doc.quotation',
        'PO' => 'purchase::doc.order',
        'GRN' => 'purchase::doc.receipt',
        'PBL' => 'purchase::doc.bill',
        /*
         * কোডগুলো ছোট, উপসর্গগুলো নয়।
         *
         * কাগজে ছাপা হয় উপসর্গ (PMT-2026-2027-0001), আর কোডটা ভেতরের
         * নাম। SP-কে PAY বলা যেত না: হিসাবের পরিশোধ ভাউচার আগে থেকেই
         * PAY উপসর্গ নেয়, আর দুইটা কাগজে একই নম্বর ছাপা হলে মেলানোর
         * সময় কেউ বুঝত না কোনটার কথা।
         */
        'SP' => 'purchase::doc.payment',
        'PR' => 'purchase::doc.return',
    ],

    /*
     * ⭐ যে কাগজগুলো নিশ্চিত হলেই খাতায় ওঠার কথা (২১ সেপ্টেম্বর ২০২৬)।
     * ⚠️ ক্রয়াদেশ ও গ্রহণ নেই — ওগুলো খতিয়ানে ওঠে না।
     */
    'posts_to_the_books' => [
        'purchase_bill' => PurchaseBill::class,
        'purchase_return' => PurchaseReturn::class,
        'purchase_payment' => Payment::class,
    ],

    'drill_sources' => [
        'purchase_order' => PurchaseOrder::class,
        'purchase_receipt' => PurchaseReceipt::class,
        'purchase_bill' => PurchaseBill::class,
        'purchase_payment' => Payment::class,
        'purchase_return' => PurchaseReturn::class,
    ],

    /*
     * অনুমোদন লাগতে পারে এমন কাজ — চারটাই "নিশ্চিত" করার মুহূর্তে।
     *
     * ⓘ খসড়া লেখা কেউ আটকায় না; আটকায় কেবল নিশ্চিত করা, কারণ ওখানেই
     * দায় বা টাকা নড়ে। ক্রয়াদেশে খতিয়ানে কিছু বসে না ঠিকই, কিন্তু
     * ওটাই সরবরাহকারীর কাছে দেওয়া কথা — আর কথাটা দেওয়ার আগেই সই লাগে।
     *
     * ⚠️ **এই সারিগুলো কারো আজকের কাজ থামায় না।** ছক না বসানো পর্যন্ত
     * `ApprovalEngine::request()` `null` ফেরায় আর `confirm()` আগের মতোই
     * চলে। কত টাকার উপরে সই লাগবে সেটা প্রতিটা কোম্পানি নিজে বসাবে —
     * এক ডিপোর "বড় ক্রয়" আরেকটার রোজকার কাজ, তাই সংখ্যাটা কোডে থাকতে
     * পারে না।
     */
    'approvals' => [
        /*
         * ⭐ চাহিদার অনুমোদন — ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ অঙ্কটা **আন্দাজি** মোট, আর সেটাই ঠিক: চাহিদায় আসল
         * দাম বলে কিছু নেই। ⚠️ তবু আন্দাজটাই একমাত্র সংখ্যা যা
         * সিদ্ধান্তের **আগে** পাওয়া যায়, আর অনুমোদনের পুরো কথাই
         * হলো সিদ্ধান্তের আগে থামা।
         */
        'requisition' => 'purchase::approval.requisition',
        'order' => 'purchase::approval.order',
        'receipt' => 'purchase::approval.receipt',
        'bill' => 'purchase::approval.bill',
        'payment' => 'purchase::approval.payment',
        'return' => 'purchase::approval.return',
    ],

    /*
     * ⛔ যে কাজগুলোতে **টাকা নড়ে** — ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ⭐ মালিকের সিদ্ধান্ত: এগুলোতে **একসাথে সই দেওয়া যায় না**
     * ([[BulkApproval]]) — একটা একটা করে দেখে দিতে হবে।
     *
     * ⓘ পরিশোধ টাকা বের করে, আর বিল দেনা বসায়।
     *
     * ⚠️ নামগুলো `approvals`-এ থাকতেই হবে — [[ModuleDefinition]]
     * মিলিয়ে দেখে। ⛔ একটা টাইপো নীরবে কাগজটাকে bulk-এ
     * ঢুকিয়ে দিত।
     */
    'moves_money' => ['payment', 'bill'],

    'reports' => [
        PurchaseReports::class,
        SettlementReport::class,
        ReturnOnCapitalReport::class,
    ],

    'dashboard' => PurchaseDashboard::class,

    'widgets' => [
        PurchaseWidgets::class,
    ],

    // ক্রয়ের কাগজ নিজের সাথে মেলে কি না — বিক্রয়ের একই দুইটা প্রশ্ন
    'integrity' => [
        PurchaseChecks::class,
    ],

    'settings' => [
        /*
         * ⭐ কোন কাগজে ছাপা হবে — মালিকের সিদ্ধান্ত, যন্ত্রের নয়।
         *
         * ⓘ মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬: *"কি কাগজে প্রিন্ট করবো এটা
         * নিজে নির্ধারণ করে দিব"*। ⚠️ আগে ঠিকানায় মাপ না থাকলে প্রতিটা
         * কন্ট্রোলার নিজে থেকে A4 ধরে নিত, আর বদলানোর কোনো পথ ছিল না।
         *
         * ⓘ প্রতিটা কাগজের নিজের ঘর, কারণ একই দোকানে বিল যায় রোলে আর
         * ভাউচার যায় A4-তে। ⛔ একটা মাত্র ডিফল্ট দিলে তার একটাকে বাঁচাতে
         * গিয়ে অন্যটা প্রতিবার হাতে বদলাতে হত।
         */
        [
            'key' => 'purchase.print.paper.bill',
            'label' => 'purchase::settings.paper_bill',
            'type' => 'choice',
            'options' => PaperSize::all(),
            'default' => PaperSize::A4,
            'group' => 'print',
        ],
        [
            'key' => 'purchase.print.paper.order',
            'label' => 'purchase::settings.paper_order',
            'type' => 'choice',
            'options' => PaperSize::all(),
            'default' => PaperSize::A4,
            'group' => 'print',
        ],
        [
            'key' => 'purchase.print.paper.receipt',
            'label' => 'purchase::settings.paper_receipt',
            'type' => 'choice',
            'options' => PaperSize::all(),
            'default' => PaperSize::A4,
            'group' => 'print',
        ],
        /*
         * কোন পর্দাগুলো থাকবে — বিক্রয়ের মতোই (Sales/module.php)।
         *
         * ছোট ডিপো সরাসরি কেনে: গাড়ি আসে, মাল নামে, চালান হাতে ধরিয়ে
         * দেয়। তার মেনুতে "ক্রয় আদেশ" আর "মাল গ্রহণ" দুইটা সারি সারা
         * বছর অব্যবহৃত পড়ে থাকে। বড় প্রতিষ্ঠানে ঠিক উল্টো — ওখানে
         * আদেশ ছাড়া মাল ঢোকে না।
         */
        [
            /*
             * ⭐ চাহিদা ও দরপত্রের পর্দা — ডিফল্টে **চালু**, ২৫ সেপ্টেম্বর ২০২৬।
             *
             * ── ⓘ একদিনে উল্টে গেছে, আর সেটাই লিখে রাখা দরকার ────────
             * ২৪ সেপ্টেম্বর বন্ধ রাখা হয়েছিল: *"চাহিদাপত্র বড়
             * প্রতিষ্ঠানের জিনিস"*। ⭐ মালিকের সিদ্ধান্ত পরদিন:
             * *"সব চালু করো, পরে আমি বন্ধ করব"*।
             *
             * ⚠️ পুরনো যুক্তিটা মুছে দেওয়া হয়নি, কারণ ওটা এখনো সত্যি —
             * ⓘ বদলেছে কেবল **কে বেছে নেবে**: আমরা ধরে নেব, না তিনি
             * দেখে ঠিক করবেন। ⛔ আর ধরে নেওয়াটাই ছিল ভুল অংশ।
             *
             * ⛔ `holds` নেই, ইচ্ছাকৃতভাবে: চাহিদা কোনো মাল বা
             * টাকা ধরে রাখে না, তাই সুইচ বন্ধ করলে কিছু আটকায় না।
             * ⓘ কাগজগুলো থেকে যায়, কেবল মেনুর সারিটা যায় — তাই পরে
             * বন্ধ করা সম্পূর্ণ নিরাপদ।
             */
            'key' => 'purchase.screen_requisitions',
            'label' => 'purchase::settings.screen_requisitions',
            'type' => 'boolean',
            'default' => true,
            'group' => 'screens',
        ],
        [
            'key' => 'purchase.screen_direct',
            'label' => 'purchase::settings.screen_direct',
            'type' => 'boolean',
            'default' => true,
            'group' => 'screens',
        ],
        [
            'key' => 'purchase.screen_orders',
            'label' => 'purchase::settings.screen_orders',
            'type' => 'boolean',
            'default' => true,
            'group' => 'screens',

            // কাগজ থাকলে আড়াল করা যাবে না — কারণটা Sales/module.php-এ
            'holds' => PurchaseOrder::class,
        ],
        [
            'key' => 'purchase.screen_receipts',
            'label' => 'purchase::settings.screen_receipts',
            'type' => 'boolean',
            'default' => true,
            'group' => 'screens',
            'holds' => PurchaseReceipt::class,
        ],
        [
            /*
             * বিল ছাড়া মাল নেওয়া যাবে কি না।
             *
             * বড় প্রতিষ্ঠানে আদেশ ছাড়া মাল ঢোকে না — নিয়ন্ত্রণটাই মূল কথা।
             * ছোট ডিপোতে উল্টো: সরবরাহকারী মাল নামিয়ে দিয়ে যান, কাগজ পরে
             * হয়। দুটোই বাস্তব, তাই কোডে একটা বেছে নেওয়া যায় না (নিয়ম ৭)।
             */
            'key' => 'purchase.receipt_needs_order',
            'label' => 'purchase::settings.receipt_needs_order',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            /*
             * ফ্রি পরিমাণের ঘর — "১০ কার্টন কিনলে ১ কার্টন ফ্রি"।
             *
             * ── কেন সুইচটা এখন যোগ হলো ──────────────────────────────
             * ঘরটা ক্রয়ের পর্দায় আগে থেকেই ছিল, আর কন্ট্রোলার
             * `purchase.field_free_qty` পড়ত — কিন্তু কেউ সেটা ঘোষণাই
             * করেনি। ফলে ডিফল্ট `true` ধরে ঘরটা সবসময় দেখাত, আর
             * Control Panel-এ বন্ধ করার কোনো উপায় ছিল না।
             *
             * নিয়মটা প্রকল্পের নিজের: প্রতিটা ঐচ্ছিক ফিল্ড একই
             * পরিবর্তনে Control Panel-এ সুইচ পায়। এটা সেই ঋণ শোধ।
             *
             * ডিফল্ট চালু — ফ্রি মাল বাংলাদেশে পরিবেশনের রোজকার অংশ,
             * আর যা আগে দেখা যেত তা হঠাৎ উধাও হলে সেটা ভাঙা মনে হয়।
             */
            'key' => 'purchase.field_free_qty',
            'label' => 'purchase::settings.field_free_qty',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            /*
             * আদেশের চেয়ে বেশি মাল নেওয়া যাবে কতটুকু বেশি।
             *
             * শূন্য মানে এক কেজিও বেশি নয়। বাস্তবে বস্তায় ভরা মালে দুই-এক
             * শতাংশ এদিক-ওদিক হয়, আর প্রতিবার আদেশ সংশোধন করতে বললে
             * গুদামের লোক শেষে আদেশ ছাড়াই মাল নামাতে শুরু করেন।
             */
            'key' => 'purchase.over_receipt_percent',
            'label' => 'purchase::settings.over_receipt_percent',
            'type' => 'integer',
            'default' => 0,
            'group' => 'entry',
        ],
        [
            // বিল আদেশের দামের সাথে না মিললে আটকে দেওয়া হবে কি না
            'key' => 'purchase.block_price_mismatch',
            'label' => 'purchase::settings.block_price_mismatch',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
    ],

    /*
     * ⭐ ক্রয় যা ঘোষণা করে — ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ তালিকাটা একটা **চুক্তি**: অন্য মডিউল এটা দেখে ঠিক করে কার কথা
     * শুনবে। ⚠️ তালিকা না থাকলে জানার একমাত্র উপায় হত গোটা কোডবেসে
     * `event(` খোঁজা, আর তখন কোনটা ইচ্ছাকৃত চুক্তি আর কোনটা ভিতরের
     * খুঁটিনাটি তা বোঝা যেত না।
     *
     * ⛔ চালানের **দাখিলা ও স্টক চলাচল ইভেন্টে যায় না** — ওগুলো
     * `confirm()`-এর ভিতরে, একই লেনদেনে। ইভেন্ট একদিন হারায়; খাতা
     * হারানো যায় না।
     */
    'events' => [
        GoodsReceived::class,
    ],

    /*
     * নিজের ঘটনা নিজেই শোনা — পরিদর্শনের কাগজ।
     *
     * ── ⚠️ কেন `confirm()`-এর ভিতরে নয় ──────────────────────────────
     * কাগজটা গ্রহণের অংশ নয়। ⛔ পরিদর্শনের সেবা ব্যতিক্রম ছুড়লে
     * গোটা গ্রহণটা ফিরে যাওয়া উচিত নয় — ট্রাক থেকে নামা মাল খাতায়
     * না ওঠা আর একটা কাগজ না খোলা এক জিনিস নয়।
     *
     * ── ⓘ কেন শ্রোতাটা মজুদে নয়, এখানে ──────────────────────────────
     * মজুদের `depends_on`-এ ক্রয় নেই, আর থাকার কথাও নয় — তীরটা উল্টো
     * দিকে (ক্রয় মজুদকে চেনে)। ⚠️ মজুদে লিখলে [[BoundariesTest]]
     * ধরত, আর উল্টো ঘোষণা একটা চক্র বানাত।
     *
     * ⭐ নজিরটা [[SendTheOrderToTheKitchen]]-এর: নির্ভরতার তীর যেদিকে
     * সত্যি, ফাইলটাও সেদিকে।
     */
    'listeners' => [
        GoodsReceived::class => [OpenInspectionsForGoodsThatNeedThem::class],
    ],
];
