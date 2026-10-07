<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Services;

use App\Core\Contracts\OffersChoicesOnAForm;
use App\Core\Support\CompanyContext;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\TransferMode;

/**
 * ভাউচারের ফর্মে মাস্টার তালিকার দুইটা ঘর।
 *
 * ── ⚠️ কেন কথাটা এখানে, Accounts-এ নয় ───────────────────────────────
 * আগে ভাউচারের কন্ট্রোলার `TransferMode` আর `PartyType` সরাসরি ডাকত।
 * ⛔ কিন্তু MasterData নিজেই accounts-এর উপর দাঁড়িয়ে, তাই নির্ভরতাটা
 * ঘোষণা করলে **চক্র** হত আর রেজিস্ট্রি বুট-টাইমেই থামাত।
 *
 * ⭐ এখন ঘরটা ভাউচারের পর্দায় বসে, কিন্তু তালিকাটা যার — সে-ই দেয়
 * (২১ সেপ্টেম্বর ২০২৬)।
 */
final class MasterListsOnTheVoucherForm implements OffersChoicesOnAForm
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
            'transferModes' => TransferMode::query()->orderBy('code')->pluck('name_en', 'id'),

            /*
             * জাবেদার সারিতে বাছার মতো পক্ষগুলো।
             *
             * ⓘ কেবল সরবরাহকারীর দিকের ধরন — ভাউচারের এই ঘরটা "কাকে
             * দেওয়া হচ্ছে" প্রশ্নের উত্তর।
             */
            'payeeTypes' => PartyType::query()
                ->where('company_id', CompanyContext::id())
                ->whereIn('applies_to', ['supplier', 'both'])
                ->where('is_active', true)
                ->orderBy('name_bn')
                ->get()
                ->mapWithKeys(fn ($t) => [$t->id => $t->name_bn ?? $t->name_en])
                ->all(),
        ];
    }
}
