<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ⛔ বিলের শোধের তারিখ গ্রাহকের বাকির দিনের বাইরে নয় — পূর্ণ নিরীক্ষা §৫, ১৮ অক্টোবর ২০২৬।
 *
 * ⓘ আগে ক্যাশিয়ার "কোন তারিখে" ঘরে, বা "কত দিন" ঘরে, বা "মাসের শেষে" বেছে ৩০ দিনের গ্রাহককে ৯০ দিন দিতে পারতেন — বাকির
 * দিন বাড়াতে মালিকের সই লাগে ([[CustomerService::assertDaysRaiseIsSigned()]]), অথচ একটা বিলে সেই সই এড়িয়ে যাওয়া যেত,
 * আর মেয়াদ-পেরোনোর দেয়াল ঐ বিলে দুই মাস দেরিতে জাগত।
 *
 * ⭐ শোধের তারিখ ≤ বিলের দিন + গ্রাহকের বাকির দিন; আগে হলে ঠিক আছে (তাড়াতাড়ি চাওয়া ঝুঁকি কমায়)।
 * ⭐ বাকির দিন ০ = বিলের দিনেই শোধ (মালিকের নিয়ম, fe-র মাধ্যমে ১৮ অক্টোবর ২০২৬; নিরীক্ষার D10) — পরের যেকোনো তারিখ ফেরে।
 * ⓘ বাকির দিন পরম, সীমার মতো: "মাসের শেষে" যদি দিনের বাইরে পড়ে, সেটাও ফেরে — বার্তা বলে কত দিনের অনুমতি আর চাওয়াটা কত দূরে।
 * ⓘ সম্পাদনায় বিলের নিজের আগের তারিখ রাখা যায় — পরে গ্রাহকের দিন কমলেও পুরনো বিল খোলা আটকায় না; বদলালে তবেই মাপা।
 */
final class DueWithinTerms
{
    public static function check(?string $due, Carbon $billDate, int $creditDays, ?string $kept = null): void
    {
        if ($due === null || $due === '') {
            return;
        }

        $on = Carbon::parse($due)->startOfDay();
        if ($kept !== null && $kept !== '' && $on->equalTo(Carbon::parse($kept)->startOfDay())) {
            return;
        }

        $from = $billDate->copy()->startOfDay();
        $latest = $from->copy()->addDays(max(0, $creditDays));

        if ($on->greaterThan($latest)) {
            throw ValidationException::withMessages([
                'due_on' => __('sales::due.beyond_terms', [
                    'days' => max(0, $creditDays),
                    'asked' => (int) $from->diffInDays($on),
                    'due' => $on->toDateString(),
                    'latest' => $latest->toDateString(),
                ]),
            ]);
        }
    }
}
