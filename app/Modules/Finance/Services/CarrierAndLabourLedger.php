<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Services\PartyRegistry;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * পরিবহন ও শ্রমিকের খতিয়ান — ডিপোর বিশেষ খতিয়ান (মানচিত্র §৬)।
 *
 * ── কোথা থেকে পড়ে ────────────────────────────────────────────────────
 * দুইটা আলাদা দেনার ঘর থেকে: ২১১৬ (পরিবহনের প্রদেয় — চালান নিশ্চিত হলে
 * গাড়ির ভাড়া এখানে জমে, [[DeliveryChallanService::postTransportCost]]) আর
 * ২১১৭ (শ্রমিকের প্রদেয়)। ⓘ ক্রেডিট = ভাড়া/মজুরি জমল, ডেবিট = দেওয়া হলো।
 *
 * ── কেন পক্ষ ধরে ─────────────────────────────────────────────────────
 * মালিকের কথা: *"transporter-এর সাথে হিসাব হবে"* — মাসে একবার মেটে। তাই
 * প্রশ্নটা সবসময় "করিম ট্রান্সপোর্টকে এখন কত দিতে হবে", আর উত্তরটা
 * পক্ষের সারি ধরে। ⚠️ পক্ষ ছাড়া বসা সারি (হাতে লেখা ভাউচার) লুকানো হয় না —
 * "পক্ষ বাছা নেই" নামে আলাদা সারিতে দেখায়, নাহলে মোট মিলত না।
 */
final class CarrierAndLabourLedger
{
    /** @var array<string, string> ট্যাব → খাতের কোড */
    public const HEADS = [
        'transport' => StandardChart::TRANSPORT_PAYABLE,
        'labour' => StandardChart::LABOUR_PAYABLE,
    ];

    public function __construct(private readonly PartyRegistry $parties) {}

    public function head(string $tab): ?Account
    {
        return StandardChart::find(self::HEADS[$tab] ?? '');
    }

    /**
     * পক্ষ ধরে: শুরুতে বাকি · জমল · দেওয়া হলো · এখন বাকি।
     *
     * @return Collection<int, array{key: string, label: string, opening: string, charged: string, paid: string, closing: string}>
     */
    public function parties(Account $head, string $from, string $to): Collection
    {
        $rows = LedgerEntry::query()
            ->where('account_id', $head->id)
            ->whereDate('trx_date', '<=', $to)
            ->selectRaw('party_type, party_id,
                SUM(CASE WHEN trx_date < ? THEN credit - debit ELSE 0 END) as opening,
                SUM(CASE WHEN trx_date >= ? THEN credit ELSE 0 END) as charged,
                SUM(CASE WHEN trx_date >= ? THEN debit ELSE 0 END) as paid', [$from, $from, $from])
            ->groupBy('party_type', 'party_id')
            ->get();

        $labels = $this->parties->labelsOf($rows->map(fn ($r) => [(string) $r->party_type, (int) $r->party_id]));

        return $rows
            ->map(function ($r) use ($labels) {
                $key = $r->party_type ? $r->party_type.':'.$r->party_id : '';
                $opening = (string) $r->opening;
                $charged = (string) $r->charged;
                $paid = (string) $r->paid;

                return [
                    'key' => $key,
                    'label' => $key === '' ? __('finance::carrier_labour.no_party') : ($labels[$key] ?? $key),
                    'opening' => $opening,
                    'charged' => $charged,
                    'paid' => $paid,
                    'closing' => bcsub(bcadd($opening, $charged, 4), $paid, 4),
                ];
            })
            ->reject(fn ($p) => bccomp($p['opening'], '0', 4) === 0 && bccomp($p['charged'], '0', 4) === 0
                && bccomp($p['paid'], '0', 4) === 0)
            ->sortByDesc(fn ($p) => (float) $p['closing'])
            ->values();
    }

    /**
     * একটা পক্ষের সারিগুলো, চলমান বাকিসহ — পাতা ভাগ করে।
     *
     * ⚠️ চলমান বাকিটা আগের সব সারির উপর দাঁড়ায়, তাই দ্বিতীয় পাতায়
     * শূন্য থেকে শুরু করলে সংখ্যাগুলো মিথ্যা হত। ⓘ তাই এই পাতার আগের
     * সারিগুলোর যোগফল একটা আলাদা প্রশ্নে আনা হয়, আর সেটা থেকেই পাতার
     * হিসাব শুরু হয়। ⛔ সারিগুলো সবসময় একই ক্রমে (তারিখ, তারপর আইডি),
     * নাহলে "আগের সারি" বলে কিছু থাকত না।
     *
     * @return array{opening: string, brought: string, rows: LengthAwarePaginator<int, LedgerEntry>}
     */
    public function statement(Account $head, string $partyKey, string $from, string $to, int $perPage = 100): array
    {
        [$type, $id] = array_pad(explode(':', $partyKey, 2), 2, null);

        $scope = fn () => LedgerEntry::query()
            ->where('account_id', $head->id)
            ->when($type, fn ($q) => $q->where('party_type', $type)->where('party_id', (int) $id),
                fn ($q) => $q->whereNull('party_type'));

        $opening = (string) ($scope()->whereDate('trx_date', '<', $from)
            ->selectRaw('COALESCE(SUM(credit - debit), 0) as b')->value('b') ?? '0');

        $rows = $scope()
            ->whereBetween('trx_date', [$from, $to])
            ->orderBy('trx_date')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        $first = $rows->first();

        $brought = $first === null ? $opening : bcadd($opening, (string) ($scope()
            ->whereBetween('trx_date', [$from, $to])
            ->whereRaw('(trx_date, id) < (?, ?)', [$first->trx_date->toDateString(), $first->id])
            ->selectRaw('COALESCE(SUM(credit - debit), 0) as b')->value('b') ?? '0'), 4);

        $running = $brought;

        foreach ($rows as $entry) {
            $running = bcadd($running, bcsub((string) $entry->credit, (string) $entry->debit, 4), 4);
            $entry->setAttribute('running_balance', $running);
        }

        return ['opening' => $opening, 'brought' => $brought, 'rows' => $rows];
    }
}
