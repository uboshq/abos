<?php

declare(strict_types=1);

namespace App\Core\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ "আগের জের (ওপেনিং)" আর "অগ্রিম জমা" — পক্ষের খাতার জেরের যে অংশ কোনো খোলা বিলে নেই (মালিকের নমুনা v2, ২৬ নভেম্বর ২০২৬:
 * আগের-জের-ওপেনিং-নমুনা-২৬-নভেম্বর.html; আন্তর্জাতিক মান — SAP-এর খোলা আইটেম, Dynamics-এর ওপেনিং ট্রানজ্যাকশন, Odoo-র ওপেনিং এন্ট্রি)।
 *
 * ⓘ অঙ্ক = খাতার জের − সব খোলা বিলের বাকির যোগ (সব শাখার)। যোগ হলে কমলা "আগের জের" (`opening`), বিয়োগ হলে সবুজ "অগ্রিম জমা"
 *   (`advance`), শূন্য হলে সারিই নেই — তাই তালিকার মোট সবসময় খাতার জের, দুই দিকেই।
 * ⓘ আগের জেরে টিক দিয়ে টাকা লেখা যায় (`public_id` = [[TICK_ID]]); সেই টাকা কোনো বিলে বসে না, পক্ষের খাতায় থাকে, আর জের থেকেই কমে।
 *   টিক না দিলে আগের মতো সবচেয়ে পুরনো আগে — আগের জের সবচেয়ে পুরনো। অগ্রিম সারিতে টিক নেই (ওটা পাওনা নয়, জমা)।
 * ⛔ খাতা, বিল বা রসিদে কিছু লেখা হয় না; লেখার যাচাই ([[VoucherAllocations::resolve()]]) এই সারি চেনে না, পর্দা পাঠায়ও না।
 * ⓘ বয়স জেরের প্রথম দিন থেকে (বয়সের ঘর বাকি নিয়ন্ত্রণের সাত ধাপে, [[CreditPositionReports::bucketOf()]]); ⓘ-র লেখায় উৎস আর তারিখ; খাতার লিংক কেবল যার জন্য বিবরণী আসলেই খোলে
 *   (`accounts.report`, বা পক্ষটা দেখার অনুমতি — [[PartyStatementController]]-এর একই দরজা)।
 */
final class OpeningRow
{
    public const KIND = 'opening';

    public const ADVANCE = 'advance';

    /** আগের জেরের সারির টিকের চাবি — বিলের public_id নয়, তাই কখনো পাঠানো হয় না */
    public const TICK_ID = 'opening';

    /**
     * @param  string  $balance  পক্ষের খাতার জের, বিলের দিকে ধনাত্মক (গ্রাহকের পাওনা, সরবরাহকারীর দেনা)
     * @param  string  $onBills  খোলা বিলের বাকির যোগ (সব বিল, তালিকার সীমা নয়)
     * @param  string|null  $since  পক্ষের খাতার প্রথম দিন
     * @param  Model|null  $party  পক্ষ নিজে — লিংকের অনুমতি মাপতে
     * @return array<string, mixed>|null
     */
    public static function make(string $partyType, int $partyId, string $balance, string $onBills, ?string $since, ?Model $party = null): ?array
    {
        $amount = bcsub($balance, $onBills, 4);

        // ⓘ পয়সার নিচের ফাঁক (গোল করার দাগ) সারি নয় — দুই দিকেই
        if (bccomp(ltrim($amount, '-'), '0.005', 4) < 0) {
            return null;
        }

        $kind = bccomp($amount, '0', 4) > 0 ? self::KIND : self::ADVANCE;
        $date = $since === null ? null : Carbon::parse($since)->startOfDay();
        $age = $date === null ? 0 : (int) $date->diffInDays(Carbon::today());
        $shown = $date === null ? '—' : DateFormat::format($date);

        return [
            'kind' => $kind,
            'against_type' => $kind,
            'id' => 0,
            // ⓘ উল্টো দিকের নাম পক্ষ দেখে — গ্রাহকের "অগ্রিম জমা", সরবরাহকারীকে আমাদের "অগ্রিম দেওয়া"
            'no' => (string) __('core.opening_row.'.($kind === self::KIND ? 'label' : ($partyType === 'supplier' ? 'advance_paid_label' : 'advance_label'))),
            'date' => $date?->toDateString() ?? '',
            'age' => $age,
            'outstanding' => $amount,
            'due_date' => '',
            'days_late' => 0,
            'total' => '',
            'public_id' => $kind === self::KIND ? self::TICK_ID : '',
            'info' => (string) __('core.opening_row.'.($kind === self::KIND ? 'info' : ($partyType === 'supplier' ? 'advance_paid_info' : 'advance_info')), ['date' => $shown]),
            'url' => self::canOpenLedger($party) && Route::has('accounts.statement')
                ? route('accounts.statement', ['type' => $partyType, 'id' => $partyId]) : null,
        ];
    }

    private static function canOpenLedger(?Model $party): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->can('accounts.report') || ($party !== null && Gate::forUser($user)->allows('view', $party));
    }
}
