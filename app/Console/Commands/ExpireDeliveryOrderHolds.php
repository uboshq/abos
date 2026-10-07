<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Sales\Services\DeliveryOrderStock;
use Illuminate\Console\Command;

/**
 * ডেলিভারি অর্ডারের মালের ঘড়ি — প্রতি ঘণ্টায়। বিক্রয়ের কাজের ধারা, ধাপ ঘ (৩ অক্টোবর ২০২৬)।
 *
 * ⓘ দুই ধাপ ([[DeliveryOrderStock::expireOld()]]): ২৪ ঘণ্টা পেরোলে কড়া আটকানো নামে (বিক্রি চলে, কেবল দেখায়), ৭২ ঘণ্টায়
 * টাকা না এলে ছাড়। ⚠️ দিনে একবার চালালে ২৪ ঘণ্টার কিনারা প্রায় একদিন পিছিয়ে ধরা পড়ত — তাই ঘণ্টায়।
 */
final class ExpireDeliveryOrderHolds extends Command
{
    protected $signature = 'abos:do-holds-expire
        {--company= : কেবল এই কোম্পানি কোড}';

    protected $description = 'Ease a delivery order\'s hard stock hold after its hours, and release it after its days';

    public function handle(DeliveryOrderStock $stock): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('id')
            ->get();

        $softened = 0;
        $released = 0;

        foreach ($companies as $company) {
            // ⓘ কোম্পানি ধরে, আর প্রসঙ্গটা ফেরত — একটায় ব্যতিক্রম হলে পরের কাজ ভুল কোম্পানিতে লিখত না ([[ExpireLapsedPromotions]])
            $done = CompanyContext::forCompany($company->id, fn () => $stock->expireOld());

            if ($done['softened'] + $done['released'] > 0) {
                $this->line(sprintf('%s: %d eased, %d released', $company->code, $done['softened'], $done['released']));
            }

            $softened += $done['softened'];
            $released += $done['released'];
        }

        $this->info(sprintf('abos:do-holds-expire — %d eased, %d released', $softened, $released));

        return self::SUCCESS;
    }
}
