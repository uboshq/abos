<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Services\PartyRegistry;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;

/**
 * ভাউচারের পক্ষ — তালিকায় নম্বরের পাশে বসানোর জন্য, গোটা পাতার একবারে।
 *
 * ⭐ মালিক, ১৯ অক্টোবর ২০২৬: *"নম্বরের পাশে গ্রাহকের নাম আর পয়েন্টের নাম দিলে সহজে বোঝা যায়।"*
 *
 * ⓘ ক্রম: ভাউচারের নিজের পক্ষ ("নাম · পয়েন্ট") → প্রাপকের হাতে লেখা নাম (`payee_name`) → লাইনগুলোয় **একটাই** পক্ষ থাকলে সেটা।
 * ⚠️ লাইনে দুই বা বেশি পক্ষ (কয়েক গ্রাহকের জাবেদা) — কিছুই নয়; একজনের নাম বসালে বাকিদের টাকাও তাঁর মনে হত।
 * ⓘ পক্ষ ছাড়া জাবেদা — কিছুই নয়, কেবল নম্বর।
 *
 * ⚠️ প্রশ্ন: লাইনে একটা (কেবল পক্ষহীন ভাউচারগুলোর), আর পক্ষের ধরন প্রতি একটা ([[PartyRegistry::labelsOf()]])।
 */
final class VoucherParties
{
    public function __construct(private readonly PartyRegistry $parties) {}

    /**
     * @param  iterable<Voucher>  $vouchers
     * @return array<int, string> ভাউচারের id => পক্ষ
     */
    public function of(iterable $vouchers): array
    {
        $vouchers = collect($vouchers);

        if ($vouchers->isEmpty()) {
            return [];
        }

        $headless = $vouchers
            ->filter(fn (Voucher $v) => ($v->party_type === null || $v->party_id === null) && blank($v->payee_name))
            ->map(fn (Voucher $v) => (int) $v->getKey())
            ->values()
            ->all();

        $sole = [];

        if ($headless !== []) {
            VoucherLine::query()
                ->whereIn('voucher_id', $headless)
                ->whereNotNull('party_type')
                ->whereNotNull('party_id')
                ->select('voucher_id', 'party_type', 'party_id')
                ->distinct()
                ->toBase()
                ->get()
                ->groupBy('voucher_id')
                ->each(function ($lines, $voucherId) use (&$sole) {
                    if ($lines->count() === 1) {
                        $sole[(int) $voucherId] = [(string) $lines->first()->party_type, (int) $lines->first()->party_id];
                    }
                });
        }

        $labels = $this->parties->labelsOf(
            $vouchers
                ->filter(fn (Voucher $v) => $v->party_type !== null && $v->party_id !== null)
                ->map(fn (Voucher $v) => [(string) $v->party_type, (int) $v->party_id])
                ->values()
                ->concat(array_values($sole)),
        );

        $found = [];

        foreach ($vouchers as $v) {
            $id = (int) $v->getKey();
            $label = $labels[$v->party_type.':'.$v->party_id] ?? null;
            $label ??= filled($v->payee_name) ? trim((string) $v->payee_name) : null;
            $label ??= isset($sole[$id]) ? ($labels[$sole[$id][0].':'.$sole[$id][1]] ?? null) : null;

            if ($label !== null && $label !== '') {
                $found[$id] = $label;
            }
        }

        return $found;
    }
}
