<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CollectionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrintsTheStandardPaper;
use Tests\TestCase;

/**
 * ⛔ ঠিকানায় `?paper[]=` — কারণসহ ৪২২, ৫০০ নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, ছাপা ১৬; [[SalesPrintController::askedPaper()]])।
 *
 * ⓘ কাগজের মাপের ঘরে তালিকা এলে [[PaperSize::chosen()]] টাইপ-ত্রুটিতে ভাঙত, আর ছাপার পাতা সার্ভারের ভুল দেখাত।
 */
final class APaperListInTheAddressIsRefusedTest extends TestCase
{
    use PrintsTheStandardPaper;
    use RefreshDatabase;

    public function test_a_list_for_the_paper_size_is_a_422_and_a_plain_size_still_prints(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->printTheStandardPaper();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $receipt = app(CollectionService::class)->create(['customer_id' => Customer::query()->orderBy('id')->value('id'),
            'trx_date' => now()->toDateString(), 'amount' => '500', 'instrument' => 'cash'], []);

        $this->get(route('sales.print.receipt', $receipt).'?paper[]=a4')->assertStatus(422);
        $this->get(route('sales.print.receipt', $receipt).'?paper=a5')->assertOk();
    }
}
