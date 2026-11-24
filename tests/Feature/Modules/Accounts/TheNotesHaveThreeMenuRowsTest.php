<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\TradeVoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ নোটের তিন মেনু-সারি — ক্রেডিট নোট, গ্রাহকের ডেবিট নোট, সরবরাহকারীর ডেবিট নোট (মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬);
 * পুরনো একটা "ডেবিট ও ক্রেডিট নোট" সারি আর নেই। ⓘ চাবি একই (`accounts.note.view`)।
 *
 * ⓘ স্টক ছাড়া বিক্রয়/ক্রয় ভাউচারের দুই সারি কোম্পানির সুইচ (`accounts.stock_free_vouchers`) বন্ধ থাকলে নেই, চালু করলে আছে।
 */
final class TheNotesHaveThreeMenuRowsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_the_module_declares_three_note_rows_on_the_note_view_key(): void
    {
        $rows = $this->declaredRows();
        $byLabel = collect($rows)->keyBy('label');

        foreach (['cn', 'cdn', 'dn'] as $type) {
            $row = $byLabel->get('accounts::note.menu_'.$type);
            $this->assertNotNull($row, "⛔ মেনুতে `accounts::note.menu_{$type}` সারি নেই।");
            $this->assertSame('accounts.note.'.$type.'.index', $row['route']);
            $this->assertSame('accounts.note.view', $row['permission'], "⛔ {$type}: সারির চাবি নোট দেখার চাবি নয়।");
        }

        $this->assertFalse($byLabel->has('accounts::note.title'), '⛔ পুরনো এক-সারির "ডেবিট ও ক্রেডিট নোট" এখনো মেনুতে।');
        $this->assertNotContains('accounts.note.index', array_column($rows, 'route'), '⛔ মেনু পুরনো দুই-দিকের তালিকায় যায়।');
    }

    public function test_the_owner_sees_the_three_note_rows(): void
    {
        $labels = $this->menuLabels();

        foreach (['menu_cn', 'menu_cdn', 'menu_dn'] as $key) {
            $this->assertContains(__('accounts::note.'.$key), $labels, "⛔ মালিকের মেনুতে `{$key}` নেই।");
        }
    }

    public function test_the_two_stock_free_voucher_rows_follow_the_switch(): void
    {
        $settings = app(SettingsService::class);
        $settings->set(TradeVoucherService::SWITCH, false);
        $settings->flush();

        $labels = $this->menuLabels();
        $this->assertNotContains(__('accounts::trade.menu_sale'), $labels, '⛔ সুইচ বন্ধ, তবু বিক্রয় ভাউচারের সারি মেনুতে।');
        $this->assertNotContains(__('accounts::trade.menu_purchase'), $labels, '⛔ সুইচ বন্ধ, তবু ক্রয় ভাউচারের সারি মেনুতে।');

        $settings->set(TradeVoucherService::SWITCH, true);
        $settings->flush();

        $labels = $this->menuLabels();
        $this->assertContains(__('accounts::trade.menu_sale'), $labels, '⛔ সুইচ চালু, অথচ বিক্রয় ভাউচারের সারি নেই।');
        $this->assertContains(__('accounts::trade.menu_purchase'), $labels, '⛔ সুইচ চালু, অথচ ক্রয় ভাউচারের সারি নেই।');
    }

    /** @return list<array<string, mixed>> module.php-র মেনুর সব সারি, দল নির্বিশেষে */
    private function declaredRows(): array
    {
        $module = require base_path('app/Modules/Accounts/module.php');
        $rows = [];

        foreach ($module['menu'] as $group) {
            foreach ($group as $row) {
                if (is_array($row) && isset($row['label'])) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /** @return list<string> মেনুর প্রতিটা সারির লেখা, যত গভীরেই থাকুক */
    private function menuLabels(): array
    {
        $labels = [];

        $walk = function (array $rows) use (&$walk, &$labels): void {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    if (isset($row['label']) && is_string($row['label'])) {
                        $labels[] = $row['label'];
                    }

                    $walk($row);
                }
            }
        };

        $walk(app(MenuBuilder::class)->forUser($this->owner->fresh()));

        return $labels;
    }
}
