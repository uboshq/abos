<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Currency;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * বিনিময় হারে `1e3` লিখলে ৫০০ — মাস্টার লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ `numeric` নিয়ম `1e3` ছাড়ত, আর [[ExchangeRateService::record()]]-এর `bccomp()` ওগুলোয় ValueError ছুড়ত;
 * ১২ অঙ্কের বেশি হলে decimal(18,6) কলাম উপচাত। ⓘ "১১৭.৫০" (বাংলা অঙ্ক) এখন পড়া হয় — দাবি সেটাও দেখে।
 */
final class ARateWrittenAsOneE3GaveAServerErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rate_is_a_plain_number_or_a_message(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SettingsService::class)->set('master_data.multi_currency_enabled', true);

        $usd = Currency::query()->where('code', 'USD')->firstOrFail();
        $save = fn (string $rate) => $this->post(route('master_data.currency.rates.store', ['id' => $usd->id]), [
            'effective_from' => '2026-08-01', 'rate' => $rate,
        ]);

        foreach (['1e3', '0x1F', '1234567890123', '0', '-5'] as $bad) {
            $save($bad)->assertRedirect()->assertSessionHasErrors('rate');
        }

        $save('১১৭.৫০')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp((string) $usd->fresh()->rateOn(Carbon::parse('2026-08-01')), '117.50', 6),
            'বাংলা অঙ্কের হার অন্য সংখ্যা হয়ে বসল।');
    }
}
