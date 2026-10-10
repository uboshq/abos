<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\NotificationService;
use App\Core\Services\Ownership;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Jobs\SendPushToUser;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryEvent;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ধাপ বদলালে বার্তা — ডেলিভারি ট্র্যাকিং, ধাপ ২ (মালিকের আদেশ, ২ অক্টোবর ২০২৬)।
 *
 * ── ⭐ কে পান (সমন্বয়কের সিদ্ধান্ত, ২ অক্টোবর ২০২৬) ─────────────────────────
 * • যিনি লিখলেন — অর্ডারের লেখক আর চালানের লেখক;
 * • অনুমোদনকারীরা — এই বিক্রির কোনো অনুমোদন যিনি চেয়েছেন বা যিনি সিদ্ধান্ত দিয়েছেন;
 * • মালিক — কোম্পানির সব সক্রিয় সুপার অ্যাডমিন ([[Ownership::activeOwnersIn()]])।
 * ⚠️ "SR নিজের ডিলার, ASM/DSM নিজের এলাকা" — ডিলার-SR-এলাকার সম্পর্ক বানাচ্ছে ⛔১৬ (abos-bb, "বিক্রয়কর্মী
 * কেবল নিজের ডিলার")। ⛔ এখানে আলাদা করে বানানো নিষেধ — দুই জায়গায় দুই উত্তর হত; ওটা এলে সেই সূত্রই
 * [[recipientsOf()]]-এ যোগ হবে।
 * ⓘ যিনি ধাপটা বদলালেন তিনি নিজে পান না ([[NotificationService]]-এর নিয়ম); কেউ ধরনটা বন্ধ রাখলে পান না।
 * ⚠️ দোকানি নিজে: পোর্টালে বার্তার কোনো টেবিল নেই (`notifications.user_id` → users) — আসবে আলাদা কাজে,
 * সমন্বয়কের অনুমতি নিয়ে নতুন টেবিলে। ততক্ষণ দোকানি পোর্টালে নিজের ট্র্যাকিং দেখেন।
 *
 * ⛔ পেছনের ভরাট (`backfill`) আর একই ধাপে থাকা কোনো বার্তা নয় — ওগুলো "বদল" নয়, আর এক রাতে শত শত খবর যেত।
 */
final class TrackingNotices
{
    public const TYPE = 'sales.delivery_stage';

    /** ⭐ নতুন ধারার আদেশ বাকির সীমায় আটকে গেল — লেখক আর মালিক (DO+SO মেশানো, ধাপ ১১, ৫ অক্টোবর ২০২৬) */
    public const ORDER_HELD = 'sales.order_credit_held';

    /** ⭐ নতুন ধারার আদেশ সইয়ের অপেক্ষায় — এখনকার স্তরের অনুমোদনকারীরা ([[ApprovalEngine::canDecide()]]) */
    public const ORDER_AWAITS = 'sales.order_awaits_you';

    public function __construct(
        private readonly NotificationService $notices,
        private readonly Ownership $owners,
    ) {}

    public function stageChanged(DeliveryEvent $event): void
    {
        if ($event->source === DeliveryStage::BY_BACKFILL || $event->from_stage === $event->to_stage) {
            return;
        }

        $challan = DeliveryChallan::query()->withoutGlobalScopes()->with('customer.location.parent')->find($event->delivery_challan_id);

        if ($challan === null) {
            return;
        }

        // ⓘ প্রসঙ্গ না থাকলে (পোর্টাল, সারির কাজ) বার্তা ভুল কোম্পানিতে বসত — চালানের নিজের কোম্পানিতে লেখা
        CompanyContext::forCompany((int) $challan->company_id, function () use ($challan, $event): void {
            $users = $this->recipientsOf($challan)
                ->merge($this->owners->activeOwnersIn((int) $challan->company_id)->modelKeys())
                ->filter()->unique()->values();

            if ($users->isEmpty()) {
                return;
            }

            $no = (string) ($challan->sale_no ?: $challan->document_no);
            $step = __('sales::delivery.stage.'.$event->to_stage, [], 'bn');

            $title = __('sales::tracking.notice.title', ['no' => $no, 'step' => $step], 'bn');
            $sent = $this->notices->sendMany(
                $users->all(),
                self::TYPE,
                $title,
                // ⭐ গ্রাহকের পাশে পয়েন্ট — মালিক, ৭ অক্টোবর ২০২৬ ([[Customer::nameWithPoint()]])
                __('sales::tracking.notice.body', ['customer' => (string) $challan->customer?->nameWithPoint()], 'bn'),
                route('sales.tracking.show', ['challan', $challan->public_id]),
                // ⭐ একই ধাপ-বদলের খবর একবারই, আর কোন চালান (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)
                key: 'sales:delivery-event:'.$event->getKey(),
                about: $challan,
            );

            /*
             * ⭐ অ্যাপ বন্ধ থাকলেও — ফোনে পুশ (ধাপ ৩), কেবল যাঁরা ইন-অ্যাপ বার্তা পেলেন তাঁদের (বন্ধ রাখা বা নিজে
             * বদলানো মানুষ বাদ)। ⛔ লক-স্ক্রিনে কেবল নম্বর আর ধাপ — দোকানের নাম, টাকা নয় (সমন্বয়কের শর্ত ২)।
             * ⓘ চাপলে অ্যাপ ট্র্যাকিং খোলে — `kind`/`id` দিয়ে।
             */
            foreach ($sent->pluck('user_id')->unique() as $userId) {
                SendPushToUser::dispatch((int) $userId, $title, [
                    'open' => 'tracking', 'kind' => 'challan', 'id' => (string) $challan->public_id,
                ], $challan->customer?->nameWithPoint());
            }
        });
    }

    /**
     * ⭐ আদেশ বাকির সীমায় আটকে গেল (প্রথমবার) — লেখক আর মালিক জানেন, টাকা এলে আদেশ নিজেই এগোবে।
     *
     * ⓘ ডাকে [[SalesOrderService]] লেনদেন পাকা হলে। যিনি জমা দিলেন তিনি নিজে পর্দায় দেখছেন, তাই তাঁর কাছে যায় না
     * ([[NotificationService]]-এর নিয়ম); পোর্টালের গ্রাহক পোর্টালে আদেশের পাতায় দেখেন।
     * ⛔ লক-স্ক্রিনে কেবল নম্বর আর ধাপ — দোকানের নাম বা টাকা নয় (সমন্বয়কের শর্ত ২)।
     */
    public function orderHeld(SalesOrder $order): void
    {
        CompanyContext::forCompany((int) $order->company_id, function () use ($order): void {
            $users = collect([(int) $order->created_by])
                ->merge($this->owners->activeOwnersIn((int) $order->company_id)->modelKeys())
                ->filter()->unique()->values();

            $this->tell($users->all(), self::ORDER_HELD, __('sales::tracking.notice.order_held', ['no' => $order->document_no], 'bn'),
                $order, route('sales.order.show', $order));
        });
    }

    /**
     * ⭐ আদেশ সইয়ের অপেক্ষায় — এখনকার স্তরের প্রত্যেক অনুমোদনকারী জানেন।
     *
     * ⓘ অনুমোদন-ইঞ্জিন নিজে অনুমোদনকারীকে খবর দেয় না (কেবল ফলাফল লেখককে) — তাই এখানে, একবারই।
     * কে সই দিতে পারেন সেটা ইঞ্জিনের নিজের প্রশ্ন ([[ApprovalEngine::canDecide()]]) — এখানে আলাদা নিয়ম নয়।
     */
    public function orderAwaitsYou(SalesOrder $order): void
    {
        $engine = app(ApprovalEngine::class);
        $approval = $engine->latestFor($order, SalesOrderService::APPROVAL_ACTION);

        if ($approval === null || ! $approval->isPending()) {
            return;
        }

        CompanyContext::forCompany((int) $order->company_id, function () use ($order, $approval, $engine): void {
            $users = User::query()
                ->where('is_active', true)
                ->whereHas('companies', fn ($q) => $q->where('companies.id', (int) $order->company_id))
                ->get()
                ->filter(fn (User $user) => $engine->canDecide($approval, $user))
                ->modelKeys();

            $this->tell($users, self::ORDER_AWAITS, __('sales::tracking.notice.order_awaits', ['no' => $order->document_no], 'bn'),
                $order, route('sales.order.show', $order));
        });
    }

    /**
     * ইন-অ্যাপ খবর, তারপর যাঁরা পেলেন কেবল তাঁদের ফোনে পুশ — চাপলে অ্যাপ আদেশের ট্র্যাকিং খোলে।
     *
     * @param  list<int>  $users
     */
    private function tell(array $users, string $type, string $title, SalesOrder $order, string $url): void
    {
        if ($users === []) {
            return;
        }

        // ⭐ কোন আদেশ — শাখার দেয়াল খবরেও (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)। ⓘ চাবি নেই: একই আদেশ পরের স্তরে আবার কারও
        // সইয়ের অপেক্ষায় যেতে পারে, আর একই মানুষ দুই স্তরে থাকলে দ্বিতীয় খবরটা হারাত
        $sent = $this->notices->sendMany($users, $type, $title,
            __('sales::tracking.notice.body', ['customer' => (string) $order->customer?->nameWithPoint()], 'bn'), $url,
            about: $order,
            // ⓘ নিয়মের মান — আদেশের নম্বর, টাকা, গ্রাহক, অবস্থা (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩-এর অনুসরণ)
            data: array_filter([
                'paper_no' => (string) $order->document_no,
                'amount' => $order->total === null ? '' : Money::format((string) $order->total),
                'party' => (string) ($order->customer?->nameWithPoint() ?? ''),
                'status' => (string) $order->status,
            ], fn ($v) => $v !== ''));

        foreach ($sent->pluck('user_id')->unique() as $userId) {
            SendPushToUser::dispatch((int) $userId, $title, [
                'open' => 'tracking', 'kind' => 'order', 'id' => (string) $order->public_id,
            ], $order->customer?->nameWithPoint());
        }
    }

    /**
     * লেখক আর অনুমোদনকারীরা — এই বিক্রির কাগজ ধরে।
     *
     * @return Collection<int, int>
     */
    private function recipientsOf(DeliveryChallan $challan): Collection
    {
        $people = collect([(int) $challan->created_by]);

        if ($challan->sales_order_id !== null) {
            $people->push((int) SalesOrder::query()->withoutGlobalScopes()
                ->whereKey($challan->sales_order_id)->value('created_by'));
        }

        $invoiceIds = DB::table('sal_challan_lines as cl')
            ->join('sal_invoice_lines as il', 'il.delivery_challan_line_id', '=', 'cl.id')
            ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
            ->where('i.company_id', $challan->company_id) // ⓘ লাইনে কোম্পানি নেই — চালানের নিজের কোম্পানি
            ->where('cl.delivery_challan_id', $challan->id)->distinct()->pluck('il.sales_invoice_id');

        $approvals = Approval::query()->withoutGlobalScopes()
            ->where('company_id', $challan->company_id)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('approvable_type', DeliveryChallan::class)->where('approvable_id', $challan->id))
                ->orWhere(fn ($w) => $w->where('approvable_type', SalesInvoice::class)->whereIn('approvable_id', $invoiceIds)))
            ->with('decisions')->get();

        foreach ($approvals as $approval) {
            $people->push((int) $approval->requested_by);
            foreach ($approval->decisions as $decision) {
                $people->push((int) $decision->user_id);
            }
        }

        return $people->filter();
    }
}
