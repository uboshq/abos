<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Core\Contracts\PayeeBankAccount;
use App\Modules\Supplier\Models\Supplier;

/**
 * ⭐ সরবরাহকারীর সই-পাওয়া ব্যাংক হিসাব, পুরো — ব্যাংক ফাইলের জন্য ([[PayeeBankAccount]]; cb-ma-4)।
 *
 * ⓘ কেবল [[SupplierBank::signedFor()]] — দুজনের সই পাওয়া সর্বশেষ তথ্য; অপেক্ষার বদল বা না-থাকা তথ্যে `null`, তাই ফাইলে সেই সারিই যায় না।
 */
final class SupplierPayeeBankAccount implements PayeeBankAccount
{
    public function __construct(private readonly SupplierBank $banks) {}

    public function accountOf(string $partyType, int $partyId): ?array
    {
        if ($partyType !== Supplier::drillSourceType()) {
            return null;
        }

        $supplier = Supplier::query()->find($partyId);
        $signed = $supplier === null ? null : $this->banks->signedFor($supplier);

        if ($signed === null) {
            return null;
        }

        return [
            'bank' => (string) $signed->bank_name,
            'branch' => (string) ($signed->branch_name ?? ''),
            'account_name' => (string) ($signed->account_name ?: $supplier->name()),
            'account_no' => (string) $signed->account_no,
            'routing_no' => (string) ($signed->routing_no ?? ''),
        ];
    }
}
