<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ "বাকি ও আদায়" — বিক্রয়ের মেনুতে বাকি নিয়ন্ত্রণের নিজের ভাঁজ (SAP Credit Management / D365 Credit and collections;
 * মালিকের অনুমোদিত পরিকল্পনা, ৫ অক্টোবর ২০২৬)।
 *
 * ভাঁজে ছয়টা সারি, প্রতিটা খোলে: সীমার ব্যবহার, বাকি বন্ধের তালিকা, ঝুঁকির গ্রাহক, বয়সভিত্তিক বকেয়া (গ্রাহকের রিপোর্ট),
 * সীমায় আটকানো আদেশ, সীমা বদলের ইতিহাস।
 */
final class CreditControlHasItsOwnMenuFoldTest extends TestCase
{
    use RefreshDatabase;

    private const ROWS = [
        ['sales.report.show', 'credit-use'],
        ['sales.report.show', 'blocked-customers'],
        ['sales.report.show', 'risky-customers'],
        ['customer.report.show', 'ageing'],
        ['sales.report.show', 'credit-blocked'],
        ['sales.report.show', 'limit-history'],
    ];

    public function test_the_fold_holds_the_six_credit_screens_and_each_opens(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $fold = [];
        foreach (app(MenuBuilder::class)->forUser($owner->fresh()) as $module) {
            if (($module['code'] ?? null) !== 'sales') {
                continue;
            }
            foreach ($module['groups'] as $rows) {
                foreach ($rows as $row) {
                    if (($row['cluster'] ?? null) === 'credit_collections') {
                        $fold[] = (string) $row['url'];
                    }
                }
            }
        }

        $expected = array_map(fn (array $r) => route($r[0], ['slug' => $r[1]]), self::ROWS);
        $this->assertSame($expected, $fold, '⛔ "বাকি ও আদায়" ভাঁজের সারিগুলো পরিকল্পনার নয়।');
        $this->assertNotSame('menu.credit_collections', __('core.menu.credit_collections'));
        $this->assertSame('বাকি ও আদায়', __('core.menu.credit_collections', [], 'bn'));

        foreach ($expected as $url) {
            $this->get($url)->assertOk();
        }
    }
}
