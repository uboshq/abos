<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Contracts\RecipeBook;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\NumberSeries;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\FreeAllowance;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\TransferMode;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Sales\Http\Requests\DirectSaleRules;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CounterSaleSources;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SaleNumber;
use App\Modules\Sales\Services\MarginGuard;
use App\Modules\Sales\Services\SaleEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * সরাসরি বিক্রয়ের পর্দা — নমুনা অনুযায়ী চারটা অংশ।
 *
 *     ১. এন্ট্রি স্ট্রিপ  — পণ্য খোঁজা, লাইভ মজুদ, পরিমাণ ও দর, কার্টে যোগ
 *     ২. ডকুমেন্ট হেডার  — গুদাম, তারিখ, বাকির মেয়াদ, DO নম্বর, ক্রেতা
 *     ৩. কার্ট           — পণ্যের সারি, আর তার নিচে আলাদা উপহারের সারি
 *     ৪. টোটাল প্যানেল   — মোট থেকে বকেয়া পর্যন্ত, নমুনার ক্রমেই
 *
 * ── কেন পুরো তালিকা পাতার সাথে ───────────────────────────────────────
 * POS-এর মতোই: প্রতিটা অক্ষরে সার্ভারে গেলে কাউন্টারে দেরি হয়, আর ইন্টারনেট
 * গেলে বিক্রিই বন্ধ। এখানে প্রতিটা পণ্যের সাথে ছয়টা মজুদ সংখ্যাও যায়,
 * কারণ নমুনা দাবি করে সেগুলো পণ্য বাছার সাথে সাথেই দেখা যাবে।
 */
class DirectSaleController extends Controller implements HasMiddleware
{
    use GrandTotals;

    /** এর বেশি পণ্য হলে পাতার সাথে পাঠানো বন্ধ, সার্ভারে খোঁজা শুরু। */
    private const INLINE_CATALOGUE_LIMIT = 2000;

    public function __construct(
        private readonly DirectSaleService $sales,
        private readonly SettingsService $settings,
        private readonly MenuBuilder $menu,
        private readonly RecipeBook $recipes,
        private readonly CreditExposure $credit,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.challan.create')];
    }

    public function create(Request $request): View|RedirectResponse
    {
        /*
         * ⭐ ডিপোর যাচাই থেকে — `?source=do&source_id=12` (বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬, ধাপ ঙ)। ⓘ উৎসের
         * সারি আগে থেকে ভরা, লট কাউন্টারে বাছা ([[sourceScreen()]]); ⛔ না পেলে ৪০৪ — দেয়াল মডেলের নিজের স্কোপে।
         */
        $fromSource = null;

        if ($request->filled('source')) {
            $fromSource = $this->openFromSource($request);

            if ($fromSource instanceof RedirectResponse) {
                return $fromSource;
            }
        }

        // ⓘ উৎস নিজের গুদাম বললে সেটাই — পর্দা অন্য গুদাম না চাইলে
        $sourceWarehouse = $fromSource['screen']['warehouse_id'] ?? null;
        $warehouse = $sourceWarehouse !== null && ! $request->filled('warehouse_id')
            ? (Warehouse::query()->find($sourceWarehouse) ?? $this->warehouse($request))
            : $this->warehouse($request);
        /*
         * withOutstanding() — নিচের customerTerms-এর জন্য, আর কারণটা গোনার।
         *
         * প্রতিটা গ্রাহকের বকেয়া ওখানে চাওয়া হয়। স্কোপটা না দিলে
         * outstanding() নিজে থেকে খাতা খুঁজত — গ্রাহকপ্রতি একটা কোয়েরি।
         * ছয়জনের ডেমোতে সেটা চোখে পড়ে না, তিন হাজার গ্রাহকের ডিপোতে
         * কাউন্টারের পাতা খোলা মানেই তিন হাজার কোয়েরি।
         */
        /*
         * `with('location')` — নাহলে উপরের লকআপে এলাকার নাম চাইতে গিয়ে
         * গ্রাহকপ্রতি একটা করে কোয়েরি হত। ঠিক যে N+1-টা `withOutstanding()`
         * দিয়ে বন্ধ করা হয়েছিল, সেটাই পাশের দরজা দিয়ে ফিরে আসত।
         */
        $customers = Customer::query()->inViewedBranch()->active()->with('location')
            ->withOutstanding()->orderBy('name_en')->get();

        /*
         * ⭐ খাতার বাইরে আটকে থাকা টাকা — বিল না হওয়া ডিও আর খসড়া বিল।
         *
         * ⓘ মালিক, ২৬ সেপ্টেম্বর ২০২৬: মাল বেরোলেই আর খসড়া হলেই সীমা আটকায়।
         * ⚠️ পর্দা কেবল `due` জানলে অবশিষ্ট সীমা বেশি দেখাত, বিক্রেতা পুরো
         * কার্ট তুলতেন, আর সেবা শেষে আটকাত। ⓘ দলবদ্ধ দুই কোয়েরি
         * ([[CreditExposure::pendingFor()]]) — গ্রাহকপ্রতি নয়।
         */
        $held = $this->credit->pendingFor($customers->pluck('id')->map(fn ($id) => (int) $id)->all());

        /*
         * ⭐ খোলা খসড়া নিজেকে আটকায় না — মালিক, ৪ অক্টোবর ২০২৬ (DRF-0014, M/S Bokthiyar: অগ্রিম ৪৪,৫৮৯.৫৫, বিল
         * ৪৪,৫০৩.৭৩, তবু "অবশিষ্ট সীমা ৳85.82, বিল ৳44,417.91 বেশি")। খসড়াটা `held`-এ থাকে, আর পর্দায় খুললে একই বিল
         * কার্টেও — তাই দুবার গোনা হত। ⓘ সেবা আর সারাংশ খসড়াটা বাদ দিয়ে মাপে ([[DirectSaleOverview]]
         * `withoutTheDraftBeingFinished()`), পর্দাও এখন তা-ই।
         */
        $finishing = $request->integer('draft') > 0
            ? SalesInvoice::query()->whereKey($request->integer('draft'))
                ->where('status', \App\Core\Support\DocumentStatus::DRAFT)->first(['id', 'customer_id'])
            : null;
        $finishingFor = $finishing === null ? null : $customers->firstWhere('id', (int) $finishing->customer_id);

        if ($finishingFor !== null) {
            $held[(int) $finishingFor->id] = (float) $this->credit->pending($finishingFor, (int) $finishing->id);
        }

        /*
         * ⭐ সম্পাদনার বিল ক্রেতার বকেয়া থেকে বাদ — মালিক, ৪ অক্টোবর ২০২৬ (INV-0002: "বিলের টাকা জমা আছে, তবু আটকাচ্ছে")।
         * ⓘ সম্পাদনা পুরনো বিল উল্টে নতুন বসায় ([[SaleEditor]]); পুরনোটা তখনো খাতায়, তাই পর্দার সীমার হিসাব
         * ([[direct-sale.js]] `creditLeft`) একই বিল দুবার গুনে ৪৭,৭০৬ টাকা "সীমা পার" দেখাত, অথচ সেবা পার হতে দিত।
         */
        $editing = $request->integer('edit') > 0
            ? SalesInvoice::query()->whereKey($request->integer('edit'))
                ->where('status', \App\Core\Support\DocumentStatus::CONFIRMED)->first(['id', 'customer_id', 'total'])
            : null;

        // শীট আর প্যাকের ড্রপডাউন — একই তালিকা, তাই একবারই তোলা
        $sheetProducts = Product::query()->soldInViewedBranch()->active()->with('unit')->orderBy('name_en')->get();

        $catalogue = $this->catalogue($warehouse);

        return view('sales::direct.index', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => $catalogue,

            /*
             * ⭐ কার্টের সারির মার্জিন — NEXUS §৩২ ([[MarginGuard::screen()]])। ⛔ খরচের চাবি
             * না থাকলে খরচের তালিকা খালি যায় — পাতার উৎসেও খরচ থাকে না।
             */
            'margin' => app(MarginGuard::class)->screen(
                $request->user(),
                // ⚠️ ক্যাটালগের সারি মডেল নয় (stdClass) — খরচের সিঁড়ি পণ্যের মডেল চায়
                Product::query()->whereIn('id', collect($catalogue)->pluck('id'))->get(),
                // ⓘ ফ্রি ভাণ্ডার গুদাম ধরে — সিদ্ধান্ত "ক", ৪ অক্টোবর ২০২৬
                $warehouse,
            ),

            /*
             * ⭐ লট ধরা পণ্যের লটগুলো — পণ্যের আইডি ধরে, মেয়াদের ক্রমে।
             *
             * ⓘ তালিকাটা একবারই যায়, ঠিক পণ্যের তালিকার মতো — ⚠️ পণ্য
             * বাছার পর আলাদা অনুরোধ পাঠালে কাউন্টারে প্রতিটা সারিতে
             * একটা করে অপেক্ষা যোগ হত।
             */
            'lots' => $this->lotsFor($warehouse),

            /*
             * ── প্যাকের একক — "২ বাক্স @ ৮০০" ─────────────────────────
             *
             * ── কী বাদ পড়েছিল (মাপা ৩ সেপ্টেম্বর ২০২৬) ─────────────────
             * প্যাক-এন্ট্রির পুরো ইঞ্জিন আগে থেকেই ছিল — বাক্স থেকে পিসে
             * পরিমাণ ও **দর দুইটাই** নামে ([[PackConversion]]), কন্ট্রোল
             * প্যানেলে সুইচ আছে, আর **ছয়টা ফর্মে ড্রপডাউনটা চলছেও**।
             *
             * ⚠️ **কেবল সরাসরি বিক্রয়ের পর্দাটাই বাদ পড়েছিল।** ওখানে
             * এককের ঘরটা ছিল পড়ার-জন্য লেখা, পণ্যের নিজের একক দেখাত।
             * ফলে কাউন্টারে দাঁড়িয়ে **"২ বাক্স" লেখার কোনো উপায় ছিল না** —
             * বিক্রেতাকে মাথায় গুণে "২০০ পিস" লিখতে হত, আর দরটাও নিজে
             * ভাগ করে বসাতে হত। ⚠️ ওখানেই ভুল হওয়ার আসল জায়গা।
             *
             * ── কেন সুইচের পেছনে ────────────────────────────────────────
             * যে ব্যবসা এক এককেই বেচে, তার প্রতিটা সারিতে একটা বাড়তি
             * ড্রপডাউন কেবল টাইপিং বাড়াত। সুইচ বন্ধ থাকলে খালি অ্যারে
             * যায়, আর ঘরটা আগের মতোই পড়ার-জন্য থাকে।
             *
             * ⓘ `optionsFor()` **সব পণ্যের জন্য একবারে** তোলে — পণ্যপ্রতি
             * একটা করে কোয়েরি নয়। দুই হাজার পণ্যের গুদামে ওটাই পার্থক্য।
             */
            'packs' => $this->settings->enabled('inventory.pack_entry_enabled')
                ? app(PackConversion::class)->optionsFor($sheetProducts)
                : [],

            /*
             * ── জমা নেওয়ার উপায়, আর টাকাটা কোন খাতে বসবে ───────────────
             *
             * মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"Add deposit-এ Ref
             * Date, Payment Method, into, Amount, Narration … Cash, MFS,
             * Bank … একাধিক payment add করতে পারবে"*।
             *
             * ⚠️ **আজ পর্যন্ত যা হচ্ছিল, আর কেন ওটা টাকার ভুল:** ফর্মে
             * খাতের কোনো ঘরই ছিল না, কন্ট্রোলার `account_id` নিতই না, আর
             * `CollectionService` খালি পেলে **প্রধান টিলের নগদ খাত** ধরে
             * নেয়। ফলে গ্রাহক বিকাশে দিলেও **খাতা বলত নগদ** — বিলের গায়ে
             * `deposit_method` লেখা থাকত বটে, কিন্তু ওটা স্রেফ একটা শব্দ,
             * খাত নয়। মাস শেষে বিকাশের ব্যালেন্স মিলত না, আর কেন মিলছে
             * না তা কোথাও লেখা থাকত না।
             *
             * ── কেন দুইটা তালিকা, একটা নয় ──────────────────────────────
             * উপায়ের সারিটা নিজের খাত বহন করে (`mdm_payment_methods.
             * account_id`), তাই বেশিরভাগ সময় খাত বাছার দরকারই নেই — উপায়
             * বাছলেই খাত বসে যায়।
             *
             * কিন্তু **এক উপায়ের একাধিক খাত থাকতে পারে**: "ব্যাংক" উপায়ে
             * তিনটা ব্যাংক হিসাব। তাই খাতের ঘরটাও আছে, আগে থেকে ভরা —
             * বদলাতে হলে বদলানো যায়।
             *
             * ⓘ শুধু **সন্তান** খাত, মাথা নয়: গ্রুপ খাতে টাকা বসে না
             * (`CollectionService::resolveMoneyAccount` ওটা ফিরিয়ে দেয়),
             * আর তিনটা মাথাই — নগদ · ব্যাংক · মোবাইল মানি।
             */
            // ⓘ ফোনের কাউন্টারের সাথে একই তালিকা ([[DirectSaleOptions::depositMethods()]], ৪ অক্টোবর ২০২৬)
            'depositMethods' => app(\App\Modules\Sales\Services\DirectSaleOptions::class)->depositMethods(),

            /*
             * ── বাহকের তালিকা — পরিবহনকারী ও ভাড়ার গাড়ি ────────────────
             *
             * ⚠️ এতদিন পর্দায় কেবল **নাম লেখার একটা ঘর** ছিল, তাই
             * `sal_challans.carrier_id` কখনো বসতই না — আর তখন ভাড়ার
             * দাখিলাটা কোনো পক্ষ পেত না।
             *
             * ⭐ কিন্তু মালিকের চাওয়া ঠিক তার উল্টো: *"transporter-এর সাথে
             * হিসাব হবে"* — অর্থাৎ ভাড়াটা তার খাতায় **পাওনা** হয়ে জমবে,
             * মাস শেষে মিটবে। নাম লেখা থাকলে খতিয়ানই দাঁড়ায় না।
             *
             * ⓘ ছাঁকনিটা পক্ষের **ধরন** ধরে — পরিবহনকারী ও ভাড়ার গাড়ি।
             * দুইটাই সেটিংসের সারি, তাই কোডে কোনো নাম লেখা নেই: কোড দিয়ে
             * খোঁজা হয়, আর কোম্পানি চাইলে আরও ধরন যোগ করতে পারে।
             */
            // ⓘ ফোনের কাউন্টারের সাথে একই তালিকা ([[DirectSaleOptions::carriers()]], ৪ অক্টোবর ২০২৬)
            'carriers' => app(\App\Modules\Sales\Services\DirectSaleOptions::class)->carriers(),

            /*
             * ── চালকের পরামর্শ — একবার লিখলে পরের বার আসে ─────────────
             *
             * মালিকের ছবি, ২৭ সেপ্টেম্বর ২০২৬ (রাত): *"চালকের নাম & Mobile no
             * ekbar save korle porbortite sajest korbe"*।
             *
             * ⓘ আলাদা তালিকা নয় — এই কোম্পানির আগের চালান (নতুনটা আগে) আর
             * গাড়ির মাস্টার। ⚠️ দুইটাই কোম্পানির স্কোপে, তাই অন্য কোম্পানির
             * চালক কখনো আসে না।
             */
            'drivers' => $this->driverSuggestions(),

            // ⭐ অন্য শাখার টিলের খাত বাদ (৩০ সেপ্টেম্বর ২০২৬) — [[Account::scopeNotAnotherBranchsTill()]]
            // ⓘ ফোনের কাউন্টারের সাথে একই তালিকা — অন্যের নগদ বাক্স আর অন্য শাখার টিল বাদ ([[DirectSaleOptions::moneyAccounts()]])
            'moneyAccounts' => collect(app(\App\Modules\Sales\Services\DirectSaleOptions::class)->moneyAccounts()),

            /*
             * চার্ট / বাল্ক DO-র শীটের জন্য — আসল পণ্য ও তাদের মজুদ।
             *
             * ── কেন উপরের `catalogue()`-টা এখানে চলে না ──────────────
             * ওটা কাউন্টারের স্ট্রিপের জন্য বানানো সাদামাটা অবজেক্ট,
             * আর শীটটা মডেল চায় (`$product->name()`, `unit`,
             * `sale_price`)। দুইটার একটাকে অন্যটার মতো সাজানোর চেয়ে
             * দুইটাই নিজের জিনিস পাঠানো সস্তা — আর শীটটা চালানের
             * ফর্মেও ঠিক এই দুইটাই পায়, তাই একই কম্পোনেন্ট দুই পর্দায়
             * একই আচরণ করে।
             */
            'sheetProducts' => $sheetProducts,
            'sheetStock' => app(StockService::class)->statesForAll($warehouse),
            'customers' => $customers,
            /*
             * পরিচয়ের ঘরগুলোও যায় — শুধু শর্ত নয় (২ সেপ্টেম্বর ২০২৬)।
             *
             * ── কেন ─────────────────────────────────────────────────
             * ক্রেতা এখন একটা ড্রপডাউনের এক লাইন নয়, একটা **পরিচয়ের
             * খণ্ড**: নাম, এলাকার চিপ, ফোন, ঠিকানা। মালিকের পাঠানো
             * NEXUS-এর নমুনা ঠিক তাই, আর কারণটা কাউন্টারের কাজেই —
             * মাল ছাড়ার আগে যিনি দাঁড়িয়ে আছেন তাঁর দোকানটা চেনা
             * দরকার, শুধু নামটা নয়।
             *
             * ঘরগুলো এখানেই বসে, ব্রাউজারে আলাদা করে খোঁজা হয় না:
             * তালিকাটা একবারই যায়, আর ক্রেতা বদলালে নতুন কোনো
             * অনুরোধ লাগে না।
             *
             * `location` — এলাকার নামটা, আইডি নয়। NEXUS-এ একবার কাঁচা
             * UUID ছাপা হয়েছিল ওই চিপে, আর একটা চিপ যেটা বলতে পারে না
             * দোকানটা কোথায়, সেটা জায়গাটুকুরও যোগ্য নয়।
             */
            'customerTerms' => $customers->mapWithKeys(fn (Customer $c) => [$c->id => [
                'limit' => (float) $c->credit_limit,
                'due' => (float) $c->outstanding()
                    - ($editing !== null && (int) $editing->customer_id === (int) $c->id ? (float) $editing->total : 0),
                'held' => (float) ($held[(int) $c->id] ?? 0),
                'days' => (int) $c->credit_days,
                'name' => $c->name(),

                /*
                 * ⛔ খোঁজার জন্য **দুইটা নামই** — ৭ সেপ্টেম্বর ২০২৬।
                 *
                 * ── ⚠️ কী ভাঙা ছিল ─────────────────────────────────────
                 * পর্দায় যেত কেবল `name()`, অর্থাৎ **চলতি ভাষার নামটা**।
                 * ⓘ বাংলা লোকেলে ইংরেজি নামটা ব্রাউজারে পৌঁছাতই না, তাই
                 * `Rahim Traders` লিখে `রহিম ট্রেডার্স`-কে খুঁজে পাওয়া
                 * যেত না।
                 *
                 * ⛔ আর পর্দা বলত **"ওই নামে কোনো গ্রাহক নেই"** — যেটা
                 * মিথ্যা। ⚠️ ক্যাশিয়ার তখন হয় নতুন করে একই গ্রাহক বসাতেন
                 * (দুইটা সারি, দুই জায়গায় বকেয়া), নয় বিক্রিটাই থেমে যেত।
                 *
                 * ⓘ ধরা পড়েছে লাইভে, হাতে চালিয়ে — `Bengal` লিখে
                 * `বেঙ্গল ফুডস লিমিটেড` পাওয়া যায়নি।
                 */
                'name_en' => (string) $c->name_en,
                'name_bn' => (string) $c->name_bn,

                'code' => (string) $c->code,
                'phone' => (string) ($c->phone ?? ''),
                'address' => (string) ($c->address() ?? ''),
                'location' => (string) ($c->location?->name() ?? ''),
            ]]),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'warehouse' => $warehouse,
            'walkinId' => (int) $this->settings->get('sales.walkin_customer_id', 0),

            /*
             * বিলের পরের নম্বরটা — দেখানোর জন্য, খরচ করার জন্য নয়।
             *
             * ── কেন `preview()`, `next()` নয় (৩ সেপ্টেম্বর ২০২৬) ────────
             * মালিক চেয়েছেন নম্বরটা পর্দাতেই দেখা যাক আর দরকারে বদলানো
             * যাক। `next()` ডাকলে সেটা হত, কিন্তু **পাতা খোলামাত্র একটা
             * নম্বর খরচ হয়ে যেত** — কেউ শুধু দেখে চলে গেলেও। দিনের শেষে
             * সিরিজে ফাঁক, আর নিরীক্ষায় "৪৭ নম্বর বিলটা কোথায়" প্রশ্নের
             * কোনো উত্তর নেই।
             *
             * `preview()` কেবল পড়ে — তালা নেয় না, কিছু বাড়ায় না। আসল
             * নম্বরটা বসে সংরক্ষণের ট্রানজেকশনের ভেতরে।
             *
             * ⚠️ দুইজন একসাথে কাউন্টার খুললে দুইজনেই একই নম্বর দেখবেন,
             * আর সেটা ঠিক আছে: যিনি আগে সেভ করবেন তিনি ওটা পাবেন,
             * পরেরজন পরেরটা। ভুল হত দেখানো নম্বরটাকে প্রতিশ্রুতি ভাবলে।
             *
             * সিরিজ না থাকলে খালি — ঘরটা তখন "নিশ্চিত করলে" লেখা
             * placeholder দেখায়, অর্থাৎ আগের আচরণেই ফেরে।
             */
            // ⭐ একটা বিক্রির একটাই নম্বর — চালান আর বিল দুইটাই এটা ([[SaleNumber]])
            'salePreview' => $this->seriesPreview(SaleNumber::DOC_TYPE),

            /*
             * ⭐ রাখা খসড়া — "পেন্ডিং" তালিকা আর খোলা খসড়া (মালিকের নকশা,
             * ২৬ সেপ্টেম্বর ২০২৬)। ⓘ তালিকাটা ক্রেতা ধরে ভাগ করা, তাই পর্দা
             * কেবল বাছা ক্রেতার খসড়াগুলো দেখায়।
             */
            'pendingDrafts' => $this->pendingDrafts(),

            /*
             * ⭐ ব্যাংকে জমার "ট্রান্সফার মোড" — রসিদ ভাউচারের একই তালিকা
             * ([[MasterListsOnTheVoucherForm]]), মালিকের ছবি, ২৭ সেপ্টেম্বর ২০২৬।
             */
            'transferModes' => TransferMode::query()->orderBy('code')->get(['id', 'name_en'])
                ->map(fn (TransferMode $m) => ['id' => (string) $m->id, 'label' => (string) $m->name_en])
                ->values()->all(),
            // ⭐ নিশ্চিত বিক্রি সম্পাদনা (?edit=) আগে, তারপর রাখা খসড়া (?draft=) — [[editFrom()]]
            'resume' => $this->editFrom($request) ?? $this->resumeFrom($request),

            /* ⭐ উৎস থেকে খোলা পর্দা — রাখা খসড়ার একই আকারে ([[sourceScreen()]]), আর মাথার "DO-0012 থেকে" */
            'sourceResume' => $fromSource === null ? null : $this->sourceScreen($fromSource['screen'], $warehouse),
            'counterSource' => $fromSource === null ? $this->sourceOfDraft($request) : $fromSource['banner'],

            /*
             * বাকির শর্তগুলো — মাস্টার ডাটা থেকে, হাতে লেখা তালিকা থেকে নয়।
             *
             * ── কেন (৩ সেপ্টেম্বর ২০২৬) ────────────────────────────────
             * মালিক চেয়েছেন দুইটা ঘরের বদলে একটা ড্রপডাউন। তালিকাটা
             * এখানে `['৭ দিন', '১৫ দিন', '৩০ দিন']` লিখে দেওয়া যেত, আর
             * সেটা হত ঠিক সেই ভুল যেটা তিনি বারবার বারণ করেছেন: "কোন
             * কোন ধরনের জিনিস" এমন প্রতিটা তালিকা কোম্পানির নিজের
             * বাড়ানোর কথা, কোডে বাঁধা থাকার কথা নয়।
             *
             * `mdm_payment_terms` ঠিক সেই তালিকা, আর সেটা মাস্টার
             * ডাটার পর্দা থেকে সম্পাদনা করা যায়। কেউ "৪৫ দিন" যোগ
             * করলে সেটা পরের দিনই কাউন্টারের ড্রপডাউনে দেখা যাবে,
             * কাউকে কিছু ছাড়াতে হবে না।
             */
            /*
             * ── ⭐ পাঁচটা ধরন, ক্রয়ের কাউন্টারের হুবহু সমান ────────────
             *
             * মালিক, ৫ সেপ্টেম্বর ২০২৬: *"r zaza nai sob daw direct
             * sales e"*।
             *
             * ⛔ আগে এখানে কেবল **দিনসংখ্যা** যেত, তাই বিক্রয়ে দুইটা
             * ধরন বলাই যেত না: `COD` আর `মাস শেষ পর্যন্ত`। ⚠️ অথচ
             * ডিপোর কাজে ঐ দুইটাই সবচেয়ে চলতি — মাল ভ্যানে যায় আর
             * টাকা ফেরে, নয়তো মাস শেষে হিসাব হয়।
             *
             * ⓘ ছাঁচটা `kind` বা `kind:days` — ক্রয়ের কাউন্টারে ঠিক
             * এটাই ([[DirectPurchaseController::paymentTerms()]]), আর
             * দুই পর্দা এক ছাঁচে থাকলে একটা শেখা মানে দুইটা জানা।
             */
            'paymentTerms' => $this->paymentTerms(),
            'paymentTermDefault' => 'cash',

            /*
             * ⭐ বাকির সীমার নিয়মগুলো পর্দায় — মালিকের নির্দেশ,
             * ২৫ সেপ্টেম্বর ২০২৬: *"Available Balance … blocks entry"*।
             *
             * ── ⛔ দেয়ালটা নতুন নয়, খবরটা দেরিতে আসত ─────────────────
             * ⓘ আসল দেয়াল সেবায় আগে থেকেই আছে
             * ([[SalesInvoiceService::assertWithinCreditLimit()]])। ⚠️ কিন্তু
             * সে কথা বলে **সংরক্ষণের সময়** — অর্থাৎ ত্রিশটা সারি তোলার পরে,
             * আর তখন কোন সারিটা বাদ দিলে চলবে তা কেউ বলে না।
             *
             * ── ⚠️ কেন সুইচগুলোও পাঠাতে হয় ───────────────────────────
             * ⛔ পর্দা যদি নিজের মতো একটা নিয়ম বানাত, তবে দুইটা উত্তর হত:
             * পর্দা আটকাত যেখানে সেবা দেয়, বা উল্টোটা। ⓘ প্রথমটা বিক্রি
             * বন্ধ করত, দ্বিতীয়টা মিথ্যা আশা দিত — দুইটাই খারাপ।
             *
             * ⓘ তাই সেবার প্রতিটা শর্তই এখানে যায়, হুবহু একই নামে।
             * `canOverride` — যাঁর চাবি আছে তাঁর কাছে পর্দা আটকাবেই না,
             * ঠিক যেমন সেবাও আটকায় না ([[CustomerPolicy]])।
             */
            /*
             * ⛔ ২৬ সেপ্টেম্বর ২০২৬: `blocks` আর `canOverride` উঠে গেছে।
             * ⓘ সীমা চালু থাকলে সে আটকায়ই, আর কারও চাবি তাকে পার করায় না
             * ([[CreditExposure]])। ⚠️ পর্দার নিয়ম সেবার হুবহু — নইলে পর্দা
             * ছেড়ে দিত আর সেবা আটকাত, অথবা উল্টোটা।
             */
            'creditRules' => [
                'enabled' => $this->credit->isOn(),
                // ⛔ শূন্য মানে শূন্য, সবসময় — দেয়ালের হুবহু ([[Customer::wouldExceedCreditLimit()]], ১ অক্টোবর ২০২৬)
                'zeroBlocks' => true,
            ],

            /*
             * ঘরগুলো কোম্পানি চাইলে বন্ধ করতে পারে (নিয়ম ৭)।
             *
             * DMS-এ প্রতিটা ঘরের নিজের সুইচ আছে, আর কারণটা বাস্তব: যে
             * ডিপো ভ্যাট দেয় না তার কাগজে ভ্যাটের সারি থাকলে প্রতিবার
             * শূন্য দেখে চোখ সরাতে হয়, আর একদিন ভুল ঘরে টাকা বসে।
             */
            'show' => [
                'free_qty' => $this->settings->get('sales.field_free_qty', true),
                'gift' => $this->settings->get('sales.field_gift', true),
                'line_discount' => $this->settings->get('sales.field_line_discount', true),
                'expense' => $this->settings->get('sales.field_expense', true),
                'rounding' => $this->settings->get('sales.field_rounding', true),
                'do_no' => $this->settings->get('sales.field_do_no', true),
                'deposit' => $this->settings->get('sales.field_deposit', true),
                'transport' => $this->settings->get('sales.field_transport', true),
                'shipment' => $this->settings->get('sales.field_shipment', true),
                'credit_limit' => $this->settings->get('sales.field_credit_limit', true),
                'vat' => $this->settings->get('sales.vat_enabled', false),
                'warehouse_select' => $this->settings->get('sales.field_warehouse_select', true),
                'sub_total' => $this->settings->get('sales.field_sub_total', true),
                'total_item' => $this->settings->get('sales.field_total_item', true),
                'sales_qty' => $this->settings->get('sales.field_sales_qty', true),
                'free_qty_total' => $this->settings->get('sales.field_free_qty_total', true),
                'total_qty' => $this->settings->get('sales.field_total_qty', true),
            ],
        ]);
    }

    /**
     * ⭐ এই মালে কতটা ফ্রি দেওয়া যাবে — সারি যোগ করার **আগে**।
     *
     * ── ⭐ মালিকের নির্দেশ, ২৪ সেপ্টেম্বর ২০২৬ ────────────────────
     * *"অনুপাতের বেশি ফ্রি দিলে বিল প্রডাক্ট এন্টিতেই আটকে যাবে, কার্টে
     * যোগ হবে না আর ওয়ার্নিং দিবে ফ্রি এতটা দেওয়া যাবে"*।
     *
     * ── ⚠️ দেয়ালটা এখনো সেবায়, এটা কেবল উত্তর ────────────────
     * ⓘ [[DirectSaleService]] বিল বসানোর সময়ঙ3 মিলিয়ে দেখে। ⛔ শুধু
     * পর্দায় আটকালে অন্য পথে আসা বিল — কাউন্টার, আদেশ, কালকের
     * নতুন পর্দা — প্রতিটাই একটা করে ফাঁক হত।
     *
     * ⭐ তাই এটা দেয়াল নয়, এটা **দেয়ালটা কোথায় তা আগে বলা** —
     * ⓘ মানুষ সারি যোগ করার মুহূর্তেই জানবেন, বিল শেষ করার পর নয়।
     */
    public function freeAllowed(Request $request): JsonResponse
    {
        $companyId = CompanyContext::id();

        /*
         * ⚠️ যাচাইটা নিজে হাতে, `$request->validate()` দিয়ে নয় — ২৫ সেপ্টেম্বর ২০২৬।
         *
         * ── ⛔ কী ধরা পড়েছে ────────────────────────────────────────────
         * এই অ্যাপে JSON উত্তর দেওয়া হয় **কেবল `api/*` পথে**
         * (`bootstrap/app.php`-এর `shouldRenderJsonWhen`)। ⓘ এই দরজাটা
         * `sales/direct/…`, তাই `validate()` ছুঁড়লে উত্তরটা ৪২২ নয় —
         * **৩০২, হোমে রিডাইরেক্ট**, এমনকি `Accept: application/json`-এও।
         *
         * ⚠️ আর ক্ষতিটা নীরব: কাউন্টারের JS `answer.ok` দেখে, আর রিডাইরেক্ট
         * অনুসরণ করে একটা ২০০ HTML পাতা আসে। ⛔ তখন `json()` ছোঁড়ে, ধরা
         * পড়ে, আর সারিটা **কোনো বাধা ছাড়াই কার্টে চলে যায়** — অর্থাৎ
         * সীমাটা থাকত, কিন্তু কথা বলত না।
         *
         * ⓘ দরজাটা `api/*`-এ সরানো যেত, কিন্তু তাতে একই পর্দার একটা
         * ঠিকানা দুই জায়গায় ভাগ হত। ⭐ এখানে উত্তরটা নিজেই বলা সহজ ও সৎ।
         */
        $check = Validator::make($request->all(), [
            'product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'qty' => ['required', 'numeric', 'gt:0'],

            /* ⓘ ঐচ্ছিক — লট বললে তারই অনুপাত, না বললে পুরনো পথ। */
            'batch_id' => ['nullable', 'integer'],
        ]);

        if ($check->fails()) {
            return response()->json(['message' => $check->errors()->first()], 422);
        }

        $data = $check->validated();

        $product = Product::query()->findOrFail($data['product_id']);

        $warehouse = isset($data['warehouse_id'])
            ? Warehouse::query()->find($data['warehouse_id'])
            : Warehouse::query()->where('is_default', true)->first();

        /*
         * ⓘ গুদাম না থাকলে সীমা বলা যায় না — তখন চুপ করাই সৎ।
         *
         * ⛔ শূন্য বললে পর্দা ভাবত *"কোনো ফ্রি দেওয়া যাবে না"*, আর
         * সেটা মিথ্যা — প্রশ্নটারই উত্তর নেই।
         */
        if ($warehouse === null) {
            return response()->json(['data' => ['known' => false, 'allowed' => null]]);
        }

        /*
         * ⭐ লট বলা থাকলে **তারই** অনুপাত — ২৫ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ মালিকের সিদ্ধান্তে বিক্রেতা এখন লট নিজে বাছেন, আর মাল ঐ
         * লট থেকেই বেরোয়। ⛔ FEFO ধরে হিসাব করলে সংখ্যাটা এমন একটা
         * লটের অনুপাত বলত যা এই বিলে ছোঁয়াই হবে না — আর দুইটা লটের
         * অনুপাত আলাদা হলে ফ্রি ভুল বসত, নীরবে।
         *
         * ⓘ লট না বললে পুরনো পথ অবিকল — অন্য পর্দা (চালান, পোর্টাল)
         * এখনো লট পাঠায় না, আর তাদের কিছু বদলায় না।
         */
        // ⭐ সুইচ বন্ধ (আন্তর্জাতিক মান, ৪ অক্টোবর ২০২৬) — লটের অনুপাতে কিছু বাঁধা নয়; পর্দা নিজে কিছু বসায় না, সতর্কও করে না
        if (! (bool) $this->settings->get('sales.free_by_lot_ratio', true)) {
            return response()->json(['data' => ['known' => false]]);
        }

        $batch = isset($data['batch_id'])
            ? Batch::query()->where('product_id', $product->id)->find($data['batch_id'])
            : null;

        /*
         * ⭐ ফ্রি-ভাণ্ডারের বাইরেও ফ্রি (সুইচ `sales.free_beyond_pool`, ৪ অক্টোবর ২০২৬) — পর্দা জানে, তাই লাল দেয়ালের জায়গায়
         * হলুদ সতর্কতা: *"ফ্রি ভাণ্ডারে আছে N — বাকি M নিজের মাল থেকে, প্রচারের খরচে"*। ⓘ `pool` = এই লটের ফ্রি-ভাণ্ডারে কত।
         */
        $beyond = (bool) $this->settings->get('sales.free_beyond_pool', false)
            ? ['beyond_pool' => true, 'pool' => $batch !== null
                ? app(\App\Modules\Inventory\Services\BatchAllocator::class)->lockedFreeBalance($batch, $warehouse)
                : app(\App\Modules\Inventory\Services\StockService::class)->freeAvailableQty($product, $warehouse)]
            : [];

        if ($batch !== null) {
            return response()->json(['data' => [
                'known' => true,
                ...app(FreeAllowance::class)->onLot($batch, (string) $data['qty']),
                ...$beyond,
            ]]);
        }

        return response()->json([
            'data' => [
                'known' => true,
                'allowed' => app(FreeAllowance::class)
                    ->on($product, $warehouse, (string) $data['qty']),

                /* ⓘ লট ছাড়া *"আর কত নিলে"* বলা যায় না — কোন লটের
                     অনুপাত ধরে বলব সেটাই জানা নেই। ⚠️ শূন্য বললে পর্দা
                     ভাবত "আর কিছু লাগবে না", তাই খালি। */
                'short' => '',
                ...$beyond,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        // ⓘ যাচাইয়ের তালিকা এক জায়গায় — ওয়েব আর অ্যাপের কাউন্টার একই নিয়ম ডাকে ([[DirectSaleRules::store()]])
        $data = $request->validate(DirectSaleRules::store($companyId, $request->all()));

        /*
         * ⭐ অনুমোদনে আটকালে — পপ-আপ, ত্রুটির তালিকা নয়। মালিকের ছবি, ২৭
         * সেপ্টেম্বর ২০২৬ (সন্ধ্যা): *"অনুমোদনের জন্য পাঠানো হয়েছে — ডেলিভারি
         * চালান · ৳… যিনি সই দেবেন…"* পাতার মাথায় নয়, বোতামের নিচেও নয়।
         *
         * ⓘ বার্তাটা সেবারই ([[DocumentApproval::awaitingWord()]]) — এখানে কেবল
         * কোথায় বসবে তা বদলায়। ⚠️ `approval_failed` — বিলটা হয়নি, তাই পর্দা
         * কার্ট ফেরায় ঠিক ত্রুটির মতো ([[direct-sale.js]] `hasErrors`)।
         */
        // ⓘ মাল কীভাবে যাবে — প্রশ্নটা এখন ছাপার দরজায়, নিশ্চিতে নয় (মালিকের অনুমোদিত বদল, ১ অক্টোবর ২০২৬; [[RequireTransportBeforePrint]])
        //    কাউন্টারের "মাল কীভাবে যাবে" ঘর থাকল, ঐচ্ছিক — দিলে চালানে বসে, না দিলে ছাপার আগে চাওয়া হয়।

        /*
         * ⭐ নিশ্চিত বিক্রি গেট পাসের আগে সম্পাদনা — মালিক, ২ অক্টোবর ২০২৬ ([[SaleEditor]])। ⓘ একই পাতা, একই
         * যাচাই; কেবল শেষ ধাপটা আলাদা: আগের এন্ট্রি উল্টে একই নম্বরে আবার বসে। আটকালে কিছুই বদলায় না।
         */
        if (filled($data['edit_invoice_id'] ?? null)) {
            abort_unless($request->user()?->can('sales.invoice.create'), 403);

            $editing = SalesInvoice::query()->findOrFail((int) $data['edit_invoice_id']);

            try {
                $edited = app(SaleEditor::class)->edit($editing, $data, $data['lines'], array_values(array_filter(
                    $data['gifts'] ?? [],
                    fn (array $gift) => filled($gift['product_id'] ?? null) && (float) ($gift['qty'] ?? 0) > 0,
                )));
            } catch (HeldForApproval $held) {
                return redirect()
                    ->route('sales.direct.create', ['edit' => $editing->id])
                    ->withInput()
                    ->with('approval_notice', (string) collect($held->errors())->flatten()->first())
                    ->with('approval_failed', true);
            }

            return redirect()
                ->route('sales.invoice.show', $edited)
                ->with('saved', __('sales::message.sale_edited', ['no' => $edited->document_no]));
        }

        try {
            $result = $this->sales->complete(
                $data,
                $data['lines'],
                array_values(array_filter(
                    $data['gifts'] ?? [],
                    fn (array $gift) => filled($gift['product_id'] ?? null) && (float) ($gift['qty'] ?? 0) > 0,
                )),
            );
        } catch (HeldForApproval $held) {
            return redirect()
                ->route('sales.direct.create')
                ->withInput()
                ->with('approval_notice', (string) collect($held->errors())->flatten()->first())
                ->with('approval_failed', true);
        }

        /*
         * ⭐ কাউন্টারের ডিপোজিটে সই লাগলে — বিলের পাতায়, ছাপায় নয় (১৯ সেপ্টেম্বর)।
         *
         * ⓘ মালিকের নিয়ম: *"কোনো print option আসবে না যতক্ষণ approve
         * হচ্ছে।"* ⚠️ তাই রসিদের PDF-এ যাওয়াই চলে না; বিলের পাতা বলে কোন
         * ডিপোজিট কার সইয়ের অপেক্ষায়, আর সই হলে সেখান থেকেই "নিশ্চিত"।
         */
        /*
         * ⭐ খসড়া রাখা হলে — একই পর্দায়, ছাপায় নয়। মালিক: *"sudu challan
         * inv print hobe na"*। ⓘ পর্দা খালি হয়ে ফেরে, আর খসড়াটা "পেন্ডিং"-এ।
         */
        if ($result['parked'] ?? false) {
            return redirect()
                ->route('sales.direct.create')
                ->with('saved', __('sales::message.draft_parked', [
                    'invoice' => $result['invoice']->document_no,
                    'challan' => $result['challan']->document_no,
                ]));
        }

        /*
         * ⭐ সইয়ের অপেক্ষা — কাউন্টারেই ফেরে, বার্তাটা পপ-আপে। মালিকের ছবি,
         * ২৭ সেপ্টেম্বর ২০২৬ (সন্ধ্যা): অনুমোদনের বার্তা পপ-আপ; পাতার মাথার
         * "INV-0006 … সইয়ের অপেক্ষায়" ব্যানার ভুল (একই দিন সকালের ছবিতে দাগানো),
         * আর বোতামের নিচের লেখাও (২৬ তারিখের নিয়ম) এখন বাতিল।
         *
         * ⓘ আগে বিলের পাতায় পাঠানো হত, আর বার্তাটা সেখানে পাতার মাথায় বসত।
         * ⚠️ বিক্রেতার চোখ থাকে কাউন্টারে, আর পরের ক্রেতা দাঁড়িয়ে — তাই পর্দা
         * খালি হয়ে ফেরে। ⓘ শেষ করাটা আগের মতোই বিলের পাতার বোতামে; বার্তা
         * বিলের নম্বর বলে।
         */
        /* ⭐ মার্জিনের সইয়ে গেছে — চালানের সইয়ের মতোই, কাউন্টারে ফেরে বার্তা নিয়ে (NEXUS §৩২) */
        /* ⭐ ছাড়ের সইয়েও একই — মার্জিন আর ছাড় দুটোই চাওয়া হলে বার্তা দুটোই (মালিকের নিয়ম, ১ অক্টোবর ২০২৬) */
        if (($result['margin_held'] ?? false) || ($result['discount_held'] ?? false)) {
            return redirect()
                ->route('sales.direct.create')
                ->with('approval_notice', implode(' ', array_filter([
                    ($result['margin_held'] ?? false) ? $result['margin_notice'] : null,
                    ($result['discount_held'] ?? false) ? $result['discount_notice'] : null,
                ])));
        }

        /* ⓘ চালানের সই চাওয়া হয়েছে — বিক্রিটা সইয়ের অপেক্ষায় জমা, কার্ট খোলা রাখা নয় */
        if (($result['challan_held'] ?? null) !== null) {
            return redirect()
                ->route('sales.direct.create')
                ->with('approval_notice', $result['challan_held']);
        }

        if (($result['awaiting'] ?? []) !== []) {
            return redirect()
                ->route('sales.direct.create')
                ->with('approval_notice', __('sales::message.direct_sale_held', [
                    'invoice' => $result['invoice']->document_no,
                ]));
        }

        /*
         * ⓘ আগে এখানে আরেকটা শাখা ছিল: আদায়ের কাগজ `sales|collection` ছকে
         * আটকালে আদায়ের খসড়া তালিকায় যেত। ১৯ সেপ্টেম্বর ২০২৬ থেকে কাউন্টারের
         * টাকা রসিদ ভাউচার, আর তার সই কাউন্টারের নিজের নিয়মে — উপরের শাখায়।
         */

        /*
         * সোজা রসিদে — বিক্রির পরের কাজটা কাগজ দেওয়া।
         *
         * ⭐ বাড়তি টাকা "ফেরত" নয় (১৯ সেপ্টেম্বর ২০২৬)। মালিকের নিয়মে বাইরের
         * সবার খাতা ব্যাংকের মতো — বাড়তিটা গ্রাহকের খাতায় জমা থাকে। ⛔ আগে
         * বার্তা "ফেরত" বলত অথচ খাতায় পুরোটা বসত: ক্যাশিয়ার ফেরত দিলে
         * টাকা দুইবার গোনা হত।
         */
        $extra = (string) ($result['extra'] ?? '0');

        $done = __('sales::message.direct_done', [
            'challan' => $result['challan']->document_no,
            'invoice' => $result['invoice']->document_no,
        ]);

        if (bccomp($extra, '0', 4) > 0) {
            $done .= ' '.__('sales::message.direct_extra_kept', ['amount' => Money::format($extra)]);
        }

        return redirect()
            ->route('sales.print.invoice', ['invoice' => $result['invoice']->id, 'paper' => '80mm'])
            ->with('saved', $done);
    }

    /**
     * পর্দার সাথে যাওয়া পণ্যতালিকা — ছয়টা মজুদ সংখ্যা সহ।
     *
     * সাব-সিলেক্টে, সারি প্রতি কোয়েরিতে নয়।
     *
     * @return Collection<int, object>
     */
    /**
     * ⭐ লট ধরা পণ্যের লটগুলো — মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬।
     *
     * ── ⓘ কেন ক্রমটা সেবার সাথে হুবহু এক ─────────────────────────────
     * `unexpired()` ও `fefo()` — ঠিক যে দুইটা স্কোপ
     * [[BatchAllocator::candidates()]] ব্যবহার করে। ⚠️ নিজের মতো একটা
     * ক্রম লিখলে পর্দা এক লট উপরে দেখাত আর সেবা অন্যটা নিত, আর
     * পার্থক্যটা কেবল মেয়াদ ফুরানোর দিন ধরা পড়ত।
     *
     * ⛔ মেয়াদ পেরোনো লট তালিকায় আসেই না — ⚠️ মালিক "লট বাছা
     * বাধ্যতামূলক" বেছেছেন, আর বাছাইয়ের তালিকায় মেয়াদোত্তীর্ণ মাল
     * থাকলে তাড়াহুড়োয় সেটাই বাছা হত।
     *
     * ── ⚠️ কোয়েরি একটাই, পণ্যপ্রতি নয় ───────────────────────────────
     * ⓘ `$batch->balance($warehouse)` প্রতিটা লটের জন্য আলাদা কোয়েরি
     * চালাত। ⛔ দুইশো লটের গুদামে ওটা দুইশো কোয়েরি, আর ধীরগতিটা
     * কোথাও লাল হত না।
     *
     * @return array<int, list<array<string, string>>> পণ্যের আইডি ধরে
     */
    private function lotsFor(?Warehouse $warehouse): array
    {
        // ⓘ ফোনের কাউন্টারের সাথে একই তালিকা ([[DirectSaleOptions::lots()]], ৪ অক্টোবর ২০২৬)
        return app(\App\Modules\Sales\Services\DirectSaleOptions::class)->lots($warehouse);
    }

    /** ⓘ ফোনের কাউন্টারের সাথে একই তালিকা ([[DirectSaleOptions::catalogue()]], ৪ অক্টোবর ২০২৬) */
    private function catalogue(?Warehouse $warehouse): Collection
    {
        return app(\App\Modules\Sales\Services\DirectSaleOptions::class)->catalogue($warehouse, self::INLINE_CATALOGUE_LIMIT);
    }

    private function warehouse(Request $request): ?Warehouse
    {
        $id = $request->integer('warehouse_id');

        return $id > 0
            ? Warehouse::query()->find($id)
            : Warehouse::query()->where('is_default', true)->active()->first();
    }

    /**
     * ⭐ রাখা খসড়া বাতিল — কারণ বাধ্যতামূলক, কারণ বাতিলের কাগজে সেটাই থাকে।
     *
     * ⚠️ `{invoice}` রুট-বাঁধাই মডেলের স্কোপ মানে (কোম্পানি, শাখা), তাই অন্যের
     * খসড়া 404। ⓘ বাকি পাহারা — এখনো খসড়া কি না, কাউন্টারের কি না — সেবায়
     * ([[DirectSaleService::discardParked()]]), তালার ভিতরে।
     */
    public function discard(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->sales->discardParked($invoice, $data['reason']);

        // ⓘ তালিকা থেকে বাতিল করলে তালিকাতেই ফেরা — কাউন্টারে নয়
        return redirect()
            ->route($request->input('back') === 'drafts' ? 'sales.direct.drafts' : 'sales.direct.create')
            ->with('saved', __('sales::message.draft_discarded', ['no' => $invoice->document_no]));
    }

    /**
     * রাখা খসড়ার তালিকা — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ ঠিক [[pendingDrafts()]]-এর একই ছাঁকনি (কাউন্টারের খসড়া, এখনো খসড়া),
     * যাতে কাউন্টারের Pending ড্রপডাউন আর এই তালিকা কখনো আলাদা কথা না বলে।
     * ⚠️ মডেলের স্কোপ কোম্পানি ও শাখা বসায়, তাই অন্যের খসড়া আসে না।
     */
    /**
     * ⭐ বিল বাতিল (Ctrl+X) — পাকা হওয়ার আগে, কারণ বাধ্যতামূলক, অডিটে (মালিক, ৪ অক্টোবর ২০২৬; "সব মুছুন"-এর জায়গায়)।
     *
     * ⓘ দুই অবস্থা: রাখা খসড়া খোলা থাকলে সেটাই বাতিল ([[DirectSaleService::discardParked()]], কারণসহ, বিলের
     * নিজের অডিটে); আর না রাখা কার্ট — সার্ভারে কোনো কাগজ নেই, তাই ঘটনাটা ক্রেতার অডিটে বসে: কারণ, কয়টা সারি,
     * কত টাকা। ⛔ কারণ ছাড়া নয় — মালিকের কথায়, কাউন্টারে বিল মুছে দেওয়া নীরবে হলে চোখের আড়ালে বিক্রি মুছত।
     * ⓘ পাকা বিলে এই দরজা কিছুই করে না — পাকা বিল ভুল হলে বাতিল-ইনভয়েস ([[SalesInvoiceCancellationService]])।
     */
    public function void(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()?->can('sales.invoice.create'), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'customer_id' => ['nullable', 'integer'],
            'resume_invoice_id' => ['nullable', 'integer'],
            'lines' => ['nullable', 'integer', 'min:0'],
            'total' => ['nullable', 'numeric'],
        ]);

        if (filled($data['resume_invoice_id'] ?? null)) {
            $this->sales->discardParked(SalesInvoice::query()->findOrFail((int) $data['resume_invoice_id']), $data['reason']);
        } else {
            $customer = \App\Modules\Customer\Models\Customer::query()
                ->find((int) ($data['customer_id'] ?? 0) ?: (int) $this->settings->get('sales.walkin_customer_id', 0));

            if ($customer !== null) {
                app(\App\Core\Engines\Audit\AuditEngine::class)->record($customer, 'counter_bill_voided', [
                    'lines' => [(int) ($data['lines'] ?? 0), 0],
                    'total' => [(string) ($data['total'] ?? '0'), '0'],
                ], $data['reason']);
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => ['voided' => true]]);
        }

        return redirect()->route('sales.direct.create')->with('saved', __('sales::message.bill_voided'));
    }

    public function drafts(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        /* ⭐ দুই ট্যাব — খসড়া আর অনুমোদনের অপেক্ষায় (মালিক, ২৮ সেপ্টেম্বর ২০২৬) */
        $tab = $request->query('tab') === 'approval' ? 'approval' : 'drafts';

        $query = ($tab === 'approval' ? DirectSaleService::awaitingApproval() : DirectSaleService::trueDrafts())
            ->with(['customer.location', 'lines.challanLine.challan', 'lines.product'])
            ->when($q !== '', fn ($query) => $query->search($q))
            ->orderByDesc('id');

        $drafts = (clone $query)->paginate(50)->withQueryString();

        return view('sales::direct.drafts', [
            'menu' => $this->menu->forUser($request->user()),
            'drafts' => $drafts,
            'why' => $this->whyStuck($drafts->getCollection()),
            // ⭐ যোগফলের পট্টি — এই ট্যাবের গোটা ছাঁকনির মোট, পাতার নয় (মালিক, ৫ অক্টোবর ২০২৬)
            'grand' => $this->grandTotals($query, ['total' => 't.total']),
            'tab' => $tab,
            'tabCounts' => [
                'drafts' => DirectSaleService::trueDrafts()->count(),
                'approval' => DirectSaleService::awaitingApproval()->count(),
            ],
            'held' => $drafts->getCollection()->mapWithKeys(
                fn (SalesInvoice $d) => [$d->id => DirectSaleService::isHeldForSignature($d)])->all(),
            'q' => $q,
        ]);
    }

    /**
     * খসড়াটা কেন আটকে — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: *"sudu stats dekhabe keno
     * se atke ache"*।
     *
     * ⓘ তিনটা সইয়ের জায়গা দেখা হয় — চালান, বিল, আর বিলের বিপরীতে কাউন্টারের জমা
     * (`against_*`); কোনোটাই না থাকলে কাউন্টারে রাখা খসড়া। ⚠️ এক কোয়েরিতে সব সারির,
     * নাহলে পঞ্চাশ সারিতে পঞ্চাশবার খোঁজা হত।
     *
     * @param  \Illuminate\Support\Collection<int, SalesInvoice>  $drafts
     * @return array<int, string>
     */
    private function whyStuck($drafts): array
    {
        $invoiceIds = $drafts->pluck('id')->all();
        $challans = $drafts->mapWithKeys(fn (SalesInvoice $d) => [
            $d->id => (int) ($d->lines->first()?->challanLine?->challan?->id ?? 0),
        ]);

        $vouchers = \App\Modules\Accounts\Models\Voucher::query()
            ->where('against_type', SalesInvoice::drillSourceType())
            ->whereIn('against_id', $invoiceIds)
            ->get(['id', 'document_no', 'against_id']);

        $pending = \App\Models\Approval::query()
            ->where('status', \App\Models\Approval::PENDING)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('approvable_type', DeliveryChallan::class)
                    ->whereIn('approvable_id', $challans->filter()->values()->all()))
                ->orWhere(fn ($w) => $w->where('approvable_type', SalesInvoice::class)
                    ->whereIn('approvable_id', $invoiceIds))
                ->orWhere(fn ($w) => $w->where('approvable_type', \App\Modules\Accounts\Models\Voucher::class)
                    ->whereIn('approvable_id', $vouchers->pluck('id')->all())))
            ->get(['approvable_type', 'approvable_id']);

        $held = fn (string $type, int $id) => $pending->contains(
            fn ($a) => $a->approvable_type === $type && (int) $a->approvable_id === $id);

        $why = [];

        foreach ($drafts as $draft) {
            $challanId = (int) $challans->get($draft->id, 0);
            $voucher = $vouchers->first(fn ($v) => (int) $v->against_id === $draft->id
                && $held(\App\Modules\Accounts\Models\Voucher::class, (int) $v->id));

            $why[$draft->id] = match (true) {
                $draft->draft_paused_at !== null => __('sales::message.stuck_paused'),
                $challanId > 0 && $held(DeliveryChallan::class, $challanId) => __('sales::message.stuck_challan_signature'),
                $held(SalesInvoice::class, (int) $draft->id) => __('sales::message.stuck_invoice_signature'),
                $voucher !== null => __('sales::message.stuck_deposit_signature', ['no' => $voucher->document_no]),
                $draft->counter_draft !== null => __('sales::message.stuck_parked'),
                default => __('sales::message.stuck_unfinished'),
            };
        }

        return $why;
    }

    /** সইয়ের অপেক্ষায় থাকা বিক্রির অনুমোদন-পাতা — চালান, বিল বা জমা, যেটায় সই ঝুলছে। */
    private function approvalUrlFor(SalesInvoice $draft): string
    {
        $approval = DirectSaleService::pendingApprovalsOf($draft)->first()?->id;

        return $approval === null
            ? route('sales.direct.drafts', ['tab' => 'approval'])
            : route('approval.inbox.show', $approval);
    }

    /** ⭐ সইয়ের অপেক্ষা থেকে খসড়ায় — কাউন্টারের "কেবল দেখা" ব্যানারের বোতাম ([[DirectSaleService::withdrawHeld()]]). */
    public function withdrawHeld(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $this->sales->withdrawHeld($invoice, $request->user());

        // ⓘ খসড়া হয়ে একই কাউন্টারে খোলে — বদলে আবার নিশ্চিত করার জন্য
        return redirect()->route('sales.direct.create', ['draft' => $invoice->id])
            ->with('saved', __('sales::message.held_withdrawn', ['no' => $invoice->document_no]));
    }

    /** খসড়া নিষ্ক্রিয় — তালিকার বোতাম ([[DirectSaleService::pauseDraft()]]). */
    public function pauseDraft(SalesInvoice $invoice): RedirectResponse
    {
        $this->sales->pauseDraft($invoice);

        return redirect()->route('sales.direct.drafts')
            ->with('saved', __('sales::message.draft_paused', ['no' => $invoice->document_no]));
    }

    /** খসড়া আবার সক্রিয় — সীমা আর "একটাই খসড়া" আবার যাচাই হয়। */
    public function resumeDraft(SalesInvoice $invoice): RedirectResponse
    {
        $this->sales->resumeDraft($invoice);

        return redirect()->route('sales.direct.drafts')
            ->with('saved', __('sales::message.draft_resumed', ['no' => $invoice->document_no]));
    }

    /**
     * ক্রেতা ধরে রাখা খসড়াগুলো — `[customerId => [{id, no, total, date}]]`।
     *
     * ⓘ কেবল কাউন্টারের রাখা খসড়া (`counter_draft` আছে) — সইয়ের অপেক্ষার
     * খসড়া বিলের পাতা থেকে শেষ হয়, এখানে নয়। ⚠️ মডেলের স্কোপ কোম্পানি ও
     * শাখা বসায়, তাই অন্যের খসড়া তালিকায় আসে না। ⓘ ৫০০-র ঊর্ধ্বসীমা:
     * কাউন্টারে এতগুলো অসমাপ্ত বিল থাকা মানে অন্য কোনো সমস্যা।
     *
     * @return array<int, list<array{id: int, no: string, total: string, date: string}>>
     */
    private function pendingDrafts(): array
    {
        // ⓘ নিষ্ক্রিয় খসড়া ড্রপডাউনে নয় — তালিকায় থাকে ([[DirectSaleService::pauseDraft()]])
        /* ⭐ দুই ভাগ — খসড়া (কাউন্টারে খোলে) আর অনুমোদনের অপেক্ষায় (অনুমোদনের পাতায় খোলে,
           দেখা আর ফিরিয়ে আনা যায়) — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬ */
        $held = DirectSaleService::awaitingApproval()->pluck('sal_invoices.id')->flip();

        $groups = DirectSaleService::activeCounterDrafts()
            ->orderByDesc('id')
            ->limit(500)
            ->with('customer')
            ->get(['id', 'document_no', 'customer_id', 'total', 'trx_date', 'counter_draft', 'counter_screen'])
            ->groupBy('customer_id')
            ->map(fn (Collection $drafts) => $drafts->map(fn (SalesInvoice $draft) => [
                'id' => (int) $draft->id,
                'no' => (string) $draft->document_no,
                // ⓘ ক্রেতা না বাছা থাকলে ড্রপডাউনে সবার খসড়া আসে — তখন নামটাই পরিচয়
                'customer' => (string) ($draft->customer?->name() ?? ''),
                'total' => (string) $draft->total,
                'date' => $draft->trx_date?->format('d-m-Y') ?? '',
                'group' => $held->has($draft->id) ? 'approval' : 'draft',
                /* ⭐ সব ভাগ কাউন্টারেই খোলে — মালিকের অনুমোদিত নকশা, ২৮ সেপ্টেম্বর ২০২৬; সইয়ের
                   অপেক্ষারটা কেবল দেখার জন্য ([[resumeFrom()]] `viewOnly`)। ⓘ পর্দার ছবি ছাড়া
                   পুরনো বিক্রি (এই বদলের আগের) দেখানোর কিছু নেই — সেটা অনুমোদনের পাতায়। */
                'url' => $held->has($draft->id) && $draft->counter_screen === null
                    ? $this->approvalUrlFor($draft)
                    : route('sales.direct.create', ['draft' => $draft->id]),
            ])->values()->all())
            ->all();

        /* ⭐ তৃতীয় ভাগ — ডেলিভারির অপেক্ষায়; কাউন্টারে কেবল দেখা, নিশ্চিত হয় চালানের পাতায়।
           ⓘ নতুন বিলের দেয়াল এদের গোনে না — পাকা বিক্রি খসড়া নয় (direct-sale.js). */
        foreach (DirectSaleService::awaitingDelivery()->with('customer')->orderByDesc('id')->limit(200)
            ->get(['id', 'document_no', 'customer_id', 'total', 'trx_date']) as $sale) {
            $groups[$sale->customer_id][] = [
                'id' => (int) $sale->id,
                'no' => (string) $sale->document_no,
                'customer' => (string) ($sale->customer?->name() ?? ''),
                'total' => (string) $sale->total,
                'date' => $sale->trx_date?->format('d-m-Y') ?? '',
                'group' => 'delivery',
                'url' => route('sales.direct.create', ['draft' => $sale->id]),
            ];
        }

        return $groups;
    }

    /**
     * `?draft=ID` — খোলা খসড়াটা পর্দায় ফেরানোর জন্য যা লাগে।
     *
     * ⛔ কেবল এখনো খোলা, কাউন্টারের রাখা খসড়া; নাহলে `null`, আর পর্দা খালি
     * খোলে। ⓘ আসল পাহারা সংরক্ষণে ([[DirectSaleService::parkedFor()]]) —
     * এখানে দেখানো আর পাকা করার মাঝে কেউ পাকা করে ফেললে সেবাই বলে দেয়।
     *
     * @return array{invoiceId: int, invoiceNo: string, challanNo: string, customerId: int, screen: array<mixed>, fields: array<string, mixed>, viewOnly: bool, approvalUrl: string|null}|null
     */
    /**
     * ⭐ নিশ্চিত বিক্রি সম্পাদনার পর্দা — `?edit=<বিল>` (মালিক, ২ অক্টোবর ২০২৬; [[SaleEditor]])।
     *
     * ⓘ পর্দার ছবি বিক্রির নিজের (`counter_screen`), আগের জমাগুলো বাদে — ⛔ নাহলে "হালনাগাদ" চাপলে একই জমা
     * আবার ভাউচার হত। সম্পাদনা না চললে (গেট পাস, ফেরত, অন্য পথের বিল) পর্দা কারণটা বলে, খালি নতুন বিল খোলে।
     *
     * @return array<string, mixed>|null
     */
    private function editFrom(Request $request): ?array
    {
        $id = $request->integer('edit');

        if ($id <= 0 || ! $request->user()?->can('sales.invoice.create')) {
            return null;
        }

        $sale = SalesInvoice::query()->with('lines.challanLine.challan')->find($id);

        if ($sale === null) {
            return null;
        }

        try {
            $challan = app(SaleEditor::class)->assertEditable($sale);
        } catch (ValidationException $e) {
            session()->now('approval_notice', (string) collect($e->errors())->flatten()->first());

            return null;
        }

        $saved = (array) $sale->counter_screen;
        $screen = (array) ($saved['screen'] ?? []);
        $screen['deposits'] = [];

        return [
            'invoiceId' => (int) $sale->id,
            'invoiceNo' => (string) $sale->document_no,
            'challanNo' => (string) $challan->document_no,
            'customerId' => (int) $sale->customer_id,
            'screen' => $screen,
            'fields' => (array) ($saved['fields'] ?? []),
            'viewOnly' => false,
            'approvalUrl' => null,
            'stage' => 'edit',
            'editInvoiceId' => (int) $sale->id,
            'challanUrl' => null,
        ];
    }

    private function resumeFrom(Request $request): ?array
    {
        $id = $request->integer('draft');

        if ($id <= 0) {
            return null;
        }

        $draft = DirectSaleService::openCounterDrafts()
            ->with('lines.challanLine.challan')
            ->find($id);

        if ($draft === null) {
            return $this->deliveryViewFrom($id);
        }

        /* ⭐ সইয়ের অপেক্ষায় থাকলে কেবল দেখা — মালিকের অনুমোদিত নকশা, ২৮ সেপ্টেম্বর ২০২৬।
           ⛔ খসড়ার ছবি (`counter_draft`) নয়, দেখার ছবি (`counter_screen`) — আর সংরক্ষণেও
           পাহারা আছে ([[DirectSaleService::parkedFor()]] `counter_draft` চায়)। */
        $held = DirectSaleService::isHeldForSignature($draft);
        $saved = (array) ($held ? $draft->counter_screen : $draft->counter_draft);

        /*
         * ⛔ মালিক, ১ অক্টোবর ২০২৬: *"পেন্ডিং বিল কাউন্টারে ওপেন হচ্ছে না"* — পর্দার ছবি
         * (`counter_draft`) ছাড়া রাখা খসড়া (ছবি-ব্যবস্থার আগের, বা অন্য পথে বানানো) বাছলে
         * পাতা চুপচাপ একটা খালি নতুন বিল খুলত। ⭐ ছবি না থাকলে খসড়ার নিজের সারি থেকে পর্দা।
         */
        if ($saved === []) {
            $saved = app(\App\Modules\Sales\Services\DirectSaleOptions::class)->screenFromDraft($draft);
        }

        if ($saved === []) {
            return null;
        }

        return [
            'invoiceId' => (int) $draft->id,
            'invoiceNo' => (string) $draft->document_no,
            'challanNo' => (string) ($draft->lines->first()?->challanLine?->challan?->document_no ?? ''),
            'customerId' => (int) $draft->customer_id,
            'screen' => (array) ($saved['screen'] ?? []),
            'fields' => (array) ($saved['fields'] ?? []),
            'viewOnly' => $held,
            'approvalUrl' => $held ? $this->approvalUrlFor($draft) : null,
            'stage' => $held ? 'approval' : 'draft',
            'challanUrl' => null,
        ];
    }

    /**
     * ⭐ ডিপোর যাচাই থেকে কাউন্টারে — `?source=do&source_id=12` (বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬, ধাপ ঙ)।
     *
     * ⓘ ক্রম: উৎস খোঁজা (⛔ না পেলে ৪০৪ — কোম্পানি ও শাখার দেয়াল মডেলের নিজের স্কোপে) → এই উৎসের খসড়া আগে থেকে
     * থাকলে সেটাই খোলে (এক কাগজ, এক বিল) → "খোলার মতো?"।
     * ⛔ GET কিছুই বদলায় না (সমন্বয়ক, ৩ অক্টোবর ২০২৬) — ডিপো যাচাইয়ে তোলা তালিকার বোতামের POST-এ
     * ([[DepotCheckController::open()]])। খোলার মতো না হলে (বিল হয়ে গেছে, বাতিল …) তালিকায়, কারণসহ।
     *
     * @return array{screen: array<string, mixed>, banner: array<string, mixed>}|RedirectResponse
     */
    private function openFromSource(Request $request): array|RedirectResponse
    {
        $key = (string) $request->query('source', '');
        $id = (int) $request->query('source_id', 0);
        $source = app(CounterSaleSources::class)->find($key, $id);

        abort_if($source === null, 404);

        $draft = SalesInvoice::query()
            ->where('counter_source', $key)
            ->where('counter_source_id', $id)
            ->where('status', 'draft')
            ->orderByDesc('id')
            ->value('id');

        if ($draft !== null) {
            return redirect()->route('sales.direct.create', ['draft' => (int) $draft]);
        }

        try {
            $source->assertReadyForCounter();
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('sales.direct.depot_check')->withErrors($e->errors());
        }

        $screen = $source->counterScreen();

        return [
            'screen' => $screen,
            'banner' => ['key' => $key, 'id' => $id, 'ref' => (string) $screen['ref'], 'fromDraft' => false],
        ];
    }

    /**
     * উৎসের সারি থেকে কাউন্টারের পর্দা — রাখা খসড়ার হুবহু আকারে ([[screenFromDraft()]]), তাই পাতার জাভাস্ক্রিপ্ট
     * নতুন কিছু শেখে না: `resume`-এর মতোই ভরে, কেবল `invoiceId` খালি (নতুন বিল)।
     *
     * ⭐ লট কাউন্টারের নিয়মে — লট ধরা পণ্যে FEFO ধরে, মজুদ যতটা আছে ততটা প্রতিটা লটে ([[lotsFor()]]-এর একই ক্রম);
     * না কুলোলে বাকিটা লট ছাড়া সারিতে — বিক্রেতা দেখেন, আর নিশ্চিতের আগে বাদ দেন বা লট বাছেন। ⓘ প্রতিটা ভাগ
     * উৎসের একই সারির (`sourceLineId`), তাই সেবা মোট ধরে মেলায়।
     *
     * @param  array{ref: string, customer_id: int, warehouse_id: int|null, lines: list<array<string, mixed>>}  $screen
     * @return array<string, mixed>
     */
    private function sourceScreen(array $screen, ?Warehouse $warehouse): array
    {
        $products = Product::query()->with(['unit', 'tax'])
            ->whereIn('id', collect($screen['lines'])->pluck('product_id')->all())
            ->get()->keyBy('id');
        $lots = $this->lotsFor($warehouse);
        $plain = fn (string $n): string => str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;

        $lines = [];
        $key = 1;

        foreach ($screen['lines'] as $row) {
            $left = bcadd((string) $row['qty'], '0', 4);

            if (bccomp($left, '0', 4) <= 0) {
                continue;
            }

            $product = $products->get((int) $row['product_id']);
            $chunks = [];

            if ($product?->track_batch) {
                foreach ($lots[(int) $product->id] ?? [] as $lot) {
                    if (bccomp($left, '0', 4) <= 0) {
                        break;
                    }

                    $take = bccomp($left, (string) $lot['qty'], 4) <= 0 ? $left : bcadd((string) $lot['qty'], '0', 4);
                    $chunks[] = ['qty' => $take, 'batchId' => (string) $lot['id'], 'batchNo' => (string) $lot['no']];
                    $left = bcsub($left, $take, 4);
                }
            }

            if (bccomp($left, '0', 4) > 0) {
                $chunks[] = ['qty' => $left, 'batchId' => '', 'batchNo' => ''];
            }

            foreach ($chunks as $i => $chunk) {
                $lines[] = [
                    'key' => $key++,
                    'id' => (int) $row['product_id'],
                    'name' => (string) ($product?->name() ?? ''),
                    'unit' => (string) ($product?->unit?->name() ?? ''),
                    'vatRate' => (float) ($product?->tax?->rate ?? 0),
                    'vatInclusive' => (bool) ($product?->tax?->is_inclusive ?? false),
                    'qty' => $plain($chunk['qty']),
                    // ⓘ ফ্রি প্রথম ভাগে — অনুপাতের দেয়াল সেবায় যেমন আছে
                    'freeQty' => $i === 0 ? $plain(bcadd((string) ($row['free_qty'] ?? '0'), '0', 4)) : '0',
                    'rate' => $plain(bcadd((string) $row['rate'], '0', 4)),
                    'discountPercent' => $plain(bcadd((string) ($row['discount_percent'] ?? '0'), '0', 4)),
                    'unitId' => '',
                    'gifts' => [],
                    'batchId' => $chunk['batchId'],
                    'batchNo' => $chunk['batchNo'],
                    'sourceLineId' => (int) $row['source_line_id'],
                ];
            }
        }

        // ⓘ ক্রেতার নিজের বাকির মেয়াদ, ড্রপডাউনে থাকলে — ক্রেতা বাছলে পর্দা যা বসাত ([[direct-sale.js]] chooseCustomer)
        $days = (int) (Customer::query()->inViewedBranch()->whereKey($screen['customer_id'])->value('credit_days') ?? 0);
        $term = $days > 0 && collect($this->paymentTerms())->contains('value', 'credit:'.$days) ? 'credit:'.$days : 'cash';

        return [
            'invoiceId' => '',
            'invoiceNo' => '',
            'challanNo' => '',
            'customerId' => (int) $screen['customer_id'],
            'screen' => [
                'customerId' => (string) $screen['customer_id'],
                'creditTerm' => $term,
                'dueOn' => '',
                'lines' => $lines,
                'nextKey' => $key,
            ],
            'fields' => [],
            'viewOnly' => false,
            'approvalUrl' => null,
            'stage' => 'source',
            'challanUrl' => null,
        ];
    }

    /**
     * `?draft=ID` — খসড়াটা কোনো উৎস থেকে এসে থাকলে মাথার "DO-0012 থেকে"। ⓘ পাকা করার সময় উৎস সেবা নিজেই
     * খসড়া থেকে পড়ে ([[DirectSaleService::sourceFor()]]), তাই এখানে কেবল দেখানো।
     *
     * @return array{key: string, id: int, ref: string, fromDraft: bool}|null
     */
    private function sourceOfDraft(Request $request): ?array
    {
        $id = $request->integer('draft');

        if ($id <= 0) {
            return null;
        }

        $draft = SalesInvoice::query()->whereNotNull('counter_source')
            ->find($id, ['id', 'company_id', 'counter_source', 'counter_source_id']);
        $source = $draft === null ? null : app(CounterSaleSources::class)->forInvoice($draft);

        return $source === null ? null : [
            'key' => (string) $draft->counter_source,
            'id' => (int) $draft->counter_source_id,
            'ref' => (string) $source->counterScreen()['ref'],
            'fromDraft' => true,
        ];
    }

    /**
     * পাকা বিক্রি, মাল এখনো পৌঁছায়নি — কাউন্টারে কেবল দেখা; "ডেলিভারি নিশ্চিত" চালানের পাতায়
     * ([[DirectSaleService::awaitingDelivery()]])।
     *
     * @return array<string, mixed>|null
     */
    private function deliveryViewFrom(int $id): ?array
    {
        $sale = DirectSaleService::awaitingDelivery()->with('lines.challanLine.challan')->find($id);

        if ($sale === null) {
            return null;
        }

        $saved = (array) $sale->counter_screen;
        $challan = $sale->lines->first()?->challanLine?->challan;

        return [
            'invoiceId' => (int) $sale->id,
            'invoiceNo' => (string) $sale->document_no,
            'challanNo' => (string) ($challan?->document_no ?? ''),
            'customerId' => (int) $sale->customer_id,
            'screen' => (array) ($saved['screen'] ?? []),
            'fields' => (array) ($saved['fields'] ?? []),
            'viewOnly' => true,
            'approvalUrl' => null,
            'stage' => 'delivery',
            'challanUrl' => $challan === null ? null : route('sales.challan.show', $challan),
        ];
    }

    /**
     * চালকের নাম আর নম্বর — আগের চালান আর গাড়ির মাস্টার থেকে, নাম ধরে একবার।
     *
     * @return list<array{name: string, phone: string}>
     */
    private function driverSuggestions(): array
    {
        $seen = [];

        $fromChallans = DeliveryChallan::query()
            ->whereNotNull('driver_name')
            ->where('driver_name', '!=', '')
            ->orderByDesc('id')
            ->limit(300)
            ->get(['driver_name', 'driver_phone']);

        $fromVehicles = Vehicle::query()
            ->whereNotNull('driver_name')
            ->where('driver_name', '!=', '')
            ->get(['driver_name', 'driver_phone']);

        foreach ($fromChallans->concat($fromVehicles) as $row) {
            $name = trim((string) $row->driver_name);
            $key = mb_strtolower($name);

            // ⓘ নতুন চালানটাই জেতে; নম্বর ছাড়া আগের সারি পরে নম্বর পেলে ভরে
            if (! isset($seen[$key])) {
                $seen[$key] = ['name' => $name, 'phone' => trim((string) $row->driver_phone)];
            } elseif ($seen[$key]['phone'] === '') {
                $seen[$key]['phone'] = trim((string) $row->driver_phone);
            }
        }

        return array_values(array_slice($seen, 0, 100));
    }

    /** সিরিজের পরের নম্বর, কেবল দেখানোর জন্য — [[NumberSeriesEngine::preview()]]. */
    private function seriesPreview(string $docType): string
    {
        $series = NumberSeries::query()
            ->where('company_id', CompanyContext::id())
            ->where('doc_type', $docType)
            ->where('is_active', true)
            ->orderByRaw('branch_id IS NULL')
            ->first();

        return $series === null ? '' : app(NumberSeriesEngine::class)->preview($series);
    }

    /**
     * কাউন্টারে কী কী শর্ত বাছা যাবে।
     *
     * ── ⓘ মানের ছাঁচ ────────────────────────────────────────────────
     * `cash` · `cod` · `credit:30` · `month_end` · `fixed` — কোলনের
     * পরের সংখ্যাটা কেবল `credit`-এ, আর পর্দাই ওটা তারিখে অনুবাদ করে।
     *
     * ── ⚠️ দিনসংখ্যাগুলো কোডে নেই ───────────────────────────────────
     * `['৭ দিন', '১৫ দিন', '৩০ দিন']` লিখে দেওয়া যেত, আর সেটা হত ঠিক
     * সেই ভুল যা মালিক বারবার বারণ করেছেন: *"কোন কোন ধরনের জিনিস"*
     * এমন প্রতিটা তালিকা কোম্পানির নিজের বাড়ানোর কথা।
     *
     * ⓘ `mdm_payment_terms` সেই তালিকা, আর সেটা মাস্টার ডাটার পর্দা
     * থেকে সম্পাদনা করা যায়। কেউ "৪৫ দিন" যোগ করলে পরের দিনই
     * কাউন্টারের ড্রপডাউনে দেখা যাবে।
     *
     * ⚠️ শূন্য দিনের সারিগুলো বাদ — ওটার নাম "নগদ", আর সেটা উপরেই আছে।
     * ⛔ না বাদ দিলে তালিকায় দুইটা "নগদ" থাকত, দুই নামে।
     *
     * @return list<array{value: string, label: string}>
     */
    private function paymentTerms(): array
    {
        // ⓘ ফোনের কাউন্টারের সাথে একই তালিকা ([[DirectSaleOptions::paymentTerms()]])
        return app(\App\Modules\Sales\Services\DirectSaleOptions::class)->paymentTerms();
    }
}
