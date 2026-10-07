<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ সমন্বয় জাবেদা নিজের তারিখে নিজেই উল্টায় — ভাউচারের আন্তর্জাতিক পরিকল্পনা, অংশ ৩ঘ (fe, ৭ অক্টোবর ২০২৬)।
 *
 * ⓘ মাসশেষের সমন্বয় জাবেদায় (`is_adjusting`) "উল্টো দাখিলার তারিখ" (`reverse_on`) থাকলে, সেই তারিখ এলে একটা উল্টো জাবেদা
 * বসে — আসলটার প্রতিটা সারির ডেবিট ↔ ক্রেডিট, পক্ষসহ, সেই তারিখে, JV সিরিজে, নিজেও "সমন্বয়" দাগে (reversing entry)।
 * ⛔ আগে তারিখটা কেবল জমা থাকত — কেউ উল্টাত না, আর পরের মাসে বকেয়া দুইবার গোনা হতো।
 *
 * ⓘ একবারই: উল্টোটা `reversal_of_id`-এ আসলটাকে চেনে, আর (company_id, reversal_of_id) অনন্য — দুই শিডিউলার একসাথে চললেও
 * দ্বিতীয়টা বসতে পারে না। আসলটার সারি লক করে আবার দেখা হয়। আসলটার নিরীক্ষায় "নিজে উল্টানো" দাগ পড়ে।
 * ⓘ কেবল পাকা আর বাতিল-নয় এমন আসল; বন্ধ মাসে তারিখ পড়লে পোস্টিং নিজেই থামায় — ভাঙাটা ফেরত আসে, বাকিরা চলে।
 */
final class AdjustingReversals
{
    public function __construct(private readonly VoucherService $vouchers) {}

    /**
     * চলতি কোম্পানির — আজ বা তার আগে তারিখ পড়া, এখনো না উল্টানো সমন্বয় জাবেদা।
     *
     * @return array{reversed: int, failed: list<string>}
     */
    public function run(?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $reversed = 0;
        $failed = [];

        $due = Voucher::acrossBranches()
            ->where('type', Voucher::JOURNAL)
            ->where('is_adjusting', true)
            ->where('status', DocumentStatus::CONFIRMED)
            ->whereNotNull('reverse_on')
            ->whereDate('reverse_on', '<=', $today->toDateString())
            ->whereNotExists(fn ($q) => $q->from('vouchers as r')
                ->whereColumn('r.reversal_of_id', 'vouchers.id')
                ->where('r.company_id', CompanyContext::id()))
            ->orderBy('reverse_on')->orderBy('id')
            ->pluck('id');

        foreach ($due as $id) {
            try {
                if ($this->reverse((int) $id) !== null) {
                    $reversed++;
                }
            } catch (\Throwable $e) {
                $failed[] = $id.': '.$e->getMessage();
            }
        }

        return ['reversed' => $reversed, 'failed' => $failed];
    }

    /** একটা আসলের উল্টো — আগে উল্টানো থাকলে `null`। */
    public function reverse(int $voucherId): ?Voucher
    {
        return DB::transaction(function () use ($voucherId): ?Voucher {
            $original = Voucher::acrossBranches()->lockForUpdate()->findOrFail($voucherId);

            $already = Voucher::acrossBranches()->where('reversal_of_id', $original->id)->exists();

            if ($already || ! $original->isPosted() || ! $original->is_adjusting || $original->reverse_on === null) {
                return null;
            }

            $original->load('lines');

            $reversal = $this->vouchers->create([
                'type' => Voucher::JOURNAL,
                'trx_date' => $original->reverse_on->toDateString(),
                'branch_id' => $original->branch_id,
                'narration' => __('accounts::voucher.adjusting_reversal_narration', [
                    'no' => $original->document_no,
                    'date' => DateFormat::format($original->trx_date),
                ]),
                'party_type' => $original->party_type,
                'party_id' => $original->party_id,
                'is_adjusting' => true,
                'reversal_of_id' => $original->id,
            ], $original->lines->map(fn (VoucherLine $line) => [
                'account_id' => $line->account_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'narration' => $line->narration,
                'party_type' => $line->party_type,
                'party_id' => $line->party_id,
            ])->values()->all());

            $this->vouchers->post($reversal);

            app(AuditEngine::class)->recordAction($original, 'adjusting_reversed', $reversal->document_no);

            return $reversal;
        });
    }
}
