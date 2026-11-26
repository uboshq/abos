<?php

declare(strict_types=1);

namespace App\Core\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ "আগের জের (ওপেনিং)" — পক্ষের খাতার জেরের যে অংশ কোনো খোলা বিলে নেই (মালিকের নমুনা, ২৬ নভেম্বর ২০২৬:
 * আগের-জের-ওপেনিং-নমুনা-২৬-নভেম্বর.html)।
 *
 * ⓘ অঙ্ক = খাতার জের − খোলা বিলগুলোর বাকির যোগ; শূন্য বা কম হলে সারিই নেই। বেশিরভাগ ক্ষেত্রে পুরনো সিস্টেম থেকে আনা খোলা জের।
 * ⛔ কেবল দেখায়: খাতা, বিল বা রসিদে কিছু লেখা হয় না; টিক বা "এখন নেওয়া" নেই, আর লেখার যাচাই ([[VoucherAllocations::resolve()]]) একে চেনে না।
 * ⓘ সারির আকার খোলা বিলের সারির মতোই, যাতে একই তালিকায় সবার উপরে বসে; `kind` = `opening`, `url` = পক্ষের হিসাব বিবরণী।
 */
final class OpeningRow
{
    public const KIND = 'opening';

    /**
     * @param  string  $balance  পক্ষের খাতার জের, বিলের দিকে ধনাত্মক (গ্রাহকের পাওনা, সরবরাহকারীর দেনা)
     * @param  string  $onBills  খোলা বিলের বাকির যোগ (সব বিল, তালিকার সীমা নয়)
     * @param  string|null  $since  পক্ষের খাতার প্রথম দিন
     * @return array<string, mixed>|null
     */
    public static function make(string $partyType, int $partyId, string $balance, string $onBills, ?string $since): ?array
    {
        $amount = bcsub($balance, $onBills, 4);

        // ⓘ পয়সার নিচের ফাঁক (গোল করার দাগ) সারি নয়
        if (bccomp($amount, '0.005', 4) < 0) {
            return null;
        }

        $today = Carbon::today();
        $date = $since === null ? null : Carbon::parse($since)->startOfDay();

        return [
            'kind' => self::KIND,
            'against_type' => self::KIND,
            'id' => 0,
            'no' => (string) __('core.opening_row.label'),
            'date' => $date?->toDateString() ?? '',
            'age' => $date === null ? 0 : (int) $date->diffInDays($today),
            'outstanding' => $amount,
            'due_date' => '',
            'days_late' => 0,
            'total' => '',
            'public_id' => '',
            'url' => Route::has('accounts.statement') ? route('accounts.statement', ['type' => $partyType, 'id' => $partyId]) : null,
        ];
    }
}
