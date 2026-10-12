<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\MasterData\Models\PaymentMethod;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দরকারি ঘর খালি অথচ তালিকায় "—" — দেখে মনে হত সব ঠিক (পাতা-ঝাড়ু ১০ অক্টোবর ২০২৬, ধাপ ০-এর ৮)।
 *
 * ⓘ শুরুর চার উপায় (CASH/BANK/CHQ/MFS) ইচ্ছে করে খাত ছাড়া বসে — কোম্পানি পরে নিজের খাত বসায়। ⭐ তালিকায় সেই ঘরে লাল
 * "বসানো হয়নি — বসান" চিপ, আর চাপলে সেই সারির সম্পাদনা; খাত বসানো সারিতে চিপ নেই। ঐচ্ছিক ঘর (ভ্যাটের খাত) আগের মতো "—"।
 */
final class ARequiredFieldLeftEmptyLookedFineTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_required_account_shows_a_red_chip_that_leads_to_the_edit(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $empty = PaymentMethod::query()->where('code', 'BANK')->firstOrFail();
        $set = PaymentMethod::query()->where('code', 'CASH')->firstOrFail();
        $cash = Account::query()->money()->postable()->orderBy('code')->firstOrFail();
        $set->forceFill(['account_id' => $cash->id])->save();
        // ⓘ ধরনও খালি — ঐচ্ছিক ঘর, তাই চিপ নয়
        PaymentMethod::query()->where('code', 'BANK')->update(['account_id' => null, 'kind' => null]);

        $page = $this->get(route('master_data.payment_method.index'))->assertOk();

        $page->assertSee(__('master_data::message.required_not_set'));
        $page->assertSee(route('master_data.payment_method.edit', $empty->id), false);
        $this->assertStringNotContainsString(route('master_data.payment_method.edit', $set->id).'" data-required-missing', $page->getContent(),
            '⛔ খাত বসানো সারিতেও লাল চিপ');
        $page->assertSee($cash->name());
        $this->assertStringContainsString('data-required-missing="account_id"', $page->getContent());
        $this->assertStringNotContainsString('data-required-missing="kind"', $page->getContent(), '⛔ ঐচ্ছিক ঘর খালি, তবু লাল চিপ');

        // ⓘ ঐচ্ছিক ঘর — ভ্যাটের তালিকায় কোনো চিপ নয়
        $this->get(route('master_data.tax.index'))->assertOk()->assertDontSee(__('master_data::message.required_not_set'));
    }
}
