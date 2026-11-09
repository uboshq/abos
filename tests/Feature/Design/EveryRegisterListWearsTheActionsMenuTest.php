<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Core\Support\RowMenu;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মালিক, ৯ নভেম্বর ২০২৬: "Abos er sokol list sob skin dekhe deke update koro" — গ্রাহকের তালিকার "অ্যাকশন ▾" মেনুটাই
 * খাতার তালিকাগুলোয় (পণ্য, শাখা, কোম্পানি, মাস্টার তালিকা, এলাকা, প্রতিষ্ঠান, বিমা, মূলধন, রেসিপি): মাথায় কোড · নাম আর
 * অবস্থা, তারপর দেখা (দেখুন · প্রিভিউ · ইতিহাস), তারপর কাজ — আগের কাজগুলো সবই, চিহ্নসহ।
 *
 * ⛔ আগে এগুলো কেবল তিন বিন্দু ছিল, মাথা নেই, দেখার ভাগ নেই; এলাকার গাছে কেবল একটা "+"।
 */
final class EveryRegisterListWearsTheActionsMenuTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_product_list_menu_has_a_head_a_look_part_and_the_old_work(): void
    {
        $product = Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail();

        $html = $this->get(route('inventory.product.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(__('core.table.action_menu')), $html, '⛔ কলামের নাম "অ্যাকশন" নয়।');
        $this->assertStringContainsString('data-row-actions-button', $html, '⛔ সারিতে "অ্যাকশন ▾" বোতাম নেই — এখনো তিন বিন্দু।');
        $this->assertStringContainsString(e(RowMenu::title($product)), $html, '⛔ মেনুর মাথায় কোড · নাম নেই।');
        $this->assertStringContainsString(e(__('core.row_menu.preview')), $html, '⛔ দেখার ভাগে প্রিভিউ নেই।');
        $this->assertStringContainsString(route('inventory.product.show', $product), $html);
        $this->assertStringContainsString(route('inventory.product.edit', $product), $html, '⛔ আগের সম্পাদনা হারিয়ে গেছে।');
        $this->assertStringContainsString(route('inventory.product.destroy', $product), $html, '⛔ আগের নিষ্ক্রিয় করা হারিয়ে গেছে।');
    }

    public function test_the_location_tree_rows_carry_the_menu_with_add_below_inside(): void
    {
        $country = Location::query()->whereNull('parent_id')->firstOrFail();
        $child = Location::childLevelOf($country->level);
        $this->assertNotNull($child);

        $html = $this->get(route('master_data.location.index'))->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(Location::query()->count(), substr_count($html, 'data-row-actions-button'),
            '⛔ গাছের প্রতিটা সারিতে মেনু নেই।');
        $this->assertStringContainsString(e(__('master_data::action.new').' — '.__('master_data::level.'.$child)), $html,
            '⛔ আগের "+" (নিচের স্তর যোগ) মেনুতে আসেনি।');
        $this->assertStringContainsString(e(route('master_data.location.create', ['level' => $child, 'parent' => $country->id])), $html);
        $this->assertStringContainsString(route('master_data.location.edit', $country), $html);
    }

    public function test_the_branch_list_menu_names_the_branch_and_keeps_edit(): void
    {
        $branch = Branch::query()->where('company_id', $this->company->id)->firstOrFail();

        $html = $this->get(route('system_admin.branch.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(RowMenu::title($branch)), $html);
        $this->assertStringContainsString(route('system_admin.branch.edit', $branch->id), $html);
    }

    public function test_dress_puts_head_look_and_work_in_order_with_icons(): void
    {
        $menu = RowMenu::dress([
            ['label' => 'v', 'url' => 'https://x.test/p/1'],
            ['label' => 'e', 'url' => 'https://x.test/p/1/edit'],
            ['label' => 'off', 'url' => 'https://x.test/p/1', 'method' => 'delete', 'tone' => 'danger'],
            ['label' => 'gone', 'url' => 'https://x.test/p/1/purge', 'method' => 'delete', 'tone' => 'danger'],
            ['label' => 'on', 'url' => 'https://x.test/p/1/activate', 'method' => 'post', 'tone' => 'success'],
        ], 'P-1 · Rice', 'active', 'https://x.test/p/1');

        $this->assertSame('P-1 · Rice', $menu[0]['head']);
        $this->assertSame(__('core.row_menu.group_look'), $menu[1]['group']);
        $this->assertSame(__('core.row_menu.view'), $menu[2]['label'], 'দেখুন প্রথমে');
        // ⓘ লেবেলের তালিকা ধরে — একটা সারি না থাকলে সূচক ভুল জায়গায় পড়ে ভুল (error) দিত, দাবি নয় (Mac রান, R4)
        $labels = array_values(array_filter(array_column($menu, 'label')));
        $this->assertContains(__('core.row_menu.preview'), $labels, '⛔ দেখার ভাগে প্রিভিউ নেই।');
        $this->assertSame(__('core.row_menu.preview'), $labels[1] ?? null, '⛔ প্রিভিউ দেখুনের ঠিক পরে নয়।');

        $work = array_values(array_filter($menu, fn ($i) => isset($i['label']) && in_array($i['label'], ['v', 'e', 'off', 'gone', 'on'], true)));
        $this->assertSame(['e', 'off', 'gone', 'on'], array_column($work, 'label'), '⛔ দেখার লিংকটা কাজের ভাগে দুবার এসেছে।');
        $this->assertSame(['edit', 'pause', 'trash', 'check-circle'], array_column($work, 'icon'),
            '⛔ কাজের চিহ্ন ভুল — নিষ্ক্রিয় করা বিরতি, পাকা মোছা ঝুড়ি, সক্রিয় করা টিক।');
    }

    public function test_nothing_to_do_means_no_button_not_a_bare_head(): void
    {
        $this->assertSame([], RowMenu::dress([], 'K-1', 'active'), '⛔ চাবি নেই, তবু মাথা-সর্বস্ব একটা "অ্যাকশন ▾" বোতাম।');
    }
}
