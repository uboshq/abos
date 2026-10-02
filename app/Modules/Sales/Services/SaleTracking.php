<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * একটা বিক্রি এখন কোথায় — ফোনের "ডেলিভারি ট্র্যাকিং" (মালিক, ২ অক্টোবর ২০২৬: *"Etar Nam 'Delivery Traking' Daw"*)।
 *
 * ── ⭐ মালিকের চাওয়া ─────────────────────────────────────────────────────
 * ১৯ সেপ্টেম্বর: *"অ্যাপ ১০০% হওয়ার পর সব customer তার নিজের, employee তার অধীনের সকল order
 * ট্রেস করতে পারবে"*; ২ অক্টোবর: "Order Traking"।
 *
 * ── ⚠️ কেন কেবল বিক্রয় আদেশ নয় ──────────────────────────────────────────
 * ADI-র বিক্রি বেশিরভাগ সরাসরি বিক্রয়/DO — বিল আর চালান, বিক্রয় আদেশ ছাড়াই
 * ([[DeliveryOrderTabs]]: "প্রতিটি বিক্রির চালান = একটি DO")। ⛔ ওয়েবের [[OrderTracking]]
 * কেবল `sal_orders` ধরে — ফোনে ওটা দিলে মালিকের আসল বিক্রিগুলোই বাদ পড়ত। তাই এখানে দুই উৎস:
 * চালান (DO) — প্রতিটাই একটা বিক্রি; আর যে আদেশের এখনো চালান হয়নি — "অর্ডার" ধাপে।
 *
 * ── ধাপ (এক কথায়, ফোনের এক লাইনে) ─────────────────────────────────────────
 *   ordered   — আদেশ এসেছে, DO হয়নি
 *   draft     — কাউন্টারে খসড়া
 *   approval  — সইয়ের অপেক্ষায় (চালান, বিল বা বিলের বিপরীতে জমা — [[DirectSaleService::whereHeld()]])
 *   warehouse — পাকা, গুদামে (বরাদ্দ/তোলা/প্যাক)
 *   gate_out  — গেট পেরিয়েছে ([[DeliveryStage::DISPATCHED]])
 *   partial   — কিছু পৌঁছেছে
 *   delivered — পৌঁছেছে
 *   cancelled — বাতিল
 * ⓘ বিল হয়েছে কি না আলাদা পতাকা (`billed`) — ধাপের সাথে মেশানো নয়, কারণ বিল আগে-পরে দুইই হয়।
 *
 * ⓘ দেয়াল: কোম্পানি আর শাখার সাধারণ স্কোপ (মডেলের নিজের)। কর্মীর "নিজের অধীনের" দেয়াল আসবে
 * SR-এর নিজের-দোকানি দেয়ালের সাথে — আজ চাবিওয়ালা সবাই সব দেখেন, ওয়েবের ট্র্যাকিংয়ের মতোই।
 */
final class SaleTracking
{
    public const STEPS = ['ordered', 'draft', 'approval', 'warehouse', 'gate_out', 'partial', 'delivered', 'cancelled'];

    /**
     * ⭐ ৯ রং — মালিকের আদেশ, ২ অক্টোবর ২০২৬; অ্যাপের `trackingColours` হুবহু একই।
     * ⓘ পাতায় ইনলাইন রং, Tailwind শ্রেণি নয় — বান্ডলে না থাকা শ্রেণি নীরবে রংহীন হত।
     */
    public const COLOURS = [
        'draft' => '#9CA3AF', 'pending' => '#F97316', 'approved' => '#2563EB', 'processing' => '#7C3AED',
        'loading' => '#EAB308', 'dispatched' => '#22C55E', 'delivered' => '#15803D', 'rejected' => '#DC2626',
        'hold' => '#111827',
    ];

    /** তালিকার ধাপ → ৯ রঙের কোনটা ([[milestones()]]-এর একই রং) */
    public const CATEGORY_OF_STEP = [
        'ordered' => 'approved', 'draft' => 'draft', 'approval' => 'pending', 'warehouse' => 'processing',
        'gate_out' => 'dispatched', 'partial' => 'dispatched', 'delivered' => 'delivered', 'cancelled' => 'rejected',
    ];

    public function __construct(private readonly DeliveryStageService $stages) {}

    /**
     * তালিকা — সর্বশেষ আগে, `$limit` পর্যন্ত। ধাপের ছাঁকনি PHP-তে (ধাপ কয়েকটা টেবিল মিলিয়ে ঠিক হয়)।
     *
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    /**
     * @param  array{from?: ?string, to?: ?string, branch_id?: ?int, sort?: ?string}  $filter  ওয়েবের টুলবারের ছাঁকনি
     *         (মালিক, ২ অক্টোবর ২০২৬: "Delivery tracking টুলবার dibe") — দুই উৎসেই একই নিয়মে
     */
    public function list(?string $term, ?string $step, ?int $customerId, int $limit = 100, array $filter = []): array
    {
        $narrow = function ($q) use ($filter) {
            return $q
                ->when($filter['from'] ?? null, fn ($w, $d) => $w->where('trx_date', '>=', $d))
                ->when($filter['to'] ?? null, fn ($w, $d) => $w->where('trx_date', '<=', $d))
                ->when($filter['branch_id'] ?? null, fn ($w, $b) => $w->where('branch_id', $b));
        };

        $challans = $narrow(DeliveryChallan::query())
            ->with('customer')
            ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($term, fn ($q, $t) => $q->where(fn ($w) => $w
                ->where('document_no', 'like', "%{$t}%")
                ->orWhere('sale_no', 'like', "%{$t}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_en', 'like', "%{$t}%")
                    ->orWhere('name_bn', 'like', "%{$t}%")->orWhere('code', 'like', "%{$t}%"))))
            ->orderByDesc('trx_date')->orderByDesc('id')
            ->limit($limit)
            ->get();

        // যে আদেশের কোনো (বাতিল নয়) চালান নেই — "অর্ডার" ধাপ
        $orders = $narrow(SalesOrder::query())
            ->with('customer')
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sal_challans as c')
                ->whereColumn('c.sales_order_id', 'sal_orders.id')
                ->where('c.status', '<>', DocumentStatus::CANCELLED))
            ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($term, fn ($q, $t) => $q->where(fn ($w) => $w
                ->where('document_no', 'like', "%{$t}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_en', 'like', "%{$t}%")
                    ->orWhere('name_bn', 'like', "%{$t}%")->orWhere('code', 'like', "%{$t}%"))))
            ->orderByDesc('trx_date')->orderByDesc('id')
            ->limit($limit)
            ->get();

        $steps = $this->stepsOf($challans);
        $billed = $this->billedChallans($challans->modelKeys());

        $rows = collect();

        foreach ($challans as $challan) {
            $rows->push([
                'kind' => 'challan',
                'id' => (string) $challan->public_id,
                'no' => (string) ($challan->sale_no ?: $challan->document_no),
                'document_no' => (string) $challan->document_no,
                'date' => $challan->trx_date?->toDateString(),
                'customer' => $challan->customer?->name(),
                'total' => bcadd((string) $challan->total, '0', 2),
                'step' => $steps[$challan->id],
                'billed' => isset($billed[$challan->id]),
                'sort' => [$challan->trx_date?->toDateString(), (int) $challan->id],
            ]);
        }

        foreach ($orders as $order) {
            $rows->push([
                'kind' => 'order',
                'id' => (string) $order->public_id,
                'no' => (string) $order->document_no,
                'document_no' => (string) $order->document_no,
                'date' => $order->trx_date?->toDateString(),
                'customer' => $order->customer?->name(),
                'total' => bcadd((string) $order->total, '0', 2),
                'step' => 'ordered',
                'billed' => false,
                'sort' => [$order->trx_date?->toDateString(), (int) $order->id],
            ]);
        }

        $key = fn (array $r) => ($r['sort'][0] ?? '').sprintf('%012d', $r['sort'][1]);
        $rows = match ($filter['sort'] ?? 'recent') {
            'oldest' => $rows->sortBy($key),
            'largest' => $rows->sortByDesc(fn (array $r) => (float) $r['total']),
            'customer' => $rows->sortBy(fn (array $r) => mb_strtolower((string) $r['customer']).$key($r)),
            default => $rows->sortByDesc($key),
        };
        $rows = $rows->values();
        $counts = array_fill_keys(['all', ...self::STEPS], 0);
        $counts['all'] = $rows->count();

        foreach ($rows as $r) {
            $counts[$r['step']]++;
        }

        return [
            'rows' => $rows
                ->when($step !== null && in_array($step, self::STEPS, true), fn ($c) => $c->where('step', $step))
                ->take($limit)
                ->map(fn (array $r) => array_diff_key($r, ['sort' => true]) + ['category' => self::CATEGORY_OF_STEP[$r['step']]])
                ->values()->all(),
            'counts' => $counts,
        ];
    }

    /**
     * একটা বিক্রির সময়রেখা — কে কখন কী করলেন, পুরনো আগে।
     *
     * @return array<string, mixed>
     */
    public function story(DeliveryChallan|SalesOrder $sale): array
    {
        $events = collect();
        $order = $sale instanceof SalesOrder ? $sale : $sale->salesOrder;

        if ($order !== null) {
            $order->loadMissing('creator');
            $events->push($this->event($order->created_at, 'ordered', $order->creator?->name,
                __('sales::tracking.ordered', ['no' => $order->document_no])));
        }

        if ($sale instanceof SalesOrder) {
            // ⓘ চালান হয়নি — অর্ডার তৈরিতে টিক, বাকি সামনে, পরেরটা "এখন"
            $milestones = [$this->mile('order_created', 'approved', true, $sale->created_at, $sale->creator?->name)];
            foreach (['challan_draft' => 'draft', 'challan_confirmed' => 'approved', 'stock_allocated' => 'processing',
                'transport_assigned' => 'processing', 'loading_started' => 'loading', 'loading_completed' => 'loading',
                'invoice_generated' => 'processing', 'gate_pass_generated' => 'dispatched', 'dispatched' => 'dispatched',
                'delivered' => 'delivered'] as $key => $category) {
                $milestones[] = $this->mile($key, $category, false, null, null);
            }
            $milestones[1]['state'] = 'current';

            return $this->head($sale, 'ordered', false) + ['events' => $events->values()->all(), 'milestones' => $milestones];
        }

        $challan = $sale->loadMissing(['customer', 'creator']);
        $events->push($this->event($challan->created_at, 'draft', $challan->creator?->name,
            __('sales::tracking.do_written', ['no' => $challan->document_no])));

        foreach ($this->approvalsOf($challan) as $approval) {
            $events->push($this->event($approval->requested_at ?? $approval->created_at, 'approval',
                $approval->requester?->name, __('sales::tracking.sent_for_signature')));

            foreach ($approval->decisions as $decision) {
                // ⓘ সিদ্ধান্তের তিন মান — approved, rejected, forwarded ([[ApprovalEngine]])
                $kind = in_array($decision->decision, ['approved', 'rejected', 'forwarded'], true) ? $decision->decision : 'other';
                $events->push($this->event($decision->decided_at, 'approval', $decision->user?->name,
                    __('sales::tracking.decision.'.$kind).($decision->remarks ? ' — '.$decision->remarks : '')));
            }
        }

        foreach ($this->stages->timeline($challan) as $event) {
            $events->push($this->event($event->occurred_at, $this->stepFromStage((string) $event->to_stage),
                $event->creator?->name,
                trim(__('sales::delivery.stage.'.$event->to_stage)
                    .($event->receiver_name ? ' — '.__('sales::tracking.received_by', ['name' => $event->receiver_name]) : '')
                    .($event->note ? ' · '.$event->note : ''))));
        }

        $pass = GatePass::query()->withoutGlobalScope('user-branch')
            ->where('delivery_challan_id', $challan->id)
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->with('issuer')->latest('id')->first();

        if ($pass !== null) {
            $events->push($this->event($pass->issued_at, 'gate_out', $pass->issuer?->name,
                __('sales::tracking.gate_pass', ['no' => $pass->document_no, 'vehicle' => $pass->vehicle_no ?: '—'])));
        }

        foreach ($this->invoicesOf($challan) as $invoice) {
            $events->push($this->event($invoice->updated_at, 'billed', $invoice->creator?->name,
                __('sales::tracking.billed', ['no' => $invoice->document_no, 'total' => bcadd((string) $invoice->total, '0', 2)])));
        }

        $step = $this->stepsOf(collect([$challan]))[$challan->id];

        return $this->head($challan, $step, $this->invoicesOf($challan)->isNotEmpty()) + [
            'events' => $events->sortBy(fn (array $e) => $e['at'] ?? '')->values()->all(),
            'milestones' => $this->milestones($challan, $order, $pass),
        ];
    }

    /**
     * ⭐ টিকচিহ্নের অগ্রগতি-দাগ — মালিকের আদেশ, ২ অক্টোবর ২০২৬ (সমন্বয়কের মারফত)।
     *
     * ── ধাপ, ক্রমে ──────────────────────────────────────────────────────────
     * অর্ডার তৈরি → (কোম্পানির নিজের অনুমোদন-স্তর, নাম ধরে) → চালানের খসড়া → চালান নিশ্চিত →
     * মাল বরাদ্দ → পরিবহন ঠিক → লোডিং শুরু → লোডিং শেষ → বিল তৈরি → গেট পাস → রওনা → পৌঁছেছে।
     * ⓘ অনুমোদনের স্তর কোডে বাঁধা নয় — [[ApprovalEngine::stepsFor()]]-এর `step_name`; কোম্পানি ছক বদলালে
     * দাগও বদলায়।
     *
     * ── ৯ রং (`category`) ────────────────────────────────────────────────────
     * draft ধূসর · pending কমলা · approved নীল · processing বেগুনি · loading হলুদ ·
     * dispatched সবুজ · delivered গাঢ় সবুজ · rejected লাল · hold কালো।
     *
     * ── অবস্থা (`state`) ──────────────────────────────────────────────────────
     * done (টিক) · current (এখন এখানে) · todo (সামনে) · rejected (ফেরত/বাতিল) · hold (থামানো)।
     * ⓘ পরের কোনো ধাপ হয়ে গেলে আগের ধাপ ঘটনা ছাড়াও "হয়েছে" — যেমন লোডিং না চেপে সরাসরি রওনা;
     * সময় তখন নেই (null), মিথ্যা সময় বসানো হয় না।
     *
     * @return list<array{key: string, label: string, category: string, state: string, at: ?string, by: ?string}>
     */
    public function milestones(DeliveryChallan $challan, ?SalesOrder $order, ?GatePass $pass): array
    {
        $rank = [DeliveryStage::PENDING => 0, DeliveryStage::ALLOCATED => 1, DeliveryStage::PICKING => 2,
            DeliveryStage::PACKED => 3, DeliveryStage::DISPATCHED => 4, DeliveryStage::PARTIALLY_DELIVERED => 5,
            DeliveryStage::DELIVERED => 6];
        $state = DeliveryState::query()->where('delivery_challan_id', $challan->id)->value('stage');
        $posted = in_array($challan->status, DocumentStatus::POSTED, true);
        $now = $rank[(string) $state] ?? ($posted ? 1 : 0);
        $reached = fn (string $stage): ?array => $this->firstEvent($challan, $stage);
        $invoice = $this->invoicesOf($challan)->first();
        $m = [];

        $first = $order ?? $challan;
        $m[] = $this->mile('order_created', 'approved', true, $first->created_at, $first->creator?->name);

        foreach ($this->approvalsOf($challan) as $approval) {
            $byLevel = $approval->decisions->groupBy('level');

            foreach (app(\App\Core\Engines\Approval\ApprovalEngine::class)->stepsFor($approval) as $level) {
                $n = (int) $level->level;
                $decision = $byLevel->get($n)?->sortByDesc('id')->first();
                $label = trim((string) ($level->step_name ?? '')) ?: __('sales::tracking.milestone.approval_level', ['level' => $n]);

                [$category, $done, $st] = match (true) {
                    $decision?->decision === 'rejected' => ['rejected', false, 'rejected'],
                    $approval->status === Approval::APPROVED || $n < (int) $approval->current_level
                        || $decision?->decision === 'approved' => ['approved', true, 'done'],
                    $approval->status === Approval::PENDING && $n === (int) $approval->current_level => ['pending', false, 'current'],
                    default => ['pending', false, 'todo'],
                };

                $m[] = ['key' => 'approval_'.$approval->id.'_'.$n, 'label' => $label, 'category' => $category, 'state' => $st,
                    'at' => $done || $st === 'rejected' ? $this->iso($decision?->decided_at) : null,
                    'by' => $done || $st === 'rejected' ? $decision?->user?->name : null];
            }
        }

        $m[] = $this->mile('challan_draft', 'draft', true, $challan->created_at, $challan->creator?->name);
        $m[] = $this->mile('challan_confirmed', 'approved', $posted, $reached(DeliveryStage::ALLOCATED)['at'] ?? null, $reached(DeliveryStage::ALLOCATED)['by'] ?? null);
        $m[] = $this->mile('stock_allocated', 'processing', $posted && $now >= 1, $reached(DeliveryStage::ALLOCATED)['at'] ?? null, null);
        $m[] = $this->mile('transport_assigned', 'processing', TransportRule::named($challan), null, null);
        $m[] = $this->mile('loading_started', 'loading', $now >= 2, $reached(DeliveryStage::PICKING)['at'] ?? null, $reached(DeliveryStage::PICKING)['by'] ?? null);
        $m[] = $this->mile('loading_completed', 'loading', $now >= 3, $reached(DeliveryStage::PACKED)['at'] ?? null, $reached(DeliveryStage::PACKED)['by'] ?? null);
        $m[] = $this->mile('invoice_generated', 'processing', $invoice !== null, $invoice?->updated_at, $invoice?->creator?->name);
        $m[] = $this->mile('gate_pass_generated', 'dispatched', $pass !== null, $pass?->issued_at, $pass?->issuer?->name);
        $m[] = $this->mile('dispatched', 'dispatched', $now >= 4, $reached(DeliveryStage::DISPATCHED)['at'] ?? null, $reached(DeliveryStage::DISPATCHED)['by'] ?? null);
        $m[] = $this->mile('delivered', 'delivered', $now >= 6, $reached(DeliveryStage::DELIVERED)['at'] ?? null, $reached(DeliveryStage::DELIVERED)['by'] ?? null);

        // ── এখন কোথায়, আর থেমে আছে কি না ──────────────────────────────────
        $stopped = match (true) {
            $challan->status === DocumentStatus::CANCELLED || $state === DeliveryStage::CANCELLED => 'rejected',
            $state === DeliveryStage::FAILED => 'hold',
            $challan->status === DocumentStatus::DRAFT && $this->isPaused($challan) => 'hold',
            default => null,
        };
        $rejectedAlready = collect($m)->contains(fn (array $x) => $x['state'] === 'rejected');
        // ⛔ অনুমোদনের স্তর আগেই "এখন" হলে আর কোনোটা নয় — একসাথে দুই জায়গায় "এখন" কখনো নয়
        $currentAlready = collect($m)->contains(fn (array $x) => $x['state'] === 'current');

        foreach ($m as $i => $x) {
            if ($x['state'] !== 'todo') {
                continue;
            }
            if ($rejectedAlready || ($currentAlready && $stopped === null)) {
                break;
            }
            $m[$i]['state'] = $stopped ?? 'current';
            if ($stopped !== null) {
                $m[$i]['category'] = $stopped;
            } elseif ($x['key'] === 'delivered' && $state === DeliveryStage::PARTIALLY_DELIVERED) {
                $m[$i]['category'] = 'delivered';
            }
            break;
        }

        return array_values($m);
    }

    /** @return array{key: string, label: string, category: string, state: string, at: ?string, by: ?string} */
    private function mile(string $key, string $category, bool $done, mixed $at, ?string $by): array
    {
        return [
            'key' => $key,
            'label' => __('sales::tracking.milestone.'.$key),
            'category' => $category,
            'state' => $done ? 'done' : 'todo',
            'at' => $done ? $this->iso($at) : null,
            'by' => $done ? $by : null,
        ];
    }

    private function iso(mixed $at): ?string
    {
        return $at instanceof Carbon ? $at->toIso8601String() : ($at ? Carbon::parse((string) $at)->toIso8601String() : null);
    }

    /** @return array{at: ?string, by: ?string}|null এই ধাপে প্রথম পৌঁছানোর ঘটনা */
    private function firstEvent(DeliveryChallan $challan, string $stage): ?array
    {
        $event = $this->stageEvents($challan)->firstWhere('to_stage', $stage);

        return $event === null ? null : ['at' => $this->iso($event->occurred_at), 'by' => $event->creator?->name];
    }

    /** @var array<int, Collection<int, \App\Modules\Sales\Models\DeliveryEvent>> */
    private array $eventCache = [];

    /** @return Collection<int, \App\Modules\Sales\Models\DeliveryEvent> */
    private function stageEvents(DeliveryChallan $challan): Collection
    {
        return $this->eventCache[$challan->id] ??= $this->stages->timeline($challan);
    }

    /** কাউন্টারে থামিয়ে রাখা খসড়া — [[DirectSaleService::pauseDraft()]] */
    private function isPaused(DeliveryChallan $challan): bool
    {
        $invoiceIds = DB::table('sal_challan_lines as cl')
            ->join('sal_invoice_lines as il', 'il.delivery_challan_line_id', '=', 'cl.id')
            ->where('cl.delivery_challan_id', $challan->id)->distinct()->pluck('il.sales_invoice_id');

        return $invoiceIds->isNotEmpty() && SalesInvoice::query()->whereIn('id', $invoiceIds)
            ->whereNotNull('draft_paused_at')->exists();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /**
     * চালান → ধাপ, এক প্রশ্নে পুরো পাতা।
     *
     * @param  Collection<int, DeliveryChallan>  $challans
     * @return array<int, string>
     */
    private function stepsOf(Collection $challans): array
    {
        if ($challans->isEmpty()) {
            return [];
        }

        $ids = $challans->map(fn (DeliveryChallan $c) => (int) $c->id)->values()->all();
        $stages = DeliveryState::query()->whereIn('delivery_challan_id', $ids)->pluck('stage', 'delivery_challan_id');
        $held = $this->heldChallans($ids);
        $out = [];

        foreach ($challans as $c) {
            $out[$c->id] = match (true) {
                $c->status === DocumentStatus::CANCELLED => 'cancelled',
                isset($held[$c->id]) => 'approval',
                $c->status === DocumentStatus::DRAFT => 'draft',
                default => $this->stepFromStage((string) ($stages[$c->id] ?? DeliveryStage::ALLOCATED)),
            };
        }

        return $out;
    }

    private function stepFromStage(string $stage): string
    {
        return match ($stage) {
            DeliveryStage::DISPATCHED => 'gate_out',
            DeliveryStage::PARTIALLY_DELIVERED => 'partial',
            DeliveryStage::DELIVERED => 'delivered',
            DeliveryStage::CANCELLED, DeliveryStage::FAILED => 'cancelled',
            DeliveryStage::PENDING => 'draft',
            default => 'warehouse',
        };
    }

    /**
     * সইয়ের অপেক্ষায় থাকা চালান — চালানের নিজের, তার বিলের, বা বিলের বিপরীতে জমার অনুমোদন।
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function heldChallans(array $ids): array
    {
        $invoiceOf = DB::table('sal_challan_lines as cl')
            ->join('sal_invoice_lines as il', 'il.delivery_challan_line_id', '=', 'cl.id')
            ->whereIn('cl.delivery_challan_id', $ids)
            ->select('cl.delivery_challan_id', 'il.sales_invoice_id')->distinct()->get();

        $heldInvoices = $invoiceOf->isEmpty() ? collect() : DirectSaleService::awaitingApproval(
            SalesInvoice::query()->whereIn('sal_invoices.id', $invoiceOf->pluck('sales_invoice_id')->unique()),
        )->pluck('id')->flip();

        $direct = Approval::query()->pending()
            ->where('approvable_type', DeliveryChallan::class)
            ->whereIn('approvable_id', $ids)->pluck('approvable_id')->flip();

        $out = [];

        foreach ($ids as $id) {
            if (isset($direct[$id])) {
                $out[$id] = true;
            }
        }

        foreach ($invoiceOf as $pair) {
            if (isset($heldInvoices[$pair->sales_invoice_id])) {
                $out[(int) $pair->delivery_challan_id] = true;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function billedChallans(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('sal_challan_lines as cl')
            ->join('sal_invoice_lines as il', 'il.delivery_challan_line_id', '=', 'cl.id')
            ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
            ->whereIn('cl.delivery_challan_id', $ids)
            ->whereIn('i.status', DocumentStatus::POSTED)
            ->distinct()->pluck('cl.delivery_challan_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /** @return Collection<int, SalesInvoice> */
    private function invoicesOf(DeliveryChallan $challan): Collection
    {
        return SalesInvoice::query()
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereHas('lines.challanLine', fn ($q) => $q->where('delivery_challan_id', $challan->id))
            ->with('creator')->orderBy('id')->get();
    }

    /** @return Collection<int, Approval> */
    private function approvalsOf(DeliveryChallan $challan): Collection
    {
        $invoiceIds = DB::table('sal_challan_lines as cl')
            ->join('sal_invoice_lines as il', 'il.delivery_challan_line_id', '=', 'cl.id')
            ->where('cl.delivery_challan_id', $challan->id)->distinct()->pluck('il.sales_invoice_id');

        return Approval::query()
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('approvable_type', DeliveryChallan::class)->where('approvable_id', $challan->id))
                ->orWhere(fn ($w) => $w->where('approvable_type', SalesInvoice::class)->whereIn('approvable_id', $invoiceIds)))
            ->with(['requester', 'decisions.user'])
            ->orderBy('id')->get();
    }

    /** @return array{at: ?string, step: string, by: ?string, text: string} */
    private function event(mixed $at, string $step, ?string $by, string $text): array
    {
        return [
            'at' => $at instanceof Carbon ? $at->toIso8601String() : ($at ? Carbon::parse((string) $at)->toIso8601String() : null),
            'step' => $step,
            'by' => $by,
            'text' => $text,
        ];
    }

    /** @return array<string, mixed> */
    private function head(DeliveryChallan|SalesOrder $sale, string $step, bool $billed): array
    {
        $sale->loadMissing('customer');

        return [
            'kind' => $sale instanceof SalesOrder ? 'order' : 'challan',
            'id' => (string) $sale->public_id,
            'no' => (string) ($sale instanceof DeliveryChallan && $sale->sale_no ? $sale->sale_no : $sale->document_no),
            'document_no' => (string) $sale->document_no,
            'date' => $sale->trx_date?->toDateString(),
            'customer' => $sale->customer?->name(),
            'total' => bcadd((string) $sale->total, '0', 2),
            'step' => $step,
            'category' => self::CATEGORY_OF_STEP[$step],
            'billed' => $billed,
        ];
    }
}
