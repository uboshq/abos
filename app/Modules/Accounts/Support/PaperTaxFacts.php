<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

use App\Core\Contracts\PartyTaxIds;
use App\Core\Services\PartyRegistry;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ কর-কাগজের তথ্য — মূসক-৬.৭/৬.৮ নোট আর মূসক-৬.৩ ধাঁচের বিক্রয় ভাউচারের ছাপা দুজনেই ডাকে (মূসক ও সম্পূরক শুল্ক আইন ২০১২
 * ধারা ৫২(১), বিধি ৪০: দুই পক্ষের নাম, ঠিকানা, BIN; কাগজের তারিখ ও সময়)।
 *
 * ⓘ কোম্পানির BIN খালি থাকলে কাগজ থামে না — BIN-এর জায়গায় স্পষ্ট সতর্কবার্তা ([[accounts::note.bin_missing]]); ADI-র BIN লাইভে খালি।
 * ⓘ পক্ষের BIN [[PartyTaxIds]] থেকে (সরবরাহকারীর মাস্টার); না পেলে পক্ষের নিজের ঘর ([[PartyRegistry::paperFacts()]])।
 */
final class PaperTaxFacts
{
    public function __construct(private readonly PartyRegistry $parties) {}

    /** @return array{name: string, address: string, bin: string} — কাঁচা; নাম/ঠিকানা কাগজের ভাষায় ছাঁচ নিজে পড়ে */
    public function company(int $companyId): array
    {
        $company = Company::query()->find($companyId);

        return [
            'name' => (string) ($company?->name() ?? ''),
            'address' => (string) ($company?->address() ?? ''),
            'bin' => trim((string) ($company?->bin ?? '')),
        ];
    }

    /** @return array{name: string, code: string, address: string, phone: string, bin: string} */
    public function party(string $type, int $id): array
    {
        $facts = $this->parties->paperFacts($type, $id) ?? ['name' => '', 'code' => '', 'address' => '', 'phone' => '', 'bin' => ''];
        $bin = trim((string) (app(PartyTaxIds::class)->taxIdsOf($type, $id)['bin'] ?? ''));

        return [
            'name' => (string) $facts['name'],
            'code' => (string) $facts['code'],
            'address' => (string) $facts['address'],
            'phone' => (string) $facts['phone'],
            'bin' => $bin !== '' ? $bin : trim((string) ($facts['bin'] ?? '')),
        ];
    }

    /**
     * কাগজের তারিখ ও সময় — তারিখ কাগজের (`trx_date`), সময় পাকা হওয়ার (না হলে লেখার) মুহূর্তের।
     */
    public static function issuedAt(Model $paper): string
    {
        $date = DateFormat::format($paper->getAttribute('trx_date'));
        $moment = $paper->getAttribute('confirmed_at') ?? $paper->getAttribute('created_at');

        return $moment === null ? $date : trim($date.' '.$moment->format(DateFormat::time()));
    }

    /**
     * একটা সারি কাগজের জন্য — বিবরণ, পরিমাণ, একক, দর, মূল্য, ভ্যাটের হার, ভ্যাট, মোট।
     *
     * @return array{name: string, qty: string, unit: string, rate: string, amount: string, vat_rate: string, vat: string, total: string, return_no: string}
     */
    public static function line(Model $line): array
    {
        $name = trim((string) $line->getAttribute('item_name'));
        $description = trim((string) $line->getAttribute('description'));

        if ($name === '' && $line->getAttribute('account_id') !== null) {
            $account = $line->getRelationValue('account');
            $name = $account === null ? '' : trim((string) $account->name());
        }

        $label = $name === '' ? $description : ($description === '' || $description === $name ? $name : $name.' — '.$description);
        $isAccount = $line->getAttribute('line_type') === 'account';
        $vatRate = rtrim(rtrim(number_format((float) $line->getAttribute('vat_rate'), 2, '.', ''), '0'), '.');

        return [
            'name' => $label !== '' ? $label : '—',
            'qty' => $isAccount ? '' : Money::quantity($line->getAttribute('qty')),
            'unit' => $isAccount ? '' : (string) ($line->getAttribute('unit') ?? ''),
            'rate' => $isAccount ? '' : Money::format($line->getAttribute('rate')),
            'amount' => Money::format($line->getAttribute('amount')),
            'vat_rate' => $vatRate === '' ? '0%' : $vatRate.'%',
            'vat' => Money::format($line->getAttribute('vat_amount')),
            'total' => Money::format($line->getAttribute('total')),
            'return_no' => (string) ($line->getAttribute('return_no') ?? ''),
        ];
    }
}
