<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Core\Support\PhoneInput;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\FreeAllowance;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Sales\Http\Requests\DirectSaleRules;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleOptions;
use App\Modules\Sales\Services\DirectSaleOverview;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ফোনের কাউন্টার — `/api/v1/sales/direct` (৪ অক্টোবর ২০২৬, মালিক: *"direct sales er counter banaw app e"*)।
 *
 * ⛔ এখানে কোনো নিয়ম নেই — কেবল অনুবাদ: ফোনের public_id → ভেতরের id, তারপর ওয়েবের কাউন্টারের **একই যাচাই**
 * ([[DirectSaleRules::store()]]) আর **একই দরজা** ([[DirectSaleService::complete()]])। তাই ঋণসীমা, ছাড়ে মালিকের সই,
 * লট বাধ্যতামূলক, শূন্য দর নয়, স্কিমের ফ্রি, খসড়ায় দেয়াল নেই — সব ফোনেও হুবহু।
 * ⓘ চাবি ওয়েবের কাউন্টারের (`sales.challan.create`); কোম্পানি আর শাখার দেয়াল মডেলের। টাকা আছে, তাই কেবল অনলাইনে —
 * অফলাইন সারিতে নয় (মালিকের নিয়ম, ২ সেপ্টেম্বর ২০২৬)।
 */
class DirectSaleApiController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly DirectSaleService $sales,
        private readonly DirectSaleOptions $options,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.challan.create')];
    }

    /** `GET /direct/setup?warehouse=` — গুদাম, শর্ত, টাকার খাত, আর বাছা গুদামের লট (FEFO) */
    public function setup(Request $request): JsonResponse
    {
        $warehouse = $this->warehouse(PhoneInput::text($request, 'warehouse', ''));

        /*
         * ⛔ লটের পরিমাণ কেবল মজুদ দেখার চাবিতে — "দাম দেখুন"-এর একই নিয়ম ([[price()]]; সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর
         * ২০২৬: এখানে কেবল কাউন্টারের চাবি দেখা হত, তাই চাবিহীন কাউন্টার-ব্যবহারকারী প্রতিটা লটের মজুদ পেতেন)। লট, নম্বর
         * আর মেয়াদ যায় — বিলে লট বাছতে লাগে; কত আছে তা নয়।
         */
        $seesStock = (bool) $request->user()?->can('inventory.stock.view');

        $lots = [];
        foreach ($this->options->lots($warehouse) as $productId => $rows) {
            $product = Product::query()->find((int) $productId);
            if ($product === null) {
                continue;
            }
            $ids = Batch::query()->whereKey(array_column($rows, 'id'))->pluck('public_id', 'id');
            $lots[(string) $product->public_id] = array_map(fn (array $lot) => [
                'id' => (string) ($ids[(int) $lot['id']] ?? ''),
                'no' => $lot['no'],
                'expiry' => $lot['expiry'],
                'qty' => $seesStock ? $lot['qty'] : null,
            ], $rows);
        }

        $accounts = Account::query()->whereKey(array_map('intval', array_column($this->options->moneyAccounts(), 'id')))
            ->pluck('public_id', 'id');

        return response()->json([
            'warehouse' => $warehouse === null ? null : ['id' => (string) $warehouse->public_id, 'name' => $warehouse->name()],
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get()
                ->map(fn (Warehouse $w) => ['id' => (string) $w->public_id, 'name' => $w->name(), 'default' => (bool) $w->is_default])
                ->values(),
            'paymentTerms' => $this->options->paymentTerms(),
            'moneyAccounts' => array_map(fn (array $a) => [
                'id' => (string) ($accounts[(int) $a['id']] ?? ''),
                'label' => $a['label'],
                'kind' => match ($a['parent']) {
                    '1101' => 'cash', '1102' => 'bank', '1105' => 'mobile', default => 'other'
                },
            ], $this->options->moneyAccounts()),
            'lots' => (object) $lots,

            /*
             * ⭐ ওয়েবের কাউন্টারের বাকি তালিকা — একই জায়গা থেকে ([[DirectSaleOptions::depositMethods()]],
             * [[DirectSaleOptions::carriers()]]); ফোনের ৮ বোতাম (মালিক, ৪ অক্টোবর ২০২৬)। ⓘ id-গুলো public_id, ক্রমিক নয়।
             */
            'depositMethods' => $this->publicMethods(),
            'carriers' => $this->publicCarriers(),

            // ⓘ বিল বাতিলের কারণ — ওয়েবের পপ-আপের একই তালিকা (`sales::field.cancel_reasons`)
            'voidReasons' => array_values(array_filter(explode('|', (string) __('sales::field.cancel_reasons')))),
        ]);
    }

    /**
     * `GET /direct/price/{product}?warehouse=` — "দাম দেখুন": বিলে না তুলে দর, বিক্রয়যোগ্য মজুদ আর লট।
     * ⓘ ওয়েবের পপ-আপের একই উৎস — কাউন্টারের পণ্য-তালিকা ([[DirectSaleOptions::catalogue()]]) আর লট ([[DirectSaleOptions::lots()]])।
     * ⛔ মজুদ আর লটের পরিমাণ কেবল মজুদ দেখার চাবিতে (`inventory.stock.view`) — SR-এর ফোনে মজুদ নয় (মালিক, ১ অক্টোবর ২০২৬)।
     */
    public function price(Request $request, string $product): JsonResponse
    {
        $id = Product::query()->where('public_id', $product)->value('id');

        abort_if($id === null, 404);

        $warehouse = $this->warehouse(PhoneInput::text($request, 'warehouse', ''));

        // ⭐ `?customer=` (public id) দিলে এই গ্রাহকের দর তালিকার দাম ([[SalesPrice]], ৫ অক্টোবর ২০২৬); না দিলে সবার দর
        $customer = filled($request->query('customer'))
            ? Customer::query()->inViewedBranch()->where('public_id', PhoneInput::text($request, 'customer'))->first()
            : null;
        $row = $this->options->catalogue($warehouse, 1, (int) $id, $customer)->first();

        abort_if($row === null, 404);

        $seesStock = (bool) $request->user()?->can('inventory.stock.view');
        $lots = $this->options->lots($warehouse)[(int) $id] ?? [];
        $lotIds = Batch::query()->whereKey(array_column($lots, 'id'))->pluck('public_id', 'id');

        return response()->json([
            'product' => $product,
            'name' => $row->name,
            'code' => (string) $row->code,
            'unit' => (string) $row->unit,
            'rate' => (string) $row->rate,
            'priceSource' => (string) $row->priceSource,
            'priceLabel' => (string) $row->priceLabel,
            'available' => $seesStock ? (string) $row->available : null,
            'lots' => array_map(fn (array $lot) => [
                'id' => (string) ($lotIds[(int) $lot['id']] ?? ''),
                'no' => $lot['no'],
                'expiry' => $lot['expiry'],
                'qty' => $seesStock ? $lot['qty'] : null,
                'paid' => $lot['paid'],
                'free' => $lot['free'],
            ], $lots),
        ]);
    }

    /**
     * `GET /direct/drafts` — রাখা খসড়া, খোলার জন্য (ওয়েবের "খসড়া" তালিকার একই প্রশ্ন, [[DirectSaleService::trueDrafts()]])।
     */
    public function drafts(Request $request): JsonResponse
    {
        $drafts = DirectSaleService::trueDrafts()
            ->with(['customer', 'lines'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['drafts' => $drafts->map(fn (SalesInvoice $d) => [
            'id' => (string) $d->public_id,
            'no' => (string) $d->document_no,
            'customer' => (string) ($d->customer?->name() ?? ''),
            'date' => $d->trx_date?->toDateString(),
            'total' => (string) $d->total,
            'lines' => $d->lines->count(),
            // ⭐ ৩ দিন পেরোনো খসড়া লাল — ওয়েবের একই সীমা (পরিকল্পনা §৪.৩, ৬ অক্টোবর ২০২৬; লেখার সময় থেকে)
            'age_days' => $d->created_at === null ? 0 : (int) $d->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay()),
            'stale' => $d->created_at !== null && $d->created_at->lte(\App\Modules\Sales\Services\OrderProgress::staleCutoff()),
        ])->values()]);
    }

    /**
     * `GET /direct/drafts/{id}` — একটা খসড়া কাউন্টারে আবার বসানোর জন্য: সারিগুলো ফোনের আকারে।
     * ⓘ ওয়েবের একই রূপান্তর ([[DirectSaleOptions::screenFromDraft()]]), কেবল ভেতরের id → public_id।
     */
    public function draft(string $id): JsonResponse
    {
        $draft = DirectSaleService::trueDrafts()->where('public_id', $id)->first();

        abort_if($draft === null, 404);

        $screen = (array) ($this->options->screenFromDraft($draft)['screen'] ?? []);
        $lines = array_values((array) ($screen['lines'] ?? []));
        $productIds = Product::query()->whereKey(array_column($lines, 'id'))->pluck('public_id', 'id');
        $lotIds = Batch::query()->whereKey(array_filter(array_column($lines, 'batchId')))->pluck('public_id', 'id');

        return response()->json([
            'id' => (string) $draft->public_id,
            'no' => (string) $draft->document_no,
            'customer' => (string) ($draft->customer?->public_id ?? ''),
            'customerName' => (string) ($draft->customer?->name() ?? ''),
            'lines' => array_map(fn (array $l) => [
                'product' => (string) ($productIds[(int) $l['id']] ?? ''),
                'name' => (string) $l['name'],
                'lot' => $l['batchId'] !== '' ? (string) ($lotIds[(int) $l['batchId']] ?? '') : null,
                'lotNo' => (string) $l['batchNo'],
                'qty' => (string) $l['qty'],
                'freeQty' => (string) $l['freeQty'],
                'rate' => (string) $l['rate'],
                'discountPercent' => (string) $l['discountPercent'],
            ], $lines),
        ]);
    }

    /**
     * `POST /direct/void` — বিল বাতিল, কারণসহ (ওয়েবের Ctrl+X, [[DirectSaleController::void()]]-এর একই দুই পথ):
     * খোলা খসড়া হলে খসড়াটাই বাতিল ([[DirectSaleService::discardParked()]]), নইলে না-জমা বিলটা অডিটে লেখা।
     * ⓘ চাবি ওয়েবের মতোই — কাউন্টারের চাবির উপর `sales.invoice.create`।
     */
    public function void(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('sales.invoice.create'), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'customer' => ['nullable', 'string', 'max:64'],
            'resume' => ['nullable', 'string', 'max:64'],
            'lines' => ['nullable', 'integer', 'min:0'],
            'total' => ['nullable', 'numeric', PhoneInput::DECIMAL],
        ]);

        if (filled($data['resume'] ?? null)) {
            $draft = DirectSaleService::trueDrafts()->where('public_id', $data['resume'])->first();
            abort_if($draft === null, 404);
            $this->sales->discardParked($draft, $data['reason']);

            return response()->json(['voided' => true, 'draft' => true]);
        }

        $customer = filled($data['customer'] ?? null)
            ? Customer::query()->where('public_id', $data['customer'])->first()
            : Customer::query()->find((int) app(SettingsService::class)->get('sales.walkin_customer_id', 0));

        if ($customer !== null) {
            app(AuditEngine::class)->record($customer, 'counter_bill_voided', [
                'lines' => [(int) ($data['lines'] ?? 0), 0],
                'total' => [(string) ($data['total'] ?? '0'), '0'],
            ], $data['reason']);
        }

        return response()->json(['voided' => true, 'draft' => false]);
    }

    /** `GET /direct/free-allowed?product=&warehouse=&qty=&lot=` — স্কিমের ফ্রি কত; ওয়েবের একই প্রশ্ন ([[FreeAllowance]]) */
    public function freeAllowed(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'product' => ['required', 'string', 'max:64'],
            'warehouse' => ['nullable', 'string', 'max:64'],
            'qty' => ['required', 'numeric', PhoneInput::DECIMAL, 'gt:0'],
            'lot' => ['nullable', 'string', 'max:64'],
        ])->validate();

        $product = Product::query()->where('public_id', $data['product'])->firstOrFail();
        $warehouse = $this->warehouse((string) ($data['warehouse'] ?? ''));

        if ($warehouse === null) {
            return response()->json(['known' => false, 'allowed' => null]);
        }

        $batch = filled($data['lot'] ?? null)
            ? Batch::query()->where('product_id', $product->id)->where('public_id', $data['lot'])->first()
            : null;

        return response()->json($batch !== null
            ? ['known' => true, ...app(FreeAllowance::class)->onLot($batch, (string) $data['qty'])]
            : ['known' => true, 'allowed' => app(FreeAllowance::class)->on($product, $warehouse, (string) $data['qty'])]);
    }

    /**
     * ⭐ `POST /direct/overview` — নিশ্চিতের আগে সারাংশ (মালিক, ৪ অক্টোবর ২০২৬; [[ConfirmOverview]])। বিক্রির একই ঘর, একই
     * যাচাই — কিন্তু কিছুই লেখে না; ফোন এটা নিচ থেকে ওঠা পাতায় দেখায়, তারপর "নিশ্চিত" বা "খসড়া"।
     */
    public function overview(Request $request): JsonResponse
    {
        $input = $this->translate($request->all());
        $data = Validator::make($input, DirectSaleRules::store(CompanyContext::id(), $input))->validate();

        return response()->json(app(DirectSaleOverview::class)->build($data, $data['lines'])->toArray());
    }

    /**
     * `POST /direct` — বিক্রি। ফোনের ঘর → ওয়েবের ঘর, তারপর ওয়েবের একই যাচাই আর একই দরজা।
     *
     * উত্তর: `status` = `done` (বিল আর চালান হলো) · `parked` (খসড়া রাখা) · `held` (সইয়ের অপেক্ষায়, `notice`-এ কার)।
     */
    public function store(Request $request): JsonResponse
    {
        $input = $this->translate($request->all());
        $data = Validator::make($input, DirectSaleRules::store(CompanyContext::id(), $input))->validate();
        $gifts = array_values(array_filter($data['gifts'] ?? [],
            fn (array $g) => filled($g['product_id'] ?? null) && is_numeric($g['qty'] ?? null) && bccomp((string) $g['qty'], '0', 4) > 0));

        try {
            $result = $this->sales->complete($data, $data['lines'], $gifts);
        } catch (HeldForApproval $held) {
            return response()->json(['status' => 'held', 'notice' => (string) collect($held->errors())->flatten()->first()], 202);
        }

        $papers = [
            'invoice' => ['id' => (string) $result['invoice']->public_id, 'no' => (string) $result['invoice']->document_no],
            'challan' => ['no' => (string) $result['challan']->document_no],
        ];

        if ($result['parked'] ?? false) {
            return response()->json(['status' => 'parked', ...$papers, 'notice' => __('sales::message.draft_parked', [
                'invoice' => $result['invoice']->document_no, 'challan' => $result['challan']->document_no,
            ])], 201);
        }

        $held = array_filter([
            ($result['margin_held'] ?? false) ? $result['margin_notice'] : null,
            ($result['discount_held'] ?? false) ? $result['discount_notice'] : null,
            $result['challan_held'] ?? null,
            ($result['awaiting'] ?? []) !== [] ? __('sales::message.direct_sale_held', ['invoice' => $result['invoice']->document_no]) : null,
        ]);

        if ($held !== []) {
            return response()->json(['status' => 'held', ...$papers, 'notice' => implode(' ', $held)], 201);
        }

        $extra = (string) ($result['extra'] ?? '0');
        $done = __('sales::message.direct_done', ['challan' => $result['challan']->document_no, 'invoice' => $result['invoice']->document_no]);
        if (bccomp($extra, '0', 4) > 0) {
            $done .= ' '.__('sales::message.direct_extra_kept', ['amount' => Money::format($extra)]);
        }

        return response()->json(['status' => 'done', ...$papers, 'notice' => $done], 201);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /**
     * ফোনের public_id → ওয়েবের ঘরের ভেতরের id। ⓘ না মিললে ৪২২ — কোন ঘর, সেটা বলে; অন্য কোম্পানির id মডেলের দেয়ালেই
     * মেলে না। বাকি সব ঘর অবিকল যায়, আর যাচাই ওয়েবের তালিকায়।
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function translate(array $in): array
    {
        $out = $in;
        $out['customer_id'] = $this->idOf(Customer::class, $in['customer'] ?? null, 'customer');
        $out['warehouse_id'] = filled($in['warehouse'] ?? null) ? $this->idOf(Warehouse::class, $in['warehouse'], 'warehouse') : null;

        $out['lines'] = array_map(fn ($l) => is_array($l) ? [
            ...$l,
            'product_id' => $this->idOf(Product::class, $l['product'] ?? null, 'lines'),
            'batch_id' => filled($l['lot'] ?? null) ? $this->idOf(Batch::class, $l['lot'], 'lines') : null,
        ] : $l, array_values((array) ($in['lines'] ?? [])));

        $out['gifts'] = array_map(fn ($g) => is_array($g) ? [
            ...$g,
            'product_id' => $this->idOf(Product::class, $g['product'] ?? null, 'gifts'),
            'against_product_id' => filled($g['against'] ?? null) ? $this->idOf(Product::class, $g['against'], 'gifts') : null,
        ] : $g, array_values((array) ($in['gifts'] ?? [])));

        $out['deposits'] = array_map(fn ($d) => is_array($d) ? [
            ...$d,
            'account_id' => $this->idOf(Account::class, $d['account'] ?? null, 'deposits'),
            // ⭐ টাকা নেওয়ার পদ্ধতি — ঐচ্ছিক, ওয়েবের মতোই ([[DirectSaleOptions::depositMethods()]])
            'payment_method_id' => filled($d['method'] ?? null) ? $this->idOf(PaymentMethod::class, $d['method'], 'deposits') : null,
        ] : $d, array_values((array) ($in['deposits'] ?? [])));

        // ⭐ গাড়ি ও ভাড়ার বাহক, আর আবার খোলা খসড়া — public_id → ভেতরের id (৪ অক্টোবর ২০২৬)
        if (filled($in['carrier'] ?? null)) {
            $out['carrier_id'] = $this->idOf(Supplier::class, $in['carrier'], 'carrier');
        }
        if (filled($in['resume'] ?? null)) {
            $out['resume_invoice_id'] = $this->idOf(SalesInvoice::class, $in['resume'], 'resume');
        }

        unset($out['customer'], $out['warehouse'], $out['carrier'], $out['resume']);

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function publicMethods(): array
    {
        $rows = $this->options->depositMethods();
        $methods = PaymentMethod::query()->whereKey($rows->pluck('id')->map(fn ($id) => (int) $id)->all())->pluck('public_id', 'id');
        $accounts = Account::query()->whereKey($rows->pluck('accountId')->filter()->map(fn ($id) => (int) $id)->all())->pluck('public_id', 'id');

        return $rows->map(fn (array $m) => [
            'id' => (string) ($methods[(int) $m['id']] ?? ''),
            'label' => $m['label'],
            'kind' => $m['kind'] ?? null,
            'account' => $m['accountId'] === '' ? null : (string) ($accounts[(int) $m['accountId']] ?? ''),
            'needsReference' => (bool) $m['needsReference'],
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function publicCarriers(): array
    {
        $rows = $this->options->carriers();
        $ids = Supplier::query()->whereKey($rows->pluck('id')->map(fn ($id) => (int) $id)->all())->pluck('public_id', 'id');

        return $rows->map(fn (array $c) => [
            'id' => (string) ($ids[(int) $c['id']] ?? ''),
            'label' => $c['label'],
            'phone' => $c['phone'],
        ])->values()->all();
    }

    /** @param  class-string<Model>  $model */
    private function idOf(string $model, mixed $publicId, string $field): int
    {
        $id = is_string($publicId) && $publicId !== '' ? $model::query()->where('public_id', $publicId)->value('id') : null;

        if ($id === null) {
            throw ValidationException::withMessages([$field => __('sales::sync.unknown_reference')]);
        }

        return (int) $id;
    }

    private function warehouse(string $publicId): ?Warehouse
    {
        return $publicId !== ''
            ? Warehouse::query()->where('public_id', $publicId)->first()
            // ⭐ প্রধান না থাকলে শাখার একমাত্র চালু গুদাম — ফোনের কাউন্টারেও ([[Warehouse::defaultInView()]], ৬ অক্টোবর ২০২৬)
            : Warehouse::defaultInView();
    }
}
