<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryPerformance;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\OpportunityService;
use App\Modules\Sales\Services\PrintQueue;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * ⭐ বিক্রয়ের চারটা ডেস্ক-পাতা ফোনের নিজের পর্দায় — ডিপোর যাচাই, না ছাপা কাগজ, ডেলিভারির কাজের হিসাব আর সম্ভাবনার পাইপলাইন
 * (মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"*)।
 *
 * ⓘ প্রতিটা ওয়েবের পাতার একই প্রশ্নে, একই সেবায়, একই চাবিতে — [[DepotCheckController]], [[PrintQueueController]],
 * [[DeliveryPerformanceController]], [[OpportunityController::pipeline()]]। ফোনে কোনো সংখ্যা কষা হয় না।
 * ⓘ সারি চেনা যায় public_id-তে (চুক্তি §৩ক)।
 */
final class SalesDeskApiController extends Controller implements HasMiddleware
{
    use GrandTotals;

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.challan.create', only: ['depotCheck']),
            new Middleware('can:sales.invoice.view', only: ['printQueue', 'settlePrint']),
            new Middleware('can:sales.delivery.view', only: ['deliveryPerformance']),
            new Middleware('can:sales.opportunity.view', only: ['pipeline']),
        ];
    }

    /**
     * `GET /sales/depot-check?q=` — ডিপোর যাচাইয়ের অপেক্ষায় DO আর ধরে রাখা বিক্রয় আদেশ, DO-র সর্বমোটসহ।
     * ⓘ "যাচাই করে বিক্রয়ে খুলুন" এখনো ওয়েবের কাউন্টারে — ফোনের কাউন্টার উৎস থেকে খোলে না।
     */
    public function depotCheck(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $query = DepotCheckController::deliveryOrders($q);

        $orders = $query === null ? collect() : (clone $query)->with(['customer', 'lines'])->orderBy('id')->limit(50)->get();
        $grand = $query === null ? ['total' => '0'] : $this->grandTotals($query, ['total' => 't.total']);
        $held = DepotCheckController::heldOrders($q)->limit(50)->get();

        return response()->json([
            'orders' => $orders->map(fn ($o) => [
                'id' => (string) $o->public_id,
                'document_no' => (string) $o->document_no,
                'date' => DateFormat::format($o->trx_date),
                'customer' => (string) ($o->customer?->name() ?? ''),
                'lines' => $o->lines->count(),
                'total' => Money::format($o->total),
                'status' => __('sales::delivery_order.status.'.$o->status),
                // ⓘ হিসাবের সতর্কতা — ওয়েবের পাতার একই লেখা ([[DepotCheckController::warningText()]])
                'warnings' => collect((array) ($o->accounts_warnings ?? []))
                    ->filter(fn ($w) => is_array($w))
                    ->map(fn (array $w) => DepotCheckController::warningText($w))->values(),
            ])->values(),
            'orders_count' => $query === null ? 0 : (clone $query)->count(),
            'orders_total' => Money::format($grand['total'] ?? '0'),
            'held' => $held->map(fn (SalesOrder $o) => [
                'id' => (string) $o->public_id,
                'document_no' => (string) $o->document_no,
                'date' => DateFormat::format($o->trx_date),
                'customer' => (string) ($o->customer?->name() ?? ''),
                'lines' => $o->lines->count(),
                'total' => Money::format($o->total),
                'status' => SalesOrderStatus::deliveryLabel((string) $o->delivery_status),
            ])->values(),
        ]);
    }

    /** `GET /sales/print-queue?q=&page=` — ছাপা হয়নি এমন কাগজ, ব্যর্থতার কারণসহ */
    public function printQueue(Request $request): JsonResponse
    {
        $jobs = app(PrintQueue::class)->pending((string) $request->query('q'));

        return response()->json([
            'jobs' => collect($jobs->items())->map(fn (PrintJob $j) => [
                'id' => (string) $j->public_id,
                'document_no' => (string) ($j->document_no ?? ''),
                'paper' => (string) ($j->paper ?? ''),
                'status' => (string) $j->status,
                'status_label' => __('sales::message.print_'.$j->status),
                'failed' => $j->status === PrintJob::FAILED,
                'failure' => (string) ($j->failure ?? ''),
            ])->values(),
            'count' => $jobs->total(),
            'next_page' => $jobs->hasMorePages() ? $jobs->currentPage() + 1 : null,
        ]);
    }

    /** `POST /sales/print-queue/{id}/settle` — হাতে ছাপা হয়ে গেছে, সারি থেকে সরাও (ওয়েবের একই কাজ) */
    public function settlePrint(string $id): JsonResponse
    {
        $job = PrintJob::query()->where('public_id', $id)->firstOrFail();

        $job->update(['status' => PrintJob::PRINTED, 'failure' => null]);

        return response()->json(['message' => __('sales::message.print_job_settled', ['no' => $job->document_no ?? ''])]);
    }

    /** `GET /sales/delivery-performance?from=&to=` — সময়মতো ও পুরো, গড় সময়, আর দেরির চালান */
    public function deliveryPerformance(Request $request): JsonResponse
    {
        $from = $this->date($request->query('from')) ?? Carbon::today()->startOfMonth()->toDateString();
        $to = $this->date($request->query('to')) ?? Carbon::today()->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $performance = app(DeliveryPerformance::class);
        $summary = $performance->summary($from, $to);
        $late = $performance->late($from, $to)->paginate(50, ['*'], 'page', max(1, (int) $request->query('page', 1)));
        $today = Carbon::today();
        $hours = $summary['avg_hours'];

        // ⓘ কার্ডগুলো ওয়েবের পাতার একই পাঁচটা, একই লেখা ([[delivery-performance/index.blade.php]])
        $cards = [
            ['label' => __('sales::delivery_performance.otif'), 'value' => $summary['percent'] === null ? '—' : $summary['percent'].'%',
                'hint' => __('sales::delivery_performance.otif_hint', ['otif' => $summary['otif'], 'due' => $summary['due']])],
            ['label' => __('sales::delivery_performance.on_time'), 'value' => $summary['on_time'].' / '.$summary['due'],
                'hint' => __('sales::delivery_performance.on_time_hint')],
            ['label' => __('sales::delivery_performance.in_full'), 'value' => $summary['in_full'].' / '.$summary['due'],
                'hint' => __('sales::delivery_performance.in_full_hint')],
            ['label' => __('sales::delivery_performance.lead'),
                'value' => $hours === null ? '—' : __('sales::delivery_performance.days_hours', ['days' => intdiv($hours, 24), 'hours' => $hours % 24]),
                'hint' => __('sales::delivery_performance.lead_hint')],
            ['label' => __('sales::delivery_performance.late'), 'value' => (string) $summary['late'],
                'hint' => __('sales::delivery_performance.late_hint')],
        ];

        return response()->json([
            'from' => $from,
            'to' => $to,
            'cards' => $cards,
            'late' => collect($late->items())->map(fn ($d) => [
                'id' => (string) $d->public_id,
                'document_no' => (string) $d->document_no,
                'customer' => (string) ($d->customer?->name() ?? ''),
                'order_no' => (string) ($d->order_no ?? ''),
                'promised_on' => DateFormat::format($d->promised_on),
                'arrived_on' => $d->arrived_on ? DateFormat::format($d->arrived_on) : __('sales::delivery_performance.not_yet'),
                'days_late' => (int) Carbon::parse($d->promised_on)->diffInDays($d->arrived_on ? Carbon::parse($d->arrived_on) : $today),
                'stage' => $d->stage ? DeliveryStage::label((string) $d->stage) : '',
            ])->values(),
            'late_count' => $late->total(),
            'next_page' => $late->hasMorePages() ? $late->currentPage() + 1 : null,
        ]);
    }

    /** `GET /sales/opportunities/pipeline` — ধাপ ধরে সম্ভাবনা, প্রতি ধাপের গোনা, মোট আর ওজন-করা মোট */
    public function pipeline(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $service = app(OpportunityService::class);
        $board = $service->pipeline($user);

        return response()->json([
            'open_weighted' => Money::format($service->openWeighted($board)),
            'stages' => array_map(fn (array $column) => [
                'name' => $column['stage']->name(),
                'count' => $column['count'],
                'total' => Money::format($column['total']),
                'weighted' => Money::format($column['weighted']),
                'rows' => $column['rows']->map(fn ($o) => [
                    'id' => (string) $o->public_id,
                    'title' => (string) $o->title,
                    'party' => $o->partyName(),
                    'value' => Money::format($o->estimated_value),
                    'probability' => (int) $o->probability,
                    'close_on' => $o->expected_close_date ? DateFormat::format($o->expected_close_date) : '',
                    'salesperson' => (string) ($o->salesperson?->name ?? ''),
                ])->values(),
            ], $board),
        ]);
    }

    private function date(mixed $raw): ?string
    {
        $raw = trim((string) $raw);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 && strtotime($raw) !== false ? $raw : null;
    }
}
