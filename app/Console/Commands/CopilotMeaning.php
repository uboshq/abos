<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Assistant\MeaningIndex;
use App\Core\Engines\Assistant\ModelClient;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Inventory\Assistant\ProductInQuestion;
use App\Modules\Inventory\Models\Product;
use Illuminate\Console\Command;
use Throwable;

/**
 * ⭐ রাতে পণ্যের নামের মানে-ছাপ হালনাগাদ — কোপাইলট অক্ষর না মিললেও মানে ধরে পণ্য চেনে (১৩ নভেম্বর ২০২৬; [[MeaningIndex]])।
 *
 * ⓘ কেবল নতুন বা বদলানো নাম গেটওয়েতে যায়; প্রথম রাতে সব, পরে রোজ কয়েকটা। গেটওয়ে বন্ধ বা ঠিকানা খালি — কিছু বদলায় না, কোপাইলট
 *   শব্দের মিলে চলে।
 */
final class CopilotMeaning extends Command
{
    protected $signature = 'abos:copilot-meaning';

    protected $description = 'Refresh the meaning index (embeddings) of product names for Copilot, only new or renamed ones';

    public function handle(MeaningIndex $index, ModelClient $model): int
    {
        $made = 0;

        foreach (Company::query()->pluck('id') as $companyId) {
            CompanyContext::forCompany((int) $companyId, function () use ($index, $model, &$made): void {
                if (! $model->configured()) {
                    return;
                }

                try {
                    $texts = Product::query()->where('is_active', true)->get(['id', 'name_en', 'name_bn'])
                        ->mapWithKeys(fn (Product $p) => [(int) $p->id => ProductInQuestion::meaningText($p)])
                        ->filter(fn (string $t): bool => $t !== '')
                        ->all();
                    $made += $index->refresh(ProductInQuestion::KIND, $texts)['made'];
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        $this->info("made={$made}");

        return self::SUCCESS;
    }
}
