<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Core\Contracts\PayeeBankView;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierBankDetail;

/**
 * ⭐ সরবরাহকারীর ব্যাংক — হিসাবের পর্দায় আর ফোনে, ঢাকা নম্বরে ([[PayeeBankView]]; মিল-তালিকা #৬৬/#৮০, ২৫ অক্টোবর ২০২৬)।
 *
 * ⓘ নিয়ম একটাই জায়গার: চালু তথ্য [[SupplierBank::signedFor()]], অপেক্ষার বদল [[SupplierBank::pendingFor()]], নম্বর
 * [[SupplierBankDetail::maskedAccount()]] — পাহারা ([[SupplierPayeeBankGuard]]) যা দেখে, পর্দাও তাই দেখে।
 * ⓘ সই-পাওয়া তথ্য থাকলে অবস্থা `signed`, পাশে অপেক্ষার বদল থাকলেও — টাকা সই-পাওয়া হিসাবেই যায়, বদল সই না পাওয়া পর্যন্ত নয়।
 */
final class SupplierPayeeBankView implements PayeeBankView
{
    public function __construct(private readonly SupplierBank $banks) {}

    public function bankOf(string $partyType, int $partyId): ?array
    {
        if ($partyType !== Supplier::drillSourceType()) {
            return null;
        }

        $supplier = Supplier::query()->find($partyId);

        if ($supplier === null) {
            return null;
        }

        $signed = $this->banks->signedFor($supplier);

        if ($signed !== null) {
            return ['state' => 'signed', 'line' => $this->lineOf($signed)];
        }

        $pending = $this->banks->pendingFor($supplier);

        return $pending !== null
            ? ['state' => 'pending', 'line' => $this->lineOf($pending)]
            : ['state' => 'none', 'line' => null];
    }

    /** "Dutch-Bangla ••••7890" — ব্যাংকের নাম আর শেষ চার অঙ্ক; ⛔ পুরো নম্বর কখনো নয় */
    private function lineOf(SupplierBankDetail $detail): string
    {
        return trim(trim((string) $detail->bank_name).' '.$detail->maskedAccount());
    }
}
