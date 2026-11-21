<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\ListFilter\ChoiceFacet;
use App\Core\ListFilter\DateRangeFacet;
use App\Core\ListFilter\FieldsFacet;
use App\Core\ListFilter\FilterSet;
use App\Core\ListFilter\Panel;
use App\Core\Services\ListExport;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\MasterData\Support\Places;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryCostReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ⭐ ডেলিভারি খরচ — চালান অনুযায়ী (মালিক, ২১ নভেম্বর ২০২৬; নমুনা v6, খ)।
 *
 * ⓘ হিসাব [[DeliveryCostReport]]-এ; এখানে ছাঁকনি (তারিখ, গাড়ি, পয়েন্ট, পার্টি, সীমা %), ভাগ (`?by=` চালান · গাড়ি · পয়েন্ট · মাস)
 *   আর পাতা। শাখা — মাথার বাছা শাখা (চালানের নিজের দেয়াল, [[ScopedToUserBranch]])।
 * ⛔ চাবি নিজের — `sales.delivery_cost.report`: রিপোর্টটা খরচ দেখায় (মার্জিনের রিপোর্টের একই কারণ)।
 * ⓘ ছাপা, PDF, Excel — টুলবারের "নামান ▾" (ধরা টেবিল, [[ListExport]])।
 */
final class DeliveryCostController extends Controller implements HasMiddleware
{
    public const PERMISSION = 'sales.delivery_cost.report';

    public function __construct(
        private readonly DeliveryCostReport $report,
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:'.self::PERMISSION)];
    }

    public function index(Request $request): View
    {
        // ⓘ তারিখ না দিলে এ মাস — শুরু থেকে আজ (পুরো ইতিহাস এক পাতায় নয়)
        if (! $request->filled('from') && ! $request->filled('to')) {
            $request->query->add([
                'from' => Carbon::today()->startOfMonth()->toDateString(),
                'to' => Carbon::today()->toDateString(),
            ]);
        }

        $by = in_array($request->query('by'), DeliveryCostReport::GROUPS, true) ? (string) $request->query('by') : 'challan';
        $threshold = $this->threshold($request);
        $filters = $this->filters($threshold);

        // ⓘ কেবল পাকা চালান; শাখা আর দেয়াল মডেলের নিজের স্কোপে
        $base = fn () => DeliveryChallan::query()
            ->whereIn('sal_challans.status', DocumentStatus::POSTED)
            ->search(is_string($request->query('q')) ? $request->query('q') : null);

        $query = $base()->with(['customer.location.parent', 'vehicle']);
        $filters->apply($query, $request);

        $rows = $this->report->rows($query->get(), $threshold);
        $totals = $this->report->totals($rows, $threshold);
        $decimals = DeliveryCostReport::decimals($rows);

        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        $title = __('sales::delivery_cost.title');

        // ⭐ কাগজ আর ফাইলের মাথা — নাম আর সময়কাল; টাকার কলাম ফাইলে সংখ্যা ([[ListExport::numeric()]])
        $export = app(ListExport::class);
        $export->describe([
            'title' => $title,
            'period' => trim(($from !== '' ? DateFormat::format($from) : '…').' – '.($to !== '' ? DateFormat::format($to) : '…')),
        ]);
        $export->numeric(['count', 'value', 'fare', 'loading', 'other', 'total', 'percent']);

        return view('sales::delivery-cost.index', [
            'menu' => $this->menu->forUser($request->user()),
            'title' => $title,
            'by' => $by,
            'threshold' => $threshold,
            'rows' => $rows,
            'shown' => $this->report->group($rows, $by, $threshold),
            'totals' => $totals,
            'decimals' => $decimals,
            'cards' => $this->report->cards($rows, $totals, $decimals),
            'chart' => $this->report->chart($rows, $by, $threshold),
            'filters' => Panel::of($filters, $base, $request),
            'groups' => array_map(fn (string $g) => [
                'key' => $g,
                'label' => __('sales::delivery_cost.by_'.$g),
                'url' => $request->url().'?'.http_build_query(array_merge($request->except(['by', 'page', 'export']), ['by' => $g])),
                'active' => $g === $by,
            ], DeliveryCostReport::GROUPS),
        ]);
    }

    /** সীমা % — ছাঁকনির ঘরে দিলে সেটা, নইলে সেটিং (ডিফল্ট ৩) */
    private function threshold(Request $request): string
    {
        $raw = $request->query('threshold');

        if (is_scalar($raw) && is_numeric((string) $raw) && bccomp((string) $raw, '0', 4) >= 0 && bccomp((string) $raw, '100', 4) <= 0) {
            return \App\Core\Support\Money::round((string) $raw, 2);
        }

        $setting = $this->settings->get(DeliveryCostReport::THRESHOLD_KEY, DeliveryCostReport::DEFAULT_THRESHOLD);

        return \App\Core\Support\Money::round(is_numeric($setting) ? (string) $setting : DeliveryCostReport::DEFAULT_THRESHOLD, 2);
    }

    private function filters(string $threshold): FilterSet
    {
        return new FilterSet([
            (new DateRangeFacet('date', __('sales::delivery_cost.date'), function (Builder $q, ?string $from, ?string $to): void {
                if ($from !== null) {
                    $q->where('sal_challans.trx_date', '>=', $from);
                }
                if ($to !== null) {
                    $q->where('sal_challans.trx_date', '<=', $to);
                }
            }, fromKey: 'from', toKey: 'to'))->look('sky', '◷')->onTheBar(),
            (new ChoiceFacet('vehicle', __('sales::delivery_cost.vehicle'), $this->vehicles()))->look('blue', '▣'),
            $this->pointFacet()->look('teal', '◈'),
            (new ChoiceFacet('party', __('sales::delivery_cost.party'), $this->parties()))->look('violet', '●'),
            (new FieldsFacet('threshold', __('sales::delivery_cost.threshold'), [
                ['key' => 'threshold', 'label' => __('sales::delivery_cost.threshold'), 'type' => 'number', 'min' => 0, 'max' => 100,
                    'placeholder' => rtrim(rtrim($threshold, '0'), '.')],
            ], fn (Builder $q, array $values) => $q, fn (array $values) => __('sales::delivery_cost.threshold_chip', ['n' => $values['threshold'] ?? '']),
            ))->look('amber', '%'),
        ]);
    }

    /**
     * গাড়ি — চালানের গাড়ি, বা চালানটা যে ট্রিপে গেছে তার গাড়ি (বাতিল ট্রিপ নয়)।
     *
     * @return array<string, array{label: string, apply: \Closure(Builder): mixed}>
     */
    private function vehicles(): array
    {
        $options = [];

        foreach (Vehicle::query()->orderBy('registration_no')->get(['id', 'registration_no', 'name_en', 'name_bn']) as $vehicle) {
            $id = (int) $vehicle->id;
            $options[(string) $id] = [
                'label' => (string) ($vehicle->registration_no ?: ($vehicle->name_bn ?: $vehicle->name_en)),
                'apply' => fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('sal_challans.vehicle_id', $id)
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('sal_shipment_lines as vl')
                        ->join('sal_shipments as vs', 'vs.id', '=', 'vl.shipment_id')
                        ->whereColumn('vl.delivery_challan_id', 'sal_challans.id')
                        ->where('vs.vehicle_id', $id)
                        ->where('vs.status', '<>', DocumentStatus::CANCELLED)
                        ->whereNull('vs.deleted_at'))),
            ];
        }

        return $options;
    }

    /** পয়েন্ট — গ্রাহকের জায়গা পয়েন্টে বা তার নিচে ([[Places]], চালানের তালিকার এলাকার একই ধাঁচ) */
    private function pointFacet(): ChoiceFacet
    {
        $section = Places::section(
            Location::POINT,
            fn (array $ids) => fn (Builder $q) => $q->whereIn('sal_challans.customer_id',
                Customer::query()->whereIn('location_id', $ids ?: [0])->select('customers.id')),
            fn (Builder $q) => (clone $q)->reorder()->toBase()
                ->selectRaw('(SELECT place_customer.location_id FROM customers AS place_customer WHERE place_customer.id = sal_challans.customer_id) AS loc, COUNT(*) AS n')
                ->groupBy('loc')->get(),
        );

        return new ChoiceFacet('point', __('sales::delivery_cost.point'), $section['options'], countAll: $section['count']);
    }

    /**
     * পার্টি — যাঁদের পাকা চালান আছে (দেয়াল মেনে)।
     *
     * @return array<string, array{label: string, apply: \Closure(Builder): mixed}>
     */
    private function parties(): array
    {
        $options = [];
        $ids = DeliveryChallan::query()->whereIn('sal_challans.status', DocumentStatus::POSTED)->distinct()->pluck('customer_id')->filter()->all();

        foreach (Customer::query()->whereIn('id', $ids ?: [0])->get() as $customer) {
            $id = (int) $customer->id;
            $options[(string) $id] = [
                'label' => $customer->name(),
                'apply' => fn (Builder $q) => $q->where('sal_challans.customer_id', $id),
            ];
        }

        uasort($options, fn (array $a, array $b) => strcmp($a['label'], $b['label']));

        return $options;
    }
}
