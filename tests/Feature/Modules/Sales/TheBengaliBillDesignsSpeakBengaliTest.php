<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ বাংলা ভাষার ব্যবহারকারীর বিলে ইংরেজি লেখা বসে থাকত — "Closing balance", "This invoice", "Special for DB"
 * (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, ছাপা ১৯; `invoice-acct_card`, `invoice-acct_classic`, `invoice-a5_special_db`)।
 *
 * ⓘ লেখাগুলো ছাঁচে সরাসরি লেখা ছিল, তাই ভাষা বদলালেও বদলাত না; এখন ভাষা-ফাইল থেকে আসে।
 */
final class TheBengaliBillDesignsSpeakBengaliTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $user->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($user);
    }

    public function test_the_accounts_designs_carry_no_hard_written_english(): void
    {
        foreach (['acct_card', 'acct_classic', 'special_db', 'acct_tiles', 'acct_sidebar_light', 'acct_t_account'] as $design) {
            $paper = (string) $this->get(route('sales.invoice_sample', ['design' => $design]))->assertOk()->getContent();

            foreach (['Closing balance', 'This invoice', 'Special for DB', '>Previous ', '+ Bill ', 'Previous balance', 'Received today', 'owed by customer', 'paid by customer', '− Received '] as $english) {
                $this->assertStringNotContainsString($english, $paper, "⛔ বাংলা বিলে ({$design}) ইংরেজি «{$english}» ছাপা হলো");
            }
        }
    }
}
