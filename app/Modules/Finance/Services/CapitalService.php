<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\CapitalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * মূলধন ও বিনিয়োগ — ব্যবসার প্রথম কাজ।
 *
 * ── কেন এটা লাগল ─────────────────────────────────────────────────────
 * মালিক ব্যবসার পথটা ক্রমে বললেন: প্রথমে মূলধন, তারপর বিনিয়োগ, তারপর
 * গুদাম… এগারোটা ধাপের দশটা ABOS-এ ছিল। **প্রথমটা ছিল না।**
 *
 * খাত ছিল, ভাউচার দিয়ে টাকাটা ঢোকানোও যেত। কিন্তু পর্দা না থাকায়
 * ব্যবসার সবচেয়ে প্রথম কাজটা হত একটা হাতে লেখা জাবেদা, বিবরণে
 * "ওপেনিং" লিখে — আর কে কত দিয়েছেন, কার অংশ কত, কিছুই লেখা থাকত না।
 */
final class CapitalService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly VoucherService $vouchers,
    ) {}

    /**
     * কথা হয়েছে — লিখে রাখা।
     *
     * ── কেন খাতায় এখনই বসে না ───────────────────────────────────────
     * "মালিক পাঁচ লাখ দেবেন" কথাটা যেদিন হয়, টাকাটা আসে অন্যদিন —
     * কখনো অন্য মাসে। এখনই খাতায় বসালে ব্যবসার নগদ পাঁচ লাখ বেশি
     * দেখাত, আর ওই টাকায় মাল কেনার সিদ্ধান্ত নেওয়া হত।
     *
     * @param  array<string, mixed>  $data
     */
    public function record(array $data): CapitalEntry
    {
        return DB::transaction(function () use ($data) {
            $this->assertKnown($data);

            return CapitalEntry::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => $this->numbers->next('CAP'),
                'contributor_name' => trim((string) $data['contributor_name']),
                'contributor_type' => $data['contributor_type'],
                'entry_type' => $data['entry_type'],
                'trx_date' => $data['trx_date'],
                'amount' => $data['amount'],
                'share_percent' => ($data['share_percent'] ?? '') !== '' ? $data['share_percent'] : null,
                'narration' => ($data['narration'] ?? '') ?: null,
                'status' => CapitalEntry::DRAFT,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * টাকাটা এসেছে — খাতায় বসানো।
     *
     * ── কেন কোন খাতে জিজ্ঞেস করা হয় ────────────────────────────────
     * "মালিক পাঁচ লাখ এনেছেন" কথাটা এন্ট্রি নয়, যতক্ষণ না কেউ বলে
     * টাকাটা সিন্দুকে গেল না ব্যাংকে। ধরে নেওয়া যেত "নগদ", আর তাতে
     * ব্যাংকে আসা টাকা সিন্দুকে দেখাত — মাস শেষে মিলত না।
     *
     * ── অঙ্কটা ────────────────────────────────────────────────────
     * টাকা যেখানে এল সেটা ডেবিট (সম্পদ বাড়ল), আর মালিকের মূলধন
     * ক্রেডিট (ব্যবসা মালিকের কাছে দায়বদ্ধ হলো)।
     */
    public function post(CapitalEntry $entry, Account $into): CapitalEntry
    {
        if ($entry->status === CapitalEntry::POSTED) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.capital_already_posted', ['no' => $entry->document_no]),
            ]);
        }

        if ($into->is_group) {
            throw ValidationException::withMessages([
                'received_into_account_id' => __('finance::validation.not_a_postable_account'),
            ]);
        }

        return DB::transaction(function () use ($entry, $into) {
            $capital = Account::query()
                ->where('code', StandardChart::OWNER_CAPITAL)
                ->firstOrFail();

            $voucher = $this->vouchers->create(
                [
                    'type' => Voucher::RECEIPT,
                    'trx_date' => $entry->trx_date->toDateString(),
                    'narration' => $entry->narration
                        ?? __('finance::message.capital_narration', [
                            'who' => $entry->contributor_name,
                            'no' => $entry->document_no,
                        ]),
                ],
                [
                    ['account_id' => $into->id, 'debit' => $entry->amount, 'credit' => '0'],
                    ['account_id' => $capital->id, 'debit' => '0', 'credit' => $entry->amount],
                ],
            );

            $this->vouchers->post($voucher);

            $entry->forceFill([
                'status' => CapitalEntry::POSTED,
                'voucher_id' => $voucher->id,
                'received_into_account_id' => $into->id,
                'posted_at' => now(),
            ])->save();

            return $entry->fresh();
        });
    }

    /**
     * কে কোথায় দাঁড়িয়ে — দিয়েছেন কত, তুলেছেন কত, বাকি কত।
     *
     * ── কেন কেবল পোস্ট করাগুলো গোনা হয় ─────────────────────────────
     * খসড়া মানে টাকা আসেনি। ওটা গুনলে কারও অংশ বেশি দেখাত, আর
     * অংশীদারি ব্যবসায় ওই সংখ্যাটা নিয়েই ঝগড়া হয়।
     *
     * ── উত্তোলন কোথা থেকে ───────────────────────────────────────────
     * উত্তোলনের খাত (`3200`) থেকে, নাম মিলিয়ে। আলাদা টেবিলে রাখলে
     * খাতা আর তালিকা দুই কথা বলত — আর তখন কোনটা সত্যি তা বলা যেত না।
     *
     * @return list<array{name: string, type: string, contributed: string, withdrawn: string, net: string, share: ?string}>
     */
    public function positions(): array
    {
        $given = CapitalEntry::query()
            ->posted()
            ->selectRaw('contributor_name, contributor_type, MAX(share_percent) as share, SUM(amount) as total')
            ->groupBy('contributor_name', 'contributor_type')
            ->get();

        $out = [];

        foreach ($given as $row) {
            $taken = $this->withdrawnBy((string) $row->contributor_name);

            $out[] = [
                'name' => (string) $row->contributor_name,
                'type' => (string) $row->contributor_type,
                'contributed' => (string) $row->total,
                'withdrawn' => $taken,
                'net' => bcsub((string) $row->total, $taken, 4),
                'share' => $row->share !== null ? (string) $row->share : null,
            ];
        }

        return $out;
    }

    /**
     * এই নামে উত্তোলনের খাত থেকে কত গেছে।
     *
     * বিবরণে নাম খোঁজা হয়, কারণ উত্তোলন একটা সাধারণ পরিশোধ ভাউচার —
     * ওর নিজের কোনো "কে" কলাম নেই। নাম না মিললে শূন্য, আর সেটাই ঠিক:
     * যে উত্তোলনে কারও নাম লেখা নেই সেটা কারও নামে বসানো যায় না।
     */
    private function withdrawnBy(string $name): string
    {
        $drawings = Account::query()->postable()->where('code', StandardChart::DRAWINGS)->first();

        if ($drawings === null) {
            return '0.0000';
        }

        $sum = DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->where('account_id', $drawings->id)
            ->where('narration', 'like', '%'.$name.'%')
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
            ->value('net');

        return (string) ($sum ?: '0.0000');
    }

    /** @param  array<string, mixed>  $data */
    private function assertKnown(array $data): void
    {
        if (! in_array($data['contributor_type'] ?? '', CapitalEntry::WHO, true)) {
            throw ValidationException::withMessages([
                'contributor_type' => __('finance::validation.unknown_contributor_type'),
            ]);
        }

        if (! in_array($data['entry_type'] ?? '', CapitalEntry::KINDS, true)) {
            throw ValidationException::withMessages([
                'entry_type' => __('finance::validation.unknown_capital_kind'),
            ]);
        }

        if (bccomp((string) ($data['amount'] ?? '0'), '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('finance::validation.capital_must_be_positive'),
            ]);
        }
    }
}
