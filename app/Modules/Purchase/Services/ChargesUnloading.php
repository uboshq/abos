<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Support\Money;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Supplier\Services\LabourCost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * ⭐ আনলোডিং — মাল গ্রহণ ([[PurchaseReceiptService]]) আর সরাসরি ক্রয়ের বিল ([[PurchaseBillService]]) একই নিয়মে (মালিক, ১৮ নভেম্বর ২০২৬)।
 *
 * ⓘ তিন ধাপ: সংরক্ষণে বাছাই আর ভাগ বসে ([[stampUnloading()]]) · পাকা করার আগে বাছাই পূর্ণ কি না ([[assertUnloadingChosen()]]) ·
 *   পাকা করার মুহূর্তে ভাগ আবার গোনা হয়, মালের দামে (খরচের স্তরে) যোগ হয় আর খাতায় Dr মজুদ / Cr টিল বা ২১১৭ ([[unloadingLines()]])।
 */
trait ChargesUnloading
{
    /**
     * ⛔ নিয়ম চালু কি না — চালুই ডিফল্ট; বন্ধ কেবল পরীক্ষার ঘরে ([[TestCase]]), যাতে আগের হাজারটা মাল-গ্রহণের পরীক্ষা যা মাপে
     * সেটাই মাপে। ⓘ যে পরীক্ষা নিয়মটা মাপে, সে নিজে চালু করে (`abos.unloading_required`)।
     */
    protected function unloadingRequired(): bool
    {
        return (bool) config('abos.unloading_required', true);
    }

    /**
     * সারির হাতের দর — পাঠানো থাকলে সেটা, খালি বা না পাঠালে পণ্যের নিজের আনলোড দর (পণ্যের ফর্ম), সেটাও না থাকলে null (সমান ভাগ)।
     *
     * @param  array<string, mixed>  $line
     */
    protected function unloadRateFor(array $line, Product $product): ?string
    {
        $given = $line['unload_rate'] ?? null;

        if ($given !== null && $given !== '' && is_numeric($given) && bccomp((string) $given, '0', 4) >= 0) {
            return bcadd((string) $given, '0', 4);
        }

        return $product->unload_rate === null ? null : bcadd((string) $product->unload_rate, '0', 4);
    }

    /**
     * সংরক্ষণে — দরজা পাঠালে বাছাই বসানো, আর কার্টন, দর ও ভাগ নতুন করে।
     *
     * @param  Collection<int, Model>  $lines  যে সারিগুলো মাল আনে (বিলে কেবল চালান-ছাড়া সারি)
     * @param  array<string, mixed>  $data
     */
    protected function stampUnloading(Model $paper, Collection $lines, string $qtyField, array $data): void
    {
        $rows = UnloadingSplit::rows($lines, $qtyField);
        $rate = UnloadingSplit::rate();

        if (array_key_exists('unload_mode', $data)) {
            $paper->forceFill(app(LabourCost::class)->read($data, 'unload_', LabourCost::UNLOADING, UnloadingSplit::expected($rows, $rate)));
        }

        $paper->forceFill(['unload_rate' => $rate, 'unload_cartons' => UnloadingSplit::cartonsOf($rows)])->save();

        $this->spreadUnloading($paper, $lines, $qtyField);
    }

    /**
     * ভাগ গোনা আর সারিতে লেখা — সারির id → ভাগ।
     *
     * @param  Collection<int, Model>  $lines
     * @return array<int, string>
     */
    protected function spreadUnloading(Model $paper, Collection $lines, string $qtyField): array
    {
        $rows = UnloadingSplit::rows($lines, $qtyField);
        $amount = LabourCost::costsUs($paper->getAttribute('unload_mode')) ? (string) $paper->getAttribute('unload_amount') : '0';
        $shares = UnloadingSplit::split($amount, $rows);

        foreach ($lines as $line) {
            $id = (int) $line->getKey();
            $line->forceFill([
                'unload_cartons' => $rows[$id]['cartons'] ?? '0',
                'unload_cost' => $shares[$id] ?? '0',
            ])->save();
        }

        return $shares;
    }

    /** ⛔ পাকা করার আগে — নিয়ম চালু থাকলে বাছাই পূর্ণ হতেই হবে */
    protected function assertUnloadingChosen(Model $paper): void
    {
        if ($this->unloadingRequired() || filled($paper->getAttribute('unload_mode'))) {
            app(LabourCost::class)->assertComplete($paper, 'unload_', 'unloading');
        }
    }

    /**
     * খাতার সারি — Dr মজুদ (১১২০) ঠিক ততটা যতটা মালের দামে বসল / Cr টিল বা ২১১৭।
     *
     * @param  array<int, string>  $shares
     * @return list<array<string, mixed>>
     */
    protected function unloadingLines(Model $paper, array $shares): array
    {
        $capitalised = array_reduce($shares, fn (string $sum, string $share) => bcadd($sum, $share, 4), '0');

        if (bccomp($capitalised, '0', 4) <= 0) {
            return [];
        }

        return app(LabourCost::class)->lines(
            $paper,
            'unload_',
            (int) StandardChart::accountFor(StandardChart::INVENTORY)->id,
            __('supplier::labour.unloading_narration', [
                'no' => (string) $paper->getAttribute('document_no'),
                'cartons' => rtrim(rtrim(Money::round((string) $paper->getAttribute('unload_cartons'), 2), '0'), '.') ?: '0',
            ]).(($to = app(LabourCost::class)->paidTo($paper, 'unload_')) !== '' && $paper->getAttribute('unload_mode') === LabourCost::CASH ? ' — '.$to : ''),
            $capitalised,
        );
    }
}
