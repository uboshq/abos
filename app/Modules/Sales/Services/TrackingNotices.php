<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\NotificationService;
use App\Core\Services\Ownership;
use App\Core\Support\CompanyContext;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryEvent;

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

    public function __construct(
        private readonly NotificationService $notices,
        private readonly Ownership $owners,
    ) {}

    public function stageChanged(DeliveryEvent $event): void
    {
        if ($event->source === DeliveryStage::BY_BACKFILL || $event->from_stage === $event->to_stage) {
            return;
        }

        $challan = DeliveryChallan::query()->withoutGlobalScopes()->with('customer')->find($event->delivery_challan_id);

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
                __('sales::tracking.notice.body', ['customer' => (string) $challan->customer?->name()], 'bn'),
                route('sales.tracking.show', ['challan', $challan->public_id]),
            );

            /*
             * ⭐ অ্যাপ বন্ধ থাকলেও — ফোনে পুশ (ধাপ ৩), কেবল যাঁরা ইন-অ্যাপ বার্তা পেলেন তাঁদের (বন্ধ রাখা বা নিজে
             * বদলানো মানুষ বাদ)। ⛔ লক-স্ক্রিনে কেবল নম্বর আর ধাপ — দোকানের নাম, টাকা নয় (সমন্বয়কের শর্ত ২)।
             * ⓘ চাপলে অ্যাপ ট্র্যাকিং খোলে — `kind`/`id` দিয়ে।
             */
            foreach ($sent->pluck('user_id')->unique() as $userId) {
                \App\Jobs\SendPushToUser::dispatch((int) $userId, $title, [
                    'open' => 'tracking', 'kind' => 'challan', 'id' => (string) $challan->public_id,
                ]);
            }
        });
    }

    /**
     * লেখক আর অনুমোদনকারীরা — এই বিক্রির কাগজ ধরে।
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function recipientsOf(DeliveryChallan $challan): \Illuminate\Support\Collection
    {
        $people = collect([(int) $challan->created_by]);

        if ($challan->sales_order_id !== null) {
            $people->push((int) \App\Modules\Sales\Models\SalesOrder::query()->withoutGlobalScopes()
                ->whereKey($challan->sales_order_id)->value('created_by'));
        }

        $invoiceIds = \Illuminate\Support\Facades\DB::table('sal_challan_lines as cl')
            ->join('sal_invoice_lines as il', 'il.delivery_challan_line_id', '=', 'cl.id')
            ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
            ->where('i.company_id', $challan->company_id) // ⓘ লাইনে কোম্পানি নেই — চালানের নিজের কোম্পানি
            ->where('cl.delivery_challan_id', $challan->id)->distinct()->pluck('il.sales_invoice_id');

        $approvals = \App\Models\Approval::query()->withoutGlobalScopes()
            ->where('company_id', $challan->company_id)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('approvable_type', DeliveryChallan::class)->where('approvable_id', $challan->id))
                ->orWhere(fn ($w) => $w->where('approvable_type', \App\Modules\Sales\Models\SalesInvoice::class)->whereIn('approvable_id', $invoiceIds)))
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
