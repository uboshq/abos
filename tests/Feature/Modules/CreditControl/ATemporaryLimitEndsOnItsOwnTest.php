<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditChange;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ অস্থায়ী সীমা — শেষ দিন পর্যন্ত খাটে, পরের দিন নিজেই সাধারণ সীমায় ফেরে (বাকি নিয়ন্ত্রণ ধাপ ২.৩, মালিকের উত্তর ৩)।
 *
 *   · শেষ দিনের আগে আর শেষ দিনে দেয়াল অস্থায়ী সীমা মাপে; পরের দিন সাধারণ সীমা — কোনো কাজ (job) না চালিয়েই।
 *   · গ্রাহকের পাতা, তালিকা (সীমা পার) আর দেয়াল একই চলতি সীমা বলে।
 *   · অস্থায়ী সীমা সাধারণের বেশি আর শেষ দিন আজ বা পরে; বসানো আর তোলা দুটোই ইতিহাসে।
 *   · ⓘ ঝুলে থাকা অনুরোধ গোনা হয় না — অনুরোধের পাহারায় ([[ALimitRaiseWaitsForTheOwnersSignatureTest]])।
 */
final class ATemporaryLimitEndsOnItsOwnTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();

        $this->dealer = $this->customer('TEMP-1', '100000');
        $this->owes($this->dealer, '150000');
        app(CustomerService::class)->setTemporaryLimit($this->dealer, '200000', now()->addDays(5)->toDateString(), 'ঈদের মৌসুম');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_before_the_end_date_the_temporary_limit_counts(): void
    {
        app(CreditExposure::class)->assertRoom($this->dealer->fresh(), '40000');

        $p = app(CreditExposure::class)->positionOf($this->dealer->fresh());
        $this->assertSame(0, bccomp($p['limit'], '200000', 4));
        $this->assertSame(0, bccomp($p['normal_limit'], '100000', 4));
        $this->assertSame(0, bccomp((string) $p['temporary_limit'], '200000', 4));
        $this->assertSame('active', $p['state']);
        $this->assertFalse(Customer::query()->overCreditLimit()->whereKey($this->dealer->id)->exists());
    }

    public function test_it_still_counts_on_its_last_day(): void
    {
        Carbon::setTestNow(now()->addDays(5));

        $this->assertSame(0, bccomp($this->dealer->fresh()->effectiveCreditLimit(), '200000', 4));
        app(CreditExposure::class)->assertRoom($this->dealer->fresh(), '40000');
    }

    public function test_the_day_after_it_no_longer_counts(): void
    {
        Carbon::setTestNow(now()->addDays(6));

        $this->assertSame(0, bccomp($this->dealer->fresh()->effectiveCreditLimit(), '100000', 4));
        $this->assertFalse(app(CreditExposure::class)->check($this->dealer->fresh(), '1')['fits']);

        try {
            app(CreditExposure::class)->assertRoom($this->dealer->fresh(), '1');
            $this->fail('⛔ শেষ দিনের পরেও অস্থায়ী সীমা খেটেছে।');
        } catch (ValidationException) {
        }

        $p = app(CreditExposure::class)->positionOf($this->dealer->fresh());
        $this->assertSame(0, bccomp($p['limit'], '100000', 4));
        $this->assertNull($p['temporary_limit']);
        $this->assertSame('over_limit', $p['state']);
        $this->assertTrue(Customer::query()->overCreditLimit()->whereKey($this->dealer->id)->exists(), '⛔ তালিকা পুরনো অস্থায়ী সীমা মাপছে।');
    }

    public function test_even_the_temporary_limit_is_absolute(): void
    {
        $this->expectException(ValidationException::class);

        app(CreditExposure::class)->assertRoom($this->dealer->fresh(), '50001');
    }

    public function test_it_must_be_above_the_normal_limit_and_end_today_or_later(): void
    {
        $service = app(CustomerService::class);

        foreach ([['100000', now()->addDay()->toDateString()], ['300000', now()->subDay()->toDateString()], ['300000', null]] as [$amount, $until]) {
            try {
                $service->setTemporaryLimit($this->dealer, $amount, $until, 'x');
                $this->fail("⛔ অস্থায়ী সীমা {$amount} / {$until} বসে গেছে।");
            } catch (ValidationException) {
            }
        }

        $this->assertSame(0, bccomp((string) $this->dealer->fresh()->temporary_credit_limit, '200000', 4));
    }

    public function test_granting_and_ending_it_are_both_in_the_history(): void
    {
        app(CustomerService::class)->setTemporaryLimit($this->dealer, null, null, 'মৌসুম শেষ');

        $rows = CustomerCreditChange::query()->where('customer_id', $this->dealer->id)->orderBy('id')
            ->get(['field', 'old_value', 'new_value', 'reason'])->map(fn ($r) => [$r->field, $r->old_value, $r->new_value, $r->reason])->all();

        $until = now()->addDays(5)->toDateString();
        $this->assertSame([
            ['temporary_credit_limit', null, '200000.0000', 'ঈদের মৌসুম'],
            ['temporary_credit_until', null, $until, 'ঈদের মৌসুম'],
            ['temporary_credit_limit', '200000.0000', null, 'মৌসুম শেষ'],
            ['temporary_credit_until', $until, null, 'মৌসুম শেষ'],
        ], $rows);

        $this->assertSame(0, bccomp($this->dealer->fresh()->effectiveCreditLimit(), '100000', 4));
    }
}
