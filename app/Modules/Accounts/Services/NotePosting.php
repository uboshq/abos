<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Support\LineMath;

/**
 * ⭐ সারির নোটের দাখিলা — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬।
 *
 *   ক্রেডিট নোট:              Dr সারির খাত (দাম ঠিক করা = ৪১০০ বিক্রয়; খাতের সারি = বাছা খাত) · Dr ২১২০ ভ্যাট  /  Cr পক্ষের খাত
 *   গ্রাহকের ডেবিট নোট:        Dr পক্ষের খাত  /  Cr সারির খাত (৪১০০, ৪৩৬০ …) · Cr ২১২০ ভ্যাট
 *   সরবরাহকারীর ডেবিট নোট:     Dr পক্ষের খাত  /  Cr ১১২০ মজুদ (মজুদে থাকা ভাগ) · Cr ৫১৫০ (বিক্রি হয়ে যাওয়া ভাগ) · Cr খাতের সারি · Cr ২১২০
 *
 * ⛔ "মাল ফেরত" সারি এখানে নেই — তার টাকা আর মাল ফেরতের কাগজ (SR/PR) নিজে তোলে; এখানে আবার তুললে টাকা দুবার উঠত।
 * ⓘ একই খাতের সারিগুলো এক লাইনে — খতিয়ান পরিষ্কার থাকে, ড্রিল করলে নোট খোলে।
 */
final class NotePosting
{
    /**
     * @return list<array<string, mixed>>
     */
    public function lines(Note $note, Account $control): array
    {
        $note->loadMissing('lines');

        $byAccount = [];
        $vat = '0';
        $gross = '0';

        foreach ($note->lines as $line) {
            /** @var NoteLine $line */
            if ($line->postedByItsReturn()) {
                continue;
            }

            $amount = (string) $line->amount;
            $vat = bcadd($vat, (string) $line->vat_amount, 4);
            $gross = bcadd($gross, (string) $line->total, 4);

            if ($note->type() === Note::TYPE_DN && $line->line_type === NoteLine::PRODUCT) {
                $share = (string) ($line->stock_share ?? '1');
                $inStock = LineMath::round2(bcmul($amount, $share, 8));
                $sold = bcsub($amount, $inStock, 4);

                $this->add($byAccount, (int) StandardChart::accountFor(StandardChart::INVENTORY)->id, $inStock);
                $this->add($byAccount, (int) StandardChart::accountFor(StandardChart::PURCHASE_PRICE_VARIANCE)->id, $sold);

                continue;
            }

            $this->add($byAccount, (int) $line->account_id, $amount);
        }

        if (bccomp($gross, '0', 4) <= 0) {
            return [];
        }

        $credit = $note->isCredit();
        $side = $credit ? 'debit' : 'credit';
        $narration = $note->narration;

        $party = [
            'account_id' => (int) $control->id,
            $credit ? 'credit' : 'debit' => $gross,
            'party_type' => $note->party_type,
            'party_id' => (int) $note->party_id,
            'narration' => $narration,
        ];

        $rows = [];

        foreach ($byAccount as $accountId => $amount) {
            if (bccomp($amount, '0', 4) > 0) {
                $rows[] = ['account_id' => $accountId, $side => $amount, 'narration' => $narration];
            }
        }

        if (bccomp($vat, '0', 4) > 0) {
            $rows[] = ['account_id' => (int) StandardChart::accountFor(StandardChart::VAT_PAYABLE)->id, $side => $vat, 'narration' => $narration];
        }

        return $credit ? [...$rows, $party] : [$party, ...$rows];
    }

    /** @param array<int, string> $sums */
    private function add(array &$sums, int $accountId, string $amount): void
    {
        $sums[$accountId] = bcadd($sums[$accountId] ?? '0', $amount, 4);
    }
}
