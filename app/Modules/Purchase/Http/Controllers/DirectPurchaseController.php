<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\NumberSeries;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\LastPaidRate;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * সরাসরি ক্রয় চালান — এক পর্দায় পুরো ঘটনা।
 *
 * বিক্রয়ের DirectSaleController-এর আয়না, শুধু দিকটা উল্টো: ওখানে মাল
 * বেরোয় আর টাকা আসে, এখানে মাল ঢোকে আর টাকা যায়। গড়নটা এক রাখা
 * হয়েছে ইচ্ছাকৃতভাবে — যিনি বিক্রয়ের পর্দা চালাতে জানেন তিনি এটাও
 * জানেন, নতুন করে শিখতে হয় না।
 */
class DirectPurchaseController extends Controller implements HasMiddleware
{
    /**
     * পুরো তালিকা পর্দায় পাঠানোর সীমা।
     *
     * এর বেশি পণ্য হলে ব্রাউজারে ধরানোর চেষ্টাই ভুল — তখন খোঁজাটা
     * সার্ভারে যাওয়াই ঠিক। সীমাটা বিক্রয়ের পর্দার মতোই।
     */
    private const INLINE_CATALOGUE_LIMIT = 2000;

    /**
     * এক অনুরোধে একবারই তোলা পণ্যের তালিকা।
     *
     * @var \Illuminate\Database\Eloquent\Collection<int, Product>|null
     */
    private ?EloquentCollection $products = null;

    public function __construct(
        private readonly DirectPurchaseService $purchases,
        private readonly SettingsService $settings,
        private readonly MenuBuilder $menu,
        private readonly LastPaidRate $lastPaid,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:purchase.bill.create')];
    }

    public function create(Request $request): View
    {
        $warehouse = $this->warehouse($request);

        return view('purchase::direct.index', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => $this->catalogue($warehouse),
            'suppliers' => Supplier::query()->active()->orderBy('name_en')->get(),

            /*
             * ── গুদাম বনাম তাক — দুইটা আলাদা প্রশ্ন ──────────────────
             *
             * মালিকের কথা (৫ সেপ্টেম্বর ২০২৬): *"ডিরেক্ট পারচেসে
             * ওয়্যারহাউস দিয়ে দাও... গোডাউনে যদি আরো ছোটখাটো প্লেসমেন্ট
             * (তাক) থাকে, সে প্লেসমেন্টে করবে। আর যদি শুধুমাত্র ছোট
             * দোকান হয়... একজনই দুইটা করতে পারবেন।"*
             *
             * ⭐ তাই দুইটা স্তর, দুই জায়গায়:
             *
             *   কোন গুদামে          → এখানেই, কাউন্টারে
             *   গুদামের ভিতরে কোথায় → Inventory ▸ Stock Placement
             *
             * ⚠️ মালটা তবু **"বসানো হয়নি"** অবস্থাতেই ঢোকে (`unplaced`,
             * দেখুন [[PurchaseBillService::bringInDirectLines()]]) —
             * অর্থাৎ গুদামে আছে, কিন্তু বিক্রয়যোগ্য নয়। ⓘ পর্দায় কথাটা
             * লেখা আছে, নাহলে কাউন্টারের লোক ভাবতেন মাল তাকে উঠে গেছে।
             *
             * ⓘ ছোট দোকানের জন্য দুইটা ধাপই একজনের — Placement পর্দায়
             * এক ক্লিকেই বসে যায়।
             */
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'warehouse' => $warehouse,
            'moneyAccounts' => $this->moneyAccounts(),

            /*
             * প্যাকের তালিকা — কন্ট্রোল প্যানেলের সুইচের পেছনে।
             *
             * ⓘ ডাকটা হুবহু `components/line-editor.blade.php`-এর, আর
             * সেটাই দরকার: দুই পর্দায় দুই নিয়ম হলে একই পণ্য সরাসরি
             * ক্রয়ে বাক্সে আর বিলে পিসে লিখতে হত।
             *
             * ⚠️ সুইচ বন্ধ থাকলে তালিকাটা খালি, আর পর্দায় একক ঘর দুইটা
             * রেন্ডারই হয় না — কোম্পানি প্যাকে কেনে না, তাই ঘরটাও নেই।
             */
            'packs' => $this->settings->enabled('inventory.pack_entry_enabled')
                ? app(PackConversion::class)->optionsFor($this->products())
                : [],

            /*
             * ⭐ কে মালটা বুঝে নিলেন — মালিকের ছবির `Received by`।
             *
             * ⓘ বাছার কিছু নেই, তাই ড্রপডাউনও নেই: যিনি পর্দাটা খুলেছেন
             * তিনিই বুঝে নিচ্ছেন। ⚠️ বাছতে দিলে একদিন অন্যের নাম বসত,
             * আর "কে বুঝে নিয়েছিল" প্রশ্নের উত্তরটা **ভুল** হত — খালি
             * থাকার চেয়েও খারাপ।
             */
            /*
             * ⓘ নামের সাথে ভূমিকাও — মালিক, ৬ সেপ্টেম্বর ২০২৬:
             * *"Received by: Al-Amin Shuvo (Owner)"*।
             *
             * ⚠️ একই নামে দুইজন থাকতে পারেন, আর *"কে বুঝে নিয়েছিল"*
             * প্রশ্নের উত্তরে ভূমিকাটা পার্থক্য গড়ে দেয়। ⓘ টপবার এই
             * একই নিয়মে লেখে (`getRoleNames()->first()`), তাই দুই
             * জায়গায় একই মানুষ একইভাবে লেখা থাকে।
             */
            'receivedBy' => (function () use ($request): string {
                $user = $request->user();

                if ($user === null) {
                    return '';
                }

                $role = $user->getRoleNames()->first();

                return $role === null
                    ? $user->name
                    : $user->name.' ('.__('core.role.'.$role).')';
            })(),

            /*
             * ── ক্রয় চালানের পরের নম্বর — দেখানোর জন্য, খরচের জন্য নয় ──
             *
             * ⛔ `next()` ডাকলে **পাতা খোলামাত্র একটা নম্বর খরচ হয়ে যেত**,
             * কেউ শুধু দেখে চলে গেলেও। দিনের শেষে সিরিজে ফাঁক, আর নিরীক্ষায়
             * *"৪৭ নম্বর বিলটা কোথায়"* প্রশ্নের কোনো উত্তর নেই।
             *
             * ⭐ `preview()` কেবল পড়ে — তালা নেয় না, কিছু বাড়ায় না। আসল
             * নম্বরটা বসে সংরক্ষণের ট্রানজেকশনের ভেতরে।
             *
             * ⚠️ দুইজন একসাথে কাউন্টার খুললে দুইজনেই একই নম্বর দেখবেন, আর
             * সেটা ঠিক আছে: যিনি আগে সেভ করবেন তিনি ওটা পাবেন, পরেরজন
             * পরেরটা। ⓘ ভুল হত দেখানো নম্বরটাকে **প্রতিশ্রুতি** ভাবলে —
             * ওটা পূর্বাভাস।
             *
             * ⓘ বিক্রয়ের কাউন্টারে এই যন্ত্রটা ৩ সেপ্টেম্বর থেকে চলছে
             * ([[DirectSaleController::invoicePreview()]]); এখানে হুবহু সেটাই।
             */
            'billPreview' => $this->billPreview(),

            /*
             * ── কত দিনের বাকিতে — ছবির `CREDIT PERIOD` ────────────────
             *
             * ⓘ সারি, ধ্রুবক নয়: ক্রেতা নিজের শর্ত যোগ করতে পারবেন
             * (মালিকের স্থায়ী নিয়ম — যে তালিকা গ্রাহকভেদে বদলায় সেটা
             * সেটিংসের সারি)।
             *
             * ⚠️ পর্দা শর্তের আইডি সার্ভারে পাঠায় না, পাঠায় তার ফল —
             * `due_on` তারিখ। ⛔ আইডি রাখলে একদিন কেউ শর্তের দিনসংখ্যা
             * বদলাতেন, আর **গত বছরের বন্ধ বিলগুলোর পরিশোধের তারিখও
             * নীরবে সরে যেত**। ⓘ তারিখটা একটা ঘটনা, শর্তটা একটা নীতি।
             */
            'paymentTerms' => $this->paymentTerms(),

            /*
             * ── ঘরটা কোন মান নিয়ে খোলে ───────────────────────────────
             *
             * মালিকের নির্দেশ: *"by defolt Closing 3day thakbe"*।
             *
             * ⛔ **তিনটা কোডে লেখা নেই**, আর সেটাই মূল কথা: এক গ্রাহকের ৩,
             * আরেকজনের ৭। ⓘ মালিকের স্থায়ী নিয়ম — যে সংখ্যা গ্রাহকভেদে
             * বদলায় সেটা সেটিংসের সারি, কোডের ধ্রুবক নয়।
             *
             * ⚠️ `SettingsService::get()`-এর দ্বিতীয় প্যারামিটারটা তখনই
             * কাজে লাগে যখন কোম্পানি এখনো কিছু বসায়নি — অর্থাৎ ওটা
             * "বাক্স থেকে বের হওয়ার" মান, নিয়ম নয়। ⓘ কেউ Control
             * Panel-এ ৭ বসালে পর্দা পরদিনই ৭ খোলে, কোড না ছুঁয়ে।
             */
            'paymentTermDefault' => $this->paymentTermDefault(),

            /*
             * ── টাকা দেওয়ার উপায়গুলো ─────────────────────────────────
             *
             * তালিকাটা `mdm_payment_methods`-এর সারি, কোডের ধ্রুবক নয় —
             * ক্রেতা নিজের উপায় যোগ করতে পারবেন (মালিকের স্থায়ী নিয়ম)।
             *
             * ⚠️ `kind` ঘরটাই সেতু: পরিশোধের `instrument` ওই মানই নেয়
             * (cash · cheque · bank · mfs)। নতুন সারির `kind` চেনা
             * মানগুলোর একটা না হলে "কীভাবে দেওয়া হলো" ঘরটা খালি যাবে।
             *
             * ⓘ `accountId` থাকলে পর্দা খাতটা আগেই বসিয়ে দেয় — কাউন্টারে
             * প্রতিবার দুইটা ঘর ভরার বদলে একটা।
             */
            /*
             * ── কে মালটা আনল ─────────────────────────────────────────
             *
             * ⚠️ কেবল নাম লেখার একটা ঘর যথেষ্ট নয়। মালিকের কথা:
             * *"পরিবহনকারী মানে মাল আনার খরচ"* — অর্থাৎ ভাড়াটা তার
             * খাতায় দেনা হয়ে জমবে আর মাস শেষে মিটবে। নাম লেখা থাকলে
             * খতিয়ানই দাঁড়ায় না, আর *"এই পরিবহনকারীকে এই মাসে কত
             * দিলাম"* প্রশ্নের উত্তর থাকে না।
             *
             * ⓘ ছাঁকনিটা পক্ষের **ধরনের কোড** ধরে, নাম ধরে নয় — তাই এই
             * ফাইলে কোনো প্রতিষ্ঠানের নাম লেখা নেই, আর কোম্পানি চাইলে
             * ধরনটা নিজে বাড়াতে পারে। বিক্রয়ের দিকেও হুবহু এটাই।
             */
            'carriers' => Supplier::query()
                ->active()
                ->whereHas('partyType', fn ($q) => $q->whereIn('code', ['TRANSPORT']))
                ->orderBy('name_en')
                ->get(['id', 'code', 'name_en', 'name_bn'])
                ->map(fn (Supplier $carrier): array => [
                    'id' => (string) $carrier->id,
                    'label' => $carrier->name(),
                ])
                ->values(),

            'depositMethods' => PaymentMethod::query()
                ->active()
                ->orderBy('code')
                ->get()
                ->map(fn (PaymentMethod $method): array => [
                    'id' => (string) $method->id,
                    'label' => $method->name(),
                    'accountId' => $method->account_id === null ? '' : (string) $method->account_id,
                    'needsReference' => (bool) $method->needs_reference,
                    'kind' => $method->kind,
                ])
                ->values(),

            /*
             * ঘরগুলোর সুইচ — বিক্রয়ের পর্দার মতোই (নিয়ম ৭)।
             *
             * ভ্যাটের চাবিটা master_data-র, purchase-এর নয়: ভ্যাট দেওয়া
             * বা না-দেওয়া পুরো প্রতিষ্ঠানের ব্যাপার, এক মডিউলের নয়।
             */
            'show' => [
                'free_qty' => $this->settings->get('purchase.field_free_qty', true),
                'line_discount' => $this->settings->get('purchase.field_line_discount', true),
                'vat' => $this->settings->get('master_data.tax_enabled', true),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'trx_date' => ['nullable', 'date', 'before_or_equal:today'],

            /*
             * ⭐ যেদিন মাল এল — বিলের তারিখ থেকে আলাদা।
             *
             * ⓘ ঐচ্ছিক, আর খালি হলে সেবা `trx_date` ধরে — অর্থাৎ আজকের
             * প্রতিটা ডাক অবিকল আগের মতো।
             *
             * ⚠️ `before_or_equal:today` এখানেও: ভবিষ্যতে মাল আসা যায় না,
             * আর তারিখটা মজুদের চলাচল ঠিক করে।
             */
            'received_on' => ['nullable', 'date', 'before_or_equal:today'],

            /*
             * ⭐ ক্রয় চালানের নম্বর — পর্দা এটা ভরে পাঠায়।
             *
             * ⓘ নামটা `bill_no`, কিন্তু সেবায় যায় `document_no` হয়ে
             * (নিচে `store()`-এ)। ⚠️ কারণ যাচাইয়ের ভুল-বার্তা ঘরের নামেই
             * খোঁজা হয়, আর পর্দার ঘরটার নাম `bill_no` — দুই নাম এক না
             * রাখলে ডুপ্লিকেট নম্বরের বার্তাটা কোনোদিন দেখা যেত না।
             */
            'bill_no' => ['nullable', 'string', 'max:32'],

            /*
             * ⭐ পরিশোধের শর্ত — পর্দার `Payment Terms`।
             *
             * ⚠️ তালিকাটা `Rule::in()` দিয়ে বাঁধা, আর সেটা ইচ্ছাকৃত:
             * কলামটা ১৬ অক্ষরের, আর যেকোনো লেখা ঢুকতে দিলে একদিন
             * রিপোর্টে `"3 days Cr"` আর `"credit"` দুইটাই বসে থাকত, আর
             * *"COD-তে কত কিনলাম"* প্রশ্নের উত্তর গোনাই যেত না।
             *
             * ⓘ দিনসংখ্যাটা এখানে আসে না — সেটা `due_on` তারিখে অনুবাদ
             * হয়ে যায়, আর তারিখটাই খাতায় থাকে।
             */
            'payment_term' => ['nullable', 'string',
                Rule::in(['cash', 'credit', 'month_end', 'fixed'])],

            'supplier_bill_no' => ['nullable', 'string', 'max:64'],
            'due_on' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:500'],

            /*
             * ── আমদানি চালান — পাঁচটাই ঐচ্ছিক ────────────────────────
             *
             * দেশের ভিতরের ক্রয়ে পাঁচটাই খালি, আর সেটাই স্বাভাবিক।
             * ⓘ কিন্তু NEXUS/ABOS এগারোটা শিল্পের জন্য, আর তার একটা
             * আমদানি-রপ্তানি — ওখানে ব্যাংক ও কাস্টমস **এই নম্বরগুলো
             * ধরেই** কাগজ খোঁজে।
             *
             * ⚠️ `be_date`-এ `before_or_equal:today` নেই, আর সেটা
             * ইচ্ছাকৃত: খালাসের তারিখ কাগজে যা লেখা তা-ই, আর কাগজটা
             * হাতে আসে কয়েক দিন পরে। ⓘ ভবিষ্যতের তারিখ আটকানো আছে
             * `trx_date`-এ, কারণ ওটাই খতিয়ানে যায়।
             */
            'lc_no' => ['nullable', 'string', 'max:64'],
            'be_no' => ['nullable', 'string', 'max:64'],
            'be_date' => ['nullable', 'date'],
            'vessel' => ['nullable', 'string', 'max:120'],
            'port_of_entry' => ['nullable', 'string', 'max:120'],

            /*
             * হাতে হাতে দেওয়া টাকা — ঐচ্ছিক।
             *
             * টাকা দিলে কোন খাত থেকে গেল সেটা বলতেই হবে; নইলে পরিশোধটা
             * কোথা থেকে এল তা খাতায় লেখা থাকত না।
             */
            'paid_now' => ['nullable', 'numeric', 'min:0'],
            'paid_from_account_id' => ['nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
                Rule::requiredIf(fn () => (float) $request->input('paid_now', 0) > 0)],

            /*
             * ── একাধিক জমা ───────────────────────────────────────────
             *
             * বাস্তবে এক বিলের টাকা এক পথে যায় না: কিছু নগদ, বাকিটা
             * চেকে বা bKash-এ। উপরের একক ঘরটা ধরে নিত **একটাই উপায়**,
             * তাই দ্বিতীয় পথটা কোথাও লেখাই হত না।
             *
             * ⓘ উপায়টা `mdm_payment_methods`-এর সারি, কোডের ধ্রুবক নয় —
             * ক্রেতা নিজের উপায় যোগ করতে পারবেন। ⚠️ কিন্তু নতুন সারির
             * `kind` অবশ্যই চেনা মানগুলোর একটা হতে হবে (cash · cheque ·
             * bank · mfs), কারণ পরিশোধের `instrument` ওই মানই নেয় —
             * নাহলে জমাটা নীরবে ব্যর্থ হত।
             *
             * ⚠️ পুরনো `paid_now` ঘরটা রয়ে গেল ইচ্ছে করেই: API, ইমপোর্ট
             * আর সিডার ওটাই পাঠায়। দুইটা একসাথে এলে `deposits` জেতে —
             * বিক্রয়ের দিকেও একই নিয়ম।
             */
            /*
             * ── যে গাড়িটা মাল নিয়ে এল ────────────────────────────────
             *
             * সবগুলোই ঐচ্ছিক: নিজের গাড়িতে মাল এলে ভাড়াও নেই, বাহকও
             * নেই। ⓘ কিন্তু ভাড়া লিখলে **কে আনল সেটা বলা দরকার** —
             * নাহলে টাকাটা কার খাতায় দেনা হবে তা কেউ জানে না, আর
             * পরিবহনকারীর হিসাব কোনোদিন মেলে না।
             *
             * ⚠️ `carrier_name` তবু আলাদা রাখা: একবারের ভাড়া গাড়িকে
             * পক্ষ বানানোর দরকার নেই, আর তখন নামটাই একমাত্র তথ্য।
             */
            'carrier_id' => ['nullable', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId),
                Rule::requiredIf(fn () => (float) $request->input('transport_cost', 0) > 0
                    && blank($request->input('carrier_name')))],
            'carrier_name' => ['nullable', 'string', 'max:120'],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],
            'vehicle_no' => ['nullable', 'string', 'max:40'],
            'driver_name' => ['nullable', 'string', 'max:120'],

            'deposits' => ['nullable', 'array', 'max:20'],
            'deposits.*.amount' => ['required', 'numeric', 'gt:0'],
            'deposits.*.payment_method_id' => ['required', 'integer',
                Rule::exists('mdm_payment_methods', 'id')->where('company_id', $companyId)],
            'deposits.*.account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'deposits.*.reference' => ['nullable', 'string', 'max:64'],
            'deposits.*.ref_date' => ['nullable', 'date'],
            'deposits.*.narration' => ['nullable', 'string', 'max:255'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.free_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax' => ['nullable', 'numeric', 'min:0'],
            'lines.*.sales_price' => ['nullable', 'numeric', 'min:0'],

            /*
             * দামের নীতি — মুক্ত লেখা নয়, তিনটার একটা।
             *
             * ⚠️ `Rule::in` ছাড়া যেকোনো শব্দ কলামে বসত, আর পরে পণ্য
             * বাছার সময় পর্দা সেটা নোঙর ধরে **কিছুই করত না** — নীরবে।
             */
            'lines.*.pricing_anchor' => ['nullable', 'string', Rule::in(['markup', 'margin', 'sales_price'])],
            'lines.*.pricing_pct' => ['nullable', 'numeric'],
            'lines.*.narration' => ['nullable', 'string', 'max:500'],

            /*
             * লট · মেয়াদ · ছাপা দাম — লট ধরা পণ্যে লট নম্বরটা
             * বাধ্যতামূলক, কিন্তু সেটা **এখানে** বলা যায় না: কোন পণ্য
             * লট ধরে তা জানতে পণ্যটা দেখতে হয়।
             *
             * ⓘ শর্তটা তাই সেবা স্তরে ([[BatchService::receive]]), আর
             * সেখানকার বার্তায় পণ্যের নামও থাকে — "কোন সারিতে" প্রশ্নের
             * উত্তরসহ। ⚠️ ক্রয় বিল ও চালানের request দুইটাও হুবহু এই
             * তিনটা নিয়ম ব্যবহার করে; এখানে না থাকায় সরাসরি ক্রয়ের
             * পর্দা দিয়ে লট ধরা পণ্য কেনাই যেত না।
             *
             * ⓘ প্যাকের ঘরটাও এখানে যোগ হলো — কলাম দুইটা
             * (`entered_qty`, `entered_unit_id`) আগে থেকেই আছে, আর বাকি
             * চারটা ক্রয়-সেবা ওগুলো ব্যবহার করে; কেবল এই পর্দাটা
             * কাউকে জিজ্ঞেস করত না।
             */
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.mrp' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],

            /*
             * ⭐ ফ্রি পরিমাণের নিজের একক — মালিকের নকশার চতুর্থ ঘর
             * (`QTY. · UOM · FREE QTY · UOM`)।
             *
             * ⓘ না এলে সার্ভিস লাইনের `unit_id`-ই ধরে, অর্থাৎ আগের
             * আচরণ অবিকল। ⚠️ এলে সংখ্যাটা ওই এককেই মনে রাখা হয়
             * (`entered_free_qty` · `free_unit_id`) — নাহলে "১ কার্টন
             * ফ্রি" কাগজে "১২ পিস" হয়ে ফিরত।
             */
            'lines.*.free_unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],

            /*
             * উপহার — মিল যা সাথে দিয়ে দিল।
             *
             * ⚠️ `lines`-এর মতো `required` নয়। বেশিরভাগ চালানে কোনো
             * উপহার থাকে না, আর বাধ্যতামূলক করলে প্রতিটা সাধারণ ক্রয়
             * আটকে যেত।
             *
             * ⓘ `against_product_id` ঐচ্ছিক: মিল একটা ক্যালেন্ডার বা
             * ছাতাও পাঠাতে পারে যা কোনো নির্দিষ্ট পণ্যের সাথে নয়।
             * বাধ্যতামূলক করলে ক্যাশিয়ার যেকোনো একটা বেছে নিতেন, আর
             * তখন "কোন পণ্যের সাথে এল" প্রশ্নের উত্তরটা **ভুল** হত —
             * খালি থাকার চেয়েও খারাপ।
             */
            'gifts' => ['nullable', 'array'],
            'gifts.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'gifts.*.qty' => ['required', 'numeric', 'gt:0'],
            'gifts.*.unit_id' => ['nullable', 'integer',
                Rule::exists('mdm_units', 'id')->where('company_id', $companyId)],
            'gifts.*.against_product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'gifts.*.remarks' => ['nullable', 'string', 'max:191'],
        ]);

        /*
         * ⓘ পর্দার `bill_no` সেবায় যায় `document_no` হয়ে — দুই নামের
         * সেতুটা এখানেই, আর কেবল এখানেই।
         */
        $data['document_no'] = $data['bill_no'] ?? null;

        $result = $this->purchases->complete($data, $data['lines'], $data['gifts'] ?? []);

        return redirect()
            ->route('purchase.bill.show', $result['bill']->id)
            ->with('saved', __('purchase::message.direct_done', [
                'no' => $result['bill']->document_no,

                /*
                 * ⭐ সংখ্যা, বাক্য নয়।
                 *
                 * ⓘ *"৩০টা বসানোর অপেক্ষায়"* পড়ে মানুষ ক্লিক করেন;
                 * *"মাল ঢোকে বসানো হয়নি অবস্থায়"* পড়ে কেউ কিছু করেন না।
                 *
                 * ⚠️ ফ্রি পরিমাণও গোনা হয় — ওটাও একই লরিতে এসেছে আর
                 * ওটাও তাকে ওঠেনি। ⛔ বাদ দিলে সংখ্যাটা কম বলত, আর
                 * বসানোর পর্দায় গিয়ে মানুষ বেশি মাল দেখে অবাক হতেন।
                 *
                 * ⓘ লেজের শূন্যগুলো ছেঁটে ফেলা হয়: বাক্যটা মানুষ পড়ে,
                 * আর মানুষ *"১২"* লেখে, *"12.0000"* নয়। ⚠️ ভগ্নাংশ
                 * থাকলে সেটা থাকে — ২.৫ কেজি ২.৫-ই।
                 */
                'qty' => $this->plainQty($result['bill']->lines->reduce(
                    fn (string $sum, $line) => bcadd(
                        bcadd($sum, (string) $line->qty, 4),
                        (string) $line->free_qty,
                        4,
                    ),
                    '0',
                )),
            ]));
    }

    /**
     * এই সরবরাহকারীর কাছ থেকে গতবারের দরগুলো।
     *
     * ── কেন পাতার সাথে যায় না ───────────────────────────────────────
     * সরবরাহকারী বাছা হয় পাতা খোলার পরে, আর বাছাই বদলালে পুরো তালিকাটা
     * বদলে যায়। পাতার সাথে পাঠাতে হলে **সব** সরবরাহকারীর **সব** পণ্যের
     * দর পাঠাতে হত — হাজার হাজার সারি, যার একজনেরটা ছাড়া বাকি সব
     * অপ্রয়োজনীয়।
     *
     * ── ⚠️ সরবরাহকারীটা এই কোম্পানিরই কি না ─────────────────────────
     * রুট-বাঁধাই আইডি ধরে তুলে আনে, আর আইডিটা আসে ঠিকানা থেকে।
     * `Supplier` কোম্পানির ছাঁকনির নিচে থাকলেও প্রশ্নটা এখানে হাতে
     * দেখা হয়, কারণ **নীরব ভুলটা বেশি খরচের**: অন্য কোম্পানির দর দেখে
     * ফেলা মানে ব্যবসার গোপন কথা ফাঁস, আর সেটা কোনো ত্রুটি ছাড়াই ঘটত।
     */
    public function lastRates(Supplier $supplier): JsonResponse
    {
        abort_if($supplier->company_id !== CompanyContext::id(), 404);

        return response()->json([
            'rates' => $this->lastPaid->forSupplier((int) $supplier->id),

            /*
             * ⭐ ── আগের বকেয়া — এই দরজা দিয়েই, নতুন কোনোটা নয় ──────────
             *
             * পর্দাটা সরবরাহকারী বাছলেই এটাকে ডাকে, **আর যাচাই ব্যর্থ হয়ে
             * পাতা ফিরে এলেও** (`init()`-এ আবার ডাকা হয়)। ⓘ অর্থাৎ
             * দুইটা প্রবেশপথই আগে থেকে ঢাকা — নতুন একটা endpoint বানালে
             * দ্বিতীয়টা ঢাকতে ভুলে যাওয়ার সম্ভাবনা ছিল।
             *
             * ⚠️ **তালিকার সাথে পাঠানো হয় না, আর সেটা ইচ্ছাকৃত:** পাতা
             * খোলার সময় প্রতিটা সরবরাহকারীর বকেয়া গুনলে দুই হাজার সারিতে
             * দুই হাজার হিসাব হত, অথচ ব্যবহারকারী একজনকেই বাছেন।
             *
             * ⛔ **সংখ্যাটা "আজ পর্যন্ত", আর এই পর্দায় সেটাই "আগের
             * বকেয়া"** — কারণ এখানে কেবল নতুন বিল হয় (`create`/`store`),
             * আর খসড়া বিল খতিয়ানে বসেই না। ⚠️ যেদিন এই পর্দায় পুরনো বিল
             * সম্পাদনা করা যাবে, সেদিন `payable($upto)` লাগবে — নাহলে
             * বিলটা নিজেকে গুনত আর `DUE` দ্বিগুণ দেখাত।
             *
             * ⓘ ঋণাত্মক মানে অগ্রিম — পর্দা লেবেলটাই বদলে দেয়।
             */
            'due' => (float) $supplier->payable(),
        ]);
    }

    private function warehouse(Request $request): ?Warehouse
    {
        $chosen = $request->integer('warehouse_id');

        /*
         * ── কোনটা আগে থেকে বসানো থাকবে ──────────────────────────────
         *
         * মালিকের কথা (৫ সেপ্টেম্বর ২০২৬): *"warehouse by defolt purches e
         * boslo, placement se approval er por dekhe dekhe korlo"* —
         * অর্থাৎ ঘরটা ভরা অবস্থায় খোলে, আর কাউন্টারে প্রতিবার একটা
         * ড্রপডাউন কম খুলতে হয়। ⓘ ডিফল্ট মানে **প্রস্তাব**, তালা নয় —
         * ব্যবহারকারী বদলাতে পারেন।
         *
         * ⛔ ডিফল্ট বসানো না থাকলে **নীরবে প্রথম গুদামটা নেওয়া হয় না**,
         * আর এখানে আগে ঠিক সেটাই হত (`orderBy('code')->first()`)। ⚠️ ওই
         * নীরবতার দাম: মাল ভুল গুদামে বসত, কোনো ত্রুটি ছাড়াই, আর ধরা
         * পড়ত মাস শেষে মজুদ মেলানোর সময় — যখন আর কোন চালানটা ভুল ছিল
         * তা বলা যায় না। ⓘ এখন ঘরটা খালি থাকে আর পর্দা কারণসহ বলে
         * কোথায় গিয়ে ঠিক করতে হবে।
         */
        return $chosen > 0
            ? Warehouse::query()->find($chosen)
            : Warehouse::query()->active()->where('is_default', true)->first();
    }

    /**
     * পর্দার পণ্য তালিকা — মজুদ ও শেষ দর সহ।
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalogue(?Warehouse $warehouse): array
    {
        return $this->products()
            ->map(fn (Product $p) => $this->purchases->stockPanel($p, $warehouse))
            ->all();
    }

    /**
     * পণ্যের মডেলগুলো — এক পাতায় একবারই।
     *
     * ── কেন আলাদা একটা পদ্ধতি ও মনে রাখা ────────────────────────────
     * একই তালিকা দুইজন চায়: `catalogue()` (পর্দার সারি) আর প্যাকের
     * তালিকা (`PackConversion::optionsFor`)। ⛔ দুইবার তুললে দুই হাজার
     * সারি দুইবার আসত, আর দ্বিতীয়বারের `unit`/`tax` আবার জোড়া লাগত।
     *
     * ⚠️ `with(['unit', 'tax'])` — `preventLazyLoading` চালু, তাই এখানে
     * না চাইলে `stockPanel()` পর্দাটাই ভাঙত। ⓘ দুইটাই পর্দার নতুন
     * ঘরগুলোর জন্য: একক না বাছলে পণ্যের নিজের এককের নাম লেখা হয়, আর
     * "Per product" ভ্যাট ধরনটা হার ছাড়া কষতেই পারত না।
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Product>
     */
    private function products(): EloquentCollection
    {
        return $this->products ??= Product::query()
            ->active()
            ->with(['unit', 'tax'])
            ->orderBy('name_en')
            ->limit(self::INLINE_CATALOGUE_LIMIT)
            ->get();
    }

    /**
     * পরিশোধের শর্তের তালিকা — মালিকের `Payment Terms`।
     *
     * ── ⚠️ কোনটা কোডে, কোনটা সারিতে ─────────────────────────────────
     * চারটা **আচরণ** কোডে: নগদ · COD · মাসের শেষ · নিজের তারিখ।
     * ⓘ ওগুলো তালিকা নয়, নিয়ম — প্রতিটা ব্যবসায় একই অর্থ বহন করে।
     *
     * ⭐ যেটা গ্রাহকভেদে বদলায় সেটা **দিনসংখ্যাগুলো**, আর ওগুলো আগে
     * থেকেই সারি (`mdm_payment_terms`)। ⚠️ এক গ্রাহকের ৫ দিন,
     * আরেকজনের ৪৫ — কোড না ছুঁয়ে।
     *
     * ⛔ `Date Range` তালিকায় নেই, আর সেটা ইচ্ছাকৃত: একটা পাওনার
     * **একটাই দেয় তারিখ**। ⓘ রেঞ্জ হলে বকেয়ার বয়সের রিপোর্ট বিলটাকে
     * কোনো ঘরে ফেলতে পারত না।
     *
     * ── ⓘ মানের ছাঁচ ────────────────────────────────────────────────
     * `cash` · `credit:7` · `month_end` · `fixed` — কোলনের পরের
     * সংখ্যাটা কেবল `credit`-এ, আর পর্দাই ওটা তারিখে অনুবাদ করে।
     *
     * @return list<array{value: string, label: string}>
     */
    private function paymentTerms(): array
    {
        $terms = [
            /*
             * ⛔ এখানে একটা `cod` ছিল — ৫ সেপ্টেম্বর ২০২৬ তুলে দেওয়া।
             *
             * মালিক: *"cod to purches e lagena sekane keno dile"*।
             *
             * ⚠️ COD মানে "মাল পৌঁছালে টাকা", অথচ **সরাসরি ক্রয়ের কাগজ
             * লেখাই হয় মাল পৌঁছানোর পরে**। ⓘ যে ঘটনার অপেক্ষায় শর্তটা,
             * সেটা ইতিমধ্যেই ঘটে গেছে — তাই ওটা নগদেরই দ্বিতীয় নাম ছিল।
             *
             * ⭐ শব্দটা অর্থহীন নয়, **এই কাগজে** অর্থহীন। ক্রয়াদেশে ওটা
             * সত্যি (মাল তখনো আসেনি), আর বিক্রয়েও সত্যি (মাল ভ্যানে
             * যায়, টাকা ফেরে) — সেখানে ওটা আছে।
             */
            ['value' => 'cash', 'label' => __('purchase::field.term_cash')],
        ];

        foreach ($this->creditTerms() as $term) {
            if ($term['days'] <= 0) {
                continue;
            }

            $terms[] = [
                'value' => 'credit:'.$term['days'],
                'label' => __('purchase::field.term_credit', ['count' => $term['days']]),
            ];
        }

        $terms[] = ['value' => 'month_end', 'label' => __('purchase::field.term_month_end')];
        $terms[] = ['value' => 'fixed', 'label' => __('purchase::field.term_fixed')];

        return $terms;
    }

    /**
     * পর্দা খোলার সময় কোনটা বাছা থাকবে।
     *
     * মালিক: *"by defolt Closing 3day thakbe"* — কিন্তু তিনটা **কোডে
     * নেই**, কোম্পানির সেটিংসে (`purchase.default_credit_days`)।
     *
     * ⚠️ ঐ দিনসংখ্যার কোনো শর্ত না থাকলে `creditTerms()` নিজেই সারিটা
     * বসিয়ে দেয়, তাই বিকল্পটা সবসময় পাওয়া যায়। ⓘ তবু শূন্য বা
     * ঋণাত্মক হলে নগদে ফেরা — একটা অচেনা মান পর্দায় পাঠানোর চেয়ে ভালো।
     */
    private function paymentTermDefault(): string
    {
        $days = (int) $this->settings->get('purchase.default_credit_days', 3);

        return $days > 0 ? 'credit:'.$days : 'cash';
    }

    /**
     * বাকির মেয়াদের তালিকা — দিনের সংখ্যা ধরে।
     *
     * ── কেন মান হিসেবে `days`, `id` নয় ──────────────────────────────
     * ⚠️ পর্দা শর্তের আইডি সার্ভারে পাঠায় না, পাঠায় তার **ফল** —
     * `due_on` তারিখ। ⛔ আইডি রাখলে একদিন কেউ শর্তের দিনসংখ্যা বদলাতেন,
     * আর গত বছরের বন্ধ বিলগুলোর পরিশোধের তারিখও নীরবে সরে যেত।
     * ⓘ তারিখটা একটা ঘটনা, শর্তটা একটা নীতি — খাতায় ঘটনাটাই থাকে।
     *
     * ── ⚠️ ডিফল্টের সংখ্যাটা তালিকায় না থাকলে ───────────────────────
     * কোম্পানির ডিফল্ট ৩ দিন, অথচ শর্তের তালিকায় ৩ দিনের কোনো সারি
     * নেই — এটা প্রথম দিনেই ঘটবে। ⛔ তখন ঘরটা খালি খুলত, আর মালিকের
     * *"by defolt 3 day"* কথাটা মিথ্যা হত।
     *
     * ⭐ তাই সংখ্যাটা তালিকায় বসিয়ে দেওয়া হয়, নিজের নামে ("৩ দিন")।
     * ⓘ এটা শর্তের সারি বানানো নয় — মেয়াদটা শেষ পর্যন্ত একটা সংখ্যা,
     * আর সংখ্যাটা কোম্পানি নিজেই বসিয়েছে।
     *
     * @return list<array{label: string, days: int}>
     */
    private function creditTerms(): array
    {
        $terms = PaymentTerm::query()
            ->active()
            ->orderBy('days')
            ->get()
            ->map(fn (PaymentTerm $term): array => [
                'label' => $term->name(),
                'days' => (int) $term->days,
            ])
            ->values()
            ->all();

        $default = (int) $this->settings->get('purchase.default_credit_days', 3);

        if ($default > 0 && ! in_array($default, array_column($terms, 'days'), true)) {
            $terms[] = ['label' => __('purchase::field.credit_days', ['count' => $default]), 'days' => $default];

            usort($terms, fn (array $a, array $b) => $a['days'] <=> $b['days']);
        }

        return $terms;
    }

    /** সিরিজের পরের নম্বর, কেবল দেখানোর জন্য — [[NumberSeriesEngine::preview()]]. */
    private function billPreview(): string
    {
        $series = NumberSeries::query()
            ->where('company_id', CompanyContext::id())
            ->where('doc_type', 'PBL')
            ->where('is_active', true)
            /* ⓘ শাখার নিজস্ব সিরিজ থাকলে সেটাই আগে; না থাকলে কোম্পানির। */
            ->orderByRaw('branch_id IS NULL')
            ->first();

        return $series === null ? '' : app(NumberSeriesEngine::class)->preview($series);
    }

    /**
     * সংখ্যাটা মানুষের মতো করে লেখা — ১২, `12.0000` নয়।
     *
     * ⓘ ভেতরে সব হিসাব চার দশমিকে হয়, আর সেটাই ঠিক; ⚠️ কিন্তু ঐ চারটা
     * শূন্য বাক্যের মধ্যে ঢুকলে বার্তাটা যন্ত্রের ভাষা হয়ে যায়।
     *
     * ⭐ ভগ্নাংশ থাকলে থাকে — ২.৫ কেজি ২.৫-ই, ২.৫০০০ নয় আবার ৩-ও নয়।
     */
    private function plainQty(string $qty): string
    {
        return str_contains($qty, '.') ? rtrim(rtrim($qty, '0'), '.') : $qty;
    }

    /** নগদ ও ব্যাংক — টাকাটা কোথা থেকে গেল। */
    private function moneyAccounts()
    {
        $heads = Account::query()->postable()
            ->whereIn('code', StandardChart::MONEY_PARENTS)
            ->pluck('id');

        return Account::query()
            ->where(fn ($q) => $q->whereIn('parent_id', $heads)->orWhereIn('id', $heads))
            ->where('is_group', false)
            ->orderBy('code')
            /*
             * ⓘ মা-টা সাথেই আসে: জমার প্যানেল উপায় অনুযায়ী খাত ছাঁকে, আর
             * ছাঁকনিটা মায়ের কোড দেখে (১১০১ নগদ · ১১০২ ব্যাংক · ১১০৫ MFS)।
             * ⚠️ `preventLazyLoading` চালু, তাই এটা না আনলে পর্দাটা ভাঙত।
             */
            ->with('parent:id,code')
            ->get();
    }
}
