<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * একটা বিক্রির একটাই নম্বর — মালিকের সিদ্ধান্ত, ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ⭐ নিয়ম: নম্বর জন্মায় DO বা সরাসরি বিক্রিতে — *"sales team er kaj zekhane ses, sekhan
 * thekei"* (উদ্ধৃতি আর আদেশের নিজের নম্বর থাকে)। তারপর চালান, গেট পাস, বিল, ফেরত — সবাই
 * একই S-নম্বর। ⓘ একই বিক্রিতে একই ধরনের দ্বিতীয় কাগজ হলে S-0012/2, তৃতীয়টা /3; প্রথমটা
 * লেজ ছাড়া, কারণ বেশিরভাগ বিক্রিতে দ্বিতীয় কাগজই হয় না, আর পরে "/1" বসালে আগে ছাপা
 * কাগজের সাথে মিলত না।
 *
 * ⚠️ প্রতিটা টেবিলে (`company_id`, `document_no`) অনন্য — চালান S-0012 আর বিল S-0012 আলাদা
 * টেবিলে, তাই সংঘাত নেই; শেষ পাহারা ডাটাবেসের ইনডেক্স।
 */
final class SaleNumber
{
    /** নম্বর-সারির কাগজের ধরন — কন্ট্রোল প্যানেলের নম্বর সিরিজে উপসর্গ বদলানো যায় */
    public const DOC_TYPE = 'S';

    public function __construct(private readonly NumberSeriesEngine $numbers) {}

    /**
     * নতুন বিক্রির মূল নম্বর — DO বা সরাসরি বিক্রির জন্মে।
     *
     * ⓘ হাতে লেখা থাকলে সেটাই (যদি অন্য কোনো বিক্রিতে না থাকে), সিরিজ না ছুঁয়ে; সিরিজের
     * আগে থেকে দেখানো নম্বর হাতে-লেখা নয় — সেটা সিরিজেরই।
     *
     * @param  class-string<Model>  $model  যে কাগজ বিক্রিটা শুরু করছে (সাধারণত চালান)
     */
    public function begin(string $model, string $given = ''): string
    {
        $given = trim($given);

        if ($given !== '' && ! $this->numbers->isNextNumber(self::DOC_TYPE, $given)) {
            if ($this->taken($model, $given)) {
                throw ValidationException::withMessages([
                    'challan_no' => __('sales::validation.challan_no_taken', ['no' => $given]),
                ]);
            }

            return $given;
        }

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = $this->numbers->next(self::DOC_TYPE);

            if (! $this->taken($model, $candidate)) {
                return $candidate;
            }
        }

        return $this->numbers->next(self::DOC_TYPE);
    }

    /**
     * এই বিক্রির এই ধরনের কাগজের নম্বর — প্রথমটা S-0012, পরেরগুলো S-0012/2, /3 …
     *
     * @param  class-string<Model>  $model
     */
    public function forPaper(string $model, string $saleNo): string
    {
        if (! $this->taken($model, $saleNo)) {
            return $saleNo;
        }

        for ($n = 2; $n < 1000; $n++) {
            $candidate = $saleNo.'/'.$n;

            if (! $this->taken($model, $candidate)) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages(['sale_no' => __('sales::validation.challan_no_taken', ['no' => $saleNo])]);
    }

    /**
     * ⚠️ কোম্পানির নিজের কাগজেই — স্কোপ ছাড়া (শাখার স্কোপ অন্য শাখার একই নম্বর লুকাত, অথচ
     * ইনডেক্স কোম্পানি-জোড়া)।
     *
     * @param  class-string<Model>  $model
     */
    private function taken(string $model, string $documentNo): bool
    {
        return $model::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->where('document_no', $documentNo)
            ->exists();
    }
}
