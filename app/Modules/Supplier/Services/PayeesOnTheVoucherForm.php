<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Core\Contracts\OffersChoicesOnAForm;
use App\Core\Support\CompanyContext;
use App\Modules\Supplier\Models\Supplier;

/**
 * ভাউচারের ফর্মে "কাকে দেওয়া হচ্ছে" — ধরন ধরে সাজানো।
 *
 * ── ⚠️ কেন কথাটা এখানে ──────────────────────────────────────────────
 * আগে ভাউচারের কন্ট্রোলার `Supplier` সরাসরি ডাকত। ⛔ কিন্তু Supplier
 * নিজেই accounts-এর উপর দাঁড়িয়ে, তাই নির্ভরতাটা ঘোষণা করলে চক্র হত
 * (২১ সেপ্টেম্বর ২০২৬)।
 *
 * ⚠️ আজকের ডেটায় কোনো সরবরাহকারীর ধরন বসানো নেই (`party_type_id` সব
 * খালি), তাই তালিকাগুলো আজ খালি আসবে আর পর্দা নাম লেখার ঘরটাই দেখাবে।
 * ⓘ সরবরাহকারীর ফর্মে ধরন বসানো শুরু করলেই এটা নিজে থেকে কাজ করবে।
 */
final class PayeesOnTheVoucherForm implements OffersChoicesOnAForm
{
    /**
     * @return array<string, mixed>
     */
    public function choicesFor(string $form): array
    {
        if ($form !== 'accounts.voucher') {
            return [];
        }

        return [
            'payeesByType' => Supplier::query()
                ->where('company_id', CompanyContext::id())
                ->where('is_active', true)
                ->whereNotNull('party_type_id')
                ->orderBy('name_bn')
                ->get(['id', 'name_bn', 'name_en', 'party_type_id'])
                ->groupBy(fn ($s) => (string) $s->party_type_id)
                ->map(fn ($group) => $group
                    ->map(fn ($s) => ['id' => (int) $s->id, 'label' => $s->name_bn ?? $s->name_en])
                    ->values())
                ->all(),
        ];
    }
}
