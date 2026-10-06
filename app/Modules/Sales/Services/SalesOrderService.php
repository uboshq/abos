<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Events\SalesOrderApproved;
use App\Modules\Sales\Events\SalesOrderCancelled;
use App\Modules\Sales\Events\SalesOrderClosed;
use App\Modules\Sales\Events\SalesOrderLineRejected;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * বিক্রয় আদেশ — গ্রাহক কী চেয়েছেন।
 *
 * ── এই ফাইলের কেন্দ্রীয় সিদ্ধান্ত: Reserved ────────────────────────────
 * আদেশ নিশ্চিত হলে মালটা অর্ডারে ধরা পড়ে। মালটা তাকেই থাকে (Floor কমে না),
 * শুধু আর বেচা যায় না (Available কমে)।
 *
 * সরিয়ে ফেললে গুদামে দাঁড়িয়ে গোনা মানুষ ১০০ পেতেন আর খাতা বলত ৮০, অথচ
 * কেউ কিছু সরায়নি। আর একেবারে না ধরলে একই শেষ কার্টনটা দুইজনকে বেচা হয়ে
 * যেত — দুইটা চালান ছাপা হত, আর ভুলটা ধরা পড়ত মাল দিতে গিয়ে, ক্রেতার
 * সামনে।
 *
 * খতিয়ানে কিছুই বসে না। অর্ডার একটা প্রতিশ্রুতি, আর প্রতিশ্রুতির হিসাব হয়
 * না — গ্রাহক অর্ডার বাতিল করলে ওই আয়টা কেউ সরাত না।
 */
final class SalesOrderService
{
    use CalculatesSalesLines;
    use ReadsPackedQuantities;

    /**
     * ⭐ নতুন ধারার সইয়ের ছক — কোম্পানির একটাই `order` ছক (সমন্বয়কের উত্তর ৫: সুইচ চালু → কেবল বিক্রয় আদেশ)।
     *
     * ⓘ কোডে আক্ষরিক `'order'` লেখা থাকে — ছকের পর্দার পাহারা কোড পড়ে কে সই চায় ([[EveryApprovalAskedForCanBeConfiguredTest]])।
     */
    public const APPROVAL_ACTION = 'order';

    /**
     * ⭐ কোম্পানির সুইচ — DO বিক্রয় আদেশে মেশে (মালিক, ৪ অক্টোবর ২০২৬; নকশার §৫)। ⚠️ ডিফল্ট বন্ধ: বন্ধ থাকলে সব আজকের মতো।
     */
    public const REPLACES_DO = 'sales.orders_replace_do';

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly StockService $stock,
        private readonly SettingsService $settings,
        private readonly DocumentApproval $approvals,
        private readonly ApprovalEngine $engine,
        private readonly CreditExposure $credit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): SalesOrder
    {
        $this->assertHasLines($lines);

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $documentNo = $this->numbers->next('SO');

            $order = SalesOrder::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'customer_id' => $data['customer_id'],
                // ⓘ কোথা থেকে এলো — ফোন `sr` পাঠায় ([[SalesOrderApiController]], [[SalesOrderSync]]); না পাঠালে কলামের নিজের মান
                ...(isset($data['source']) && in_array($data['source'], SalesOrderStatus::SOURCES, true) ? ['source' => $data['source']] : []),
                'warehouse_id' => $data['warehouse_id'] ?? $this->defaultWarehouse()?->id,
                'trx_date' => $trxDate->toDateString(),
                'deliver_on' => $data['deliver_on'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                // ⓘ গ্রাহক নিজে লিখলে (পোর্টাল) লেখক তিনি, কর্মী নন — পোর্টালের পাহারায় `auth()->id()` গ্রাহকের id, কর্মীর নয়
                'created_by' => isset($data['created_by_customer_id']) ? null : auth()->id(),
                'created_by_customer_id' => $data['created_by_customer_id'] ?? null,
            ]);

            $this->replaceLines($order, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => SalesOrder::drillSourceType(),
                    'source_id' => $order->id,
                ]);

            return $order->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(SalesOrder $order, array $data, array $lines): SalesOrder
    {
        $this->assertEditable($order);
        $this->assertHasLines($lines);

        return DB::transaction(function () use ($order, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $order->trx_date);

            $order->update([
                'customer_id' => $data['customer_id'] ?? $order->customer_id,
                'warehouse_id' => $data['warehouse_id'] ?? $order->warehouse_id,
                'trx_date' => $trxDate->toDateString(),
                'deliver_on' => $data['deliver_on'] ?? null,
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($order, $lines);

            return $order->fresh(['lines']);
        });
    }

    /**
     * আদেশ নিশ্চিত — মাল অর্ডারে ধরা পড়ে।
     */
    public function confirm(SalesOrder $order): SalesOrder
    {
        /*
         * ⭐ নতুন ধারা — সুইচ চালু কোম্পানিতে "নিশ্চিত" মানে "জমা" ([[submit()]]): বাকির যাচাই, তারপর সুপারভাইজার।
         * ⓘ আজকের বোতাম, মোবাইলের সিঙ্ক আর উদ্ধৃতির রূপান্তর সবাই এখানে আসে — তাই কারো পথ বদলাতে হয় না।
         */
        if ($this->replacesDo()) {
            return $this->submit($order);
        }

        if ($order->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_confirms', ['no' => $order->document_no]),
            ]);
        }

        $order->loadMissing(['lines.product', 'warehouse', 'customer']);

        if ($order->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        /*
         * ⭐ অনুমোদন — মালিকের সিদ্ধান্ত, ১৮ সেপ্টেম্বর ২০২৬।
         *
         * মালিকের কথা: *"এখন সব জায়গায় এপ্রুভাল দিয়ে টেস্ট কর, পরে যে
         * যে জায়গায় লাগবে না তাও উঠিয়ে দিব"*।
         *
         * ⚠️ সারিটা কারো আজকের কাজ থামায় না: ছক না বসানো পর্যন্ত
         * `assertClear()` চুপচাপ ফিরে যায়, আর কাজ আগের মতোই চলে।
         * ⓘ কত টাকার উপরে সই লাগবে সেটা প্রতিটা কোম্পানি নিজে বসায় —
         * এক ডিপোর "বড় কাজ" আরেকটার রোজকার কাজ।
         */
        $this->approvals->assertClear(
            document: $order,
            module: 'sales',
            action: 'order',
            field: 'status',
            amount: (string) $order->total,
            reason: $order->narration,
        );

        /*
         * ⓘ বাকির সীমা এখানে **দেখা হয় না** — মালিকের নির্দেশ, ২৬ সেপ্টেম্বর ২০২৬।
         *
         * *"customer ba SR order kikore dibe, seta DO/delivery order theke
         * suro hobe"* — আদেশ আন্দাজের জিনিস, মাল তখনো গুদামে। ⛔ এখানে
         * আটকালে গ্রাহক বা বিক্রয়কর্মী আদেশই দিতে পারতেন না।
         *
         * ⭐ দেয়ালটা এখন চালানে আর বিলে ([[CreditExposure::assertRoom()]]),
         * যেখানে মাল গেট পার হয়। ⚠️ আর আদেশ কোনো সীমা আটকায়ও না —
         * আটকায় কেবল বিল না হওয়া চালান আর খসড়া বিল।
         */
        return DB::transaction(function () use ($order) {
            // ⛔ দ্বিতীয় ক্লিক — তালার ভিতরে অবস্থা আবার ([[lockAndReread()]])
            $this->lockAndReread($order);

            if ($order->status !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('sales::validation.only_draft_confirms', ['no' => $order->document_no]),
                ]);
            }

            // ⓘ ডিফল্ট বন্ধ — মালিক, ৬ অক্টোবর ২০২৬: মাল ধরা শুরু ডিপোর চালান নিশ্চিত হলে, আদেশে নয়
            if ($this->settings->get('sales.reserve_on_order', false)) {
                $warehouse = $order->warehouse ?? $this->defaultWarehouse();

                if ($warehouse === null) {
                    throw ValidationException::withMessages([
                        'warehouse_id' => __('sales::validation.unknown_warehouse'),
                    ]);
                }

                // ⛔ গোনার আগে পণ্যের সারিতে তালা — দুটো একসাথে ধরলে দুটোই একই খালি মাল দেখত (অডিট ম১৮, [[StockLock]])
                \App\Modules\Sales\Support\StockLock::products($order->lines->pluck('product_id'));

                foreach ($order->lines as $line) {
                    $this->assertEnoughToSell($line->product, $warehouse, (string) $line->ordered_qty);

                    $this->stock->move(
                        product: $line->product,
                        warehouse: $warehouse,
                        sourceType: SalesOrder::STOCK_SOURCE,
                        sourceId: $order->id,
                        reserved: (string) $line->ordered_qty,
                        date: $order->trx_date,
                        documentNo: $order->document_no,
                    );
                }
            }

            $order->update(['status' => DocumentStatus::CONFIRMED]);

            return $order->fresh(['lines']);
        });
    }

    /**
     * বাতিল — ধরে রাখা মাল ছেড়ে দেওয়া হয়।
     *
     * যা ইতিমধ্যে চালান হয়ে বেরিয়ে গেছে তার ধরাটা চালানই ছেড়ে দিয়েছে,
     * তাই এখানে ছাড়া হয় কেবল যেটুকু এখনো ধরা আছে। দুইবার ছাড়লে Reserved
     * ঋণাত্মক হয়ে যেত।
     */
    public function cancel(SalesOrder $order, string $reason): SalesOrder
    {
        $this->assertNotClosed($order);

        if ($order->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.already_cancelled', ['no' => $order->document_no]),
            ]);
        }

        $order->loadMissing(['lines.product', 'warehouse']);

        return DB::transaction(function () use ($order, $reason) {
            // ⛔ দ্বিতীয় ক্লিক — তালার ভিতরে অবস্থা আবার ([[lockAndReread()]])
            $this->lockAndReread($order);

            if ($order->status === DocumentStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => __('sales::validation.already_cancelled', ['no' => $order->document_no]),
                ]);
            }

            // ⛔ বন্ধ আদেশের ধরা মাল বন্ধেই ছাড়া হয়েছে — বাতিলে আবার ছাড়লে Reserved ঋণাত্মক হত
            $this->assertNotClosed($order);

            // ⭐ অফার, উপহার, কুপন আর পয়েন্টও ফেরে — চালানের সাধারণ বাতিলের মতো (পুরো ERP অডিট, প্রমোশন ⛔১, ৬ অক্টোবর ২০২৬)
            app(\App\Core\Contracts\SalesOffers::class)->reverseAll(SalesOrder::drillSourceType(), (int) $order->id);

            if ($order->status === DocumentStatus::CONFIRMED && $order->warehouse) {
                // ⛔ কেবল এই আদেশ নিজে যা ধরেছিল — অন্য কাগজের ধরা মাল নয় ([[heldByThisOrder()]], ৪ অক্টোবর ২০২৬)
                $own = $this->heldByThisOrder($order);

                foreach ($order->lines as $line) {
                    $stillReserved = $this->ownShare($own, $line);

                    if (bccomp($stillReserved, '0', 4) <= 0) {
                        continue;
                    }

                    $this->stock->move(
                        product: $line->product,
                        warehouse: $order->warehouse,
                        sourceType: SalesOrder::STOCK_SOURCE.':cancel',
                        sourceId: $order->id,
                        reserved: bcmul($stillReserved, '-1', 4),
                        date: now(),
                        documentNo: $order->document_no,
                        narration: $reason,
                    );
                }
            }

            $order->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            $fresh = $order->fresh(['lines']);

            // ⭐ abos-86-এর হোল্ড ছাড়ার জন্য — লেনদেন পাকা হলে তবেই ([[SalesOrderCancelled]])
            DB::afterCommit(fn () => event(SalesOrderCancelled::from($fresh, $reason)));

            return $fresh;
        });
    }

    /**
     * কোম্পানি নতুন ধারায় চলে কি না — DO বিক্রয় আদেশে মেশানো ([[REPLACES_DO]], ডিফল্ট বন্ধ)।
     */
    public function replacesDo(): bool
    {
        return (bool) $this->settings->get(self::REPLACES_DO, false);
    }

    /**
     * ⭐ জমা — নতুন ধারা: খসড়া → জমা → বাকির যাচাই → সীমায় আটকে | সুপারভাইজারের সই | অনুমোদিত।
     *
     * ── সমন্বয়কের উত্তর ১ (৪ অক্টোবর ২০২৬): বাকির যাচাই জমার মুহূর্তে, সুপারভাইজারের আগে ──────────────
     * ⓘ পরিকল্পনা ২-এর কথা: *"জমা দিলে বাকির সীমা যাচাই, তারপর অনুমোদন"*। আবার যাচাই চালানে আর গেট পাসে (দেয়াল আজকের মতোই)।
     * ⚠️ কুলোয় না → `credit_held`, কত কম (`credit_short`), প্রথম আটকানোর মুহূর্ত (`credit_held_at`) — সই চাওয়াই হয় না;
     * টাকা এলে [[recheckCredit()]] (abos-86-এর শ্রোতা ডাকে) আবার যাচাই করে সই চায়।
     *
     * ⓘ জমার পরে লেখক আর বদলাতে পারেন না (খসড়াই কেবল বদলায়); প্রতিটা লাইনের চাওয়া পরিমাণ `requested_qty`-তে থাকে —
     * সুপারভাইজার কমালে `ordered_qty` কমে ([[setApprovedQuantities()]])। ⓘ মাল আটকানো এই ধারায় হোল্ডে (`hold_mode =
     * holds`, abos-86) — আজকের মতো মজুদের খাতায় সরাসরি `reserved` নয়।
     *
     * ⚠️ তালা: আগে গ্রাহক ([[CreditExposure::lockCustomer()]]), তারপর আদেশ — DO-র যাচাইয়ের একই ক্রম।
     */
    public function submit(SalesOrder $order): SalesOrder
    {
        if (! $this->replacesDo()) {
            throw ValidationException::withMessages([
                'status' => __('sales::order_status.submit_needs_switch', ['no' => $order->document_no]),
            ]);
        }

        $order->loadMissing('lines');

        if ($order->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        return DB::transaction(function () use ($order) {
            $customer = $this->credit->lockCustomer((int) $order->customer_id);

            // ⛔ দ্বিতীয় ক্লিক — তালার ভিতরে অবস্থা আবার ([[lockAndReread()]])
            $this->lockAndReread($order);

            if ($order->status !== SalesOrderStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('sales::order_status.only_draft_submits', ['no' => $order->document_no]),
                ]);
            }

            foreach ($order->lines()->get() as $line) {
                if ($line->requested_qty === null) {
                    $line->forceFill(['requested_qty' => $line->ordered_qty])->save();
                }
            }

            $order->forceFill([
                'status' => SalesOrderStatus::SUBMITTED,
                'submitted_at' => now(),
                'hold_mode' => SalesOrderStatus::HOLD_HOLDS,
            ])->save();

            return $this->checkCreditThenAsk($order, $customer);
        });
    }

    /**
     * ⭐ সীমায় আটকে থাকা আদেশ আবার যাচাই — টাকা এলে (abos-86-এর শ্রোতা ডাকে: আদায়, রসিদ ভাউচার, চেক পাশ)।
     *
     * @return string আদেশের নতুন অবস্থা (আটকে না থাকলে যা ছিল তাই)
     */
    public function recheckCredit(SalesOrder $order): string
    {
        return DB::transaction(function () use ($order): string {
            $customer = $this->credit->lockCustomer((int) $order->customer_id);
            $this->lockAndReread($order);

            if ($order->status !== SalesOrderStatus::CREDIT_HELD) {
                return (string) $order->status;
            }

            return (string) $this->checkCreditThenAsk($order, $customer)->status;
        });
    }

    /**
     * গ্রাহকের সব সীমায়-আটকে আদেশ, পুরনোটা আগে — একটা না কুলোলেও পরেরটা দেখা হয় (ছোটটা হয়তো কুলোয়)।
     *
     * @return int কয়টা আর আটকে নেই
     */
    public function recheckCustomer(int $customerId): int
    {
        $moved = 0;

        $held = SalesOrder::query()
            ->where('customer_id', $customerId)
            ->where('status', SalesOrderStatus::CREDIT_HELD)
            ->orderBy('credit_held_at')->orderBy('id')
            ->get();

        foreach ($held as $order) {
            if ($this->recheckCredit($order) !== SalesOrderStatus::CREDIT_HELD) {
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * ⭐ সুপারভাইজার মজুদ দেখে পরিমাণ কমান — কেবল সইয়ের অপেক্ষায়, কেবল এখনকার স্তরের অনুমোদনকারী
     * ([[ApprovalEngine::canDecide()]]); ০ থেকে চাওয়া পরিমাণ পর্যন্ত, বাড়ানো নয় (DO-র হুবহু নিয়ম)।
     *
     * ⓘ চূড়ান্ত পরিমাণ `ordered_qty`-তেই বসে (নকশার §১.৪) — চালান, ট্যাব, রিপোর্ট সবাই ওটাই পড়ে; চাওয়াটা `requested_qty`-তে
     * থাকে। ⓘ লাইনের টাকা আবার গোনা ([[CalculatesSalesLines::lineFigures()]]): ছাড় আর হাতে দেওয়া ভ্যাট পরিমাণের অনুপাতে,
     * নাহলে পণ্যের হার থেকে; প্যাকের লেখা (`entered_*`) মুছে মূল এককে — কমানো পরিমাণ পুরো প্যাকে না-ও পড়তে পারে।
     *
     * @param  array<int, numeric-string|int|float>  $quantities  লাইনের id → নতুন পরিমাণ
     */
    public function setApprovedQuantities(SalesOrder $order, array $quantities, User $approver): SalesOrder
    {
        if ($order->status !== SalesOrderStatus::AWAITING_APPROVAL) {
            throw ValidationException::withMessages([
                'status' => __('sales::order_status.not_awaiting_you', ['no' => $order->document_no]),
            ]);
        }

        $pending = $this->engine->latestFor($order, self::APPROVAL_ACTION);

        if ($pending === null || ! $pending->isPending() || ! $this->engine->canDecide($pending, $approver)) {
            abort(403);
        }

        return DB::transaction(function () use ($order, $quantities) {
            $this->lockAndReread($order);

            if ($order->status !== SalesOrderStatus::AWAITING_APPROVAL) {
                throw ValidationException::withMessages([
                    'status' => __('sales::order_status.not_awaiting_you', ['no' => $order->document_no]),
                ]);
            }

            foreach ($order->lines()->with('product')->lockForUpdate()->get() as $line) {
                if (! array_key_exists($line->id, $quantities)) {
                    continue;
                }

                $qty = trim((string) $quantities[$line->id]);
                $asked = (string) ($line->requested_qty ?? $line->ordered_qty);

                if (! is_numeric($qty) || bccomp($qty, '0', 4) < 0 || bccomp($qty, $asked, 4) > 0) {
                    throw ValidationException::withMessages([
                        "lines.{$line->id}" => __('sales::order_status.approved_qty_range', ['asked' => $asked]),
                    ]);
                }

                $qty = bcadd($qty, '0', 4);
                $was = (string) $line->ordered_qty;

                if (bccomp($qty, $was, 4) === 0) {
                    continue;
                }

                $share = fn (string $amount): string => bccomp($was, '0', 4) > 0
                    ? bcdiv(bcmul($amount, $qty, 8), $was, 4)
                    : '0';

                $figures = $this->lineFigures(
                    $qty,
                    (string) $line->rate,
                    $share((string) $line->discount),
                    $line->tax_variance === null ? null : $share((string) $line->tax),
                    $line->product?->tax,
                );

                $line->forceFill([
                    'ordered_qty' => $qty,
                    'entered_qty' => null,
                    'entered_unit_id' => null,
                    'discount' => $figures['discount'],
                    'tax' => $figures['tax'],
                    'tax_variance' => $figures['tax_variance'],
                    'amount' => $figures['amount'],
                ])->save();
            }

            $this->retotal($order);

            return $order->fresh(['lines']);
        });
    }

    /**
     * শেষ সই হলো (বা ছক নেই) — `approved`, আর abos-86-কে সংকেত ([[SalesOrderApproved]]), লেনদেন পাকা হলে।
     */
    public function markApproved(SalesOrder $order): SalesOrder
    {
        $order->forceFill(['status' => SalesOrderStatus::APPROVED, 'approved_at' => now()])->save();
        $fresh = $order->fresh(['lines']);
        DB::afterCommit(fn () => event(SalesOrderApproved::from($fresh)));

        return $fresh;
    }

    /**
     * ⭐ সীমানা abos-86-এর জন্য — মাল আটকানোর পরে `approved` → `confirmed` (নকশার ধাপ ৪, `HoldAndCheckTheOrder`)।
     *
     * ⓘ কেবল `approved` থেকে; অন্য অবস্থায় কিছুই নয়।
     *
     * @param  list<array<string, mixed>>|null  $warnings  সতর্কবার্তা (নকশার পাঁচ ধরন)
     */
    public function markConfirmed(SalesOrder $order, ?array $warnings = null): SalesOrder
    {
        if ($order->status !== SalesOrderStatus::APPROVED) {
            return $order;
        }

        $order->forceFill(['status' => SalesOrderStatus::CONFIRMED, 'credit_warnings' => $warnings ?: null])->save();

        return $order->fresh(['lines']);
    }

    /**
     * সুপারভাইজার ফেরালেন — `rejected`, কারণসহ (থাকলে), আর বাতিলের সংকেত ([[SalesOrderCancelled]], হোল্ড ছাড়ার জন্য)।
     */
    public function reject(SalesOrder $order, ?string $note, ?User $by): SalesOrder
    {
        if (in_array($order->status, SalesOrderStatus::FINISHED, true)) {
            return $order;
        }

        $order->forceFill([
            'status' => SalesOrderStatus::REJECTED,
            'cancelled_by' => $by?->id,
            'cancelled_at' => now(),
            'cancel_reason' => $note,
        ])->save();

        $fresh = $order->fresh(['lines']);
        DB::afterCommit(fn () => event(SalesOrderCancelled::from($fresh, (string) ($note ?? 'rejected'))));

        return $fresh;
    }

    /**
     * বাকির যাচাই, তারপর সই চাওয়া — জমা আর আবার-যাচাই দুই পথেরই শেষ ধাপ। ⚠️ ডাকা হয় গ্রাহক আর আদেশে তালা দিয়ে, লেনদেনের ভিতরে।
     *
     * ⓘ সূত্র দেয়ালের হুবহু ([[CreditExposure::check()]]): বকেয়া + আটকে থাকা + ক্লিয়ার না হওয়া চেক + এই আদেশ ≤ সীমা।
     * কোম্পানির বাকির সীমার সুইচ বন্ধ থাকলে ([[CreditExposure::isOn()]]) যাচাই নেই — সোজা সইয়ের পথে।
     */
    private function checkCreditThenAsk(SalesOrder $order, Customer $customer): SalesOrder
    {
        $result = $this->credit->isOn()
            ? $this->credit->check($customer, (string) $order->total)
            : ['fits' => true, 'short' => '0.0000'];

        if (! $result['fits']) {
            $firstHold = $order->credit_held_at === null;
            $order->forceFill([
                'status' => SalesOrderStatus::CREDIT_HELD,
                'credit_short' => $result['short'],
                // ⓘ প্রথমবার আটকানোর মুহূর্তই থাকে — বারবার যাচাইয়ে বদলায় না
                'credit_held_at' => $order->credit_held_at ?? now(),
                'credit_checked_at' => now(),
            ])->save();

            $held = $order->fresh(['lines']);

            // ⭐ প্রথমবার আটকালেই খবর — বারবার যাচাইয়ে নয় (DO+SO মেশানো, ধাপ ১১; [[TrackingNotices::orderHeld()]])
            if ($firstHold) {
                DB::afterCommit(fn () => app(TrackingNotices::class)->orderHeld($held));
            }

            return $held;
        }

        $order->forceFill(['credit_short' => null, 'credit_checked_at' => now()])->save();

        $approval = $this->engine->request(
            document: $order,
            // ⓘ নামটা লেখা, ধ্রুবক নয় — ছকের পর্দার পাহারা কোড পড়ে ([[EveryApprovalAskedForCanBeConfiguredTest]]); = self::APPROVAL_ACTION
            module: 'sales', action: 'order',
            amount: (string) $order->total,
            userId: $order->created_by,
            // ⓘ ডিলারের লেখা আদেশ — সই চাওয়া ডিলারের নিজের নামে (DO-র একই নিয়ম, ৩ অক্টোবর ২০২৬)
            customerId: $order->created_by === null ? $order->created_by_customer_id : null,
        );

        if ($approval !== null) {
            $order->forceFill(['status' => SalesOrderStatus::AWAITING_APPROVAL])->save();
            $waiting = $order->fresh(['lines']);

            // ⭐ সইয়ের অপেক্ষা — এখনকার স্তরের অনুমোদনকারীরা খবর পান (DO+SO মেশানো, ধাপ ১১; [[TrackingNotices::orderAwaitsYou()]])
            DB::afterCommit(fn () => app(TrackingNotices::class)->orderAwaitsYou($waiting));

            return $waiting;
        }

        return $this->markApproved($order);
    }

    /** আদেশের মোট — লাইনগুলো থেকে আবার (সুপারভাইজার পরিমাণ কমানোর পরে)। */
    private function retotal(SalesOrder $order): void
    {
        $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];

        foreach ($order->lines()->get() as $line) {
            $totals = $this->addToTotals($totals, [
                'base' => bcmul((string) $line->ordered_qty, (string) $line->rate, 4),
                'discount' => (string) $line->discount,
                'tax' => (string) $line->tax,
                'amount' => (string) $line->amount,
            ]);
        }

        $order->forceFill($totals)->save();
    }

    /**
     * ⭐ বন্ধ — পুরো বিলের পরে, বা ইচ্ছা করে কম রেখে (মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান; পরিকল্পনার §৫.১-এর শেষ ধাপ)।
     *
     * ⓘ পুরো বিল হলে কারণ লাগে না — কাজ শেষ। ⛔ বাকি রেখে বন্ধ ("short close") করলে কারণ বাধ্যতামূলক; প্রতিটা লাইনের
     * বাকিটা "আর দেওয়া হবে না" হয় (`rejected_qty`, `reject_reason` — SAP-এর reason for rejection, নকশার §১.৪), আর যে মাল
     * এখনো এই আদেশের নামে ধরা তা ছাড়া হয় — নাহলে বন্ধ আদেশ চিরকাল তাকের মাল আটকে রাখত।
     * ⛔ কিছুই না গিয়ে থাকলে বন্ধ নয়, বাতিল — বন্ধ মানে "যা হওয়ার হয়েছে", আর কিছু না হওয়া আদেশের নাম বাতিল।
     *
     * ⓘ ছাড়ার নিয়ম [[cancel()]]-এর হুবহু (লাইনের `pendingQty()`), যাতে খসড়া চালান পরে নিশ্চিত হলে সে নিজের অংশটুকুই ছাড়ে
     * ([[DeliveryChallanService::releasableQty()]]) — দুইবার নয়। ⓘ কতটা ধরা, তা মজুদের খাতা থেকে ([[heldByThisOrder()]]) —
     * দুই ধারাতেই, কারণ নতুন ধারার আদেশও নিজের মাল একই উৎসে ধরে (abos-86, 60ac3abf)।
     */
    public function close(SalesOrder $order, ?string $reason = null): SalesOrder
    {
        $reason = trim((string) $reason);

        $order->loadMissing(['lines.product', 'warehouse']);

        return DB::transaction(function () use ($order, $reason) {
            // ⛔ দ্বিতীয় ক্লিক — তালার ভিতরে অবস্থা আবার ([[lockAndReread()]])
            $this->lockAndReread($order);

            if ($order->status !== SalesOrderStatus::CONFIRMED) {
                throw ValidationException::withMessages([
                    'status' => __('sales::order_status.only_confirmed_closes', ['no' => $order->document_no]),
                ]);
            }

            $progress = app(OrderProgress::class)->of($order);

            if ($progress['billing'] !== SalesOrderStatus::FULL && $reason === '') {
                throw ValidationException::withMessages([
                    'close_reason' => __('sales::order_status.short_close_needs_reason', ['no' => $order->document_no]),
                ]);
            }

            if ($progress['delivery'] === SalesOrderStatus::NONE) {
                throw ValidationException::withMessages([
                    'status' => __('sales::order_status.nothing_went_cancel_instead', ['no' => $order->document_no]),
                ]);
            }

            /*
             * ⛔ কেবল এই আদেশ নিজে যা ধরেছিল — সুইচ বন্ধে নিশ্চিত হওয়া আদেশ কিছুই ধরেনি, তাই কিছুই ছাড়ে না।
             * ⭐ দুই ধারাতেই: নতুন ধারার আদেশও নিজের মাল একই উৎসে ধরে (abos-86, 60ac3abf) — আগে এখানে কেবল আজকের নিয়মের আদেশ
             * ছাড়ত, আর নতুন ধারার বন্ধ আদেশের মাল চিরকাল আটকে থাকত (নকশার ধাপ ৭-এ ধরা, ৫ অক্টোবর ২০২৬)।
             */
            $own = $order->warehouse ? $this->heldByThisOrder($order) : [];

            foreach ($order->lines as $line) {
                if ($order->warehouse) {
                    $stillReserved = $this->ownShare($own, $line);

                    if (bccomp($stillReserved, '0', 4) > 0) {
                        $this->stock->move(
                            product: $line->product,
                            warehouse: $order->warehouse,
                            sourceType: SalesOrder::STOCK_SOURCE.':close',
                            sourceId: $order->id,
                            reserved: bcmul($stillReserved, '-1', 4),
                            date: now(),
                            documentNo: $order->document_no,
                            narration: $reason !== '' ? $reason : null,
                        );
                    }
                }

                // ⓘ যা যায়নি, তা "আর দেওয়া হবে না" — চূড়ান্ত পরিমাণ = যা গেছে
                $open = (string) $progress['lines'][(int) $line->id]['open'];

                $line->forceFill(array_filter([
                    'line_status' => SalesOrderStatus::LINE_CLOSED,
                    'rejected_qty' => bccomp($open, '0', 4) > 0 ? bcadd((string) $line->rejected_qty, $open, 4) : null,
                    'reject_reason' => bccomp($open, '0', 4) > 0 ? mb_substr($reason, 0, 255) : null,
                ], fn ($v) => $v !== null))->save();
            }

            $order->update([
                'status' => SalesOrderStatus::CLOSED,
                'closed_at' => now(),
                'closed_by' => Actor::userId(),
                'close_reason' => $reason !== '' ? $reason : null,
            ]);

            // ⭐ অগ্রগতির ঘর — একমাত্র লেখকের হাতে ([[OrderProgress::refresh()]])
            app(OrderProgress::class)->refresh($order->fresh(['lines']));

            $fresh = $order->fresh(['lines']);

            // ⭐ abos-86-এর হোল্ড ছাড়ার জন্য — লেনদেন পাকা হলে তবেই ([[SalesOrderClosed]])
            DB::afterCommit(fn () => event(SalesOrderClosed::from($fresh, $reason)));

            return $fresh;
        });
    }

    /**
     * ⭐ এক লাইনের বাকির কিছুটা (বা সবটা) "আর দেওয়া হবে না" — SAP-এর reason for rejection (নকশা "DO বিক্রয় আদেশে মেশানো"
     * §১.৪, ধাপ ৭; সমন্বয়কের উত্তর ৩: ডিপো কম দিলে বাকিটা খোলা থাকে — যতক্ষণ না কেউ এভাবে বন্ধ করেন)।
     *
     * ⛔ কেবল সংরক্ষিত আদেশ; কারণ বাধ্যতামূলক; খোলা পরিমাণের বেশি নয় (খসড়া চালানের মাল "যাওয়ার পথে" — বাদ যায় না)।
     * ⓘ আদেশ ঐ অংশের জন্য যে মাল নিজে ধরেছিল তা ছাড়ে ([[heldByThisOrder()]], লাইনের ক্রমে ভাগ — বাতিল আর বন্ধের একই নিয়ম),
     * দুই ধারাতেই; ঘটনাটা ([[SalesOrderLineRejected]]) বাকিদের জানানোর জন্য।
     * ⓘ লাইনে আর কিছু খোলা না থাকলে লাইন বন্ধ — কিছু গিয়ে থাকলে `closed`, কিছুই না গেলে `rejected`। কারণ জমে: আগের কারণের পরে নতুনটা।
     */
    public function rejectRemainder(SalesOrderLine $line, string $qty, string $reason): SalesOrderLine
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reject_reason' => __('sales::order_status.reject_needs_reason'),
            ]);
        }

        $order = $line->order()->with('warehouse')->firstOrFail();

        return DB::transaction(function () use ($order, $line, $qty, $reason) {
            $this->lockAndReread($order);

            if ($order->status !== SalesOrderStatus::CONFIRMED) {
                throw ValidationException::withMessages([
                    'status' => __('sales::order_status.only_confirmed_rejects', ['no' => $order->document_no]),
                ]);
            }

            $line = SalesOrderLine::query()->with('product')->whereKey($line->getKey())
                ->where('sales_order_id', $order->id)->lockForUpdate()->firstOrFail();
            $open = $line->pendingQty();
            $qty = trim($qty);

            if (! is_numeric($qty) || bccomp($qty, '0', 4) <= 0 || bccomp($qty, $open, 4) > 0) {
                throw ValidationException::withMessages([
                    'reject_qty' => __('sales::order_status.reject_qty_range', [
                        'no' => $order->document_no,
                        'open' => rtrim(rtrim($open, '0'), '.'),
                    ]),
                ]);
            }

            $qty = bcadd($qty, '0', 4);

            /*
             * ⓘ এই লাইনের ভাগে যে মাল আদেশ নিজে ধরে আছে — বাতিলের একই ভাগাভাগি, লাইনের ক্রমে। ⭐ দুই ধারাতেই: নতুন ধারার আদেশও
             * নিজের মাল মজুদের খাতায় একই উৎসে ধরে (`sales_order`, abos-86-এর [[HoldTheStockForTheApprovedOrder]], 60ac3abf)।
             */
            if ($order->warehouse_id !== null) {
                $own = $this->heldByThisOrder($order);
                $share = '0';

                foreach ($order->lines()->get() as $each) {
                    $taken = $this->ownShare($own, $each);

                    if ((int) $each->id === (int) $line->id) {
                        $share = $taken;

                        break;
                    }
                }

                $release = bccomp($qty, $share, 4) < 0 ? $qty : $share;

                if (bccomp($release, '0', 4) > 0) {
                    $this->stock->move(
                        product: $line->product,
                        warehouse: $order->warehouse,
                        sourceType: SalesOrder::STOCK_SOURCE.':reject',
                        sourceId: $order->id,
                        reserved: bcmul($release, '-1', 4),
                        date: now(),
                        documentNo: $order->document_no,
                        narration: $reason,
                    );
                }
            }

            $left = bcsub($open, $qty, 4);
            $sent = $line->deliveredQty();

            $line->forceFill([
                'rejected_qty' => bcadd((string) ($line->rejected_qty ?? '0'), $qty, 4),
                'reject_reason' => mb_substr(trim(((string) $line->reject_reason).' · '.$reason, ' ·'), 0, 255),
                'line_status' => bccomp($left, '0', 4) > 0
                    ? SalesOrderStatus::LINE_OPEN
                    : (bccomp($sent, '0', 4) > 0 ? SalesOrderStatus::LINE_CLOSED : SalesOrderStatus::LINE_REJECTED),
            ])->save();

            // ⭐ অগ্রগতির ঘর — একমাত্র লেখকের হাতে ([[OrderProgress::refresh()]])
            app(OrderProgress::class)->refresh($order->fresh(['lines']));

            $fresh = $line->fresh();
            DB::afterCommit(fn () => event(SalesOrderLineRejected::from($order, $fresh, $qty, $reason)));

            return $fresh;
        });
    }

    /** ⛔ বন্ধ আদেশ বাতিল হয় না — বন্ধের দিনেই তার বাকি মাল ছাড়া হয়েছে। */
    private function assertNotClosed(SalesOrder $order): void
    {
        if ($order->status === SalesOrderStatus::CLOSED) {
            throw ValidationException::withMessages([
                'status' => __('sales::order_status.closed_cannot_cancel', ['no' => $order->document_no]),
            ]);
        }
    }

    /**
     * ⛔ এই আদেশ নিজে কতটা ধরেছিল — পণ্যপ্রতি, আদেশের গুদামে, মজুদের খাতা থেকে (উৎস `sales_order`, এই আদেশের আইডি)।
     *
     * ── কেন খাতা থেকে, সুইচ থেকে নয় (সমন্বয়কের নির্দেশ, ৪ অক্টোবর ২০২৬) ────────────────────────────
     * ⓘ বাতিল আর বন্ধ আগে প্রতিটা লাইনের বাকি (`pendingQty()`) ছাড়ত, আদেশ সত্যিই কিছু ধরেছিল কি না না দেখে। ⛔ সংরক্ষণের
     * সুইচ (`sales.reserve_on_order`) বন্ধ থাকতে নিশ্চিত হওয়া আদেশ কিছুই ধরেনি — অথচ বাতিলে সে **অন্য কাগজের** ধরা মাল ছেড়ে
     * দিত, আর সেই মাল আবার বেচা যেত। ⚠️ আজকের সুইচ দেখলেও ভুল হত: নিশ্চিতের দিন চালু, বাতিলের দিন বন্ধ হলে আদেশের ধরা মাল
     * চিরকাল আটকে থাকত। ⭐ সত্যিটা খাতায়: এই আদেশ নিজের নামে কতটা বসিয়েছিল।
     *
     * ⭐ চালান যতটা ছেড়েছে তা-ও যোগ হয় (abos-86, ৪ অক্টোবর ২০২৬): ছাড়াটা বসে চালানের নিজের উৎসে (`delivery_challan`,
     * ঋণাত্মক), আর চালান বাতিলের ফেরত `delivery_challan:cancel`-এ — তাই যোগফলই আসল অবশিষ্ট ধরা। ⛔ শুধু `sales_order`
     * গুনলে ১০ ধরে ৬ ছাড়ার পরেও ১০ দেখাত, আর বাতিল অন্য কাগজের ৬ খেয়ে ফেলত।
     *
     * @return array<int, string> পণ্য → এই আদেশের নিজের বসানো Reserved
     */
    public function heldByThisOrder(SalesOrder $order): array
    {
        $challan = \App\Modules\Sales\Models\DeliveryChallan::class;
        $challans = $challan::query()
            ->where('sales_order_id', $order->id)
            ->where('warehouse_id', $order->warehouse_id)
            ->select('id');

        return StockMovement::query()
            ->where('warehouse_id', $order->warehouse_id)
            ->where(fn ($q) => $q
                // ⭐ আর এক লাইনের বাকি বন্ধে যা ছাড়া হলো (`:reject`) — নাহলে পরের চালান এমন মাল ছাড়ত যা আদেশ আর ধরে না (ধাপ ৭)
                /*
                 * ⛔ আইডির সাথে আদেশের নম্বরও — আদেশের নিজের প্রতিটা সারি নিজের নম্বর লেখে। ⓘ ডেমো-বীজ "sales_order"
                 * উৎসে ১ আর ২ আইডিতে ধরা বসায় এমন আদেশের নামে যা নেই; শুধু আইডি মেলালে সত্যিকারের ১ নম্বর আদেশ
                 * ঐ ৬০টাকে নিজের ভাবত আর চালান-বাতিল-বন্ধে অন্যের মাল ছাড়ত (৫ অক্টোবর ২০২৬, ধাপ ৮-এর ১০৮০p যাচাইয়ে ধরা)।
                 */
                ->where(fn ($own) => $own->whereIn('source_type', [SalesOrder::STOCK_SOURCE, SalesOrder::STOCK_SOURCE.':reject'])
                    ->where('source_id', $order->id)->where('document_no', $order->document_no))
                ->orWhere(fn ($out) => $out->whereIn('source_type', [$challan::STOCK_SOURCE, $challan::STOCK_SOURCE.':cancel'])
                    ->whereIn('source_id', $challans)))
            ->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(SUM(reserved_change), 0) as held')
            ->pluck('held', 'product_id')
            ->mapWithKeys(fn ($held, $product) => [(int) $product => bcadd((string) $held, '0', 4)])
            ->all();
    }

    /**
     * একটা লাইনের ছাড়ার অংশ — লাইনের বাকি, কিন্তু এই আদেশ নিজে যতটা ধরেছিল তার বেশি নয়।
     *
     * ⓘ একই পণ্য দুই লাইনে থাকলে ভাগটা একবারই খরচ হয় — দেওয়া অংশ হিসাব থেকে কমে।
     *
     * @param  array<int, string>  $own
     */
    private function ownShare(array &$own, SalesOrderLine $line): string
    {
        // ⛔ খসড়া চালানের অংশও এখনো আদেশের ধরা — কেবল নিশ্চিত চালান বাদ (অডিট ম১৫, ৬ অক্টোবর ২০২৬; [[SalesOrderLine::unshippedQty()]])
        $pending = $line->unshippedQty();
        $left = $own[(int) $line->product_id] ?? '0';
        $take = bccomp($pending, $left, 4) > 0 ? $left : $pending;

        if (bccomp($take, '0', 4) <= 0) {
            return '0';
        }

        $own[(int) $line->product_id] = bcsub($left, $take, 4);

        return $take;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(SalesOrder $order, array $lines): void
    {
        $order->lines()->delete();

        $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];
        $lineNo = 0;

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = $this->positive($line['ordered_qty'] ?? null, 'ordered_qty');
            $rate = $this->money($line['rate'] ?? null);

            $product = Product::query()->find($productId);

            if ($productId <= 0 || $product === null) {
                throw ValidationException::withMessages(['lines' => __('sales::validation.unknown_product')]);
            }

            // ⭐ আদেশের নতুন পণ্য সক্রিয় আর আদেশের শাখার (Inventory অডিট ম২৩) — অফিস, ফোন আর পোর্টালের আদেশ সবাই এখানে আসে
            app(\App\Modules\Inventory\Services\SellableHere::class)->assert($product, $order->branch_id === null ? null : (int) $order->branch_id);

            // "২ বাক্স @ ৮০০" — পরিমাণ আর দর একসাথে পণ্যের এককে নামে
            $pack = $this->packed($product, $qty, $line['unit_id'] ?? null, $rate);
            $qty = $pack['qty'];
            $rate = $pack['rate'];

            // ভ্যাট না পাঠালে পণ্যের নিজের হার থেকে গোনা — `?? '0'` লিখলে
            // "পাঠায়নি" আর "শূন্য বসিয়েছে" এক হয়ে যেত
            $figures = $this->lineFigures($qty, $rate, $line['discount'] ?? '0', $line['tax'] ?? null, $product->tax);

            SalesOrderLine::create([
                'sales_order_id' => $order->id,
                'product_id' => $productId,
                'ordered_qty' => $qty,
                'entered_qty' => $pack['entered_qty'],
                'entered_unit_id' => $pack['entered_unit_id'],
                'rate' => $rate,
                'discount' => $figures['discount'],
                'tax' => $figures['tax'],
                'tax_variance' => $figures['tax_variance'],
                'amount' => $figures['amount'],
                'line_no' => ++$lineNo,
                'narration' => $line['narration'] ?? null,
            ]);

            $totals = $this->addToTotals($totals, $figures);
        }

        $order->update($totals);
    }

    /**
     * বিক্রয়যোগ্য মালের চেয়ে বেশি অর্ডার নেওয়া যাবে কি না।
     *
     * ডিফল্টে যাবে না। কিন্তু কিছু ডিপোতে মাল রাস্তায় আছে জেনেই অর্ডার
     * নেওয়া হয়, আর তখন আটকে দিলে অর্ডারটাই হাতছাড়া হয় — তাই সুইচ (নিয়ম ৭)।
     */
    private function assertEnoughToSell(Product $product, Warehouse $warehouse, string $qty): void
    {
        if ($this->settings->get('sales.allow_negative_stock', false)) {
            return;
        }

        $available = $this->stock->availableQty($product, $warehouse);

        if (bccomp($available, $qty, 4) < 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.not_enough_available', [
                    'product' => $product->name(),
                    'available' => rtrim(rtrim($available, '0'), '.'),
                ]),
            ]);
        }
    }

    private function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::query()->where('is_default', true)->active()->first();
    }

    private function assertEditable(SalesOrder $order): void
    {
        if ($order->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_edits', ['no' => $order->document_no]),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertHasLines(array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }
    }

    private function resolveFinancialYear(Carbon $date): FinancialYear
    {
        $year = FinancialYear::query()
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('sales::validation.no_financial_year', ['date' => $date->toDateString()]),
            ]);
        }

        return $year;
    }

    /**
     * ⛔ দুই ক্লিক, একই আদেশ — চূড়ান্ত অডিট (abos-8f-এর পড়া), ৩০ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ নিশ্চিত/বাতিলের অবস্থা দেখা হত হাতের পুরনো মডেলে, লেনদেনের বাইরে: দুই ক্লিকে প্রতিটা সারির মাল
     * দুইবার ধরা হত, আর বাতিলে দুইবার ছাড়া (Reserved ঋণাত্মক)। ⭐ এখন লেনদেনের প্রথম কাজ সারিতে তালা আর
     * অবস্থা তাজা পড়া ([[DepositClaimService::lockPending()]]-এর ছাঁচ)।
     */
    private function lockAndReread(SalesOrder $order): void
    {
        $fresh = SalesOrder::query()
            ->withoutGlobalScopes()
            ->whereKey($order->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $order->setRawAttributes($fresh->getAttributes(), true);
    }

    /**
     * ⭐ "নিশ্চিত করুন" চাপলে কী হবে — কিছু না লিখে (মালিক, ৪ অক্টোবর ২০২৬; [[SalesPaperOverview::order()]])।
     *
     * ⓘ দুই পথ, [[confirm()]]-এর একই ভাগে:
     *   · সুইচ চালু ([[REPLACES_DO]]) — "নিশ্চিত" মানে জমা ([[submit()]]): সীমা পার হলে থামে না, আদেশ টাকার অপেক্ষায়
     *     দাঁড়ায়; জায়গা থাকলে সইয়ে যায় (ছক থাকলে)। সীমার মাপ [[checkCreditThenAsk()]]-এর হুবহু — [[CreditExposure::check()]]।
     *   · সুইচ বন্ধ — পুরনো নিশ্চিত: মাল আটকানো চালু থাকলে প্রতিটা সারিতে মজুদ ([[assertEnoughToSell()]]), আর সই।
     * ⛔ সইয়ের প্রশ্ন [[ApprovalEngine::requires()]] দিয়ে, `request()` নয় — দেখতে গিয়ে অনুরোধ লেখা চলে না।
     *
     * @return array{submits: bool, stops: list<string>, credit_short: ?string, signature: bool}
     */
    public function whatTheConfirmWouldDo(SalesOrder $order): array
    {
        $order->loadMissing(['lines.product', 'warehouse', 'customer']);
        $submits = $this->replacesDo();
        $stops = [];

        if ($order->status !== DocumentStatus::DRAFT) {
            $stops[] = $submits
                ? __('sales::order_status.only_draft_submits', ['no' => $order->document_no])
                : __('sales::validation.only_draft_confirms', ['no' => $order->document_no]);
        }

        if ($order->lines->isEmpty()) {
            $stops[] = __('sales::validation.no_lines');
        }

        $short = null;

        if ($submits && $order->customer !== null && $this->credit->isOn()) {
            $result = $this->credit->check($order->customer, (string) $order->total);
            $short = $result['fits'] ? null : (string) $result['short'];
        }

        if (! $submits && $stops === [] && $this->settings->get('sales.reserve_on_order', false)) {
            $warehouse = $order->warehouse ?? $this->defaultWarehouse();

            try {
                if ($warehouse === null) {
                    throw ValidationException::withMessages(['warehouse_id' => __('sales::validation.unknown_warehouse')]);
                }

                foreach ($order->lines as $line) {
                    $this->assertEnoughToSell($line->product, $warehouse, (string) $line->ordered_qty);
                }
            } catch (ValidationException $e) {
                $stops = [...$stops, ...array_values($e->validator->errors()->all())];
            }
        }

        return [
            'submits' => $submits,
            'stops' => $stops,
            'credit_short' => $short,
            'signature' => $this->engine->requires('sales', self::APPROVAL_ACTION, (string) $order->total, class_basename(SalesOrder::class)),
        ];
    }

    /**
     * ⭐ কত আছে আর কত চাই — ধরা ছাড়াই (ATP), যখন আদেশ মাল ধরে না (মালিক, ৬ অক্টোবর ২০২৬: ধরা শুরু চালানে)।
     * ⭐ ওয়েবের আদেশের পাতা আর ফোনের আদেশ — একই উৎস (সমন্বয়ক, ৬ অক্টোবর ২০২৬)।
     * ⓘ খোলা আদেশেই (খসড়া থেকে নিশ্চিত পর্যন্ত, বন্ধ বা বাতিল নয়), গুদাম জানা থাকলে; পণ্য ধরে একবার।
     *
     * @return array<int, array{have: string, want: string}>
     */
    public function availableToPromise(SalesOrder $order): array
    {
        if ($order->warehouse === null || (bool) app(\App\Core\Services\SettingsService::class)->get('sales.reserve_on_order', false)
            || in_array($order->status, [SalesOrderStatus::CLOSED, SalesOrderStatus::CANCELLED, SalesOrderStatus::REJECTED], true)) {
            return [];
        }

        $stock = app(\App\Modules\Inventory\Services\StockService::class);
        $out = [];

        foreach ($order->lines->groupBy('product_id') as $productId => $lines) {
            $product = $lines->first()->product;
            if ($product === null) {
                continue;
            }
            $want = $lines->reduce(fn (string $s, $l) => bcadd($s, $l->pendingQty(), 4), '0');
            if (bccomp($want, '0', 4) <= 0) {
                continue;
            }
            $out[(int) $productId] = ['have' => bcadd($stock->availableQty($product, $order->warehouse), '0', 4), 'want' => $want];
        }

        return $out;
    }
}
