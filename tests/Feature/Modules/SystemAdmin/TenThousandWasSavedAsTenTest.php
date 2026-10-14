<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পূর্ণসংখ্যার সেটিংয়ে "10,000" জমা হত ১০ — সিস্টেম-অ্যাডমিন লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ দুই সেটিং-পর্দাই `integer` ঘর `(int)` দিয়ে কাটত: "10,000" → ১০, "1e3" → ১০০০, "abc" → ০, ঋণাত্মকও চলত। দশমিকের ঘরে
 * ([[PlainNumber::from()]]) আগে থেকেই পাহারা ছিল, পূর্ণসংখ্যায় ছিল না। ⓘ ব্রাউজারের সংখ্যা-ঘর পাশ কাটালে (পুরনো খোলা পাতা,
 * হাতে বানানো অনুরোধ) `customer.overdue_block_days` −৫ হতে পারত।
 */
final class TenThousandWasSavedAsTenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_whole_number_setting_reads_commas_and_refuses_what_is_not_a_whole_number(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $key = 'customer.overdue_block_days';
        $put = fn (string $value) => $this->put(route('system_admin.control-panel.update'), [
            'scope' => [$key], 'settings' => [$key => $value],
        ]);
        $now = fn () => tap(app(SettingsService::class))->flush()->get($key);

        $put('১০,০০০');
        $this->assertSame(10000, (int) $now(), '"১০,০০০" জমা হলো অন্য সংখ্যা হয়ে।');

        foreach (['1e3', '-5', '2.5', 'abc'] as $bad) {
            $put($bad)->assertSessionHasErrors('settings');
            $this->assertSame(10000, (int) $now(), "\"{$bad}\" পূর্ণসংখ্যার ঘরে বসে গেল।");
        }
    }
}
