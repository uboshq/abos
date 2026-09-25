<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseRequisition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * চাহিদা — লেখা, অনুমোদন, আর আদেশে রূপান্তর।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"Approved PR থেকে RFQ অথবা PO তৈরি করা যাবে"*।
 *
 * ── ⚠️ তিনটা ধাপ, আর তিনটাই আলাদা সিদ্ধান্ত ─────────────────────────
 *   ১. **লেখা** — যিনি চান তিনি লেখেন; কিছুই প্রতিশ্রুত হয় না
 *   ২. **অনুমোদন** — এখানে সই লাগে, আর এখানেই বাজেটের আগে থামা যায়
 *   ৩. **রূপান্তর** — অনুমোদিত চাহিদা একটা ক্রয়াদেশ হয়ে যায়
 *
 * ⛔ আগে তিনটাই এক ছিল: ক্রয়াদেশ সরাসরি লেখা হত। ⓘ ফলে থামার কোনো
 * জায়গা ছিল না — যে মুহূর্তে কাগজটা লেখা হত, সেটাই প্রতিশ্রুতি।
 *
 * ── ⓘ সরবরাহকারীর নাম এখানে নেই, আর সেটা ইচ্ছাকৃত ──────────────────
 * ⚠️ যিনি চান তিনি জানেন **কী** লাগবে, ⛔ কিন্তু **কার কাছ থেকে** সেটা
 * ক্রয় বিভাগের সিদ্ধান্ত। ⓘ চাহিদায় সরবরাহকারীর ঘর রাখলে ঐ সিদ্ধান্তটা
 * নীরবে বিভাগের হাত থেকে বেরিয়ে যেত।
 */
final class PurchaseRequisitionService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly DocumentApproval $approvals,
        private readonly PurchaseOrderService $orders,
    ) {}

    /**
     * চাহিদা লেখা — কিছুই প্রতিশ্রুত হয় না।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): PurchaseRequisition
    {
        $clean = $this->cleanLines($lines);

        return DB::transaction(function () use ($data, $clean) {
            $requisition = PurchaseRequisition::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => $this->numbers->next('PR'),
                'trx_date' => Carbon::parse($data['trx_date'] ?? now())->toDateString(),
                'needed_by' => filled($data['needed_by'] ?? null)
                    ? Carbon::parse($data['needed_by'])->toDateString()
                    : null,
                'requested_by' => $data['requested_by'] ?? auth()->id(),
                'department' => $data['department'] ?? null,

                /*
                 * The name a person types and the link the books use are two
                 * different fields, and neither is guessed from the other.
                 * "Sales" and "sales dept" are not the same string, and
                 * matching them by hand once would put the money against the
                 * wrong department for good.
                 */
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->writeLines($requisition, $clean);

            return $requisition->load('lines');
        });
    }

    /**
     * ⭐ অনুমোদন — আর এখানেই সই লাগে।
     *
     * ── ⚠️ অঙ্কটা আন্দাজি মোট, আর সেটাই ঠিক ─────────────────────────
     * ⓘ অনুমোদনের ছক টাকার অঙ্ক দেখে ধাপ ঠিক করে। ⛔ চাহিদায় আসল দাম
     * বলে কিছু নেই — ওটা জানা যাবে সরবরাহকারীর কাছ থেকে। ⚠️ তবু
     * আন্দাজটাই একমাত্র সংখ্যা যা **সিদ্ধান্তের আগে** পাওয়া যায়, আর
     * অনুমোদনের পুরো কথাই হলো সিদ্ধান্তের আগে থামা।
     *
     * ⓘ দর না বসানো সারিগুলো শূন্য ধরা হয় ([[PurchaseRequisition::estimatedTotal()]]);
     * ধরে-নেওয়া কোনো দর বসালে সীমাটাই মিথ্যা হত।
     */
    public function approve(PurchaseRequisition $requisition): PurchaseRequisition
    {
        if ($requisition->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.requisition_not_draft'),
            ]);
        }

        $requisition->loadMissing('lines');

        if ($requisition->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.requisition_needs_lines'),
            ]);
        }

        $this->assertWithinBudget($requisition);

        $this->approvals->assertClear(
            document: $requisition,
            module: 'purchase',
            action: 'requisition',
            field: 'status',
            amount: $requisition->estimatedTotal(),
            reason: $requisition->purpose,
        );

        $requisition->update(['status' => DocumentStatus::CONFIRMED]);

        return $requisition->fresh('lines');
    }

    /**
     * ⭐ অনুমোদিত চাহিদা → ক্রয়াদেশ।
     *
     * ── ⛔ একই চাহিদা থেকে দুইবার নয় ─────────────────────────────────
     * ⚠️ পারলে একই জিনিস **দুইবার কেনা** হত, আর ভুলটা ধরা পড়ত মাল এসে
     * গুদামে জায়গা না পাওয়ার দিনে। ⓘ শর্তটা মডেলেই লেখা
     * ([[PurchaseRequisition::canBecomeAnOrder()]]), কারণ পর্দাও ঐ একই
     * প্রশ্ন করে — বোতামটা দেখাবে কি না।
     *
     * ── ⚠️ আন্দাজি দরটা আদেশে যায় না ────────────────────────────────
     * ⛔ গেলে আন্দাজটাই একদিন দাম হয়ে বসত, আর সরবরাহকারী অন্য দর
     * চাইলে কেউ বুঝত না সংখ্যাটা কোথা থেকে এসেছিল। ⓘ আদেশের ফর্মে
     * দর বসাবেন ক্রয় বিভাগ, সরবরাহকারীর কথা শুনে।
     *
     * @param  array<string, mixed>  $data  supplier_id · warehouse_id · trx_date · expected_on
     */
    public function toOrder(PurchaseRequisition $requisition, array $data): PurchaseRequisition
    {
        if (! $requisition->canBecomeAnOrder()) {
            throw ValidationException::withMessages([
                'status' => $requisition->purchase_order_id !== null
                    ? __('purchase::validation.requisition_already_ordered', [
                        'no' => $requisition->order?->document_no ?? '',
                    ])
                    : __('purchase::validation.requisition_not_approved'),
            ]);
        }

        $requisition->loadMissing('lines.product');

        return DB::transaction(function () use ($requisition, $data) {
            $order = $this->orders->create(
                [
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'warehouse_id' => $data['warehouse_id'] ?? null,
                    'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                    'expected_on' => $data['expected_on']
                        ?? $requisition->needed_by?->toDateString(),
                    'narration' => __('purchase::message.order_from_requisition', [
                        'no' => $requisition->document_no,
                    ]),
                ],
                /*
                 * ⛔ ঘরটার নাম `ordered_qty`, `qty` নয় — ২৪ সেপ্টেম্বর ২০২৬।
                 *
                 * ⚠️ `qty` পাঠানো হয়েছিল, আর [[PurchaseOrderService]]
                 * পড়ে `$line['ordered_qty'] ?? null` — অর্থাৎ পরিমাণটা
                 * নীরবে `null` হয়ে যেত, আর ব্যবহারকারী দেখতেন
                 * *"সংখ্যার ঘরে সংখ্যা দিন"*, অথচ তিনি কোনো ঘরেই হাত
                 * দেননি: সংখ্যাটা চাহিদা থেকে আপনাআপনি আসার কথা।
                 *
                 * ⓘ আদেশের পর্দায় ঘরটার নাম `ordered_qty` কারণ ওখানে
                 * দুই রকম পরিমাণ থাকে — যতটা চাওয়া হলো আর যতটা এল।
                 */
                $requisition->lines->map(fn ($line) => [
                    'product_id' => $line->product_id,
                    'ordered_qty' => (string) $line->qty,

                    /*
                     * ⛔ দর শূন্য — আন্দাজটা এখানে আসে না, আর আসা
                     * উচিতও নয়। ⓘ ক্রয় বিভাগ আদেশের পর্দায় দর বসাবেন।
                     */
                    'rate' => '0',
                ])->all(),
            );

            $requisition->update([
                'status' => DocumentStatus::CLOSED,
                'purchase_order_id' => $order->id,
            ]);

            return $requisition->fresh(['lines', 'order']);
        });
    }

    /**
     * বাতিল — খসড়া ও অনুমোদিত দুইটাই, কিন্তু রূপান্তরিতটা নয়।
     *
     * ⛔ আদেশ হয়ে যাওয়ার পর চাহিদাটা বাতিল করা মানে কাগজের গল্পে একটা
     * ফাঁক: আদেশটা থেকে যায়, অথচ যে কারণে সেটা জন্মেছিল তা নেই।
     * ⓘ তখন বাতিল করার জায়গা আদেশটাই।
     */
    public function cancel(PurchaseRequisition $requisition, string $reason): PurchaseRequisition
    {
        if ($requisition->purchase_order_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.requisition_already_ordered', [
                    'no' => $requisition->order?->document_no ?? '',
                ]),
            ]);
        }

        if ($requisition->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.requisition_already_cancelled'),
            ]);
        }

        $requisition->update([
            'status' => DocumentStatus::CANCELLED,
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        return $requisition->fresh();
    }

    /**
     * The department's money for this month, before anyone signs.
     *
     * Checked before the approval flow on purpose. Behind it, the flow would
     * call somebody in to sign and the limit would hand the paper back after
     * they had - their time wasted on a question that could not arise.
     *
     * The limit is opt-in three times over: nothing happens without a cost
     * centre on the paper, without a budget row for that centre and month, or
     * with a budget of zero. A depot that does not use budgets never sees it.
     * Making it compulsory would have added a feature by stopping everybody
     * else's work.
     *
     * It refuses rather than warns. "Over budget, carry on" is not a limit;
     * in two weeks it is a habit nobody reads. Refusing is safe here because
     * the company wrote the number themselves - entering a budget is how they
     * say this is a limit. And it stops a requisition, not an order, so the
     * buying department is never blocked from restocking.
     *
     * Approved requisitions are what counts as spent. Waiting for bills would
     * make the limit act too late: ten requisitions approved, and on the day
     * the bills arrive the budget is three times over. A promise is the thing
     * to count. Cancelled papers are not counted - a promise withdrawn holds
     * no money.
     */
    private function assertWithinBudget(PurchaseRequisition $requisition): void
    {
        $centre = $requisition->cost_center_id;

        if ($centre === null) {
            return;
        }

        $on = $requisition->trx_date ?? now();

        $budget = $this->budgetFor((int) $centre, (int) $on->format('Y'), (int) $on->format('n'));

        if (bccomp($budget, '0', 4) <= 0) {
            return;
        }

        $already = $this->committedIn((int) $centre, $on);
        $asking = $requisition->estimatedTotal();

        if (bccomp(bcadd($already, $asking, 4), $budget, 4) <= 0) {
            return;
        }

        throw ValidationException::withMessages([
            'cost_center_id' => __('purchase::validation.requisition_over_budget', [
                'budget' => $budget,
                'already' => $already,
                'asking' => $asking,
            ]),
        ]);
    }

    /**
     * That centre's budget for that month, across every account.
     *
     * Budget rows are keyed by account, and a requisition names no account -
     * nor should it: the person who needs something knows what they need, and
     * which account it lands in is the bookkeeper's question.
     *
     * More than that, a purchase order buys stock, an asset, while a budget
     * only knows income and expense accounts. Matching on account would have
     * answered zero every time and the limit would never have bitten.
     *
     * So it is counted the way the question is actually asked: what does this
     * department have for this month, all told.
     */
    private function budgetFor(int $costCentreId, int $year, int $month): string
    {
        return DB::table('fin_budgets')
            ->where('company_id', CompanyContext::id())
            ->where('cost_center_id', $costCentreId)
            ->where('year', $year)
            ->where('month', $month)
            ->pluck('amount')
            ->reduce(fn (string $sum, $amount) => bcadd($sum, (string) $amount, 4), '0');
    }

    /**
     * What that centre has already had approved that month.
     *
     * This paper is left out: counting it here would count its own figure
     * twice and the limit would bite at half the budget.
     */
    private function committedIn(int $costCentreId, Carbon $on): string
    {
        return PurchaseRequisition::query()
            ->where('cost_center_id', $costCentreId)
            ->whereYear('trx_date', (int) $on->format('Y'))
            ->whereMonth('trx_date', (int) $on->format('n'))
            /*
             * The one list, not a copy of it.
             *
             * This read CONFIRMED and CLOSED by hand, which is the same two
             * values DocumentStatus::POSTED holds - and that is exactly the
             * trouble: one of the two would be changed one day and the other
             * would keep answering the old question. A guard caught it.
             */
            ->whereIn('status', DocumentStatus::POSTED)
            ->with('lines')
            ->get()
            ->reduce(
                fn (string $sum, PurchaseRequisition $row) => bcadd($sum, $row->estimatedTotal(), 4),
                '0',
            );
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines(PurchaseRequisition $requisition, array $lines): void
    {
        foreach ($lines as $i => $line) {
            $requisition->lines()->create([
                'company_id' => CompanyContext::id(),

                /*
                 * ⓘ `line_no` একের থেকে গোনা — ⚠️ শূন্য থেকে শুরু করলে
                 * কাগজে "০ নম্বর সারি" ছাপা হত, আর কেউ ওভাবে গোনে না।
                 */
                'line_no' => $i + 1,
                'product_id' => $line['product_id'],
                'qty' => $line['qty'],
                'estimated_rate' => $line['estimated_rate'] ?? null,
                'narration' => $line['narration'] ?? null,
            ]);
        }
    }

    /**
     * খালি সারি বাদ, আর একই পণ্য দুইবার আটকানো।
     *
     * ── ⚠️ একই পণ্য দুইবার কেন আটকানো ───────────────────────────────
     * ⓘ চাহিদায় ইউনিক শর্ত নেই — একই পণ্য দুইটা আলাদা কারণে চাওয়া
     * যেতেই পারে। ⛔ কিন্তু বাস্তবে ওটা প্রায় সবসময়ই একটা ভুল: মানুষ
     * সারিটা দুইবার বসিয়ে ফেলেন, আর পরিমাণটা দ্বিগুণ হয়ে যায়।
     *
     * ⭐ তাই আটকানো হয়, আর বার্তায় পণ্যটার নাম বলা হয় — যাতে মানুষ
     * বুঝতে পারেন কোনটা মেলাতে হবে।
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function cleanLines(array $lines): array
    {
        $out = [];
        $seen = [];

        foreach ($lines as $line) {
            $productId = $line['product_id'] ?? null;
            $qty = (string) ($line['qty'] ?? '');

            if (blank($productId) || $qty === '') {
                continue;
            }

            if (! is_numeric($qty) || bccomp($qty, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.requisition_qty_positive'),
                ]);
            }

            if (isset($seen[$productId])) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.requisition_duplicate_product', [
                        'product' => Product::query()->find($productId)?->name() ?? '',
                    ]),
                ]);
            }

            $seen[$productId] = true;
            $out[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'estimated_rate' => filled($line['estimated_rate'] ?? null)
                    ? (string) $line['estimated_rate']
                    : null,
                'narration' => $line['narration'] ?? null,
            ];
        }

        if ($out === []) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.requisition_needs_lines'),
            ]);
        }

        return $out;
    }
}
