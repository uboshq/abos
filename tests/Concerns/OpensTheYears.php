<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\FinancialYear;
use Illuminate\Support\Carbon;

/**
 * দাবির নিজের অর্থবছর — জুলাই থেকে জুন; বছর ছাড়া খাতায় কিছু বসে না, আর সেই নিয়ম ঢিলা করা নয় ([[PostingEngine]])।
 *
 * ⓘ ডেমোর বছর "আজ" ধরে বসে, তাই পুরনো মাসের দাবি নিজের বছর নিজে খোলে। ⓘ আগে থেকে থাকা বছরে কিছু করে না।
 */
trait OpensTheYears
{
    protected function openTheYearsOf(string ...$dates): void
    {
        foreach ($dates as $date) {
            $day = Carbon::parse($date);

            if (FinancialYear::forDate($day) !== null) {
                continue;
            }

            $from = Carbon::create($day->month >= 7 ? $day->year : $day->year - 1, 7, 1);

            FinancialYear::query()->create([
                'company_id' => \App\Core\Support\CompanyContext::id(),
                'name' => 'CLAIM '.$from->format('Y').'-'.$from->copy()->addYear()->format('Y'),
                'starts_on' => $from->toDateString(),
                'ends_on' => $from->copy()->addYear()->subDay()->toDateString(),
                'is_current' => false,
            ]);
        }
    }
}
