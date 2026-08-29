<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PackBarcode;
use App\Modules\Inventory\Services\RecipeService;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CounterApproval;
use App\Modules\Sales\Services\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * কাউন্টারের পর্দা।
 *
 * ── কেন পণ্যগুলো আগেই পাঠানো হয় ──────────────────────────────────────
 * পুরো পণ্যতালিকা পাতার সাথেই যায়, আর খোঁজা হয় ব্রাউজারেই। প্রতিটা
 * অক্ষরে সার্ভারে গেলে কাউন্টারে দেরি হত — আর ডিপোর ইন্টারনেট গেলে
 * বিক্রিই বন্ধ হয়ে যেত (docs/Decision — নেটওয়ার্ক গেলে কী)।
 *
 * তালিকা বড় হলে (কয়েক হাজার পণ্য) এটা আর চলবে না; তখন সার্ভারে খোঁজা
 * ফিরবে। সেই দিনের জন্য অনুমানটা এখানে লেখা রইল, যাতে সীমাটা কেউ
 * আবিষ্কার না করে বরং জানতে পারে।
 */
class PosController extends Controller implements HasMiddleware
{
    /** এর বেশি পণ্য হলে পাতার সাথে পাঠানো বন্ধ, সার্ভারে খোঁজা শুরু। */
    private const INLINE_CATALOGUE_LIMIT = 2000;

    public function __construct(
        private readonly PosService $pos,
        private readonly SettingsService $settings,
        private readonly MenuBuilder $menu,
        private readonly CounterApproval $approvals,
        private readonly RecipeService $recipes,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.pos')];
    }

    public function index(Request $request): View
    {
        $warehouse = $this->warehouse($request);

        return view('sales::pos.index', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => $this->catalogue($warehouse),
            'customers' => Customer::query()->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_bn']),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'warehouse' => $warehouse,
            'walkinId' => (int) $this->settings->get('sales.walkin_customer_id', 0),
            'todaysTotal' => $this->pos->todaysTotal(),

            // কাউন্টারে ছাড়ের ঘরটা প্রতি-কোম্পানি সুইচে (নিয়ম ৭)
            'discountOn' => $this->settings->enabled('sales.field_line_discount'),

            /*
             * টাকা নেওয়ার উপায়গুলো।
             *
             * ── কেন এতদিন পর্দায় ছিল না ─────────────────────────────
             * `PosService` বিকাশ/কার্ড আগে থেকেই বুঝত, কিন্তু পর্দায়
             * বাছার কোনো ঘর ছিল না — অর্থাৎ সুবিধাটা কোডে ছিল, কাউন্টারে
             * ছিল না। এক ধরনের স্টাব: কাজটা আছে বলে দেখায়, অথচ যিনি
             * ব্যবহার করবেন তিনি পৌঁছাতে পারেন না।
             */
            'methods' => PaymentMethod::query()->active()->orderBy('code')
                ->get(['id', 'code', 'name_en', 'name_bn', 'needs_reference', 'account_id']),

            /*
             * কাউন্টারে ঝুলে থাকা বিলগুলো — পুরনোটা আগে।
             *
             * যেটা সবচেয়ে বেশিক্ষণ ঝুলে আছে সেটাই সবচেয়ে সম্ভাব্য
             * পরিত্যক্ত, আর দিন শেষে ওটাই আগে সিদ্ধান্ত চায়।
             */
            'parked' => $this->pos->parked()->map(fn (SalesInvoice $bill) => (object) [
                'id' => $bill->id,
                'no' => $bill->document_no,
                'since' => $bill->parked_at?->diffForHumans(),
                'total' => (string) $bill->total,
                'lines' => $bill->lines->count(),
            ]),

            /*
             * তোলা বিলের সারিগুলো — পর্দা খোলার সাথেই কার্টে বসে।
             *
             * তোলার পর ভিন্ন পাতায় না পাঠিয়ে একই পর্দায় ফেরানো হয়:
             * ক্যাশিয়ারের কাছে কাউন্টার একটাই জায়গা, আর ওখান থেকে
             * সরালে অভ্যাসটাই ভেঙে যেত।
             */
            'resumed' => $this->resumedCart($request),

            /*
             * তোলা বিলটার নম্বর — "সম্পূর্ণ" চাপলে ওটাই শেষ হবে, নতুন
             * একটা নয়। না থাকলে খালি, আর তখন আচরণ আগের মতোই।
             */
            'resumedId' => $this->resumedInvoice($request)?->id,

            /*
             * ছাড়ের অনুমোদনের ঘরটা কেবল তখনই, যখন সত্যিই অপেক্ষা করছে।
             *
             * সবসময় দেখালে কাউন্টারে রোজ একটা ইমেইল-পাসওয়ার্ডের বাক্স
             * চোখে পড়ত, আর যা রোজ চোখে পড়ে মানুষ তা ভরে ফেলেন — তখন
             * ম্যানেজারের পাসওয়ার্ড ক্যাশিয়ারের মুখস্থ হয়ে যেত।
             */
            'awaitingApproval' => $this->awaitingApproval($request),
        ]);
    }

    /** ঠিকানায় বলা খসড়াটা — থাকলে, আর সেটা এখনো খসড়া হলে। */
    private function resumedInvoice(Request $request): ?SalesInvoice
    {
        $id = $request->integer('resume');

        if ($id <= 0) {
            return null;
        }

        return SalesInvoice::query()
            ->where('status', DocumentStatus::DRAFT)
            ->with('lines.product')
            ->find($id);
    }

    /** তোলা বিলটার ছাড় ম্যানেজারের সিদ্ধান্তের অপেক্ষায় কি না। */
    private function awaitingApproval(Request $request): bool
    {
        $invoice = $this->resumedInvoice($request);

        return $invoice !== null && $this->approvals->pending($invoice, 'discount') !== null;
    }

    public function checkout(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'paid' => ['required', 'numeric', 'min:0'],

            /*
             * টিলের চাবি — এক কার্টে একটা, ব্রাউজারে তৈরি।
             *
             * ঐচ্ছিক, কারণ পুরনো টিল বা পরীক্ষার কোড এটা পাঠায় না, আর
             * না পাঠালে আচরণ আগের মতোই। যারা পাঠায়, তাদের দ্বিতীয়বার
             * Enter চাপা দ্বিতীয় বিল বানায় না।
             */
            'idempotency_key' => ['nullable', 'string', 'max:64'],

            /*
             * তোলা বিলটার নম্বর — থাকলে ওটাই সম্পূর্ণ হয়।
             *
             * না পাঠালে আগের মতোই নতুন বিল, তাই পুরনো টিল বা পরীক্ষার
             * কোড ভাঙে না।
             */
            'resumed_invoice_id' => ['nullable', 'integer',
                Rule::exists('sal_invoices', 'id')->where('company_id', $companyId)],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],

            /*
             * ম্যানেজারের নিজের লগইন — ছাড় সীমা ছাড়ালে তবেই লাগে।
             *
             * ঐচ্ছিক, কারণ রোজকার বিক্রিতে এগুলো আসেই না। পাসওয়ার্ডটা
             * কোথাও লেখা হয় না: `AuditEngine` `password` নামের ঘর
             * কোনোদিন লগ করে না, আর ব্যর্থ হলে পুরনো ইনপুট ফেরত
             * পাঠানোর সময়ও এটা বাদ যায় (`$request->except`)।
             */
            'approver_email' => ['nullable', 'string', 'email', 'max:255'],
            'approver_password' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->pos->checkout($data, $data['lines']);

        /*
         * সোজা রসিদে — কাউন্টারে বিক্রির পরের কাজটা ছাপা, আর কিছু নয়।
         *
         * ৮০mm ডিফল্ট: থার্মাল রোলই কাউন্টারের স্বাভাবিক কাগজ। যিনি A4
         * চান তিনি বিলের পাতা থেকে নিতে পারেন।
         */
        return redirect()
            ->route('sales.print.invoice', ['invoice' => $result['invoice']->id, 'paper' => '80mm'])
            ->with('saved', __('sales::message.pos_done', [
                'no' => $result['invoice']->document_no,
                'change' => Money::format($result['change']),
            ]));
    }

    /**
     * তোলা বিলটার সারি — কার্টে বসানোর মতো করে।
     *
     * খালি অ্যারে ফেরে যদি কিছু তোলা না হয়ে থাকে, বা যে বিলটার কথা
     * বলা হয়েছে সেটা এই কোম্পানির না হয়। খসড়া নয় এমন বিলও বাদ:
     * নিশ্চিত হওয়া বিল কার্টে তুললে ক্যাশিয়ার দ্বিতীয়বার টাকা নিতেন।
     *
     * @return list<array<string, mixed>>
     */
    private function resumedCart(Request $request): array
    {
        $invoice = $this->resumedInvoice($request);

        if ($invoice === null) {
            return [];
        }

        return $invoice->lines->map(fn ($line) => [
            'product_id' => $line->product_id,
            'name' => $line->product?->name() ?? '',
            'qty' => (string) $line->qty,
            'rate' => (string) $line->rate,
            'discount' => (string) $line->discount,
        ])->values()->all();
    }

    /**
     * কার্টটা ধরে রাখা — ক্রেতা টাকা আনতে গেছেন, পেছনে লাইন।
     *
     * ── কেন বাতিল নয় ────────────────────────────────────────────────
     * এটা না থাকলে ক্যাশিয়ার যা করেন সেটাই সমস্যা: বিলটা বাতিল করেন,
     * আর ক্রেতা ফিরলে আবার গোড়া থেকে টাইপ করেন। তখন বাতিলের সংখ্যা
     * দিয়ে আর কিছু বোঝা যায় না — দিনে ত্রিশটা বাতিল দেখে বলার উপায়
     * থাকে না কোনটা ভুল, কোনটা চুরির চেষ্টা, আর কোনটা কেবল একজন
     * ক্রেতা টাকা আনতে গিয়েছিলেন।
     */
    public function park(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'narration' => ['nullable', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $invoice = $this->pos->park($data, $data['lines']);

        return redirect()
            ->route('sales.pos.index')
            ->with('saved', __('sales::message.pos_parked', ['no' => $invoice->document_no]));
    }

    /**
     * ধরে রাখা বিলটা আবার কাউন্টারে তোলা।
     *
     * তোলার পর ওটা আর অপেক্ষমাণ তালিকায় থাকে না — নাহলে একই বিল দুই
     * কাউন্টারে একসাথে তোলা যেত, আর একজন নিশ্চিত করার পর অন্যজন খালি
     * পর্দা নিয়ে বসে থাকতেন।
     */
    public function resume(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        /*
         * অন্য কোম্পানির বিল এখানে পৌঁছায়ই না — SalesInvoice-এ কোম্পানির
         * গ্লোবাল স্কোপ আছে, তাই রুট-বাইন্ডিংই ৪০৪ দেয়। তবু ধরে নেওয়া
         * হয় না; স্কোপটা কোনোদিন সরলে এই লাইনটাই শেষ পাহারা।
         */
        abort_unless((int) $invoice->company_id === CompanyContext::id(), 404);

        $resumed = $this->pos->resume($invoice);

        return redirect()
            ->route('sales.pos.index', ['resume' => $resumed->id])
            ->with('saved', __('sales::message.pos_resumed', ['no' => $resumed->document_no]));
    }

    /**
     * বারকোড দিয়ে একটা পণ্য — স্ক্যানার এখানেই আসে।
     *
     * পাতার সাথে পাঠানো তালিকায় না পেলে তখনই কেবল সার্ভারে আসা হয়, তাই
     * স্বাভাবিক অবস্থায় এই রুটটা কখনো ডাকা হয় না।
     */
    /**
     * নম্বর ধরে বিলটা — কাউন্টারে ফেরত নেওয়ার জন্য।
     *
     * সারিগুলোর সাথে "আগে কত ফেরত গেছে" পাঠানো হয়, কারণ একই বিল থেকে
     * দ্বিতীয়বার ফেরত নিতে গেলে ক্যাশিয়ারকে জানতে হয় আর কতটুকু বাকি।
     * না জানালে তিনি পুরোটা টাইপ করতেন, আর সেবাটা আটকে দিত — ক্রেতার
     * সামনে দাঁড়িয়ে।
     */
    public function bill(Request $request): JsonResponse
    {
        try {
            $invoice = $this->pos->soldOn((string) $request->query('no'));
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'id' => $invoice->id,
            'no' => $invoice->document_no,
            'customer' => $invoice->customer?->name(),
            'date' => $invoice->trx_date?->toDateString(),
            'lines' => $invoice->lines->map(fn ($line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'name' => $line->product?->name(),
                /*
                 * দুইটাই একই ছাঁচে।
                 *
                 * `qty` কাঁচা ঢাললে "১ / ১.০০০০" দেখাত, আর পাশের
                 * সংখ্যাটা "১" — একই পর্দায় একই জিনিস দুই চেহারায়।
                 */
                'qty' => Money::quantity((string) $line->qty),
                'returned' => $this->pos->alreadyReturned($line),
                'rate' => Money::format((string) $line->rate, 2),
            ])->all(),
        ]);
    }

    /**
     * ফেরতটা নেওয়া।
     *
     * ── কেন পুরো পাতা ফেরে, JSON নয় ─────────────────────────────────
     * ফেরতের পর ক্যাশিয়ারের একটা কাগজ লাগে — ক্রেতা ওটা চাইবেনই। পাতা
     * ফিরলে ছাপার লিংকটা সাথেই আসে, আর দ্বিতীয় একটা অনুরোধ লাগে না।
     */
    public function takeBack(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'document_no' => ['required', 'string'],
            'warehouse_id' => ['nullable', 'integer'],
            'reason_code_id' => ['nullable', 'integer'],
            'refund' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.sales_invoice_line_id' => ['nullable', 'integer'],
        ]);

        $result = $this->pos->takeBack($data, $data['lines']);

        return redirect()
            ->route('sales.pos.index')
            ->with('status', __('sales::message.pos_returned', [
                'no' => $result['return']->document_no,
                'amount' => Money::format((string) $result['return']->total, 2),
            ]));
    }

    public function lookup(Request $request, PackBarcode $barcodes): JsonResponse
    {
        $raw = trim((string) $request->query('code'));

        if ($raw === '') {
            return response()->json(null, 404);
        }

        /*
         * ২ডি বারকোড হলে আগে ভেঙে নেওয়া।
         *
         * ── এটা না থাকলে যা হত ──────────────────────────────────────
         * ওষুধের কার্টনের GS1 DataMatrix স্ক্যান করলে স্ক্যানার পাঠায়
         * পুরো element string — GTIN, মেয়াদ আর লট একসাথে, মাঝে
         * বিভাজক। ওই গোটা স্ট্রিংটা `barcode` কলামে খুঁজলে কোনোদিন
         * কিছু মিলত না, আর ক্যাশিয়ার ভাবতেন পণ্যটা তালিকায় নেই —
         * অথচ ওটা সামনেই আছে।
         *
         * সাধারণ ১ডি বারকোড (EAN-১৩) হুবহু আগের মতোই চলে: read()
         * ওটাকে GS1 নয় বলে চেনায়, আর কোডটা যেমন ছিল তেমনই থাকে।
         */
        /*
         * পড়া না গেলে কাঁচা কোডটাই খোঁজা হয় — অনুরোধ ভাঙা হয় না।
         *
         * read() অচেনা AI পেলে ব্যতিক্রম ছোঁড়ে, আর সেটা ঠিক: মজুদের
         * পর্দায় কেউ হাতে বারকোড বসালে তাঁকে জানানো দরকার লেখাটা
         * বেঠিক। কিন্তু কাউন্টারে প্রশ্নটা আলাদা — "এই কোডে কোনো পণ্য
         * আছে?" — আর সেখানে অপাঠ্য কোডের সৎ উত্তর "নেই", ৪২২ নয়।
         *
         * ব্যতিক্রম যেতে দিলে আরো খারাপ হত: এই রুটটা `api/*`-এ নয়, তাই
         * Laravel JSON নয়, রিডাইরেক্ট বানাতে যেত — আর ক্যাশিয়ারের
         * পর্দায় স্ক্যানারের একটা এলোমেলো পাঠানো লেখা গোটা কাউন্টার
         * ভেঙে দিত।
         */
        try {
            $parsed = $barcodes->read($raw);
        } catch (ValidationException) {
            $parsed = ['gtin' => null, 'batch_no' => null, 'expiry_date' => null];
        }

        $code = $parsed['gtin'] ?? $raw;

        $product = Product::query()
            ->active()
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('code', $code))
            ->with('unit')
            ->first();

        if ($product === null) {
            return response()->json(null, 404);
        }

        /*
         * স্ক্যান করা লটটা এই পণ্যের কি না — মিলিয়ে দেখা হয়।
         *
         * না মিললে লটটা পাঠানোই হয় না। ভুল লট কার্টে বসলে FEFO-র
         * সিদ্ধান্ত ছাপিয়ে ভুল বাক্স থেকে মাল বেরোত, আর রিকলের খাতা
         * মিথ্যা হত। মিলল না মানে সাধারণত পণ্যটা এখনো ওই লটে গুদামে
         * ওঠেনি — তখন FEFO নিজের কাজ করবে।
         */
        $batch = $parsed['batch_no'] === null ? null : Batch::query()
            ->where('product_id', $product->id)
            ->where('batch_no', $parsed['batch_no'])
            ->first();

        return response()->json([
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name(),
            'unit' => $product->unit?->name(),
            'rate' => (string) $product->sale_price,
            'barcode' => $product->barcode,

            /*
             * স্ক্যান থেকে পাওয়া লট ও মেয়াদ — পর্দায় দেখানোর জন্য।
             *
             * কার্টে লট বসানো হয় না: কোন লট বেরোবে সেটা FEFO ঠিক করে
             * মাল বেরোনোর মুহূর্তে, আর সেটাই ঠিক। এখানে সংখ্যাগুলো
             * থাকে যাতে ক্যাশিয়ার হাতের প্যাকেটটার সাথে মিলিয়ে নিতে
             * পারেন — বিশেষত মেয়াদটা।
             */
            'scanned_batch' => $batch?->batch_no,

            // মাস/বছর — প্যাকেটের গায়ে যেভাবে ছাপা থাকে, দিন ছাড়া
            'scanned_expiry' => $parsed['expiry_date']?->format('m/Y'),
            'batch_known' => $parsed['batch_no'] !== null && $batch !== null,
        ]);
    }

    /**
     * পর্দার সাথে যাওয়া পণ্যতালিকা — বিক্রয়যোগ্য সংখ্যা সহ।
     *
     * সংখ্যাটা সাব-সিলেক্টে, সারি প্রতি কোয়েরিতে নয় — মজুদের পর্দায় এই
     * ভুলটা একবার করে শোধরানো হয়েছে।
     *
     * @return Collection<int, object>
     */
    private function catalogue(?Warehouse $warehouse)
    {
        $sum = fn (string $column) => DB::table('inv_stock_movements')
            ->selectRaw("COALESCE(SUM({$column}), 0)")
            ->whereColumn('product_id', 'inv_products.id')
            ->where('company_id', CompanyContext::id())
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id));

        return Product::query()
            ->active()
            ->with('unit')
            ->select('inv_products.*')
            ->selectSub($sum('floor_change'), 'floor_total')
            ->selectSub($sum('reserved_change'), 'reserved_total')
            ->selectSub($sum('hold_change'), 'hold_total')
            ->orderBy('name_en')
            ->limit(self::INLINE_CATALOGUE_LIMIT)
            ->get()
            ->map(fn (Product $p) => (object) [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name(),
                'unit' => $p->unit?->name() ?? '',
                'rate' => (string) $p->sale_price,
                'barcode' => (string) $p->barcode,
                'available' => $this->sellable($p, $warehouse),
            ]);
    }

    /**
     * এই পণ্যের আর কয়টা বেচা যাবে।
     *
     * ── কেন খাবারের উত্তরটা আলাদা, ২৯ আগস্ট ২০২৬ ─────────────────────
     * অর্ডারে-রান্না খাবারের **নিজের কোনো মজুদ নেই** — বিরিয়ানি রান্না
     * হয় অর্ডার পাওয়ার পর। মেঝে − ধরা − আটকানো কষলে উত্তর সবসময় শূন্য,
     * তাই কাউন্টারে বিরিয়ানির পাশে "০" বসে থাকত যদিও চাল, মাংস ও তেল
     * দিয়ে চল্লিশ প্লেট হয়।
     *
     * ক্যাশিয়ার তখন হয় বেচেন না, নয় সন্দেহ নিয়ে বেচেন — আর দুইটাই
     * খারাপ। উত্তরটা উপকরণ থেকেই আসে ([[RecipeService::portionsPossible()]])।
     *
     * ── কেন হাঁড়ির খাবারে নয় ────────────────────────────────────────
     * সকালে হাঁড়ি চড়ানো হয়েছে, বারো প্লেট হয়েছে, আর সারাদিন ওই বারোটাই
     * বিক্রি হয়। উপকরণ তখন আর প্রশ্ন নয় — ওগুলো সকালেই খরচ হয়ে গেছে।
     * উপকরণ ধরে গুনলে যা রান্নাই হয়নি তা বেচা যেত।
     *
     * ── কেন বিস্কুটের হিসাব ছোঁয়া হয়নি ─────────────────────────────
     * রেসিপির প্রশ্নটা সবার জন্য করলে চারশো পণ্যে চারশোটা কোয়েরি হত,
     * আর যাদের রেসিপি নেই তাদের উত্তরও পাল্টাত না। `consumesOnSale()`
     * একবারেই বলে দেয় প্রশ্নটা প্রাসঙ্গিক কি না।
     */
    private function sellable(Product $product, ?Warehouse $warehouse): string
    {
        return $this->recipes->sellableQty(
            $product,
            bcsub(
                bcsub((string) $product->floor_total, (string) $product->reserved_total, 4),
                (string) $product->hold_total,
                4,
            ),
            $warehouse,
        );
    }

    private function warehouse(Request $request): ?Warehouse
    {
        $id = $request->integer('warehouse_id');

        return $id > 0
            ? Warehouse::query()->find($id)
            : Warehouse::query()->where('is_default', true)->active()->first();
    }
}
