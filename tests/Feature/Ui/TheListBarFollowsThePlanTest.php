<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ তালিকার বার — পরিকল্পনা §৩.১ (মালিক, ১৪ নভেম্বর ২০২৬: "এইভাবে এই কাজটা ধরে ফেলো"; ১৬ নভেম্বর: *"অর্ধেক কাজ"*)।
 *
 *   - "নামান ▾" নিজের চাবিতে (`system_admin.list.export`): চাবি নেই তো মেনু নেই, আর ঠিকানায় `?export=csv` লিখলেও ৪০৩
 *   - চাবি থাকলে মেনুতে Excel, CSV, PDF, প্রিন্ট — আর নামানোটা সত্যিই নামে
 *   - ঘনত্ব নিজের সেটিংসে: একবার "ঘন" বাছলে সব তালিকা ঘন; বারে আর ঘনত্বের বোতাম নেই
 */
final class TheListBarFollowsThePlanTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_without_the_download_key_there_is_no_menu_and_no_file(): void
    {
        $clerk = $this->clerk(withDownload: false);
        $this->actingAs($clerk);

        $html = (string) $this->get(route('customer.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-list-download', $html, '⛔ "তালিকা নামানো" চাবি ছাড়াই "নামান ▾" মেনু দেখাল');

        $this->get(route('customer.index', ['export' => 'csv']))->assertForbidden();
        $this->get(route('customer.index', ['export' => 'xlsx']))->assertForbidden();
    }

    public function test_with_the_key_the_menu_holds_four_ways_and_the_file_comes(): void
    {
        $this->actingAs($this->clerk(withDownload: true));

        $html = (string) $this->get(route('customer.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-list-download(?:(?!<\/form>).)*data-download="xlsx"(?:(?!<\/form>).)*data-download="csv"'
            .'(?:(?!<\/form>).)*data-download="pdf"(?:(?!<\/form>).)*data-download="print"/s', $html,
            '⛔ "নামান ▾"-এর ভেতরে Excel, CSV, PDF, প্রিন্ট — এই চারটা, এই ক্রমে থাকার কথা');

        $response = $this->get(route('customer.index', ['export' => 'csv']));
        $this->assertSame(200, $response->getStatusCode(), '⛔ চাবি থাকার পরেও CSV নামল না');
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
    }

    public function test_the_density_lives_in_the_users_own_settings(): void
    {
        $clerk = $this->clerk(withDownload: false);
        $this->actingAs($clerk);

        $plain = (string) $this->get(route('customer.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('is-dense', $plain, '⛔ কেউ ঘন বাছেনি, তবু তালিকা ঘন');

        // ⓘ পাতার ফর্ম যা পাঠায় — রং, আলো, ভাষা সহ (রং বাধ্যতামূলক)
        $this->post(route('appearance.save'), [
            'accent' => \App\Core\Support\Accent::keys()[0], 'theme' => 'light', 'locale' => 'bn', 'compact_rows' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue((bool) $clerk->fresh()->compact_rows, '⛔ সেটিংসে "ঘন" বাছা জমা হল না');

        $dense = (string) $this->actingAs($clerk->fresh())->get(route('customer.index'))->assertOk()->getContent();
        $this->assertStringContainsString('is-dense', $dense, '⛔ সেটিংসে "ঘন" বাছার পরেও তালিকা ঘন হল না');
        $this->assertStringNotContainsString('aria-label="'.e(__('core.toolbar.density')).'"', $dense, '⛔ বারে ঘনত্বের বোতাম ফিরে এসেছে');
    }

    private function clerk(bool $withDownload): User
    {
        $user = User::factory()->create(['email' => 'bar-'.($withDownload ? 'yes' : 'no').'@abos.test', 'is_active' => true,
            'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($user, $withDownload): void {
            $user->givePermissionTo(Permission::findOrCreate('customer.view', 'web'));
            if ($withDownload) {
                $user->givePermissionTo(Permission::findOrCreate('system_admin.list.export', 'web'));
            }
        });

        return $user;
    }
}
