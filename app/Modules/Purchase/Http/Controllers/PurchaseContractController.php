<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseContract;
use App\Modules\Purchase\Services\PurchaseContractService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ ক্রয় চুক্তি — পর্দা। ২৪ সেপ্টেম্বর ২০২৬।
 *
 * ── ⚠️ তালিকার ক্রম মেয়াদ ধরে, তারিখ ধরে নয় ─────────────────────────
 * ⓘ রোজকার প্রশ্নটা একটাই: *"কোন চুক্তিগুলোর মেয়াদ শেষ হয়ে আসছে"*।
 * ⛔ সাম্প্রতিক দিয়ে সাজালে ঠিক ঐ চুক্তিগুলোই নিচে চাপা পড়ত যেগুলো
 * নিয়ে আজ কিছু করার আছে।
 */
class PurchaseContractController extends Controller implements HasMiddleware
{
    use AuthorizesResource;

    public function __construct(
        private readonly PurchaseContractService $contracts,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(PurchaseContract::class, 'contract'),
            new Middleware('can:activate,contract', only: ['activate']),

            /*
             * ⓘ দর জিজ্ঞেস করার দরজাটা **আদেশ বানানোর** চাবিতে, চুক্তির
             * নয় — ⚠️ যিনি আদেশ লেখেন তাঁরই ওটা লাগে, আর চুক্তির
             * তালিকা দেখার অনুমতি তাঁর না-ও থাকতে পারে।
             */
            new Middleware('can:purchase.order.create', only: ['rate']),
        ];
    }

    public function index(Request $request): View
    {
        $query = PurchaseContract::query()
            ->with('supplier')
            ->withCount('lines')
            ->orderByRaw("CASE WHEN status = '".DocumentStatus::CONFIRMED."' THEN 0 ELSE 1 END")
            ->orderBy('ends_on');

        return view('purchase::contract.index', [
            'menu' => $this->menu->forUser($request->user()),
            'contracts' => $query->paginate(50)->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('purchase::contract.form', [
            'menu' => $this->menu->forUser($request->user()),
            'suppliers' => Supplier::query()->orderBy('code')->get(),
            'products' => Product::query()->active()->with('unit')->orderBy('name_en')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'supplier_ref' => ['nullable', 'string', 'max:60'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'narration' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.agreed_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.qty_limit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.value_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $contract = $this->contracts->create($data, $data['lines']);

        return redirect()
            ->route('purchase.contract.show', $contract)
            ->with('saved', __('purchase::message.contract_created'));
    }

    public function show(Request $request, PurchaseContract $contract): View
    {
        $contract->load(['lines.product.unit', 'supplier']);

        return view('purchase::contract.show', [
            'menu' => $this->menu->forUser($request->user()),
            'contract' => $contract,
        ]);
    }

    public function activate(PurchaseContract $contract): RedirectResponse
    {
        $this->contracts->activate($contract);

        return redirect()
            ->route('purchase.contract.show', $contract)
            ->with('saved', __('purchase::message.contract_activated'));
    }

    /**
     * ⭐ আদেশের পর্দার প্রশ্ন: এই পণ্যের চুক্তির দর কত।
     *
     * ── ⚠️ এই জোড়াটাই চুক্তিকে সত্যিই কাজে লাগায় ─────────────────────
     * ⛔ এটা ছাড়া চুক্তি কেবল একটা সংরক্ষিত কাগজ — ঠিক যা আগে ছিল,
     * শুধু কাগজের বদলে পর্দায়। ⓘ আদেশ লেখার সময় দরটা হাতের কাছে
     * থাকলে তবেই কেউ মেলাতে পারেন।
     *
     * ⓘ `null` ফেরে যখন চলতি কোনো চুক্তি নেই — ⚠️ শূন্য নয়, নাহলে
     * পর্দা ভাবত চুক্তিতে জিনিসটা বিনামূল্যে।
     */
    public function rate(Request $request): JsonResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
        ]);

        return response()->json([
            'rate' => $this->contracts->rateFor(
                (int) $data['supplier_id'],
                (int) $data['product_id'],
            ),
        ]);
    }
}
