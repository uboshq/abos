<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\SettingsService;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Events\DeliveryOrderCancelled;
use App\Modules\Sales\Events\DeliveryOrderSupervisorApproved;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DO লেখা, জমা, বাতিল — মালিকের বিক্রয়-ধারা §২ক (২ অক্টোবর ২০২৬; টুকরো ২)।
 *
 * ── ⭐ কে লেখেন ──────────────────────────────────────────────────────────
 * ডিলার নিজে (পোর্টাল/অ্যাপ — `Customer`), তাঁর SR, আর SR-এর উপরের সবাই (`User`)।
 * ⛔ ডিলার কেবল নিজের নামে, আর কেবল নিজেরটা দেখেন ও বদলান — `customer_id` তিনি নিজে, ফর্মের ঘর নয়।
 * ⓘ কর্মীর "কোন ডিলার কার" দেয়াল আসবে abos-bb-র [[DealerOwnership]] থেকে; ততক্ষণ চাবি (`sales.do.create`)।
 *
 * ── ⭐ দাম ───────────────────────────────────────────────────────────────
 * পণ্যের বিক্রয়-দাম থেকে ([[SalePriceBook]]) — ⛔ লেখক দাম বা ছাড় বসান না: হাতে দেওয়া ছাড় মালিকের সইয়ের নিয়মে
 * পড়ে, আর DO সেই দরজা খোলে না।
 *
 * ── ⭐ জমার পরে ─────────────────────────────────────────────────────────
 * লেখক আর বদলাতে পারেন না (সমন্বয়কের শর্ত); সুপারভাইজারের ছক থাকলে `supervisor_pending`, নাহলে সরাসরি
 * `supervisor_approved` আর সংকেত ([[DeliveryOrderSupervisorApproved]] → abos-86-এর হিসাব)।
 */
final class DeliveryOrderService
{
    public const APPROVAL_ACTION = 'delivery_order';

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly ApprovalEngine $approvals,
        private readonly SettingsService $settings,
    ) {}

    /**
     * ⭐ নতুন DO বন্ধ — কোম্পানি বিক্রয় আদেশে চলে গেলে ([[SalesOrderService::REPLACES_DO]]; নকশার ধাপ ১২, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ কেবল নতুন বানানো বন্ধ: খোলা DO-র জমা, সই, পরিমাণ-বদল, হিসাবের যাচাই, ঘড়ি, ডিপো যাচাই আর কাউন্টার
     * আগের মতো, নিজের নম্বরে শেষ হয়। ⛔ পাহারা এখানেই, [[create()]]-এ — ডেস্ক, পোর্টাল আর API তিন দরজাই এই পথে লেখে।
     */
    public function newOnesStopped(): bool
    {
        return (bool) $this->settings->get(SalesOrderService::REPLACES_DO, false);
    }

    /**
     * @param  array{customer_id?: int, sales_order_id?: ?int, warehouse_id?: ?int, trx_date?: string, deliver_on?: ?string, narration?: ?string}  $data
     * @param  list<array{product_id: int, qty: numeric-string|int|float, free_qty?: numeric-string|int|float, note?: ?string}>  $lines
     */
    public function create(array $data, array $lines, User|Customer $by): DeliveryOrder
    {
        if ($this->newOnesStopped()) {
            throw ValidationException::withMessages(['order' => __('sales::delivery_order.write_an_order_now')]);
        }

        $customerId = $by instanceof Customer ? (int) $by->id : (int) ($data['customer_id'] ?? 0);
        $this->assertCustomer($customerId);
        $orderId = $this->salesOrderFor($data['sales_order_id'] ?? null, $customerId);

        return DB::transaction(function () use ($data, $lines, $by, $customerId, $orderId) {
            $order = DeliveryOrder::query()->create([
                'document_no' => $this->numbers->next('DO'),
                'customer_id' => $customerId,
                'sales_order_id' => $orderId,
                /*
                 * ⛔ শাখা ছাড়া DO নয় — অডিট ⛔১২ (সমন্বয়ক, ৬ অক্টোবর ২০২৬): আগে ঘরটাই লেখা হত না, তাই প্রতিটা DO শাখাহীন — শাখার
                 * তালিকা, হেডারের শাখা-ছাঁকনি আর শাখার মাল যাচাই কোনোটাই একে চিনত না। ⓘ ডিলার নিজে লিখলে তাঁর নিজের শাখা;
                 * কর্মী লিখলে যে শাখা দেখছেন ([[SalesOrderService]]-এর মতো), "সব শাখা" দেখলে গ্রাহকের শাখা।
                 */
                'branch_id' => $this->branchFor($data, $by, $customerId),
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                'deliver_on' => $data['deliver_on'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => DeliveryOrderStatus::DRAFT,
                'created_by' => $by instanceof User ? $by->id : null,
                'created_by_customer_id' => $by instanceof Customer ? $by->id : null,
            ]);

            $this->writeLines($order, $lines);

            return $order->fresh('lines');
        });
    }

    /** @param  array<string, mixed>  $data */
    private function branchFor(array $data, User|Customer $by, int $customerId): ?int
    {
        $customerBranch = Customer::query()->whereKey($customerId)->value('branch_id');
        $chosen = $by instanceof Customer
            ? $customerBranch
            : ($data['branch_id'] ?? \App\Core\Support\CompanyContext::branchId() ?? $customerBranch);

        return $chosen === null ? null : (int) $chosen;
    }

    /** ⛔ কেবল খসড়া, আর কেবল যিনি লিখেছিলেন */
    public function update(DeliveryOrder $order, array $data, array $lines, User|Customer $by): DeliveryOrder
    {
        $this->assertWriterMayEdit($order, $by);

        return DB::transaction(function () use ($order, $data, $lines) {
            $order->fill(array_intersect_key($data, array_flip(['warehouse_id', 'trx_date', 'deliver_on', 'narration'])))->save();
            $order->lines()->delete();
            $this->writeLines($order, $lines);

            return $order->fresh('lines');
        });
    }

    /** খসড়া → জমা → সুপারভাইজারের ছক (থাকলে) — নাহলে সরাসরি অনুমোদিত */
    public function submit(DeliveryOrder $order, User|Customer $by): DeliveryOrder
    {
        $this->assertWriterMayEdit($order, $by);

        if ($order->lines()->count() === 0) {
            throw ValidationException::withMessages(['lines' => __('sales::delivery_order.no_lines')]);
        }

        return DB::transaction(function () use ($order) {
            $order->forceFill(['status' => DeliveryOrderStatus::SUBMITTED, 'submitted_at' => now()])->save();

            $approval = $this->approvals->request(
                document: $order,
                // ⓘ নামটা লেখা, ধ্রুবক নয় — ছকের পর্দার পাহারা কোড পড়ে দেখে কে সই চায় ([[EveryApprovalAskedForCanBeConfiguredTest]]); = self::APPROVAL_ACTION
                module: 'sales', action: 'delivery_order',
                amount: (string) $order->total,
                userId: $order->created_by,
                // ⓘ ডিলারের লেখা DO — সই চাওয়া ডিলারের নিজের নামে (৩ অক্টোবর ২০২৬)
                customerId: $order->created_by === null ? $order->created_by_customer_id : null,
            );

            if ($approval !== null) {
                $order->forceFill(['status' => DeliveryOrderStatus::SUPERVISOR_PENDING])->save();

                return $order->fresh();
            }

            return $this->markSupervisorApproved($order);
        });
    }

    /**
     * ⭐ সুপারভাইজার মজুদ দেখে পরিমাণ বদলান — কেবল সইয়ের অপেক্ষায়, কেবল এখনকার স্তরের অনুমোদনকারী
     * ([[ApprovalEngine::canDecide()]])। ⓘ কে কোন লাইনে কী থেকে কী করলেন — লাইন অডিট-খাতায় নেই, তাই DO-র
     * নিজের সারিতে নোট নয়, অনুমোদনের মন্তব্যে লেখা থাকে; মোট আবার গোনা হয় (abos-86 সেটাই পড়েন)।
     *
     * @param  array<int, numeric-string|int|float>  $quantities  লাইনের id → নতুন পরিমাণ
     */
    public function setApprovedQuantities(DeliveryOrder $order, array $quantities, User $approver): DeliveryOrder
    {
        if ($order->status !== DeliveryOrderStatus::SUPERVISOR_PENDING) {
            throw ValidationException::withMessages(['status' => __('sales::delivery_order.not_awaiting_you')]);
        }

        $pending = $this->approvals->latestFor($order, self::APPROVAL_ACTION);

        if ($pending === null || ! $pending->isPending() || ! $this->approvals->canDecide($pending, $approver)) {
            abort(403);
        }

        return DB::transaction(function () use ($order, $quantities) {
            foreach ($order->lines()->lockForUpdate()->get() as $line) {
                if (! array_key_exists($line->id, $quantities)) {
                    continue;
                }

                $qty = (string) $quantities[$line->id];

                // ⓘ শূন্য চলে — "এই পণ্য এবার নয়"; ঋণাত্মক নয়, আর চাওয়ার বেশিও নয় (বেশি লাগলে ডিলার নতুন DO লেখেন)
                if (! is_numeric($qty) || bccomp($qty, '0', 4) < 0 || bccomp($qty, (string) $line->qty, 4) > 0) {
                    throw ValidationException::withMessages(["lines.{$line->id}" => __('sales::delivery_order.approved_qty_range')]);
                }

                $line->forceFill(['approved_qty' => $qty])->save();
            }

            $order->recalculate();

            return $order->fresh('lines');
        });
    }

    /** শেষ সই হলো (বা ছক নেই) — অনুমোদিত, আর abos-86-কে সংকেত, লেনদেন পাকা হলে */
    public function markSupervisorApproved(DeliveryOrder $order): DeliveryOrder
    {
        $order->recalculate();
        $order->forceFill(['status' => DeliveryOrderStatus::SUPERVISOR_APPROVED, 'approved_at' => now()])->save();
        $fresh = $order->fresh();
        DB::afterCommit(fn () => event(DeliveryOrderSupervisorApproved::from($fresh)));

        return $fresh;
    }

    /** সুপারভাইজার ফেরালেন (`rejected`) বা লেখক/কেউ বাতিল করলেন (`cancelled`) */
    public function stop(DeliveryOrder $order, string $reason, ?string $note, ?User $by): DeliveryOrder
    {
        if (in_array($order->status, DeliveryOrderStatus::CLOSED, true)) {
            return $order;
        }

        $order->forceFill([
            'status' => $reason === 'rejected' ? DeliveryOrderStatus::REJECTED : DeliveryOrderStatus::CANCELLED,
            'cancelled_by' => $by?->id,
            'cancelled_at' => now(),
            'cancel_reason' => $note,
        ])->save();
        $fresh = $order->fresh();
        DB::afterCommit(fn () => event(DeliveryOrderCancelled::from($fresh, $reason)));

        return $fresh;
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  list<array<string, mixed>>  $lines */
    private function writeLines(DeliveryOrder $order, array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::delivery_order.no_lines')]);
        }

        foreach ($lines as $i => $line) {
            $qty = (string) ($line['qty'] ?? '0');

            if (! is_numeric($qty) || bccomp($qty, '0', 4) <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.qty" => __('sales::delivery_order.qty_positive')]);
            }

            $product = Product::query()->find((int) ($line['product_id'] ?? 0))
                ?? throw ValidationException::withMessages(["lines.{$i}.product_id" => __('sales::delivery_order.no_product')]);

            // ⭐ DO-র পণ্য সক্রিয় আর DO-র শাখার (Inventory অডিট ম২৩) — ফোন আর পোর্টালের DO-ও এখানে আসে;
            // ⓘ DO নিজে শাখা লেখে না, তাই শাখা না থাকলে কাজের শাখা ([[CompanyContext::branchId()]])
            $branch = $order->branch_id ?? \App\Core\Support\CompanyContext::branchId();
            app(\App\Modules\Inventory\Services\SellableHere::class)->assert($product, $branch === null ? null : (int) $branch, "lines.{$i}.product_id");

            // ⭐ ডিলারের দর তালিকার দাম, নাহলে পণ্যের দাম ([[SalesPrice]], ৫ অক্টোবর ২০২৬)
            $rate = (string) app(SalesPrice::class)->for($order->customer, $product, $order->trx_date)->price;

            /*
             * ⛔ দাম শূন্য হলে DO নয় — মালিকের "sales price chara entry nibe na", ওয়েবের `gt:0`-এর একই কথা (পুরো ERP অডিট, ৬ অক্টোবর
             * ২০২৬, ফোন ⚠️১৫: শূন্য দরের DO বাকির যাচাই আর অনুমোদনের সীমার নিচ দিয়ে যেত)। ডেস্ক, পোর্টাল আর ফোন — তিন দরজাই এখানে।
             */
            if (bccomp($rate, '0', 4) <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.product_id" => __('sales::sync.order_line_has_no_price', ['product' => (string) ($product->name_bn ?: $product->name_en)])]);
            }

            $order->lines()->create([
                'product_id' => $product->id,
                'qty' => $qty,
                'rate' => $rate,
                'free_qty' => (string) ($line['free_qty'] ?? '0'),
                'note' => $line['note'] ?? null,
            ]);
        }

        $order->recalculate();
    }

    private function assertWriterMayEdit(DeliveryOrder $order, User|Customer $by): void
    {
        $mine = $by instanceof Customer
            ? (int) $order->created_by_customer_id === (int) $by->id
            : $order->created_by_customer_id === null && (int) $order->created_by === (int) $by->id;

        if (! $mine) {
            abort(403);
        }

        if (! $order->isEditableByWriter()) {
            throw ValidationException::withMessages(['status' => __('sales::delivery_order.locked_after_submit')]);
        }

        /*
         * ⛔ পুরনো খসড়া DO-ও আর বদলায় না, জমাও হয় না — কোম্পানি বিক্রয় আদেশে চলে গেলে (নকশার ধাপ ১৫, ৬ অক্টোবর ২০২৬)।
         * ⓘ নতুন DO আগেই বন্ধ ([[create()]], ধাপ ১২); কিন্তু সুইচের আগে লেখা খসড়া জমা দিলে সে DO-র পথে ঢুকত — সুইচের পরে
         * একই ডিলারের DO আর আদেশ পাশাপাশি। ⭐ জমা হওয়া DO-গুলো (সই, হিসাব, ডিপো, কাউন্টার) আগের মতো নিজের নম্বরে শেষ হয়;
         * খসড়াটা থামানো ([[stop()]]) যায়, আর মাল চাইলে আদেশ লিখুন।
         */
        if ($this->newOnesStopped()) {
            throw ValidationException::withMessages(['order' => __('sales::delivery_order.draft_now_an_order', ['no' => $order->document_no])]);
        }
    }

    private function assertCustomer(int $customerId): void
    {
        if ($customerId <= 0 || ! Customer::query()->whereKey($customerId)->exists()) {
            throw ValidationException::withMessages(['customer_id' => __('sales::delivery_order.no_customer')]);
        }
    }

    /** অর্ডারের রেফারেন্স — একই ডিলারের, এই কোম্পানির; নাহলে ফেরে */
    private function salesOrderFor(mixed $id, int $customerId): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $order = SalesOrder::query()->whereKey((int) $id)->where('customer_id', $customerId)->first();

        if ($order === null) {
            throw ValidationException::withMessages(['sales_order_id' => __('sales::delivery_order.order_elsewhere')]);
        }

        return (int) $order->id;
    }
}
