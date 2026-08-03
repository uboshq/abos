<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * টেন্যান্ট আলাদা রাখা — অলঙ্ঘনীয় শর্ত ৪ ("প্রতিটা কোয়েরিতে company_id scope")।
 *
 * এটা trait হিসেবে রাখা হয়েছে যাতে ভুলে যাওয়া কঠিন হয়: মডেলে trait বসালেই
 * প্রতিটা কোয়েরি ফিল্টার হয় আর প্রতিটা নতুন রো নিজের company_id পেয়ে যায়।
 * হাতে where('company_id', ...) লিখলে একদিন কেউ একটা কোয়েরিতে লিখতে ভুলবে,
 * আর সেই একটাই যথেষ্ট — তখন এক কোম্পানির ব্যবহারকারী আরেক কোম্পানির লেনদেন
 * দেখে ফেলবে, এবং সেটা কেউ টের পাবে না যতক্ষণ না গ্রাহক অভিযোগ করে।
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            $companyId = CompanyContext::id();

            // কনসোল কমান্ড, মাইগ্রেশন বা সিডারে কোনো কোম্পানি প্রসঙ্গ থাকে না।
            // ওখানে ফিল্টার না বসানোই ঠিক — কিন্তু ওয়েব রিকোয়েস্টে প্রসঙ্গ
            // না থাকা মানে কিছু একটা ভুল, তাই সেটা চেপে যাওয়া হয় না।
            if ($companyId === null) {
                if (app()->runningInConsole()) {
                    return;
                }

                throw new RuntimeException(
                    'No company in context while querying '.static::class.'. '
                    .'Every web request must resolve a company before touching tenant data.'
                );
            }

            $builder->where($builder->getModel()->getTable().'.company_id', $companyId);
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', CompanyContext::id());
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * স্কোপ ছাড়া কোয়েরি — শুধু সচেতনভাবে, যেমন সুপার-অ্যাডমিনের কনসোল কাজে।
     *
     * নামটা লম্বা ও অস্বস্তিকর রাখা হয়েছে ইচ্ছাকৃতভাবে: কোড রিভিউতে চোখে পড়া
     * দরকার, আর অভ্যাসবশত ব্যবহার হওয়া উচিত নয়।
     */
    public static function acrossAllCompanies(): Builder
    {
        return static::query()->withoutGlobalScope('company');
    }
}
