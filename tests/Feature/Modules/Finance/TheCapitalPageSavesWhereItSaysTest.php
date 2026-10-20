<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MovesTheHeaderBranch;
use Tests\TestCase;

/**
 * ⛔ মূলধনের পাতা যে শাখার কথা লেখে, কাগজ সেই শাখাতেই — RCV-0143 / মালিক: কোনোভাবেই অন্য শাখায় নয়।
 *
 * ⚠️ যা ভাঙা ছিল: পাতার মাথায় লেখা "কার খাতায় লিখছেন: কোম্পানি — নেত্রকোনা (B)", অথচ বাছা টাকার খাত ময়মনসিংহের (A) বাক্সের।
 * কাগজটা চুপচাপ B-তে বসত, আর টাকা এলে A-র বাক্সে ঢুকত B-র খাতায়। ⭐ এখন থামে, কারণসহ — দেখানো আর বসানো শাখা কখনো নীরবে
 * আলাদা নয়; বাক্স পাতার শাখার হলে আগের মতোই বসে।
 *
 * ⚠️ ২০ অক্টোবর: থামা ফর্মের নতুন নামটা মানুষের তালিকায় রয়ে যেত, তাই ঠিক শাখায় ফিরে আবার জমা দিলে নকল-নামের দেয়াল থামাত
 * (ম্যাকের রানে ধরা)। ⭐ এখন নাম আর সারি এক লেনদেনে।
 *
 * ec, ১৯ অক্টোবর ২০২৬।
 */
final class TheCapitalPageSavesWhereItSaysTest extends TestCase
{
    use MovesTheHeaderBranch;
    use RefreshDatabase;

    public function test_a_box_of_another_branch_is_refused_and_a_box_of_this_branch_is_kept(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $a->id);
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $till = app(CashTillService::class)->create(['name_en' => 'A box', 'holder_id' => $owner->id]);
        CashTill::query()->withoutGlobalScopes()->whereKey($till->id)->update(['branch_id' => $a->id]);

        // ⓘ উপরের কাজের শাখা B, দেখা সব শাখায় — পাতা লেখে "… — B"
        $owner = $this->moveHeaderTo($owner, $b->id);
        $owner = $this->moveHeaderTo($owner, 'all');

        $form = fn () => [
            'person_new' => 'Rahim',
            'contributor_type' => CapitalEntry::PARTNER,
            'entry_type' => CapitalEntry::INVESTMENT,
            'trx_date' => now()->toDateString(),
            'amount' => '250000',
            'received_into_account_id' => $till->account_id,
        ];

        $before = CapitalEntry::query()->withoutGlobalScopes()->count();

        $this->from(route('finance.capital.create'))->post(route('finance.capital.store'), $form())
            ->assertSessionHasErrors('received_into_account_id');

        $this->assertSame($before, CapitalEntry::query()->withoutGlobalScopes()->count(),
            '⛔ পাতা B লিখল, অথচ A-র বাক্সের মূলধন নীরবে বসে গেল।');
        // ⛔ থামা ফর্মের নতুন নাম রয়ে গেলে ঠিক শাখায় আবার জমা দিলে "এই নামে আগে থেকেই একটা পক্ষ আছে" — মানুষ আটকে যান (ec, ২০ অক্টোবর ২০২৬)
        $this->assertSame(0, Person::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('name_en', 'Rahim')->count(),
            '⛔ মূলধন থামল, অথচ নতুন নামটা মানুষের তালিকায় রয়ে গেল।');

        // ⓘ উপরে A — পাতা "… — A" লেখে, বাক্সও A-র: আগের মতোই বসে
        $owner = $this->moveHeaderTo($owner, $a->id);

        $this->post(route('finance.capital.store'), $form())->assertSessionHasNoErrors();

        $entry = CapitalEntry::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame((int) $a->id, (int) $entry->branch_id);
    }
}
