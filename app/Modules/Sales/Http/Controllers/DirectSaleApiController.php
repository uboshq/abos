<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\FreeAllowance;
use App\Modules\Sales\Http\Requests\DirectSaleRules;
use App\Modules\Sales\Services\DirectSaleOptions;
use App\Modules\Sales\Services\DirectSaleOverview;
use App\Modules\Sales\Services\DirectSaleService;
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
        $warehouse = $this->warehouse((string) $request->query('warehouse', ''));

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
                'qty' => $lot['qty'],
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
        ]);
    }

    /** `GET /direct/free-allowed?product=&warehouse=&qty=&lot=` — স্কিমের ফ্রি কত; ওয়েবের একই প্রশ্ন ([[FreeAllowance]]) */
    public function freeAllowed(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'product' => ['required', 'string', 'max:64'],
            'warehouse' => ['nullable', 'string', 'max:64'],
            'qty' => ['required', 'numeric', 'gt:0'],
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
        ] : $d, array_values((array) ($in['deposits'] ?? [])));

        unset($out['customer'], $out['warehouse']);

        return $out;
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
            : Warehouse::query()->where('is_default', true)->first();
    }
}
