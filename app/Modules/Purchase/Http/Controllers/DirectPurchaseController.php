<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\LastPaidRate;
use App\Modules\Supplier\Models\Supplier;
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
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'warehouse' => $warehouse,
            'moneyAccounts' => $this->moneyAccounts(),

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
            'supplier_bill_no' => ['nullable', 'string', 'max:64'],
            'due_on' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:500'],

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

        $result = $this->purchases->complete($data, $data['lines'], $data['gifts'] ?? []);

        return redirect()
            ->route('purchase.bill.show', $result['bill']->id)
            ->with('saved', __('purchase::message.direct_done', [
                'no' => $result['bill']->document_no,
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

        return $chosen > 0
            ? Warehouse::query()->find($chosen)
            : Warehouse::query()->where('is_default', true)->first()
                ?? Warehouse::query()->active()->orderBy('code')->first();
    }

    /**
     * পর্দার পণ্য তালিকা — মজুদ ও শেষ দর সহ।
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalogue(?Warehouse $warehouse): array
    {
        return Product::query()
            ->active()
            ->orderBy('name_en')
            ->limit(self::INLINE_CATALOGUE_LIMIT)
            ->get()
            ->map(fn (Product $p) => $this->purchases->stockPanel($p, $warehouse))
            ->all();
    }

    /** নগদ ও ব্যাংক — টাকাটা কোথা থেকে গেল। */
    private function moneyAccounts()
    {
        $heads = Account::query()
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
