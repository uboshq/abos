<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Support\CompanyContext;
use App\Modules\Customer\Models\CustomerCreditRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ সীমার অনুরোধে "1e5" লিখলে ৫০০ (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[CreditLimitRequestController::store()]])।
 *
 * ⚠️ `numeric` "1e5" মানে, bcmath মানে না — সেবার `bcadd`-এ ValueError। ⭐ এখন কারণসহ ফেরত, কিছুই বসে না।
 */
final class OneEFiveBrokeTheLimitRequestTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_scientific_notation_is_refused_with_a_reason(): void
    {
        $this->bootCreditCompany();
        $shop = $this->customer('EXP-1', '10000');

        $this->from(route('credit_control.customer.show', $shop->id))
            ->post(route('credit_control.limit_request.store', $shop->id), ['kind' => 'limit', 'amount' => '1e5', 'reason' => 'পরীক্ষা'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, CustomerCreditRequest::query()->count());
    }
}
