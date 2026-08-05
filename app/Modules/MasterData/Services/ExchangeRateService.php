<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\ExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * বিনিময় হার বসানো ও খোঁজা।
 *
 * হার বদলানো মানে নতুন সারি, পুরনোটা সম্পাদনা নয় — তাই ইতিহাস অক্ষত
 * থাকে। একই তারিখে দ্বিতীয়বার বসালে সেটাই সংশোধন (একই দিনে দুইটা হার
 * থাকতে পারে না), আর সংশোধনটা লগে থেকে যায়।
 */
final class ExchangeRateService
{
    /**
     * একটা তারিখের হার বসানো।
     *
     * @param  string  $rate  ভিত্তি মুদ্রায় এক এককের দাম
     */
    public function record(Currency $currency, string $effectiveFrom, string $rate, ?string $source = null): ExchangeRate
    {
        $this->assertNotBaseCurrency($currency);
        $this->assertRateIsPositive($rate);

        return DB::transaction(function () use ($currency, $effectiveFrom, $rate, $source) {
            $record = ExchangeRate::query()->updateOrCreate(
                [
                    'currency_id' => $currency->id,
                    'effective_from' => Carbon::parse($effectiveFrom)->toDateString(),
                ],
                [
                    'rate' => $rate,
                    'source' => $source,
                    'created_by' => auth()->id(),
                ],
            );

            return $record->fresh();
        });
    }

    /**
     * এই তারিখে এই মুদ্রার হার — না থাকলে null।
     *
     * null ফেরানো হয়, ১ নয়। ১ ফেরালে হার-বসাতে-ভুলে-যাওয়া একটা
     * ডলারের বিল ১১৭ টাকার বদলে ১ টাকায় বইয়ে বসত, আর সংখ্যাটা এত
     * ছোট যে কেউ খেয়ালও করত না।
     */
    public function rateOn(Currency $currency, ?Carbon $date = null): ?string
    {
        return $currency->rateOn($date);
    }

    /**
     * ভিত্তি মুদ্রা — কোম্পানির নিজের মুদ্রা।
     *
     * একটাও মুদ্রা না থাকলে null: তখন পর্দাটা "আগে মুদ্রা বসান" বলে,
     * কারণ ভিত্তি ছাড়া হারের কোনো মানে নেই।
     */
    public function baseCurrency(): ?Currency
    {
        return Currency::query()->where('is_default', true)->first();
    }

    /**
     * ভিত্তি মুদ্রা নিজের হার পায় না — সেটা সংজ্ঞা অনুযায়ী ১।
     *
     * বসাতে দিলে কেউ একদিন ১.০২ লিখত, আর তখন প্রতিটা অঙ্ক নিজের
     * মুদ্রাতেই দুই শতাংশ বেড়ে যেত।
     */
    private function assertNotBaseCurrency(Currency $currency): void
    {
        if ($currency->is_default) {
            throw ValidationException::withMessages([
                'rate' => __('master_data::validation.base_currency_has_no_rate'),
            ]);
        }
    }

    private function assertRateIsPositive(string $rate): void
    {
        if (bccomp($rate, '0', 6) <= 0) {
            throw ValidationException::withMessages([
                'rate' => __('master_data::validation.rate_must_be_positive'),
            ]);
        }
    }
}
