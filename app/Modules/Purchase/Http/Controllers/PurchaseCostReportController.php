<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\ListFilter\DateRangeFacet;
use App\Core\ListFilter\FieldsFacet;
use App\Core\ListFilter\FilterSet;
use App\Core\ListFilter\Panel;
use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseCostReport;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ⭐ ক্রয়ের খরচ — ক্রয় ইনভয়েস অনুযায়ী (মালিকের অনুমোদিত নমুনা v6, ট্যাব গ; ২১ নভেম্বর ২০২৬)।
 *
 * ⓘ হিসাব সব [[PurchaseCostReport]]-এ; এখানে কেবল ছাঁকনি পড়া। তারিখ না দিলে এ মাস — শুরু থেকে আজ।
 * ⛔ নিজের চাবি `purchase.cost.report` — প্রতিটা সারিতে ক্রয় মূল্য, তাই কেবল `purchase.report` যথেষ্ট নয়।
 * ⓘ শাখা: মাথায় বাছা শাখার দেয়াল মডেলের নিজের ([[ScopedToUserBranch]])।
 */
final class PurchaseCostReportController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly PurchaseCostReport $report,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:purchase.cost.report')];
    }

    public function index(Request $request): View
    {
        // ⓘ তারিখ না দিলে এ মাস — ঠিকানায় বসিয়ে দেওয়া, যাতে বারের "📅" আর কাগজের মাথা একই কথা বলে
        if (! $request->filled('from') && ! $request->filled('to')) {
            $month = DateRangeFacet::presetRange('this_month');
            $request->query->set('from', $month['from']);
            $request->query->set('to', $month['to']);
        }

        $from = $this->date($request->query('from')) ?? Carbon::today()->startOfMonth()->toDateString();
        $to = $this->date($request->query('to')) ?? Carbon::today()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $default = $this->report->defaultThreshold();
        $raw = $request->query('threshold');
        $threshold = is_scalar($raw) && is_numeric($raw) && (float) $raw >= 0 && (float) $raw <= 100
            ? bcadd((string) $raw, '0', 4) : bcadd($default, '0', 4);

        $by = is_string($request->query('by')) && in_array($request->query('by'), PurchaseCostReport::GROUPS, true)
            ? (string) $request->query('by') : 'invoice';

        $suppliers = Supplier::query()->orderBy('id')->get()
            ->mapWithKeys(fn (Supplier $s) => [(string) $s->id => $s->name()])->sort()->all();
        $products = Product::query()->orderBy('id')->get()
            ->mapWithKeys(fn (Product $p) => [(string) $p->id => $p->name()])->sort()->all();

        $supplierId = is_scalar($request->query('supplier_id')) && isset($suppliers[(string) $request->query('supplier_id')])
            ? (int) $request->query('supplier_id') : null;
        $productId = is_scalar($request->query('product_id')) && isset($products[(string) $request->query('product_id')])
            ? (int) $request->query('product_id') : null;

        $set = $this->filters($suppliers, $products, $default);

        $result = $this->report->run([
            'from' => $from, 'to' => $to,
            'supplier_id' => $supplierId, 'product_id' => $productId,
            'threshold' => $threshold, 'by' => $by,
        ]);

        return view('purchase::cost-report.index', [
            'menu' => $this->menu->forUser($request->user()),
            'filters' => Panel::of($set, fn (): Builder => PurchaseReceipt::query(), $request),
            'result' => $result,
            'dates' => ['from' => $from, 'to' => $to],
            'branch' => $this->branchLabel($request->user()),
            'supplier' => $supplierId !== null ? $suppliers[(string) $supplierId] : null,
            'product' => $productId !== null ? $products[(string) $productId] : null,
        ]);
    }

    /**
     * ছাঁকনির প্যানেল — তারিখ, সরবরাহকারী, পণ্য, সীমা %। ⓘ ঘরগুলো কেবল পড়া হয় ([[index()]]); কোয়েরিতে কিছু বসায় না —
     *   রিপোর্টটা নিজের হিসাব নিজে করে।
     *
     * @param  array<string, string>  $suppliers
     * @param  array<string, string>  $products
     */
    private function filters(array $suppliers, array $products, string $default): FilterSet
    {
        $none = function (): void {};
        $all = fn (string $word) => ['' => $word];

        return new FilterSet([
            (new DateRangeFacet('date', __('purchase::cost_report.filter_date'), $none, fromKey: 'from', toKey: 'to'))
                ->look('sky', '◷')->onTheBar(),
            (new FieldsFacet('supplier', __('purchase::cost_report.supplier'), [
                ['key' => 'supplier_id', 'label' => __('purchase::cost_report.supplier'), 'type' => 'select',
                    'options' => $all(__('purchase::cost_report.all')) + $suppliers],
            ], $none, fn (array $v) => __('purchase::cost_report.supplier').': '.($suppliers[$v['supplier_id']] ?? '—')))
                ->look('violet', '◈'),
            (new FieldsFacet('product', __('purchase::cost_report.product'), [
                ['key' => 'product_id', 'label' => __('purchase::cost_report.product'), 'type' => 'select',
                    'options' => $all(__('purchase::cost_report.all')) + $products],
            ], $none, fn (array $v) => __('purchase::cost_report.product').': '.($products[$v['product_id']] ?? '—')))
                ->look('teal', '▦'),
            (new FieldsFacet('limit', __('purchase::cost_report.threshold'), [
                ['key' => 'threshold', 'label' => __('purchase::cost_report.threshold_percent'), 'type' => 'number',
                    'min' => 0, 'max' => 100, 'placeholder' => rtrim(rtrim($default, '0'), '.')],
            ], $none, fn (array $v) => __('purchase::cost_report.threshold').': '.$v['threshold'].'%'))
                ->look('amber', '%'),
        ]);
    }

    /** মাথায় বাছা শাখা — একটা হলে তার নাম, নইলে "সব শাখা" */
    private function branchLabel(mixed $user): string
    {
        $ids = $user instanceof User ? app(DataScope::class)->viewBranchIds($user) : null;

        if (is_array($ids) && count($ids) === 1) {
            return Branch::query()->find(reset($ids))?->name() ?? __('purchase::cost_report.all_branches');
        }

        return __('purchase::cost_report.all_branches');
    }

    private function date(mixed $raw): ?string
    {
        $raw = trim((string) (is_scalar($raw) ? $raw : ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 && strtotime($raw) !== false ? $raw : null;
    }
}
