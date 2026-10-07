<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Services\OpeningStockService;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * আগে বসানো খোলা মজুদে প্রিন্সিপাল বসানো — মালিক, ৬ অক্টোবর ২০২৬।
 *
 * *"কোন পণ্য কোন প্রিন্সিপালের, খোলা মজুদে সেটা উল্লেখ করে দেওয়ার ব্যবস্থা করো"* — লাইভে খোলা মজুদ আগেই বসানো, তাই
 * সেই লটগুলোয় পরে বসানোর পাতা। ⓘ শাখা আর পণ্য ধরে তালিকা, অনেকগুলো বেছে একজন সরবরাহকারী বসানো।
 *
 * ⛔ কেবল খোলা মজুদের খরচের স্তর বদলায় (`inv_cost_layers.supplier_id`) — টাকার কোনো দাখিলা নয়, মজুদ নড়ে না।
 * ⓘ প্রতিটা বদল অডিটে আগে-পরে সহ ([[AuditEngine::record()]])। প্রিন্সিপালের "আসল" কমিশন পরের রিপোর্টেই সেটা গোনে।
 */
class OpeningPrincipalController extends Controller implements HasMiddleware
{
    public const AUDIT_ACTION = 'opening_principal_set';

    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:inventory.stock.opening')];
    }

    public function index(Request $request): View
    {
        $productId = (int) $request->query('product_id', 0);
        $supplierFilter = (string) $request->query('supplier', '');

        $rows = $this->openingLayers()
            ->when($productId > 0, fn ($q) => $q->where('l.product_id', $productId))
            ->when($supplierFilter === 'none', fn ($q) => $q->whereNull('l.supplier_id'))
            ->orderBy('p.code')
            ->orderBy('l.id')
            ->select(['l.id', 'l.product_id', 'l.qty_in', 'l.qty_remaining', 'l.trx_date', 'l.supplier_id',
                'p.code as product_code', 'p.name_en', 'p.name_bn', 'w.name_en as warehouse_name', 'b.batch_no'])
            ->paginate(50)
            ->withQueryString();

        return view('inventory::stock.opening-principal', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'suppliers' => OpeningStockController::suppliers(),
            'products' => DB::table('inv_products')->where('company_id', CompanyContext::id())->whereNull('deleted_at')
                ->orderBy('code')->get(['id', 'code', 'name_en'])->mapWithKeys(fn ($p) => [(int) $p->id => $p->code.' — '.$p->name_en])->all(),
            'productId' => $productId,
            'supplierFilter' => $supplierFilter,
            'unset' => (clone $this->openingLayers())->whereNull('l.supplier_id')->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'layer_ids' => ['required', 'array', 'min:1'],
            'layer_ids.*' => ['integer'],
            // ⓘ খালি = বসানো প্রিন্সিপাল তুলে নেওয়া
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
        ]);

        $supplier = isset($data['supplier_id']) ? (int) $data['supplier_id'] : null;

        // ⛔ কেবল খোলা মজুদের স্তর, এই কোম্পানির আর দেখা শাখার — অন্য কোনো স্তর পাঠালেও ছোঁয়া হয় না
        $ids = $this->openingLayers()->whereIn('l.id', array_map('intval', $data['layer_ids']))->pluck('l.id')->all();

        $changed = DB::transaction(function () use ($ids, $supplier) {
            $count = 0;

            foreach (CostLayer::query()->whereKey($ids)->lockForUpdate()->get() as $layer) {
                $before = $layer->supplier_id === null ? null : (int) $layer->supplier_id;

                if ($before === $supplier) {
                    continue;
                }

                $layer->forceFill(['supplier_id' => $supplier])->save();
                app(AuditEngine::class)->record($layer, self::AUDIT_ACTION, ['supplier_id' => [$before, $supplier]]);
                $count++;
            }

            return $count;
        });

        return back()->with('saved', __('inventory::message.opening_principal_saved', ['count' => $changed]));
    }

    /** খোলা মজুদের স্তর — তার চলাচল ধরে গুদাম আর শাখা, দেখা শাখার দেয়ালে */
    private function openingLayers(): \Illuminate\Database\Query\Builder
    {
        $branch = ViewedBranch::one();

        return DB::table('inv_cost_layers as l')
            ->join('inv_products as p', 'p.id', '=', 'l.product_id')
            ->join('inv_stock_movements as m', 'm.id', '=', 'l.source_id')
            ->join('inv_warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('inv_batches as b', 'b.id', '=', 'l.batch_id')
            ->where('l.company_id', CompanyContext::id())
            ->where('l.source_type', OpeningStockService::SOURCE_TYPE)
            ->when($branch !== null, fn ($q) => $q->where('w.branch_id', $branch))
            ->when(\App\Modules\Inventory\Models\Warehouse::idsInViewedBranch(), fn ($q, array $ids) => $q->whereIn('w.id', $ids))
            ->when(\App\Modules\Inventory\Models\Warehouse::idsInViewedBranch() === [], fn ($q) => $q->whereRaw('1 = 0'));
    }
}
