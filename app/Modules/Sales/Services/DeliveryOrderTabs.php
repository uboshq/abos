<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\DeliveryChallan;
use Illuminate\Database\Eloquent\Builder;

/**
 * ডেলিভারি অর্ডারের ট্যাব — প্রতিটা বিক্রির চালানই একটা DO।
 *
 * ⭐ মালিকের সিদ্ধান্ত, ২৮ সেপ্টেম্বর ২০২৬: *"প্রতিটি বিক্রির চালান = একটি DO"* —
 * কাউন্টারের বিক্রি (DS) আর বিক্রয় আদেশ থেকে বানানো চালান, দুইটাই এক তালিকায়, ধাপ
 * ধরে ট্যাবে ভাগ। ট্যাবগুলো মালিকের অনুমোদিত নকশার (ধাপ ৩): নতুন DO · খসড়া ·
 * অনুমোদনের অপেক্ষায় · ডেলিভারির অপেক্ষায় · ডেলিভার্ড · সব DO · বাতিল · DO ট্র্যাকিং।
 *
 * ⓘ খসড়া আর অনুমোদনের অপেক্ষার ট্যাব কাউন্টারের খসড়ার পাতাতেই খোলে
 * ([[DirectSaleController::drafts()]]) — নিষ্ক্রিয়, মুছুন আর দেখার পপ-আপ ওখানেই আছে;
 * এক জিনিস দুই জায়গায় বানালে একদিন দুই রকম হত। ⚠️ অফিসের খসড়া চালান (বিল ছাড়া)
 * ঐ পাতায় নেই — সেটা "সব DO"-তে থাকে, যাতে কোনো DO কোথাও অদৃশ্য না হয়।
 *
 * ⓘ "ডেলিভার্ড" মানে ডেলিভারির ধাপ DELIVERED ([[DeliveryStage]], abos-7c-এর ধাপ ৪);
 * ধাপ-সারি না থাকা পুরনো চালান অপেক্ষায় গোনে — [[DeliveryStageService::summaries()]]-এর
 * একই নিয়ম।
 */
final class DeliveryOrderTabs
{
    /** ট্যাবের ক্রম — চাবি → নাম। ⓘ যেগুলো অন্য পাতায় যায় তাদের ঠিকানা [[href()]]-এ। */
    public const TABS = [
        'new' => 'sales::do.tab.new',
        'drafts' => 'sales::do.tab.drafts',
        'approval' => 'sales::do.tab.approval',
        'awaiting' => 'sales::do.tab.awaiting',
        'delivered' => 'sales::do.tab.delivered',
        'all' => 'sales::do.tab.all',
        'cancelled' => 'sales::do.tab.cancelled',
        'tracking' => 'sales::do.tab.tracking',
    ];

    /** এই পাতার নিজের তালিকা-ট্যাব — বাকিগুলো অন্য পাতায় খোলে। */
    public const LISTED = ['awaiting', 'delivered', 'all', 'cancelled'];

    /** গোনা হয় এমন ট্যাব — নতুন আর ট্র্যাকিং তালিকা নয়। */
    public const COUNTED = ['drafts', 'approval', 'awaiting', 'delivered', 'all', 'cancelled'];

    public function href(string $tab): string
    {
        return match ($tab) {
            'new' => route('sales.direct.create'),
            'drafts' => route('sales.direct.drafts'),
            'approval' => route('sales.direct.drafts', ['tab' => 'approval']),
            // ⭐ ডেলিভারি ট্র্যাকিং — প্রতিটা বিক্রি কোথায় (মালিক, ২ অক্টোবর ২০২৬, [[SaleTracking]])
            'tracking' => route('sales.tracking.index'),
            default => route('sales.do.index', ['tab' => $tab]),
        };
    }

    /**
     * একটা তালিকা-ট্যাবের চালান।
     *
     * @return Builder<DeliveryChallan>
     */
    public function query(string $tab): Builder
    {
        $query = DeliveryChallan::query();
        $delivered = fn ($q) => $q->selectRaw('1')->from('sal_delivery_states as ds')
            ->whereColumn('ds.delivery_challan_id', 'sal_challans.id')
            ->where('ds.stage', DeliveryStage::DELIVERED);

        return match ($tab) {
            'awaiting' => $query->whereIn('sal_challans.status', DocumentStatus::POSTED)
                ->whereNotExists($delivered),
            'delivered' => $query->where('sal_challans.status', '<>', DocumentStatus::CANCELLED)
                ->whereExists($delivered),
            'cancelled' => $query->where('sal_challans.status', DocumentStatus::CANCELLED),
            default => $query->where('sal_challans.status', '<>', DocumentStatus::CANCELLED),
        };
    }

    /**
     * প্রতিটা গোনা ট্যাবের সংখ্যা — খসড়ার দুইটা কাউন্টারের নিজের নিয়মে
     * ([[DirectSaleService::trueDrafts()]], [[DirectSaleService::awaitingApproval()]])।
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'drafts' => DirectSaleService::trueDrafts()->count(),
            'approval' => DirectSaleService::awaitingApproval()->count(),
            'awaiting' => $this->query('awaiting')->count(),
            'delivered' => $this->query('delivered')->count(),
            'all' => $this->query('all')->count(),
            'cancelled' => $this->query('cancelled')->count(),
        ];
    }
}
