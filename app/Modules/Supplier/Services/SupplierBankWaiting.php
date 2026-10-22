<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Core\Contracts\SignaturesWaitingElsewhere;
use App\Models\User;
use App\Modules\Supplier\Models\SupplierBankDetail;

/**
 * ⭐ সরবরাহকারীর ব্যাংক তথ্যের অপেক্ষার বদল — অনুমোদনের বাক্সে, কেবল যিনি সই দিতে পারেন তাঁর (নকশা দফা #৬; [[SupplierBank]])।
 *
 * ⓘ কোম্পানির ছাঁকনি মডেলের নিজের ([[BelongsToCompany]]); সারি ধরে [[SupplierBank::canSign()]] — চাবি, শাখার নাগাল, আর যিনি
 * চেয়েছেন তিনি নন (মালিকও নন)।
 */
final class SupplierBankWaiting implements SignaturesWaitingElsewhere
{
    public function __construct(private readonly SupplierBank $bank) {}

    public function waitingFor(User $user): array
    {
        if (! $user->can(SupplierBank::APPROVE_KEY)) {
            return [];
        }

        return SupplierBankDetail::query()
            ->where('status', SupplierBankDetail::PENDING)
            ->where('requested_by', '!=', $user->id)
            ->with(['supplier', 'requester'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->filter(fn (SupplierBankDetail $d) => $d->supplier !== null && $this->bank->canSign($d, $user))
            ->map(fn (SupplierBankDetail $d) => [
                'kind' => 'supplier-bank',
                'title' => __('supplier::bank.waiting_title', ['supplier' => $d->supplier->name()]),
                'detail' => $this->bank->summaryOf($d),
                'requested_by' => (string) ($d->requester?->name ?? '—'),
                'requested_at' => $d->created_at,
                'url' => $this->bank->cardUrl($d->supplier),
            ])
            ->values()
            ->all();
    }
}
