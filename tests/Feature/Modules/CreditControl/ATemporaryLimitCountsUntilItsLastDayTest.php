<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Support\CompanyContext;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditChange;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ অস্থায়ী সীমা — শেষ দিন পর্যন্ত গোনা, পরদিন নিজে থেকেই সাধারণ সীমা (মালিকের উত্তর ৩, ১১ অক্টোবর ২০২৬; ধাপ ২.৩)।
 *
 *   · সাধারণ ১০,০০০, অস্থায়ী ৫০,০০০ আজ পর্যন্ত, বকেয়া ৩০,০০০ → আজ চলে (DO-র যাচাই, দেয়াল, পাতা — একই সীমা)।
 *   · পরদিন → কোনো কাজ ছাড়াই আবার ১০,০০০; একই কাগজ থামে, পাতায় "সীমা পার"।
 *   · অস্থায়ী সাধারণের চেয়ে কম হলে সাধারণই। · "সীমা পার" তালিকার ছাঁকনিও একই নিয়মে।
 *   · অস্থায়ী সীমার বসা আর শেষ দিন ইতিহাসে ওঠে।
 */
final class ATemporaryLimitCountsUntilItsLastDayTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_it_counts_through_its_last_day_and_not_the_day_after(): void
    {
        $c = $this->customer('TEMP-1', '10000');
        $this->owes($c, '30000');
        $c->forceFill(['temp_credit_limit' => '50000', 'temp_credit_limit_until' => now()->toDateString()])->save();

        $credit = app(CreditExposure::class);
        $this->assertSame(0, bccomp($c->fresh()->effectiveCreditLimit(), '50000', 4));
        $this->assertTrue($credit->check($c->fresh(), '5000')['fits'], '⛔ শেষ দিনেও অস্থায়ী সীমা গোনা হয়নি।');
        $credit->assertRoom($c->fresh(), '5000');
        $position = $credit->positionOf($c->fresh());
        $this->assertSame(0, bccomp($position['limit'], '50000', 4));
        $this->assertSame('active', $position['state']);
        $this->assertFalse(Customer::query()->overCreditLimit()->whereKey($c->id)->exists());

        Carbon::setTestNow(now()->addDay());

        $this->assertSame(0, bccomp($c->fresh()->effectiveCreditLimit(), '10000', 4), '⛔ শেষ দিনের পরেও অস্থায়ী সীমা চলছে।');
        $this->assertFalse($credit->check($c->fresh(), '5000')['fits']);
        try {
            $credit->assertRoom($c->fresh(), '5000');
            $this->fail('⛔ শেষ দিনের পরদিনও অস্থায়ী সীমায় বাকি গেল।');
        } catch (ValidationException) {
        }
        $this->assertSame('over_limit', $credit->positionOf($c->fresh())['state']);
        $this->assertTrue(Customer::query()->overCreditLimit()->whereKey($c->id)->exists(), '⛔ "সীমা পার" তালিকা অস্থায়ী সীমা ধরে রাখল।');
    }

    public function test_a_temporary_limit_below_the_normal_one_never_cuts_it(): void
    {
        $c = $this->customer('TEMP-2', '40000');
        $c->forceFill(['temp_credit_limit' => '20000', 'temp_credit_limit_until' => now()->addDays(5)->toDateString()])->save();

        $this->assertSame(0, bccomp($c->fresh()->effectiveCreditLimit(), '40000', 4));
    }

    public function test_setting_it_is_written_in_the_history(): void
    {
        $c = $this->customer('TEMP-3', '10000');
        $c->forceFill(['temp_credit_limit' => '50000', 'temp_credit_limit_until' => now()->addDays(7)->toDateString()])->save();

        $rows = CustomerCreditChange::query()->where('customer_id', $c->id)->get()->keyBy('field');
        $this->assertSame(0, bccomp((string) $rows['temp_credit_limit']->new_value, '50000', 4));
        $this->assertSame(now()->addDays(7)->toDateString(), $rows['temp_credit_limit_until']->new_value);
    }
}
