<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Support\CompanyContext;
use App\Modules\CreditControl\Reports\CreditPositionReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ বকেয়া রেখে নিষ্ক্রিয় করা গ্রাহক বাকি নিয়ন্ত্রণের রিপোর্ট থেকে পড়ে যেতেন (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
 * [[CreditPositionReports::activeOrStillOwing()]])।
 *
 * ⚠️ তালিকা আর ড্যাশবোর্ড কেবল সক্রিয় গ্রাহক নিত — ৮,০০০ বাকি রেখে বন্ধ করা দোকান মেয়াদোত্তীর্ণ, ঝুঁকি আর মোট থেকে উধাও, অথচ
 * ঠিক তাঁর টাকাই আদায় করতে হবে। ⭐ এখন জের থাকলে থাকেন; জের শূন্য হলে আর আসেন না।
 */
final class AnInactiveDebtorLeftTheCreditReportsTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_an_inactive_customer_who_still_owes_stays_on_the_credit_list(): void
    {
        $this->bootCreditCompany();

        $owing = $this->customer('GONE-OWES', '10000');
        $this->owes($owing, '8000');
        $owing->forceFill(['is_active' => false])->save();

        $quiet = $this->customer('GONE-QUIET', '10000');
        $quiet->forceFill(['is_active' => false])->save();

        $ids = array_keys(CreditPositionReports::customers(['company_id' => $this->company->id, 'branch_id' => null]));

        $this->assertContains((int) $owing->id, $ids, '⛔ বকেয়া রেখে নিষ্ক্রিয় গ্রাহক বাকি নিয়ন্ত্রণের তালিকা থেকে পড়ে গেলেন');
        $this->assertNotContains((int) $quiet->id, $ids, 'জের-শূন্য নিষ্ক্রিয় গ্রাহক তালিকায় এলেন');
    }
}
