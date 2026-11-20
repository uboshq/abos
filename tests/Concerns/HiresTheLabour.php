<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\LabourCost;
use Illuminate\Validation\ValidationException;

/**
 * হাম্মালির পরীক্ষার যন্ত্র — ঠিকাদার, নিজের নগদ টিল, খাতার সারি গোনা (২০ নভেম্বর ২০২৬)।
 *
 * ⓘ মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে"): মাল নামানো আর তোলার মজুরি — নগদে, ঠিকাদারের কাছে বাকি (২১১৭), সরবরাহকারী দিলেন,
 *   বা লাগেনি ([[LabourCost]])। ঠিকাদার একজন সরবরাহকারী-পক্ষ, ধরন `LABOUR`।
 * ⚠️ টিলে আগে টাকা রাখা হয় ([[PutsMoneyInTheTill]]) — নগদের খাত শূন্যের নিচে নামে না।
 */
trait HiresTheLabour
{
    use PutsMoneyInTheTill;

    /** হাম্মালি ঠিকাদার — দুই ভাষায় একই নাম, যাতে পাতার ভাষা যাই হোক নামটা মেলে */
    protected function labourContractor(string $code = 'LAB-01', string $name = 'Karim Labour Gang'): Supplier
    {
        return Supplier::query()->create([
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'party_type_id' => PartyType::query()->where('code', LabourCost::CONTRACTOR_TYPE)->firstOrFail()->id,
        ]);
    }

    /** নিজের নগদ টিল, টাকাসহ */
    protected function labourTill(string $code = 'LAB-T', string $money = '5000'): Account
    {
        $till = app(CashTillService::class)->create(['code' => $code, 'name_en' => 'Labour till '.$code]);
        $account = Account::query()->findOrFail($till->account_id);
        $this->putMoneyIn($account, $money);

        return $account;
    }

    /** এক কাগজের এক খাতে ডেবিট বা ক্রেডিটের যোগফল — খাতের কোড বা id ধরে */
    protected function booked(string $sourceType, int $sourceId, string|int $account, string $side): string
    {
        $accountId = is_int($account) ? $account : (int) Account::query()->where('code', $account)->value('id');

        return bcadd((string) LedgerEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('account_id', $accountId)
            ->sum($side), '0', 4);
    }

    /** এক উৎসের খরচের স্তরের মোট মূল্য — `$column` = qty_in (যা ঢুকল) বা qty_remaining (যা এখনো আছে) */
    protected function layerValue(string $sourceType, int $sourceId, string $column = 'qty_in'): string
    {
        return CostLayer::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->get()
            ->reduce(fn (string $sum, CostLayer $l) => bcadd($sum, bcmul((string) $l->{$column}, (string) $l->unit_cost, 8), 8), '0');
    }

    /** থামতেই হবে — ঠিক এই ঘরে, ঠিক এই বার্তায় */
    protected function refusedOn(\Closure $work, string $field, ?string $message = null): void
    {
        try {
            $work();
            $this->fail("⛔ থামার কথা ছিল ({$field})".($message !== null ? ": {$message}" : ''));
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey($field, $errors, 'অন্য কারণে থামল: '.json_encode($errors, JSON_UNESCAPED_UNICODE));

            if ($message !== null) {
                $this->assertSame([$message], $errors[$field], 'অন্য বার্তায় থামল: '.json_encode($errors, JSON_UNESCAPED_UNICODE));
            }
        }
    }
}
