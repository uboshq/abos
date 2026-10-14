<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হিসাবরক্ষক নিজেই পেছনের তারিখের দিন বাড়াতেন — সেটিং লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ হিসাবের সেটিংস পাতা [[SettingsService::mayChange()]] দেখত না: `accounts.manage` চাবিতেই আটটা কেবল-মালিকের সুইচ
 * (পেছনের তারিখ, ভাউচারের দ্বিতীয় যাচাই, নগদের সীমা, সম্পদের নিয়ম) বদলানো যেত। কন্ট্রোল প্যানেলে একই সুইচ আটকানো ছিল।
 */
final class TheAccountantWidenedTheBackdateWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_only_switch_stays_and_the_rest_of_the_form_still_saves(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        CompanyContext::forCompany((int) $company->id, fn () => $clerk->givePermissionTo('accounts.manage'));
        $this->assertFalse(app(SettingsService::class)->mayChange('accounts.backdate_days', $clerk->fresh()),
            'হিসাবরক্ষক নিজেই মালিক — দাবিটা কিছু মাপছে না।');

        $settings = app(SettingsService::class);
        $before = (int) $settings->get('accounts.backdate_days');

        $this->assertTrue($settings->enabled('accounts.require_narration'));

        // ⓘ চেকবক্স তুলে নেওয়া মানে ব্রাউজার কিছুই পাঠায় না — তাই require_narration অনুপস্থিত, অর্থাৎ "বন্ধ"
        $this->actingAs($clerk->fresh())->put(route('accounts.settings.update'), ['settings' => [
            'accounts.backdate_days' => (string) ($before + 358),
        ]])->assertSessionHasErrors('settings');

        $settings->flush();
        $this->assertSame($before, (int) $settings->get('accounts.backdate_days'), 'কেবল-মালিকের সুইচ হিসাবরক্ষক বদলে দিলেন।');
        $this->assertFalse($settings->enabled('accounts.require_narration'), 'বাকি ফর্মটাও বসল না — বেশি বন্ধ হয়েছে।');
    }
}
