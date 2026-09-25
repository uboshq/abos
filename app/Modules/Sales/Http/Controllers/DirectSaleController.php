<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Contracts\RecipeBook;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\NumberSeries;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\FreeAllowance;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
    /** এর বেশি পণ্য হলে পাতার সাথে পাঠানো বন্ধ, সার্ভারে খোঁজা শুরু। */
    private const INLINE_CATALOGUE_LIMIT = 2000;

    public function __construct(
        private readonly DirectSaleService $sales,
        private readonly SettingsService $settings,
        private readonly MenuBuilder $menu,
        private readonly RecipeBook $recipes,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.challan.create')];
    }

    public function create(Request $request): View
    {
        $warehouse = $this->warehouse($request);
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
        $customers = Customer::query()->active()->with('location')
            ->withOutstanding()->orderBy('name_en')->get();

        // শীট আর প্যাকের ড্রপডাউন — একই তালিকা, তাই একবারই তোলা
        $sheetProducts = Product::query()->active()->with('unit')->orderBy('name_en')->get();

        return view('sales::direct.index', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => $this->catalogue($warehouse),

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
            'depositMethods' => PaymentMethod::query()
                ->active()
                ->orderBy('code')
                ->get()
                ->map(fn (PaymentMethod $m): array => [
                    'id' => (string) $m->id,
                    'label' => $m->name(),
                    'accountId' => $m->account_id === null ? '' : (string) $m->account_id,
                    'needsReference' => (bool) $m->needs_reference,
                    /*
                     * ⚠️ ধরনটা এখনো নাও থাকতে পারে, আর সেটা ইচ্ছাকৃত।
                     *
                     * `kind` কলামটা যোগ হচ্ছে (নগদ · ব্যাংক · MFS · চেক), আর
                     * ওটাই ঠিক করে দেবে খাতের তালিকায় কোনগুলো দেখা যাবে।
                     * Eloquent অনুপস্থিত কলামে `null` ফেরায়, ব্যতিক্রম নয় —
                     * তাই কলামটা আসার আগেও পর্দা ভাঙে না, কেবল ছাঁকনিটা
                     * চুপ করে থাকে (সব খাত দেখায়)।
                     *
                     * ⓘ **এটা "method না বাছা"র চেয়ে আলাদা অবস্থা** — তখন
                     * একটাও খাত দেখা যায় না, মালিকের নির্দেশমতো।
                     */
                    'kind' => $m->kind,
                ])
                ->values(),

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
            'carriers' => Supplier::query()
                ->active()
                // RENTAL পক্ষের ধরনটা বাদ (৪ সেপ্টেম্বর, মালিকের চূড়ান্ত তালিকা) —
                // ভাড়ার গাড়িও পরিবহনকারী, তাই আলাদা ধরন নয়। এখন শুধু TRANSPORT।
                ->whereHas('partyType', fn ($q) => $q->whereIn('code', ['TRANSPORT']))
                ->orderBy('name_en')
                ->get(['id', 'code', 'name_en', 'name_bn'])
                ->map(fn (Supplier $s): array => [
                    'id' => (string) $s->id,
                    'label' => $s->name(),
                ])
                ->values(),

            'moneyAccounts' => Account::query()
                ->where('is_group', false)
                ->whereIn('parent_id', Account::query()
                    ->whereIn('code', StandardChart::MONEY_PARENTS)->select('id'))
                ->orderBy('code')
                ->with('parent:id,code')
                ->get(['id', 'parent_id', 'code', 'name_en', 'name_bn'])
                ->map(fn (Account $a): array => [
                    'id' => (string) $a->id,
                    'label' => $a->code.' · '.$a->name(),
                    /* কোন মায়ের সন্তান — ছাঁকনিটা এটাই দেখে।
                       ১১০১ নগদ · ১১০২ ব্যাংক · ১১০৫ মোবাইল মানি */
                    'parent' => (string) ($a->parent?->code ?? ''),
                ])
                ->values(),

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
                'due' => (float) $c->outstanding(),
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
            'invoicePreview' => $this->invoicePreview(),

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
            'creditRules' => [
                'enabled' => $this->settings->enabled('customer.credit_limit_enabled'),
                'blocks' => $this->settings->enabled('customer.block_over_limit'),
                'zeroBlocks' => $this->settings->enabled('customer.zero_limit_blocks'),
                'canOverride' => Gate::allows('overrideCreditLimit', Customer::class),
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
                'vat' => $this->settings->get('master_data.tax_enabled', true),
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
        $batch = isset($data['batch_id'])
            ? Batch::query()->where('product_id', $product->id)->find($data['batch_id'])
            : null;

        if ($batch !== null) {
            return response()->json(['data' => [
                'known' => true,
                ...app(FreeAllowance::class)->onLot($batch, (string) $data['qty']),
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
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            /*
             * ⚠️ ক্রেতা বাধ্যতামূলক — `nullable` ছিল, আর সেটা বিপজ্জনক ছিল।
             *
             * ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ────────────────────
             * *"Walk-in Customer default bose thakte parbe na, ete vul
             * hobe — karon eta POS na, eta depot/wholesale counter.
             * Obosoi dekhe nishchit hoye party select korte hobe."*
             *
             * ── কেন ডিফল্ট ক্রেতা এখানে ভুল ────────────────────────────
             * দোকানে খুচরা বিক্রিতে ক্রেতা কে তা জানার দরকার নেই — টাকা
             * হাতে আসে, কাগজ শেষ। **ডিপো বা পাইকারিতে উল্টো**: মাল বাকিতে
             * যায়, আর "কার নামে গেল" প্রশ্নটাই পুরো খাতার ভিত্তি।
             *
             * ঘরটা আগে থেকে ভরা থাকলে তাড়াহুড়োয় কেউ না দেখে এগিয়ে যেতেন,
             * আর **পুরো একটা চালান ভুল পার্টির নামে বসে যেত** — টাকা আদায়
             * হত অন্যজনের কাছ থেকে, বকেয়া দেখাত আরেকজনের।
             *
             * ⚠️ পর্দা থেকে ডিফল্ট তোলাই যথেষ্ট নয়। ঘরটা `nullable` থাকলে
             * **ক্রেতাহীন চালান সার্ভার মেনেই নিত** — আর তখন কাগজটা কারও
             * নামেই থাকত না, কোনো ভুলবার্তা ছাড়াই।
             */
            'customer_id' => ['required', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'trx_date' => ['nullable', 'date', 'before_or_equal:today'],
            'do_no' => ['nullable', 'string', 'max:64'],
            'vehicle_no' => ['nullable', 'string', 'max:64'],
            'driver_name' => ['nullable', 'string', 'max:191'],
            'credit_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            /*
             * ⚠️ ধরনটা **তালিকার বাইরে থেকে আসতে পারে না**।
             *
             * ⛔ পর্দা যা পাঠায় তা বিশ্বাস করার কোনো কারণ নেই — কেউ
             * হাতে অনুরোধ বানালে খাতায় `xyz` লেখা একটা শর্ত বসে যেত,
             * আর প্রতিবেদনে ওটা কোনো ঘরে ফেলা যেত না।
             *
             * ⓘ `null` চলে: পুরনো পর্দা বা অন্য পথ থেকে আসা অনুরোধে
             * ঘরটা থাকে না, আর **না-জানা আর ভুল-জানা এক জিনিস নয়**।
             */
            'payment_term' => ['nullable', 'string',
                Rule::in(['cash', 'cod', 'credit', 'month_end', 'fixed'])],

            /*
             * মেয়াদের দ্বিতীয় মুখ — নির্দিষ্ট তারিখ (৩ সেপ্টেম্বর ২০২৬)।
             *
             * `after_or_equal:trx_date` — বিলের আগের তারিখে পরিশোধের
             * মেয়াদ শেষ হতে পারে না। ওটা বসতে দিলে বকেয়ার বয়সের
             * প্রতিবেদন প্রথম দিন থেকেই মেয়াদোত্তীর্ণ দেখাত।
             */
            'due_on' => ['nullable', 'date', 'after_or_equal:trx_date'],

            /*
             * বিলের নম্বর হাতে লেখা — মালিকের নির্দেশ।
             *
             * ⚠️ `unique` এখানে বসানো হয়নি, ইচ্ছাকৃতভাবে। যাচাই আর
             * সংরক্ষণের মাঝে এক মুহূর্তের ফাঁক থাকে, আর দুইটা কাউন্টার
             * একসাথে একই নম্বর লিখলে দুইটাই ওই যাচাই পাশ করে বেরিয়ে
             * যেত। আসল পাহারা ডাটাবেসের ইউনিক ইনডেক্সে —
             * [[SalesInvoiceService]] সেটা ট্রানজেকশনের ভেতরে ধরে।
             */
            'invoice_no' => ['nullable', 'string', 'max:32'],

            /*
             * ⭐ "খসড়া রাখুন" বোতামের চিহ্ন — ২৫ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ `in:0,1` ইচ্ছাকৃত, `boolean` নয়। ⚠️ `boolean` `"true"`,
             * `"yes"`, `"on"` সবই মেনে নেয়, আর তখন পর্দার লুকানো ঘরটা
             * কী পাঠাচ্ছে তা নিয়ে দুইটা মত তৈরি হত। ⛔ একটাই মান
             * বোঝানো হয়, আর সেবা ঠিক ঐটাই দেখে।
             */
            'save_as_draft' => ['nullable', 'in:0,1'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'expense_amount' => ['nullable', 'numeric', 'min:0'],
            /*
             * খরচটা কীসের — টাকার অঙ্কের পাশে বাধ্যতামূলক নয়, কিন্তু
             * থাকলে কাগজে ছাপা হয়।
             *
             * ── কেন অঙ্ক থাকলে কারণটাও চাওয়া হয় ──────────────────────
             * "খরচ ২০০" এক মাস পরে কারও কোনো কাজে আসে না। ওটা ভাড়া ছিল,
             * না হাম্মালি, না নাশতা — জানার একমাত্র সময় এখনই, যখন যিনি
             * টাকাটা দিয়েছেন তিনি সামনেই দাঁড়ানো।
             */
            /*
             * ⚠️ `required_with` ছিল, আর সেটা ভুল ছিল (মাপা ৩ সেপ্টেম্বর ২০২৬)।
             *
             * `required_with:expense_amount` চালু হয় ঘরটা **উপস্থিত ও খালি
             * নয়** হলে। খরচের ঘরে কেউ `0` লিখলে ওটা উপস্থিত আর খালি নয় —
             * তাই **শূন্য খরচেও কারণ চাওয়া হত**, আর ব্যবহারকারী আটকে যেতেন
             * এমন একটা ঘরে যেটা তাঁর দরকারই ছিল না।
             *
             * এখন শর্তটা **অঙ্কে**, উপস্থিতিতে নয়: টাকা গেলে কারণ লাগবে,
             * না গেলে নয়।
             */
            'expense_narration' => ['nullable', 'string', 'max:191',
                Rule::requiredIf(fn (): bool => (float) $request->input('expense_amount', 0) > 0)],

            /*
             * পরিবহন — গাড়ি ও চালক আগে থেকেই ছিল, ভাড়াটা ছিল না।
             *
             * ভাড়াটা খরচের ঘরে ঢুকিয়ে দেওয়া যেত, কিন্তু তাতে "এই চালানে
             * পরিবহনে কত গেল" প্রশ্নের উত্তর আর আলাদা করে পাওয়া যেত না —
             * আর রুটপ্রতি খরচ বের করার একমাত্র উপায় ওটাই।
             */
            'carrier_name' => ['nullable', 'string', 'max:191'],
            /*
             * ⓘ বাহক বাছাই ঐচ্ছিক, আর সেটাই ঠিক: বহরের বাইরের একবারের
             * গাড়ির কোনো চলতি হিসাব থাকে না — টাকা ওই দিনই মেটে। তখন
             * নামটাই যথেষ্ট, আর ভাড়াটা সাধারণ প্রদেয়তে যায়।
             */
            'carrier_id' => ['nullable', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],

            /*
             * চালানটা কোথায় যাচ্ছে।
             *
             * গ্রাহকের ঠিকানা মাস্টারে আছে, কিন্তু মাল সবসময় সেখানে যায়
             * না — দোকান এক জায়গায়, গুদাম আরেক জায়গায়, আর মাঝে মাঝে
             * সরাসরি বাজারে। কাগজে ভুল ঠিকানা ছাপা মানে গাড়ি ভুল জায়গায়।
             */
            'ship_to' => ['nullable', 'string', 'max:191'],
            'ship_date' => ['nullable', 'date'],

            /*
             * কাউন্টারে নেওয়া টাকার বিবরণ — অঙ্কটা আগে থেকেই ছিল।
             *
             * নগদ ছাড়া অন্য কিছুতে (চেক, বিকাশ) নম্বর ছাড়া টাকাটা আর
             * খুঁজে পাওয়া যায় না, আর ব্যাংকের কাগজের সাথে মেলানোও যায় না।
             */
            'deposit_method' => ['nullable', 'string', 'max:32'],
            'deposit_ref' => ['nullable', 'string', 'max:64'],
            /*
             * ── রাউন্ডিং — দুই দিকে যায়, কিন্তু সীমার ভেতরে ─────────────
             *
             * ── কেন `min:0` তুলে দেওয়া হলো (৩ সেপ্টেম্বর ২০২৬) ──────────
             * রাউন্ডিং **দুই দিকেই** যায়: ৪,৩০০.৪০-কে ৪,৩০০ করতে −০.৪০,
             * আর ৪,২৯৯.৬০-কে ৪,৩০০ করতে +০.৪০। `min:0` থাকায় **অর্ধেক
             * কাজটা করাই যেত না**, আর বিক্রেতা বাধ্য হয়ে ছাড়ের ঘরে বসাতেন
             * — তখন ওটা রিপোর্টে ছাড় হিসেবে গোনা হত।
             *
             * ── আর সীমাটা কেন (মালিকের নির্দেশ) ─────────────────────────
             * ⚠️ সীমা ছাড়া "রাউন্ডিং" ঘরটা **ছাড়ের পিছনের দরজা**: ওখানে
             * ৪৩০ টাকাও বসানো যেত। ছাড়ের নিজের অনুমোদন, সীমা ও রিপোর্ট
             * আছে; রাউন্ডিংয়ের কিছুই নেই — **যে ছাড় রাউন্ডিং সেজে যায়,
             * সেটা কোনো রিপোর্টেই ধরা পড়ে না।**
             *
             * ⓘ সীমাটা কন্ট্রোল প্যানেলে (Sales → সীমা), শূন্য মানে সীমা নেই।
             * ⚠️ পর্দার বাধাটা যথেষ্ট নয় — যে কেউ সরাসরি অনুরোধ পাঠাতে
             * পারে, তাই আসল পাহারা এখানেই।
             */
            'rounding_amount' => ['nullable', 'numeric', function (string $attr, mixed $value, callable $fail) {
                $max = (float) $this->settings->get('sales.rounding_max', 0);

                if ($max > 0 && abs((float) $value) > $max) {
                    $fail(__('sales::validation.rounding_over_limit', ['max' => $max]));
                }
            }],
            'deposit' => ['nullable', 'numeric', 'min:0'],

            /*
             * ── একাধিক জমা — প্রতিটার নিজের খাত ─────────────────────────
             *
             * ⭐ ইঞ্জিনটা নতুন নয়: POS-এ `payments[][...]` আগে থেকেই চলছে।
             * এখানে ঘরগুলো একটু আলাদা (তারিখ ও বিবরণ লাগে, ফেরত লাগে না),
             * তাই নামটাও আলাদা — কিন্তু ধরনটা এক।
             *
             * ⚠️ `gt:0` — শূন্য টাকার একটা জমার সারি মানে একটা **খালি
             * আদায়ের কাগজ** খাতায় বসে যাওয়া, যেটা পরে কেউ ব্যাখ্যা করতে
             * পারত না। খালি সারি পর্দাতেই বাদ যায়, আর সার্ভারও নেয় না।
             *
             * ⓘ `deposit` ঘরটা রয়ে গেছে, আর ইচ্ছে করেই: পুরনো পথে আসা
             * অনুরোধ (এবং POS-এর মতো অন্য পর্দা) আগের মতোই চলবে। দুইটা
             * একসাথে এলে `deposits`-ই সত্য, কারণ ওটাই বিস্তারিত।
             */
            'deposits' => ['nullable', 'array', 'max:20'],
            'deposits.*.amount' => ['required', 'numeric', 'gt:0'],
            'deposits.*.payment_method_id' => ['nullable', 'integer',
                Rule::exists('mdm_payment_methods', 'id')->where('company_id', $companyId)],
            /*
             * ⚠️ খাত **বাধ্যতামূলক**, আর এটাই এই কাজের আসল সংশোধন।
             *
             * খালি রাখলে `CollectionService` প্রধান টিলের নগদ খাত ধরে নেয়
             * — অর্থাৎ বিকাশের টাকা নীরবে নগদ হয়ে যেত। ⓘ পুরনো একঘরের
             * পথটায় ওই ডিফল্ট এখনো আছে (নগদ বিক্রয়ে ওটাই ঠিক), কিন্তু
             * যেখানে বিক্রেতা **উপায় বেছে দিচ্ছেন**, সেখানে অনুমান করা
             * মানে তাঁর বাছাইটা ফেলে দেওয়া।
             */
            'deposits.*.account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'deposits.*.ref_date' => ['nullable', 'date'],
            'deposits.*.reference' => ['nullable', 'string', 'max:64'],
            'deposits.*.narration' => ['nullable', 'string', 'max:191'],

            /*
             * ⭐ আদায় ভাউচারের তিনটা ঘর — মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬।
             *
             * ── ⓘ কেন এগুলো এখানে এল ─────────────────────────────────
             * *"জমা যোগ botam clic korle eirokom 100% same pop up open
             * hobe"* — অর্থাৎ কাউন্টারের জমা আদায় ভাউচারের মতোই পূর্ণ
             * হবে: কখন এল, কার হাত দিয়ে এল, আর নগদ হলে কোন নোটে।
             *
             * ⚠️ ঘর তিনটা `vouchers` টেবিলে **আগে থেকেই আছে**
             * (১৪ নভেম্বরের মাইগ্রেশন), তাই নতুন কিছু বসাতে হয়নি —
             * কেবল কাউন্টারের পথটা ওগুলো বহন করত না।
             *
             * ── ⛔ নাম তিন জায়গায় বসাতে হয়, আর একটাও বাদ পড়লে নীরব ───
             * যাচাই এখানে · সারি [[DirectSaleService::depositRows()]]-এ ·
             * ভাউচার [[DirectSaleService::counterVoucher()]]-এ। ⓘ তিনটাই
             * ঘর **হাতে বেছে** নেয়। ⚠️ abos-13 আজ ঠিক এই আকারে একটা
             * ভাঙা জোড় পেয়েছেন: `batch_id` `fillable`-এ ছিল, সেবা ওটা
             * চাইত ও যাচাই করত, তবু সারিতে বসত না — কারণ
             * `replaceLines()`-এর হাতে-বাছা তালিকায় নামটা ছিল না।
             */
            'deposits.*.moved_at' => ['nullable', 'date_format:H:i'],

            /*
             * ⓘ বাহক — কেবল এই কোম্পানির মানুষ।
             *
             * ⚠️ `exists:users,id` যথেষ্ট নয়: ব্যবহারকারীর টেবিলে কোনো
             * কোম্পানি-স্কোপ নেই (বহু-কোম্পানি পিভট ধরে), তাই ঢালাও
             * `exists` অন্য ক্রেতার মানুষকেও মেনে নিত।
             */
            'deposits.*.carried_by' => ['nullable', 'integer',
                Rule::exists('company_user', 'user_id')
                    ->where('company_id', CompanyContext::id())],

            /*
             * নোটের হিসাব — চাবি নোটের মান, মান কতটা।
             *
             * ⚠️ যোগফলটা এখানে মেলানো হয় না, আর সেটা ইচ্ছাকৃত: গোনা
             * টাকা আর লেখা টাকা আলাদা হতেই পারে (বিক্রেতা গুনতে ভুল
             * করেন, বা আংশিক গোনেন)। ⓘ পর্দা পার্থক্যটা **দেখায়**,
             * আটকায় না — ঠিক আদায় ভাউচারের মতোই।
             */
            'deposits.*.note_counts' => ['nullable', 'array'],
            'deposits.*.note_counts.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'narration' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.free_qty' => ['nullable', 'numeric', 'min:0'],
            /*
             * ⛔ দর শূন্য নয় — মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬।
             *
             * তাঁর কথা: *"sales price chara entry nibe na"*।
             *
             * ── ⚠️ শূন্য দরে বিক্রির ক্ষতিটা নীরব ─────────────────────
             * ⓘ মাল গুদাম থেকে নামে, খরচ খাতায় বসে, কিন্তু আয় শূন্য —
             * ⛔ অর্থাৎ প্রতিটা শূন্য-দরের সারি খাতায় **সরাসরি লোকসান**
             * লেখে, আর কোনো পর্দা লাল হয় না।
             *
             * ⓘ ফ্রি বা উপহারের মাল এতে আটকায় না: ওগুলোর নিজের ঘর ও
             * নিজের টেবিল আছে (`free_qty`, উপহারের সারি), আর সেখানে দর
             * চাওয়াই হয় না।
             */
            'lines.*.rate' => ['required', 'numeric', 'gt:0'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            /*
             * ⭐ লট — মালিকের সিদ্ধান্ত, ২৫ সেপ্টেম্বর ২০২৬: বাছা বাধ্যতামূলক।
             *
             * ⚠️ এখানে `nullable`, আর সেটা ইচ্ছাকৃত — ⓘ "বাধ্যতামূলক" কেবল
             * **লট ধরা** পণ্যে, আর কোন পণ্য কোনটা তা এই নিয়ম জানে না।
             * ⛔ `required` লিখলে চাল-ডাল-সাবানের প্রতিটা সারিও লট চাইত।
             *
             * ⓘ আসল দেয়ালটা সেবায় ([[DirectSaleService]]), কারণ সেখানেই
             * পণ্যটা হাতে আসে। ⚠️ এখানকার নিয়মটা কেবল **আকার** দেখে:
             * সংখ্যা কি না, আর এই কোম্পানির লট কি না।
             */
            'lines.*.batch_id' => ['nullable', 'integer',
                Rule::exists('inv_batches', 'id')->where('company_id', $companyId)],

            'gifts' => ['nullable', 'array'],
            'gifts.*.product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'gifts.*.against_product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'gifts.*.qty' => ['nullable', 'numeric', 'min:0'],
            'gifts.*.remarks' => ['nullable', 'string', 'max:191'],
        ]);

        $result = $this->sales->complete(
            $data,
            $data['lines'],
            array_values(array_filter(
                $data['gifts'] ?? [],
                fn (array $gift) => filled($gift['product_id'] ?? null) && (float) ($gift['qty'] ?? 0) > 0,
            )),
        );

        /*
         * ⭐ কাউন্টারের ডিপোজিটে সই লাগলে — বিলের পাতায়, ছাপায় নয় (১৯ সেপ্টেম্বর)।
         *
         * ⓘ মালিকের নিয়ম: *"কোনো print option আসবে না যতক্ষণ approve
         * হচ্ছে।"* ⚠️ তাই রসিদের PDF-এ যাওয়াই চলে না; বিলের পাতা বলে কোন
         * ডিপোজিট কার সইয়ের অপেক্ষায়, আর সই হলে সেখান থেকেই "নিশ্চিত"।
         */
        if (($result['awaiting'] ?? []) !== []) {
            return redirect()
                ->route('sales.invoice.show', $result['invoice']->id)
                ->with('saved', __('sales::message.direct_sale_held', [
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
        if ($warehouse === null) {
            return [];
        }

        $balances = DB::table('inv_stock_movements')
            ->selectRaw('batch_id, COALESCE(SUM(floor_change), 0) as qty')
            ->where('company_id', CompanyContext::id())
            ->where('warehouse_id', $warehouse->id)
            ->whereNotNull('batch_id')
            ->groupBy('batch_id')
            ->pluck('qty', 'batch_id');

        return Batch::query()
            ->whereIn('product_id', Product::query()->active()->where('track_batch', true)->select('id'))
            ->unexpired()
            ->fefo()
            ->get()
            ->map(fn (Batch $b) => [
                'id' => (string) $b->id,
                'productId' => (string) $b->product_id,
                'no' => (string) $b->batch_no,
                'expiry' => $b->expiry_date?->toDateString() ?? '',
                'qty' => (string) ($balances[$b->id] ?? '0'),
            ])
            /*
             * ⛔ যে লটে কিছু নেই সে বাছাইয়ের তালিকায় আসে না। ⓘ ওটা বেছে
             * ফেললে সারিটা কার্টে উঠত আর সংরক্ষণের সময় ভেঙে পড়ত —
             * অর্থাৎ ভুলটা ধরা পড়ত ত্রিশটা সারি তোলার পরে।
             */
            ->filter(fn (array $lot) => bccomp($lot['qty'], '0', 4) > 0)
            ->groupBy('productId')
            ->map(fn ($rows) => $rows->values()->all())
            ->all();
    }

    private function catalogue(?Warehouse $warehouse): Collection
    {
        $sum = fn (string $column) => DB::table('inv_stock_movements')
            ->selectRaw("COALESCE(SUM({$column}), 0)")
            ->whereColumn('product_id', 'inv_products.id')
            ->where('company_id', CompanyContext::id())
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id));

        return Product::query()
            ->active()
            ->with(['unit', 'tax'])
            ->select('inv_products.*')
            ->selectSub($sum('floor_change'), 'floor_total')
            ->selectSub($sum('reserved_change'), 'reserved_total')
            ->selectSub($sum('hold_change'), 'hold_total')
            ->selectSub($sum('free_change'), 'free_total')
            ->selectSub($sum('free_reserved_change'), 'free_reserved_total')
            ->orderBy('name_en')
            ->limit(self::INLINE_CATALOGUE_LIMIT)
            ->get()
            ->map(function (Product $p) use ($warehouse) {
                /*
                 * খাবারের উত্তরটা আলাদা — কারণটা
                 * [[RecipeService::sellableQty()]]-এ। POS-ও ঠিক এই
                 * ডাকটাই করে, তাই দুই কাউন্টারে একই খাবারের পাশে
                 * দুইটা সংখ্যা বসে না।
                 */
                $available = $this->recipes->sellableQty((int) $p->id, bcsub(
                    bcsub((string) $p->floor_total, (string) $p->reserved_total, 4),
                    (string) $p->hold_total,
                    4,
                ), $warehouse?->id);

                return (object) [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name(),

                    // ⛔ খোঁজার জন্য দুইটা নামই — গ্রাহকের ঘরের একই কারণে
                    'name_en' => (string) $p->name_en,
                    'name_bn' => (string) $p->name_bn,

                    'unit' => $p->unit?->name() ?? '',
                    'rate' => (string) $p->sale_price,
                    'barcode' => (string) $p->barcode,

                    /*
                     * ভ্যাটের হার পণ্যের নিজের কর থেকে।
                     *
                     * পর্দায় হার বসিয়ে দিলে পণ্যভেদে আলাদা হার আর মানা হত
                     * না — ওষুধে শূন্য, বিস্কুটে সাড়ে সাত।
                     */
                    'vatRate' => (float) ($p->tax?->rate ?? 0),

                    /*
                     * দামের ভেতরের ভ্যাট — পর্দাকে বলে দিতে হয়।
                     *
                     * সার্ভার ভেতরের ভ্যাটে মোট বাড়ায় না (দরেই ওটা আছে),
                     * কিন্তু পর্দা না জানলে সে যোগ করে দিত — আর তখন
                     * বিক্রেতার চোখের সামনের সংখ্যা আর বিলের সংখ্যা
                     * আলাদা হত। ঠিক এই দূরত্বটাই ৩১ আগস্টে ধরা পড়েছে।
                     */
                    'vatInclusive' => (bool) ($p->tax?->is_inclusive ?? false),

                    // ক্রয়মূল্য — ভেতরের কথা, তাই পর্দায় বোতামের পেছনে
                    'cost' => (float) $p->purchase_price,

                    // নমুনার লাইভ স্টক প্যানেল — ছয়টাই
                    'main' => (string) $p->floor_total,
                    'reserved' => (string) $p->reserved_total,
                    'hold' => (string) $p->hold_total,
                    'available' => $available,
                    'free' => (string) $p->free_total,

                    /*
                     * ⭐ পুনঃক্রয়ের সীমা — খোঁজার তালিকায় "কম মজুদ" লাল
                     * দেখানোর জন্য (মালিকের নির্দেশ, ৬ সেপ্টেম্বর ২০২৬)।
                     *
                     * ⚠️ সীমাটা **পণ্যের নিজের কলাম**, কোনো ধ্রুবক নয়।
                     * ⓘ "কত হলে কম" প্রশ্নের উত্তর পণ্যভেদে আলাদা — চালের
                     * বস্তা আর ওষুধের পাতা এক মাপে কম হয় না — আর
                     * গ্রাহকভেদেও আলাদা। ⛔ কোডে একটা সংখ্যা বসালে সেটা
                     * প্রতিটা কোম্পানির জন্য ভুল হত।
                     *
                     * ⓘ ডিফল্ট ০, অর্থাৎ যিনি সীমা বসাননি তাঁর কিছুই লাল
                     * হয় না — নীরবে সবকিছু লাল দেখানোর চেয়ে সেটা ভালো।
                     */
                    'reorder' => (string) $p->reorder_level,
                    'free_available' => bcsub((string) $p->free_total, (string) $p->free_reserved_total, 4),

                    /*
                     * ⭐ লট ধরা পণ্য কি না — মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬।
                     *
                     * ⓘ পর্দাটা এটা দেখেই ঠিক করে লট বাছাইয়ের ঘরটা দেখাবে
                     * কি না। ⚠️ ডিপোর চাল-ডাল-সাবানে ঘরটা আসেই না — ⛔ প্রতিটা
                     * সারিতে একটা বাড়তি বাছাই কেবল টাইপিং বাড়াত।
                     */
                    'trackBatch' => (bool) $p->track_batch,
                ];
            })
            /*
             * ⛔ মজুদ শূন্য হলে খোঁজার তালিকায় আসে না — মালিকের নির্দেশ,
             * ২১ সেপ্টেম্বর ২০২৬: *"0 stock products ekhane asbe na"*।
             *
             * ⓘ কাউন্টারে তালিকাটা বেচার জন্য, দেখার জন্য নয়। যে মাল নেই
             * তার সারি বিক্রেতাকে কেবল পেরোতে হয় — আর ডিপোতে শূন্য মজুদের
             * পণ্যই বেশি, তাই শুরুর তিরিশটা সারির পুরোটাই শূন্য দিয়ে ভরা থাকত।
             *
             * ⚠️ ছাঁকনিটা মানচিত্রের **পরে**, SQL-এ নয়। ⓘ কারণ অর্ডারে-রান্না
             * খাবারের নিজের মজুদ শূন্যই থাকে, অথচ উপকরণ দিয়ে চল্লিশ প্লেট
             * হয় ([[RecipeService::sellableQty()]])। ⛔ SQL-এ ছাঁকলে বিরিয়ানি তালিকা
             * থেকেই হারাত, আর সেটা শূন্য দেখানোর চেয়েও খারাপ।
             */
            ->filter(fn (object $p) => bccomp((string) $p->available, '0', 4) > 0
                || bccomp((string) $p->free_available, '0', 4) > 0)
            ->values();
    }

    private function warehouse(Request $request): ?Warehouse
    {
        $id = $request->integer('warehouse_id');

        return $id > 0
            ? Warehouse::query()->find($id)
            : Warehouse::query()->where('is_default', true)->active()->first();
    }

    /** সিরিজের পরের নম্বর, কেবল দেখানোর জন্য — [[NumberSeriesEngine::preview()]]. */
    private function invoicePreview(): string
    {
        $series = NumberSeries::query()
            ->where('company_id', CompanyContext::id())
            ->where('doc_type', 'INV')
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
        $terms = [
            ['value' => 'cash', 'label' => __('sales::field.term_cash')],

            /*
             * ⭐ COD এখানে সত্যিই আলাদা কিছু বোঝায়, আর ওটাই ক্রয়ের
             * সাথে পার্থক্য।
             *
             * নগদ  → টাকা ড্রয়ারে, জমার ঘরে বসে
             * COD  → মাল ভ্যানে গেল, টাকা ফিরবে ডেলিভারিম্যানের সাথে
             *
             * ⚠️ দুইটার **তারিখ একই দিন**, তবু একটা আদায় হয়ে গেছে আর
             * আরেকটা পাওনা — খাতায় দুইটা সম্পূর্ণ আলাদা অবস্থা।
             */
            ['value' => 'cod', 'label' => __('sales::field.term_cod')],
        ];

        $rows = PaymentTerm::query()
            ->where('is_active', true)
            ->orderBy('days')
            ->get(['id', 'code', 'name_en', 'name_bn', 'days']);

        foreach ($rows as $row) {
            if ((int) $row->days <= 0) {
                continue;
            }

            $terms[] = [
                'value' => 'credit:'.(int) $row->days,
                'label' => __('sales::field.term_credit', ['count' => (int) $row->days]),
            ];
        }

        $terms[] = ['value' => 'month_end', 'label' => __('sales::field.term_month_end')];
        $terms[] = ['value' => 'fixed', 'label' => __('sales::field.term_fixed')];

        return $terms;
    }
}
