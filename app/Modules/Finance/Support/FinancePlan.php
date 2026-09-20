<?php

declare(strict_types=1);

namespace App\Modules\Finance\Support;

use Illuminate\Support\Facades\Route;

/**
 * ফিন্যান্স মডিউলের পুরো পরিকল্পনা — যা হয়েছে আর যা বাকি, এক পাতায়।
 *
 * ── কেন এই ফাইলটা, ২৯ আগস্ট ২০২৬ ─────────────────────────────────────
 * মালিক তেত্রিশ বিভাগের পূর্ণাঙ্গ Finance পরিকল্পনা দিয়ে বললেন: **কিছুই
 * বাদ দেওয়া যাবে না**, প্ল্যানের বাইরে যা আছে তাও থাকবে, আর গোটাটা আগে
 * চোখের সামনে থাকা চাই — *"দেখলে বুঝা যাবে আমি কোন কাজটা করছি আর কোনটা
 * করি নাই, তখন দেখে দেখে ইমপ্লিমেন্ট করবা"*।
 *
 * ── কেন মেনুতে দুইশো মৃত সারি নয় ─────────────────────────────────────
 * প্রথম ভাবনা ছিল প্রতিটা লাইনের জন্য একটা করে মেনু সারি বসানো। ওটা
 * করলে দুইশোটা বোতাম বসত যার একটাও কিছু করে না — আর সরাসরি বিক্রয়ের
 * পর্দায় ঠিক ওই জিনিসটাই আজ সকালে সারানো হয়েছে (চারটা বোতাম কেবল
 * "আসছে" বলত)। তার উপর [[ModuleMenuTest]] প্রতিটা মেনু সারি খুলে দেখে,
 * আর দুইশোটা ভাঙা সারিতে ওটা লাল হয়ে থাকত।
 *
 * একটা **মানচিত্র** ওই দুইটার কোনোটাই নয়: এটা একটা সৎ তালিকা। যেটা
 * হয়েছে তার লিংক আছে আর ক্লিক করলে আসল পর্দা খোলে; যেটা হয়নি তার পাশে
 * লেখা "বাকি"। কোনো বোতাম মিথ্যা বলে না।
 *
 * ── কেন তালিকাটা মিথ্যা বলতে পারে না ─────────────────────────────────
 * প্রতিটা "হয়েছে" লাইনে একটা রুটের নাম লেখা, আর
 * [[TheFinanceMapCannotLieTest]] প্রতিটা নাম রুটের তালিকায় মিলিয়ে দেখে।
 * কেউ একটা পর্দা মুছে ফেললে মানচিত্র লাল হয়, আর "হয়েছে" লেখা একটা লাইন
 * ফাঁকা জায়গায় নিয়ে যাওয়ার আগেই ধরা পড়ে।
 */
final class FinancePlan
{
    /**
     * তেত্রিশ বিভাগ, প্রতিটার লাইনগুলোসহ।
     *
     * প্রতিটা লাইন: `[বাংলা নাম, রুট বা নাল, টীকা বা নাল]`
     *   • রুট থাকা মানে **হয়েছে** — ক্লিক করলে আসল পর্দা।
     *   • রুট নাল মানে **বাকি** — টীকায় লেখা কেন বা কোথায় আছে।
     *
     * @return list<array{no: string, title: string, items: list<array{0: string, 1: ?string, 2: ?string}>}>
     */
    /**
     * শেষ কবে হাতে মিলিয়ে দেখা হয়েছে।
     *
     * ── কেন একটা তারিখ লাগে ─────────────────────────────────────────
     * [[TheFinanceMapCannotLieTest]] পাহারা দেয় **মরা লিংক** — নাম লেখা
     * আছে অথচ রুট নেই। আর [[EveryFinanceScreenIsOnTheMapTest]] পাহারা
     * দেয় **হারানো দরজা** — নতুন পর্দা মানচিত্রে ওঠেনি।
     *
     * ⚠️ কিন্তু দুইটার কোনোটাই এটা ধরতে পারে না: **একটা লাইন "বাকি"
     * লেখা থেকে গেল, অথচ কাজটা হয়ে গেছে।** ৪ সেপ্টেম্বর ২০২৬-এ ঠিক
     * সেটাই দুইবার পাওয়া গেছে — "বিক্রয় ছাড়া অন্য আয়" ও "খরচের শ্রেণি"
     * দুইটাই তৈরি ছিল, লাইন দুইটা পুরনো কথা বলছিল।
     *
     * ⓘ যান্ত্রিকভাবে ধরা যায় না, কারণ "বাকি" লাইনে রুটের নামই থাকে না।
     * তাই একটা তারিখ — **ছয় মাসের পুরনো তারিখ নিজেই বলে দেবে মানচিত্র
     * কতটা বিশ্বাস করা যায়**। পর্দার মাথায় দেখা যায়, কেবল মন্তব্যে নয়।
     */
    public const RECONCILED_ON = '2026-09-20';

    /** "পরের ধাপ" — এই শব্দে শুরু হওয়া টীকা মানে লাইনটা জেনেশুনে পরে রাখা। */
    public const LATER = 'পরের ধাপ';

    public static function sections(): array
    {
        return [
            [
                'no' => '১',
                'title' => 'ফিন্যান্স ড্যাশবোর্ড',
                'items' => [
                    ['হিসাবের ড্যাশবোর্ড', 'accounts.dashboard', null],
                    /*
                     * ⚠️ লাইনটা আগে শুধু "নগদ ও ব্যাংক" বলত, আর রুটও ঠিক ছিল —
                     * **তাই পাহারা চুপ ছিল**। কিন্তু বর্ণনাটা কম বলত: MFS তৃতীয়
                     * একটা মা, আর তার টাকা ৪ সেপ্টেম্বর পর্যন্ত **কোনো টালিতেই
                     * গোনা হত না** — bKash-এর খাতে `is_bank`ও নেই, `is_cash`ও নয়।
                     *
                     * ⓘ মানচিত্র মরা লিংক ধরে, **অসম্পূর্ণ বর্ণনা ধরে না** — তাই
                     * এটা হাতে মিলিয়ে দেখার জিনিস, আর সেজন্যই উপরে তারিখটা।
                     */
                    ['নগদ · ব্যাংক · MFS — তিনটা আলাদা অবস্থান', 'module.dashboard:finance', null],
                    ['টাকার হেফাজত', 'accounts.custody', null],
                    ['বকেয়ার সংক্ষেপ', 'customer.report.show:due-list', null],
                    ['দেনার সংক্ষেপ', 'supplier.report.show:payable-list', null],
                    ['CFO ড্যাশবোর্ড', 'finance.cfo', 'নগদ, প্রাপ্য, দেনা আর তারল্য এক পাতায়'],
                    ['ঝুঁকির ড্যাশবোর্ড', null, 'পরের ধাপ'],
                    ['বাজেটের অবস্থা', 'finance.budget.actual', 'ফিন্যান্স ড্যাশবোর্ড ও CFO পাতার কার্ড'],
                    ['ট্রেজারি সংক্ষেপ', null, 'পরের ধাপ — বহু-কোম্পানি হলে'],
                    ['অপেক্ষমাণ অনুমোদন', 'approval.inbox.index', 'অনুমোদন কেন্দ্র থেকে'],
                ],
            ],
            [
                'no' => '২',
                'title' => 'ফিন্যান্স কন্ট্রোল সেন্টার',
                'items' => [
                    ['হিসাবের সততা যাচাই', 'accounts.integrity', 'খাতা নিজে নিজে মেলে কি না'],
                    ['ব্যাংক মিলকরণ', 'accounts.reconciliation.index', null],
                    ['পিরিয়ডের অবস্থা', 'accounts.period.index', null],
                    ['পোস্টিং মনিটর', 'accounts.control.posting', null],
                    ['ব্যতিক্রম ও ভুলের সারি', 'governance.error.index', 'ভুলের খাতা — কেউ "দেখেছি" না বলা পর্যন্ত থাকে'],
                    ['ইন্টিগ্রেশন মনিটর', null, 'পরের ধাপ — ইঞ্জিন Platform Management-এ'],
                ],
            ],
            [
                'no' => '৩',
                'title' => 'ফিন্যান্স কনফিগারেশন',
                'items' => [
                    ['হিসাবের ছক', 'accounts.coa.index', null],
                    ['খাতের মাথা ও দল', 'accounts.coa.index', 'একই পর্দায়'],
                    ['খরচের কেন্দ্র', 'master_data.cost_center.index', null],
                    ['অর্থবছর', 'accounts.year_end.index', 'বছর শেষের পর্দায় সব অর্থবছর'],
                    ['নম্বর সিরিজ', 'master_data.series.index', null],
                    ['করের ছক', 'master_data.tax.index', null],
                    ['হিসাবের সেটিংস', 'accounts.settings', null],
                    ['মুনাফা কেন্দ্র ও সেগমেন্ট', null, 'পরের ধাপ — এখন খরচের কেন্দ্র দিয়ে চলে'],
                    ['মুদ্রা ও বিনিময় হার', null, 'পরের ধাপ — পর্দা আছে (master_data.currency.index), কন্ট্রোল প্যানেলে বহু-মুদ্রার সুইচ চালু করলে খোলে'],
                    ['স্বয়ংক্রিয় পোস্টিং ম্যাপিং', null, 'পরের ধাপ — খাত এখন কোডে বাঁধা'],
                    ['অনুমোদনের নিয়ম', 'approval.inbox.index', 'অনুমোদন কেন্দ্র থেকে'],
                ],
            ],
            [
                'no' => '৪',
                'title' => 'সাধারণ খতিয়ান (GL)',
                'items' => [
                    ['খতিয়ান', 'accounts.report.show:ledger', null],
                    ['রেওয়ামিল', 'accounts.report.show:trial-balance', null],
                    ['দৈনিক খতিয়ান', 'accounts.report.show:day-book', null],
                    ['বছর শেষ', 'accounts.year_end.index', null],
                    ['পিরিয়ড বন্ধ ও খোলা', 'accounts.period.index', null],
                    ['খোলা ব্যালেন্স', 'inventory.stock.opening', 'মজুদের খোলা ব্যালেন্স'],
                    ['খাত বিশ্লেষণ', 'finance.account_analysis.index', 'এক খাতের চলাচল — মাস, পক্ষ, উল্টো খাত ধরে'],
                ],
            ],
            [
                'no' => '৫',
                'title' => 'প্রাপ্য (AR)',
                'items' => [
                    ['গ্রাহকের খতিয়ান', 'customer.index', 'গ্রাহকের পাতায় লেনদেন'],
                    ['বকেয়ার তালিকা', 'customer.report.show:due-list', null],
                    ['বকেয়ার বয়স', 'customer.report.show:ageing', null],
                    ['কে কত দিল', 'customer.report.show:collection', null],
                    ['কাদের লিমিট নেই', 'customer.report.show:no-limit', null],
                    ['আদায়ের তালিকা', 'sales.collection.index', null],
                    ['অগ্রিম আদায়', null, 'পরের ধাপ — কাউন্টারের জমা এখন রসিদ, অগ্রিম খাত নেই'],
                ],
            ],
            [
                'no' => '৬',
                'title' => 'দেনা (AP)',
                'items' => [
                    ['সরবরাহকারীর খতিয়ান', 'supplier.index', null],
                    ['দেনার তালিকা', 'supplier.report.show:payable-list', null],
                    ['দেনার বয়স', 'supplier.report.show:ageing', null],
                    ['পরিশোধ', 'purchase.payment.index', null],
                    ['পরিশোধের সময়সূচি', 'purchase.payment_schedule.index', 'ক্রয়ের পাতায় — বিলের শেষ তারিখ ক্রয়ই জানে'],
                    ['ট্রান্সপোর্ট ও শ্রমিকের খতিয়ান', 'finance.carrier_labour.index', null],
                ],
            ],
            [
                'no' => '৭',
                'title' => 'ভাউচার',
                'items' => [
                    ['আদায় ভাউচার', 'accounts.voucher.index:receipt', null],
                    ['পরিশোধ ভাউচার', 'accounts.voucher.index:payment', null],
                    ['জাবেদা ভাউচার', 'accounts.voucher.index:journal', null],
                    ['কন্ট্রা ভাউচার', 'accounts.voucher.index:contra', null],
                    ['খরচ ভাউচার', 'accounts.voucher.index:expense', null],
                    ['খরচের পাতা — সব খরচ এক জায়গায়', 'finance.expense.index', 'সদ্যগুলো, অপেক্ষমাণ, আর খাত ধরে যোগ'],
                    ['উত্তোলন ভাউচার', 'finance.withdrawal.index', 'উত্তোলনের পর্দা থেকেই বসে'],
                    ['ডেবিট ও ক্রেডিট নোট', null, 'পরের ধাপ — এখন ফেরতের কাগজ দিয়ে'],
                    ['ভাউচারের ইতিহাস ও অডিট', 'governance.audit.index', 'অডিট ট্রেইল থেকে'],
                ],
            ],
            [
                'no' => '৮',
                'title' => 'নগদ ব্যবস্থাপনা',
                'items' => [
                    ['ক্যাশ বই', 'accounts.report.show:cash-book', null],
                    ['ক্যাশ টিল', 'accounts.till.index', null],
                    ['নগদ গণনা', 'accounts.count.index', null],
                    ['টাকা হস্তান্তর', 'accounts.transfer.index', null],
                    ['টাকা ও হেফাজত', 'accounts.custody', null],
                    ['নগদের পূর্বাভাস', 'finance.forecast.cash', '৩০ · ৬০ · ৯০ দিন — প্রাপ্য, দেনা আর কিস্তি ধরে'],
                ],
            ],
            [
                'no' => '৯',
                'title' => 'ব্যাংক ব্যবস্থাপনা',
                'items' => [
                    ['ব্যাংক বই', 'accounts.report.show:bank-book', null],
                    ['চেক রেজিস্টার', 'accounts.cheque.index', 'জমা · পাস · ফেরত'],
                    ['ব্যাংক মিলকরণ', 'accounts.reconciliation.index', null],
                    ['ব্যাংকে-ব্যাংকে হস্তান্তর', 'accounts.transfer.index', 'কন্ট্রা'],
                    ['ব্যাংক স্টেটমেন্ট আমদানি', null, 'বাকি'],
                    ['ব্যাংক চার্জ', 'finance.bank_charge.index', null],
                    ['আর্থিক প্রতিষ্ঠান', 'finance.institution.index', 'ব্যাংক · MFS · বিমা কোম্পানি, আর হিসাবের খাতের সাথে জোড়া'],
                    ['নতুন প্রতিষ্ঠান', 'finance.institution.create', null],
                    ['বীমা পলিসি', 'finance.insurance.index', 'পলিসি ও প্রিমিয়াম, মেয়াদ শেষের আগে'],
                    ['নতুন পলিসি', 'finance.insurance.create', null],
                ],
            ],
            [
                'no' => '১০',
                'title' => 'আয় ব্যবস্থাপনা',
                'items' => [
                    ['বিক্রয়ের আয় বিশ্লেষণ', 'sales.report.show:by-product', null],
                    ['গ্রাহকভিত্তিক আয়', 'sales.report.show:by-customer', null],
                    ['ব্র্যান্ডভিত্তিক আয়', 'sales.report.show:by-brand', null],
                    ['আয়ের শ্রেণি', 'accounts.report.show:income-by-head', 'খরচের আয়না — কোন খাতে কত আয়'],
                    // ৪ সেপ্টেম্বর: পাতাটা আগেই ছিল, লাইনটা "বাকি" রয়ে গিয়েছিল
                    ['বিক্রয় ছাড়া অন্য আয়', 'finance.income.index', null],
                ],
            ],
            [
                'no' => '১১',
                'title' => 'খরচ ব্যবস্থাপনা',
                'items' => [
                    ['খরচ ভাউচার', 'accounts.voucher.index:expense', null],
                    ['খরচের কেন্দ্র', 'master_data.cost_center.index', null],
                    ['কোন কেন্দ্রে কত', 'accounts.report.show:by-cost-centre', null],
                    /*
                     * ৪ সেপ্টেম্বর: শ্রেণিগুলো হিসাব-ছকের সন্তান, আর খরচের পাতা
                     * সেগুলো ধরে ধরেই দেখায়। ⭐ একই দিনে পরিবহনের খাতটা **পাঁচ ভাগে
                     * ভাঙা** হয়েছে (জ্বালানি · গাড়ির ভাড়া · লোডিং · আনলোডিং ·
                     * হাম্মালি), তাই শ্রেণিটা এখন সত্যিই কাজের।
                     */
                    ['খরচের শ্রেণি — খাত ধরে', 'accounts.report.show:expense-by-head', null],
                    ['খরচের অনুমোদন', 'approval.inbox.index', 'অনুমোদন কেন্দ্র থেকে'],
                ],
            ],
            [
                'no' => '১২',
                'title' => 'মূলধন ও বিনিয়োগ',
                'items' => [
                    ['মূলধন ও বিনিয়োগ', 'finance.capital.index', '২৯ আগস্ট ২০২৬-এ হয়েছে'],
                    ['কে কোথায় দাঁড়িয়ে', 'finance.capital.index', 'একই পর্দায়'],
                    ['নতুন মূলধন লেখা', 'finance.capital.create', '১৩ সেপ্টেম্বর ২০২৬ — ফর্মটা তালিকার ভিতর থেকে সরানো হলো'],
                    ['বিনিয়োগের রিটার্ন', null, 'পরের ধাপ'],
                    ['লাভ ভাগাভাগি', null, 'পরের ধাপ — অংশীদারি হলে'],
                ],
            ],
            [
                'no' => '১৩',
                'title' => 'উত্তোলন ব্যবস্থাপনা',
                'items' => [
                    ['মালিক ও অংশীদারের উত্তোলন', 'finance.withdrawal.index', null],
                    ['উত্তোলনের অনুরোধ ও অনুমোদন', 'finance.withdrawal.index',
                        'অনুমোদনের প্রবাহ বসানো থাকলে ওখানেই যায়'],
                    ['উত্তোলনের সীমা', 'finance.withdrawal.index', 'একই পর্দায়, মাসিক'],
                    ['উত্তোলন বনাম লাভ/মূলধন মিলকরণ', 'finance.capital.index', 'মালিক ও বিনিয়োগকারী ট্যাবে — কে তাঁর পাওনার বেশি তুলেছেন'],
                    ['ব্যক্তিভিত্তিক উত্তোলন বিবরণী', 'finance.withdrawal.index', null],
                    ['উত্তোলন লেখা', 'finance.withdrawal.create', null],
                ],
            ],
            [
                'no' => '১৪',
                'title' => 'ঋণ ও লিজ',
                'items' => [
                    ['ঋণের তালিকা', 'accounts.loan.index', null],
                    ['ঋণ বিতরণ', 'accounts.loan.create', null],
                    ['কিস্তির সূচি ও পরিশোধ', 'accounts.loan.index', null],
                    ['সুদের হিসাব', 'accounts.loan.index', null],
                    ['লিজ', null, 'পরের ধাপ'],
                ],
            ],
            /*
             * সঞ্চয় ও বিনিয়োগ — মালিকের নির্দেশে ঋণ থেকে আলাদা।
             *
             * ── কেন '১৪ক', নতুন একটা নম্বর নয় ────────────────────────
             * তেত্রিশটা নম্বর মালিকের নিজের লেখা, আর তিনি ওই তালিকা ধরে
             * কাজ মেলান। মাঝখানে একটা ঢোকালে পরের উনিশটা বিভাগের নম্বর
             * সরে যেত, আর তাঁর কাগজের সাথে পর্দা আর মিলত না।
             *
             * ── কেন ঋণের ঘরে নয় ─────────────────────────────────────
             * তাঁর কথা: *"fd, deposit, সঞ্চয়পত্র ইত্যাদি আলাদাভাবে করতে
             * হবে"*। ঋণ মানে টাকা খাটানো বা নেওয়া; জমা মানে টাকা সরিয়ে
             * রাখা। ঘরগুলোই আলাদা — জামানত আর পুনর্বিবেচনার তারিখ জমায়
             * লাগে না, আর মেয়াদপূর্তি ও কিস্তির দিন ঋণে নেই।
             */
            [
                'no' => '১৪ক',
                'title' => 'সঞ্চয় ও বিনিয়োগ',
                'items' => [
                    ['ব্যাংক আমানত — FD · DPS · মাসিক মুনাফা', 'finance.deposit.index:bank', null],
                    ['সঞ্চয়পত্র', 'finance.deposit.index:national_savings', null],
                    ['বন্ড', 'finance.deposit.index:bond', null],
                    ['কিস্তি · মুনাফা · ভাঙা', 'finance.deposit.index:bank', 'জমার নিজের পাতায়'],
                    /*
                     * ৪ সেপ্টেম্বর: তিন ইস্যুকারী একসাথে — অর্থের ড্যাশবোর্ডের
                     * "জমা" টালিটা এখানেই নামে।
                     *
                     * ⚠️ টালিটা তিনটার **যোগফল** দেখায়, তাই একটা ইস্যুকারীর পাতায়
                     * নামালে সংখ্যা মিলত না। ⓘ দরজা না থাকলে মানুষ জানেন কিছু নেই;
                     * **ভুল দরজা থাকলে তাঁরা ভুল সংখ্যাটাই বিশ্বাস করেন।**
                     */
                    ['সব জমা — তিন ইস্যুকারী একসাথে', 'finance.deposit.all', null],
                    ['নতুন আমানত', 'finance.deposit.create:bank', 'ইস্যুকারী ধরে ফর্ম'],
                    /*
                     * ⭐ ২০ সেপ্টেম্বর ২০২৬ — তিনটাই নেমেছে।
                     *
                     * ⓘ ধরনগুলো এখন নিজের পর্দায় যোগ ও সম্পাদনা করা যায়, আর
                     * মেয়াদ ও বন্ধক দুইটাই জমার তালিকার ট্যাব। ⚠️ কেবল আগাম
                     * **খবর পাঠানো** বাকি — পর্দায় গোনা হয়, কিন্তু কেউ না খুললে
                     * কেউ জানে না।
                     */
                    ['জমার ধরন সেটিংস', 'finance.deposit_kind.index', null],
                    ['নতুন ধরন যোগ', 'finance.deposit_kind.create', null],
                    ['মেয়াদ আসছে — ৩০/৬০/৯০ দিন', 'finance.deposit.index:bank', 'জমার তালিকার ট্যাব'],
                    ['বন্ধকী জমা বনাম ঋণ', 'finance.deposit.index:bank', 'জমার তালিকার ট্যাব'],
                    ['কোন প্রতিষ্ঠানে কত', 'finance.deposit.index:bank', 'জমার তালিকার ট্যাব'],
                    ['মেয়াদপূর্তির আগাম খবর', null, 'বাকি — পর্দায় গোনা হয়, নোটিফিকেশন নেই'],
                ],
            ],
            /*
             * হাতধার — মালিকের নির্দেশে ঋণ থেকে সম্পূর্ণ আলাদা।
             *
             * *"এটা ঋণের ধরন হইলে হবে না, এটা টোটালি আলাদা একটা জিনিস।
             * hand loan আলাদা মেনু করো মানে পূর্ণাঙ্গ হিসাব আলাদা।"*
             */
            [
                'no' => '১৪খ',
                'title' => 'হাতধার',
                'items' => [
                    ['কে পায়, কাকে দিতে হবে', 'finance.hand_loan.index', null],
                    ['টাকা দেওয়া ও নেওয়া', 'finance.hand_loan.index', 'মানুষটার নিজের পাতায়'],
                    ['চুকে গেছে চিহ্নিত করা', 'finance.hand_loan.index', null],
                    ['নতুন হাতধার', 'finance.hand_loan.create', null],
                    ['পক্ষের সাথে জোড়া', null, 'বাকি — ঘরটা আছে, পর্দা নেই'],
                    ['মনে করিয়ে দেওয়া', null, 'বাকি — বিজ্ঞপ্তি ইঞ্জিন লাগবে'],
                ],
            ],
            /*
             * ব্যাংক ঋণ — মালিকের নির্দেশে হাতধার থেকে সম্পূর্ণ আলাদা।
             *
             * ⭐ *"bank loan alada rako"* (১৪ সেপ্টেম্বর ২০২৬)।
             *
             * ⛔ পর্দাটা ১৬ সেপ্টেম্বর বানানো হয়েছিল, কিন্তু মানচিত্রে
             * বসানো হয়নি — আর `test_no_finance_screen_is_missing_from_the_map`
             * ঠিক সেটাই ধরেছে। ⓘ মানচিত্র না থাকলে পর্দাটা থাকে, কিন্তু
             * "ফাইন্যান্সে কী কী আছে" প্রশ্নের উত্তরে সে অদৃশ্য।
             */
            [
                'no' => '১৪গ',
                'title' => 'ব্যাংক ঋণ',
                'items' => [
                    ['সুবিধার তালিকা — সীমা ও শর্ত', 'finance.bank_facility.index', null],
                    ['নতুন সুবিধা খোলা', 'finance.bank_facility.index', 'CC · মেয়াদি · LTR · লিজ · গ্যারান্টি'],
                    ['নতুন সুবিধার ফর্ম', 'finance.bank_facility.create', null],
                    ['ড্রয়িং পাওয়ার', 'finance.bank_facility.index', 'CC-তে স্টক ও মার্জিন ধরে'],
                    ['নবায়নের আগাম খবর', 'finance.bank_facility.index', 'তালিকার উপরে আলাদা করে'],
                    ['সুবিধা বন্ধ করা', 'finance.bank_facility.index', 'সুবিধার নিজের পাতায়'],
                    ['ব্যবহৃত অঙ্ক ও বকেয়া', 'finance.bank_facility.index', 'তালিকার কলামে, খতিয়ান থেকে গোনা'],
                ],
            ],
            /*
             * ভাড়ার চুক্তি — গুদাম বা দোকানের অগ্রিম ও মাসিক ভাড়া।
             *
             * ⭐ মালিকের ছক, ৫ সেপ্টেম্বর ২০২৬: *"ভাড়া ৩০ হাজার, অ্যাডভান্স
             * ১২ লাখ, ২০ হাজার নগদে আর বাকি ১০ হাজার অ্যাডভান্স থেকে কাটবে,
             * মেয়াদ দুই বছর।"* আর সাথে: *"চুক্তি কিন্তু ক্যানসেল হয়, আপডেট
             * হয় — সব এডিটের ব্যবস্থা রাখতে হবে।"*
             *
             * ⛔ পর্দাগুলো ৫ সেপ্টেম্বরেই তৈরি হয়েছিল, কিন্তু **মানচিত্রে
             * লেখা হয়নি** — আর [[TheFinanceMapCannotLieTest]] ঠিকই ধরেছে।
             * ⚠️ টেস্টের নিজের বার্তাটাই কারণটা বলে: *"মানচিত্র কম বললে
             * মালিক ভাবেন কাজটা হয়নি — মিথ্যার মতোই ক্ষতিকর।"*
             */
            [
                'no' => '১৪গ',
                'title' => 'ভাড়ার চুক্তি',
                'items' => [
                    /*
                     * ⚠️ সবগুলো `index`-এ, `show`-তে নয়।
                     *
                     * ⓘ `finance.rental.show`-এর URI `finance/rentals/{contract}` —
                     * একটা প্যারামিটার চায়। ⛔ মানচিত্রের পরীক্ষা প্রতিটা রুট
                     * খুলে দেখে, আর প্যারামিটার ছাড়া `route()` ব্যতিক্রম ছোড়ে।
                     *
                     * ⭐ হাতধারের সারিগুলোতেও ঠিক এই ছাঁচ (`hand_loan.index` +
                     * *"মানুষটার নিজের পাতায়"*) — কাজটা যে ভিতরের পাতায় হয়,
                     * সেটা **নোটে** বলা হয়, ভাঙা লিংকে নয়।
                     */
                    ['চুক্তির তালিকা ও মেয়াদ', 'finance.rental.index', null],
                    ['নতুন ভাড়ার চুক্তি', 'finance.rental.create', null],
                    ['অগ্রিম ও মাসিক ভাড়া', 'finance.rental.index', 'চুক্তির নিজের পাতায়'],
                    ['মাসের ভাড়া বসানো', 'finance.rental.index', 'নগদ + অগ্রিম থেকে কাটা'],
                    ['শর্ত বদল ও অগ্রিম বাড়ানো', 'finance.rental.index', 'চুক্তির নিজের পাতায়'],
                    ['চুক্তি শেষ ও অগ্রিম ফেরত', 'finance.rental.index', 'চুক্তির নিজের পাতায়'],
                ],
            ],
            [
                'no' => '১৫',
                'title' => 'স্থায়ী সম্পদ',
                'items' => [
                    ['সম্পদের তালিকা', 'accounts.asset.index', null],
                    ['অবচয়', 'accounts.asset.index', null],
                    ['সম্পদ অবসান', 'accounts.asset.index', null],
                    ['সম্পদ স্থানান্তর', null, 'বাকি'],
                ],
            ],
            [
                'no' => '১৬',
                'title' => 'বাজেট',
                'items' => [
                    ['বাজেট পরিকল্পনা', 'finance.budget.index', 'খাত ও মাস ধরে, ঐচ্ছিক খরচের কেন্দ্রসহ'],
                    ['নতুন বাজেট', 'finance.budget.create', null],
                    ['বাজেট বনাম প্রকৃত', 'finance.budget.actual', 'প্রকৃত অঙ্ক খতিয়ান থেকে, দ্বিতীয় কপি নয়'],
                    ['বিভাগভিত্তিক বাজেট', 'finance.budget.centers', null],
                ],
            ],
            [
                'no' => '১৭',
                'title' => 'ব্যয় হিসাব',
                'items' => [
                    ['কোন কেন্দ্রে কত', 'accounts.report.show:by-cost-centre', null],
                    ['পণ্যের খরচ', 'inventory.report.show:stock-summary', 'ভারিত গড়'],

                    // ৪ সেপ্টেম্বর: অর্থের পাতা থেকে সরাসরি — ⚠️ `inventory.cost.view`-এর পিছনে
                    ['কোন মালে কত টাকা আটকে', 'inventory.stock.movement', null],
                    ['কোন রুট চলে', 'sales.report.show:by-customer', null],
                ],
            ],
            [
                'no' => '১৮',
                'title' => 'প্রকল্প হিসাব',
                'items' => [
                    ['প্রকল্পভিত্তিক খতিয়ান', null, 'পরের ধাপ — ডিপোতে ঐচ্ছিক, বহু-প্রকল্প হলে'],
                ],
            ],
            [
                'no' => '১৯',
                'title' => 'কর ও ভ্যাট',
                'items' => [
                    ['করের ছক', 'master_data.tax.index', null],
                    ['করের হিসাব ও পোস্টিং', 'accounts.report.show:ledger', 'বিলেই বসে'],
                    ['মুশক চালান', null, 'পরের ধাপ — NBR-এর ছক মিলিয়ে'],
                    ['NBR রিপোর্টিং', null, 'পরের ধাপ — NBR-এর ছক মিলিয়ে'],
                ],
            ],
            [
                'no' => '২০',
                'title' => 'ট্রেজারি',
                'items' => [
                    ['তহবিল পরিকল্পনা', null, 'পরের ধাপ'],
                    ['উদ্বৃত্ত তহবিলের বিনিয়োগ', null, 'পরের ধাপ'],
                ],
            ],
            [
                'no' => '২১',
                'title' => 'আন্তঃকোম্পানি',
                'items' => [
                    ['শাখার মধ্যে স্থানান্তর', 'inventory.transfer.index', 'মালের স্থানান্তর'],
                    ['আন্তঃকোম্পানি মিলকরণ', null, 'পরের ধাপ — বহু-কোম্পানি হলে'],
                ],
            ],
            [
                'no' => '২২',
                'title' => 'একীভূত বিবরণী',
                'items' => [
                    ['গ্রুপের একীভূত হিসাব', null, 'পরের ধাপ — বহু-কোম্পানি হলে'],
                ],
            ],
            [
                'no' => '২৩',
                'title' => 'পরিকল্পনা ও বিশ্লেষণ',
                'items' => [
                    ['পূর্বাভাস ও দৃশ্যকল্প', null, 'পরের ধাপ'],
                ],
            ],
            [
                'no' => '২৪',
                'title' => 'মাস ও বছর শেষ',
                'items' => [
                    ['পিরিয়ড বন্ধ', 'accounts.period.index', null],
                    ['বছর শেষ', 'accounts.year_end.index', null],
                    ['মাস-শেষের চেকলিস্ট', 'accounts.control.month_end', null],
                ],
            ],
            [
                'no' => '২৫',
                'title' => 'ফিন্যান্স গভর্ন্যান্স',
                'items' => [
                    ['অডিট ট্রেইল', 'governance.audit.index', 'পুরনো ও নতুন মানসহ'],
                    ['কে কী পারে', 'system_admin.role.index', 'রোল ও অনুমতি'],
                    // ৪ সেপ্টেম্বর: কে কী অনুমোদন করেন — অর্থের পাতা থেকে
                    ['অনুমোদনের ছক', 'approval.flow.index', null],
                    ['নীতি ব্যবস্থাপনা', null, 'পরের ধাপ'],
                ],
            ],
            [
                'no' => '২৬',
                'title' => 'ফিন্যান্স ইন্টিগ্রেশন',
                'items' => [
                    ['বিক্রয় → খাতা', 'accounts.report.show:ledger', 'বিল নিশ্চিত হলেই'],
                    ['ক্রয় → খাতা', 'accounts.report.show:ledger', 'বিল নিশ্চিত হলেই'],
                    ['মজুদ → খাতা', 'accounts.report.show:ledger', 'মাল নড়লেই'],
                    ['ব্যাংক ও কর API', null, 'পরের ধাপ — Platform Management-এ'],
                ],
            ],
            [
                'no' => '২৭',
                'title' => 'AI ফিন্যান্স',
                'items' => [
                    ['OCR, স্মার্ট পোস্টিং, জালিয়াতি ধরা', null, 'পরের ধাপ — মালিক: দুই মাস পরে (নভেম্বর ২০২৬)'],
                ],
            ],
            [
                'no' => '২৮',
                'title' => 'কমপ্লায়েন্স ও অডিট',
                'items' => [
                    ['অডিট ট্রেইল', 'governance.audit.index', null],
                    ['হিসাবের সততা যাচাই', 'accounts.integrity', null],
                    ['রপ্তানির লগ', 'governance.export.index', 'কে কী নামিয়েছে'],
                    ['কাগজ সংরক্ষণ নীতি', 'governance.retention.index', 'কী কতদিন থাকে — কোডে যা ঘটে, তাই'],
                ],
            ],
            [
                'no' => '২৯',
                'title' => 'রিপোর্ট',
                'items' => [
                    ['দৈনিক খতিয়ান', 'accounts.report.show:day-book', null],
                    ['ক্যাশ বই', 'accounts.report.show:cash-book', null],
                    ['ব্যাংক বই', 'accounts.report.show:bank-book', null],
                    ['খতিয়ান', 'accounts.report.show:ledger', null],
                    ['রেওয়ামিল', 'accounts.report.show:trial-balance', null],
                    ['লাভ-ক্ষতি', 'accounts.report.show:profit-loss', null],
                    ['স্থিতিপত্র', 'accounts.report.show:balance-sheet', null],
                    ['নগদ প্রবাহ', 'accounts.report.show:cash-flow', null],
                    ['আদায়ের তালিকা', 'accounts.report.show:inflow', null],
                    ['কোন কেন্দ্রে কত', 'accounts.report.show:by-cost-centre', null],
                    ['উত্তোলনের রিপোর্ট', 'finance.withdrawal.index', 'কে কত নিলেন, মাস ধরে'],
                    ['বাজেটের রিপোর্ট', 'finance.budget.report', null],
                ],
            ],
            [
                'no' => '৩০',
                'title' => 'অ্যানালিটিক্স',
                'items' => [
                    ['ফিন্যান্সের মেট্রিক', null, 'পরের ধাপ — ইঞ্জিন Analytics & BI-তে'],
                ],
            ],
            [
                'no' => '৩১',
                'title' => 'পর্যবেক্ষণ',
                'items' => [
                    ['হিসাবের সততা যাচাই', 'accounts.integrity', null],
                    ['ব্যর্থ পোস্টিংয়ের সারি', 'accounts.control.failed', null],
                    ['পটভূমির কাজ ও সতর্কতা', 'accounts.control.jobs', 'সময়সূচি, সারি, ব্যর্থ কাজ, শেষ ব্যাকআপ'],
                ],
            ],
            [
                'no' => '৩২',
                'title' => 'সেটিংস',
                'items' => [
                    ['হিসাবের সেটিংস', 'accounts.settings', null],
                    ['কন্ট্রোল প্যানেল', 'system_admin.control-panel', 'প্রতিটা ঘরের সুইচ'],
                    ['বিজ্ঞপ্তির সেটিংস', 'notifications.settings', 'কে কোন খবর পাবেন — নিজের পছন্দ, ঘণ্টার ভিতর থেকেই'],
                ],
            ],
            [
                'no' => '৩৩',
                'title' => 'সহায়ক কাজ',
                'items' => [
                    ['আমদানি ও রপ্তানি', 'governance.export.index', null],
                    ['ব্যালেন্স আবার গোনা', 'accounts.integrity', null],
                    ['নম্বর সিরিজ মেলানো', 'accounts.control.numbers', '`abos:catch-up-numbers`-এর পর্দা'],
                    ['ডুপ্লিকেট খোঁজা ও মেরামত', 'accounts.control.duplicates', 'খোঁজা; মেরামত হাতে, জোড়া লাগানো নয়'],
                ],
            ],
        ];
    }

    /**
     * এই লাইনটার ঠিকানা — না থাকলে নাল।
     *
     * ── কেন `:` দিয়ে প্যারামিটার ─────────────────────────────────────
     * রিপোর্টগুলো একটাই রুট, স্লাগ বদলায় (`accounts.report.show:ledger`)।
     * প্রতিটার জন্য আলাদা রুট বানানোর চেয়ে এক জায়গায় লিখে এখানে ভাগ
     * করে নেওয়া সস্তা, আর তালিকাটাও পড়া যায়।
     */
    public static function urlFor(?string $route): ?string
    {
        if ($route === null) {
            return null;
        }

        [$name, $param] = array_pad(explode(':', $route, 2), 2, null);

        if (! Route::has($name)) {
            return null;
        }

        return match (true) {
            $param === null => route($name),
            str_ends_with($name, 'report.show') => route($name, ['slug' => $param]),
            str_ends_with($name, 'voucher.index') => route($name, ['type' => $param]),
            default => route($name, [$param]),
        };
    }

    /**
     * কয়টা হয়েছে, কয়টা বাকি।
     *
     * ── কেন লাইন গোনা হয়, বিভাগ নয় ──────────────────────────────────
     * বিভাগ গুনলে "৩৩-এর ১৬" শোনায় ভালো, কিন্তু একটা বিভাগে দশটা লাইন
     * আর অন্যটায় একটা। লাইন গুনলে সংখ্যাটা সত্যিকারের কাজের অনুপাত
     * বলে — আর ওটাই মালিক জানতে চেয়েছেন।
     *
     * ── "পরের ধাপ", ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
     * মালিক: *"bachai koro kongulo jororin rater modho ses hobe"*। যে লাইন
     * জেনেশুনে পরে রাখা হয়েছে, তার টীকা শুরু হয় [[self::LATER]] দিয়ে, আর
     * সেগুলো আলাদা গোনা হয়। ⛔ মোট থেকে বাদ নয়: তাহলে শতাংশটা মিথ্যা
     * বলত যে কাজ শেষ।
     *
     * @return array{done: int, total: int, later: int}
     */
    public static function tally(): array
    {
        $done = 0;
        $total = 0;
        $later = 0;

        foreach (self::sections() as $section) {
            foreach ($section['items'] as $item) {
                $total++;

                if ($item[1] !== null && self::urlFor($item[1]) !== null) {
                    $done++;
                } elseif (self::isLater($item)) {
                    $later++;
                }
            }
        }

        return ['done' => $done, 'total' => $total, 'later' => $later];
    }

    /** জেনেশুনে পরের ধাপে রাখা লাইন — টীকার শুরু দেখে। */
    public static function isLater(array $item): bool
    {
        return $item[1] === null && is_string($item[2] ?? null) && str_starts_with($item[2], self::LATER);
    }
}
