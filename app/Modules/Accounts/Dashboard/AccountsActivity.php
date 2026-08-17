<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Dashboard;

use App\Core\Contracts\ContributesActivity;
use App\Core\Dashboard\Happening;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Models\Voucher;

/**
 * হিসাবের ঘরে সদ্য যা হয়েছে।
 *
 * ── কেন এই তিনটা ────────────────────────────────────────────────────
 * ভাউচার (টাকা এল বা গেল), নগদ গণনা (ড্রয়ার মিলল কি না), আর হস্তান্তর
 * (টাকা হাত বদলাল)। তিনটাই সেই ঘটনা যেগুলো মালিক না দেখলে জানতেই
 * পারতেন না — বাকিগুলো কোনো না কোনো বিলের সাথে বাঁধা।
 */
final class AccountsActivity implements ContributesActivity
{
    /** @return list<Happening> */
    public static function activity(int $limit): array
    {
        return [
            ...self::vouchers($limit),
            ...self::counts($limit),
            ...self::transfers($limit),
        ];
    }

    /** @return list<Happening> */
    private static function vouchers(int $limit): array
    {
        return Voucher::query()
            ->posted()
            ->with('lines')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Voucher $voucher) => new Happening(
                when: $voucher->updated_at ?? $voucher->created_at,

                /*
                 * অঙ্কটা ডেবিটের যোগফল।
                 *
                 * ভাউচারের নিজের কোনো "মোট" ঘর নেই — দুই পাশ সমান
                 * হলেই সেটা পোস্ট হয়, তাই যেকোনো একটা পাশই অঙ্কটা।
                 * ডেবিট নেওয়া হয়েছে কারণ সেটাই "টাকাটা কোথায় গেল"।
                 */
                title: $voucher->document_no.' · '
                    .Money::format($voucher->totals()['debit'], 2),
                subtitle: __('accounts::doc.'.$voucher->type.'_voucher'),
                icon: 'wallet',
                permission: 'accounts.report',

                /*
                 * টাকা আসা আর টাকা যাওয়া দুই রঙে।
                 *
                 * চার সারির তালিকায় চোখ বুলিয়ে দিনের গল্পটা পড়া যায়
                 * কেবল তখনই, যখন দিক দুইটা আলাদা দেখায়।
                 */
                tone: $voucher->type === Voucher::RECEIPT ? 'good' : 'money',
                sourceType: Voucher::SOURCE_TYPES[$voucher->type] ?? null,
                sourceId: $voucher->id,
            ))
            ->all();
    }

    /**
     * নগদ গণনা — মিলেছে কি না সেটাই খবর।
     *
     * "গণনা হয়েছে" কোনো খবর নয়; খবর হলো **কত কম বা বেশি পাওয়া গেল**।
     * শূন্য পার্থক্য মানে ড্রয়ার মিলেছে, আর সেটাই সবচেয়ে ভালো খবর —
     * তাই সারিটা তখন সবুজ, নাহলে সতর্ক।
     *
     * @return list<Happening>
     */
    private static function counts(int $limit): array
    {
        return CashCount::query()
            /*
             * এই দুইটা মডেলে `->posted()` স্কোপটা নেই — তারা
             * `HasDocumentStatus` ব্যবহার করে না, যদিও `status` ঘরটা
             * একই শব্দভাণ্ডারের। তাই নামওয়ালা ধ্রুবকটাই সরাসরি, আর
             * তালিকাটা এখানে হাতে লেখা হয় না।
             */
            ->whereIn('status', DocumentStatus::POSTED)
            ->with('till')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function (CashCount $count) {
                $difference = (string) $count->difference;
                $matched = bccomp($difference, '0', 4) === 0;

                return new Happening(
                    when: $count->updated_at ?? $count->created_at,
                    title: $matched
                        ? __('accounts::message.count_matched')
                        : __('accounts::message.count_off_by', [
                            'amount' => Money::format($difference, 2),
                        ]),
                    subtitle: $count->till?->name() ?? '',
                    icon: 'cash',
                    permission: 'accounts.count.create',
                    tone: $matched ? 'good' : 'warn',
                    sourceType: CashCount::drillSourceType(),
                    sourceId: $count->id,
                );
            })
            ->all();
    }

    /** @return list<Happening> */
    private static function transfers(int $limit): array
    {
        return MoneyTransfer::query()
            ->whereIn('status', DocumentStatus::POSTED)
            ->with(['fromTill', 'toTill'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (MoneyTransfer $transfer) => new Happening(
                when: $transfer->updated_at ?? $transfer->created_at,
                title: $transfer->document_no.' · '.Money::format((string) $transfer->amount, 2),
                subtitle: trim(($transfer->fromTill?->name() ?? '').' → '.($transfer->toTill?->name() ?? ''), ' →'),
                icon: 'handover',
                permission: 'accounts.transfer.create',
                tone: 'money',
                sourceType: MoneyTransfer::drillSourceType(),
                sourceId: $transfer->id,
            ))
            ->all();
    }
}
