<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Report\ReportColumn;
use App\Modules\Supplier\Reports\PrincipalCommission;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * প্রিন্সিপালের কমিশনের অঙ্ক আর তার মাস — মালিক, ৫ অক্টোবর ২০২৬ ([[PrincipalCommission]])।
 *
 * ⓘ ডাটাবেজ ছাড়া: সংখ্যা আর তারিখ নিজেরাই সত্যি কি না। খাতা থেকে আদায় গোনার দাবিগুলো
 * [[APrincipalEarnsOnWhatTheDepotCollectsTest]]-এ।
 */
final class APrincipalsCommissionAndMonthAreArithmeticTest extends TestCase
{
    public function test_a_margin_is_a_share_of_the_collection(): void
    {
        $this->assertSame('3850.00', PrincipalCommission::commission('100000', PrincipalCommission::MARGIN, '3.850'));
    }

    public function test_a_markup_is_taken_back_out_of_the_collection(): void
    {
        // ⓘ ১,০০,০০০ × ৪ / ১০৪ = ৩,৮৪৬.১৫৩৮… → ৩,৮৪৬.১৫ (মার্জিন হলে ৪,০০০ হত)
        $this->assertSame('3846.15', PrincipalCommission::commission('100000', PrincipalCommission::MARKUP, '4.000'));
        $this->assertSame('4000.00', PrincipalCommission::commission('100000', PrincipalCommission::MARGIN, '4'));
    }

    public function test_a_half_paisa_rounds_up_not_down(): void
    {
        // ⓘ ১০০.১৩ × ৫% = ৫.০০৬৫ → ৫.০১ (কেটে দিলে ৫.০০)
        $this->assertSame('5.01', PrincipalCommission::commission('100.13', PrincipalCommission::MARGIN, '5'));
    }

    public function test_a_26_to_25_cycle_picks_the_dates_around_the_day(): void
    {
        $this->assertCycle(['2026-09-26', '2026-10-25'], PrincipalCommission::cycleContaining(26, 25, Carbon::parse('2026-10-05')));
        $this->assertCycle(['2026-10-26', '2026-11-25'], PrincipalCommission::cycleContaining(26, 25, Carbon::parse('2026-10-27')));

        // ⓘ ঠিক সীমানায়: ২৫ তারিখ পুরনো চক্রের শেষ দিন, ২৬ নতুনটার প্রথম
        $this->assertCycle(['2026-09-26', '2026-10-25'], PrincipalCommission::cycleContaining(26, 25, Carbon::parse('2026-10-25')));
        $this->assertCycle(['2026-10-26', '2026-11-25'], PrincipalCommission::cycleContaining(26, 25, Carbon::parse('2026-10-26')));

        // ⓘ বছর পেরোনো চক্র
        $this->assertCycle(['2026-12-26', '2027-01-25'], PrincipalCommission::cycleContaining(26, 25, Carbon::parse('2027-01-02')));
    }

    public function test_the_month_picker_names_the_cycle_that_closes_in_it(): void
    {
        $this->assertCycle(['2026-09-26', '2026-10-25'], PrincipalCommission::cycleClosingIn(26, 25, Carbon::parse('2026-10-01')));
        $this->assertCycle(['2026-10-01', '2026-10-31'], PrincipalCommission::cycleClosingIn(1, 31, Carbon::parse('2026-10-01')));
        $this->assertSame('2026-10-01', PrincipalCommission::month('2026-10')?->toDateString());
        $this->assertNull(PrincipalCommission::month(''));
    }

    public function test_close_day_31_in_february_is_the_last_day_of_february(): void
    {
        $this->assertCycle(['2026-02-01', '2026-02-28'], PrincipalCommission::cycleClosingIn(1, 31, Carbon::parse('2026-02-01')));
        $this->assertCycle(['2028-02-01', '2028-02-29'], PrincipalCommission::cycleContaining(1, 31, Carbon::parse('2028-02-29')));

        // ⓘ ৩১ শুরু, ৩০ শেষ: মার্চের চক্র ফেব্রুয়ারির শেষ দিনে শুরু হলে ঐ দিনটা দুই চক্রে পড়ত
        $this->assertCycle(['2026-01-31', '2026-02-28'], PrincipalCommission::cycleClosingIn(31, 30, Carbon::parse('2026-02-01')));
        $this->assertCycle(['2026-03-01', '2026-03-30'], PrincipalCommission::cycleClosingIn(31, 30, Carbon::parse('2026-03-01')));
        $this->assertCycle(['2026-03-31', '2026-04-30'], PrincipalCommission::cycleContaining(31, 30, Carbon::parse('2026-03-31')));
    }

    public function test_a_day_between_two_cycles_belongs_to_the_one_just_closed(): void
    {
        // ⓘ ১–২৫ চক্রে ২৭ তারিখ কোনো চক্রে নেই — সদ্য শেষ হওয়াটা, আগামীটা নয়
        $this->assertCycle(['2026-10-01', '2026-10-25'], PrincipalCommission::cycleContaining(1, 25, Carbon::parse('2026-10-27')));
    }

    public function test_a_month_in_the_wrong_shape_is_refused_not_ignored(): void
    {
        foreach (['2026-13', '26-10', '2026-1', 'october'] as $bad) {
            try {
                PrincipalCommission::month($bad);
                $this->fail("⛔ '{$bad}' মাস হিসেবে মেনে নেওয়া হলো।");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('month', $e->errors());
            }
        }
    }

    public function test_the_balance_reads_as_words_never_a_bare_minus(): void
    {
        app()->setLocale('bn');

        $column = ReportColumn::fromArray([
            'key' => 'balance', 'label' => 'x', 'type' => ReportColumn::DR_CR,
            'words' => ['supplier::principal.balance_to_pay', 'supplier::principal.balance_to_get'],
        ], 0);

        $this->assertSame('দিতে হবে ৳76,150.00', $column->signed('76150'));
        $this->assertSame('কোম্পানির কাছে পাব ৳23,846.15', $column->signed('-23846.15'));
        $this->assertSame('0.00', $column->signed('0'));
        $this->assertStringNotContainsString('-', $column->signed('-1234567.5'));

        // ⓘ শব্দ না দিলে আগের মতো "(Dr)/(Cr)" — খাতার জের বদলায় না
        $plain = ReportColumn::fromArray(['key' => 'b', 'label' => 'x', 'type' => ReportColumn::DR_CR], 0);
        $this->assertSame('(Cr) 23,846.15', $plain->signed('-23846.15'));
    }

    /** @param  array{0: Carbon, 1: Carbon}  $cycle */
    private function assertCycle(array $want, array $cycle): void
    {
        $this->assertSame($want, [$cycle[0]->toDateString(), $cycle[1]->toDateString()]);
    }
}
