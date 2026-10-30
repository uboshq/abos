<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Services\MenuBuilder;
use App\Core\Services\StatusNotices;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\WaitingForMe;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐ ফোনের নিচের বার আর এক জায়গার নোটিশ — মালিক, ৩০–৩১ অক্টোবর ২০২৬: *"smart kajer kichu daw int standard onuzayi"*,
 * *"ami egulo sajaini, int. standard onuzayi korte bolechi"*, *"notice ta curo kore ekta jaygay dekhaw zate porda na vange"*।
 *
 * ⛔ আগে: সবার বারে মেনুর প্রথম চারটা (হিসাব, অর্থ, সরবরাহকারী, ক্রয়) — কারও কাজের সাথে মেলে না; কোনো সংখ্যা নেই; ＋ নেই;
 * তিনটা নোটিশ তিন সারিতে, ফোনে পর্দার এক-তৃতীয়াংশ।
 *
 * দাবি:
 *   ক — ভূমিকার নাম যেভাবেই লেখা থাকুক (টেমপ্লেটের "Field Sales", লাইভের "WAREHOUSE_IN_CHARGE"), ঠিক দলে পড়ে; কয়েকটা থাকলে বড়টা;
 *   খ — মালিকের বারে মালিকের কেন্দ্র · অনুমোদন · বিক্রয় · হিসাব, এই ক্রমে; মাঝে ＋ (তৈরির তালিকা খোলে);
 *   গ — রেলে পিন করা মডিউল ভূমিকার আগে;
 *   ঘ — অনুমোদনের কোণে টপবারের একই সংখ্যা ([[WaitingForMe]]);
 *   ঙ — একাধিক নোটিশ এক সারিতে: সবচেয়ে জরুরিটা (লাল) আগে দেখায়, বাকিগুলো "+N ▾"-এর পেছনে গুটানো।
 */
final class ThePhoneBarFitsItsPersonTest extends TestCase
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
        $this->owner->forceFill(['ui' => 'navy'])->save();
    }

    public function test_k_a_role_is_known_however_it_is_written_and_the_biggest_wins(): void
    {
        $builder = app(MenuBuilder::class);
        $kind = function (array $names) use ($builder): ?string {
            $user = new User;
            $user->setRelation('roles', new Collection(array_map(fn (string $n) => new Role(['name' => $n]), $names)));

            return $builder->phoneKind($user);
        };

        $this->assertSame(
            ['warehouse', 'sales', 'accounts', 'purchase', 'manager', 'sales', 'owner', 'owner', null],
            [$kind(['WAREHOUSE_IN_CHARGE']), $kind(['Field Sales']), $kind(['ACCOUNTS']), $kind(['PURCHASE OFFICER']), $kind(['BRANCH_IN_CHARGE']),
                $kind(['SR']), $kind(['HR', 'OWNER', 'DEALER']), $kind(['super_admin']), $kind(['Kitchen'])],
            '⛔ ফোনের বার: ভূমিকার নাম চেনা যায় না, বা কয়েকটা ভূমিকায় বড়টা জেতে না — মানুষ ভুল চারটা পেতেন।');
    }

    public function test_kh_the_owner_gets_the_owners_four_and_the_plus_in_the_middle(): void
    {
        $html = $this->page();

        preg_match_all('/data-bottom-module="([a-z_]+)"/', $html, $codes);
        $this->assertSame(MenuBuilder::ROLE_PHONE_FOUR['owner'], $codes[1],
            '⛔ ফোনের বার: মালিকের বারে মালিকের চারটা নয় — আগের মতো মেনুর প্রথম চারটা (হিসাব, অর্থ, সরবরাহকারী, ক্রয়)?');
        $this->assertMatchesRegularExpression('/data-bottom-module="approval".*?<button[^>]*data-bottom-create[^>]*@click="\$dispatch\(\'open-create-menu\'\)".*?data-bottom-module="sales"/s', $html,
            '⛔ ফোনের বার: মাঝখানে ＋ নেই, বা সেটা তৈরির তালিকা খোলে না।');
        $this->assertMatchesRegularExpression('/data-shell-create[^>]*@open-create-menu\.window=/', $html, '⛔ ফোনের বার: তৈরির তালিকা নিচের ＋ শোনে না।');
    }

    public function test_g_a_pinned_module_comes_before_the_roles_four(): void
    {
        $this->owner->forceFill(['nav_layout' => ['pinned' => ['inventory'], 'order' => []]])->save();

        preg_match('/data-bottom-module="([a-z_]+)"/', $this->page(), $first);
        $this->assertSame('inventory', $first[1] ?? null, '⛔ ফোনের বার: রেলে পিন করা মডিউল ফোনের বারে আগে আসে না — নিজের সাজ মানা হয় না।');
    }

    public function test_gh_the_approval_corner_shows_the_top_bars_number(): void
    {
        $this->app->instance(WaitingForMe::class, new class(app(\App\Core\Engines\Approval\ApprovalEngine::class)) extends \stdClass
        {
            public function __construct(public $engine) {}

            public function pendingCount(): int
            {
                return 7;
            }
        });

        $this->assertMatchesRegularExpression('/data-bottom-module="approval".*?<span data-bottom-count[^>]*>7<\/span>/s', $this->page(),
            '⛔ ফোনের বার: অনুমোদনের কোণে অপেক্ষার সংখ্যা নেই — ওপরের ✔-এ ৩, নিচে কিছু নেই।');
    }

    public function test_ng_several_notices_fold_into_one_row_with_the_red_one_first(): void
    {
        $this->partialMock(StatusNotices::class, fn ($m) => $m->shouldReceive('forStrip')->andReturn([
            ['text' => 'তিনটা অনুমোদন অপেক্ষায়', 'tone' => 'pending', 'url' => null],
            ['text' => 'ঘোষণা: শুক্রবার ছুটি', 'tone' => 'info', 'url' => null],
            ['text' => 'দুই দিন ব্যাকআপ হয়নি', 'tone' => 'danger', 'url' => null],
        ]));

        $html = $this->page();
        preg_match_all('/<div data-notice-banner data-message-strip="([a-z]+)"([^>]*)>/s', $html, $strips, PREG_SET_ORDER);
        $this->assertSame(['danger', 'pending', 'info'], array_column($strips, 1), '⛔ নোটিশ: সবচেয়ে জরুরিটা (লাল) আগে নয়।');
        $this->assertSame([false, true, true], array_map(fn ($s) => str_contains($s[2], 'x-show="open"'), $strips),
            '⛔ নোটিশ: তিনটাই খোলা সারিতে — পর্দার মাথা খায়; কেবল প্রথমটা দেখানোর কথা।');
        $this->assertMatchesRegularExpression('/data-strip-toggle.*?\+2 ▾/s', $html, '⛔ নোটিশ: গুটানো নোটিশ খোলার "+২ ▾" নেই।');
    }

    private function page(): string
    {
        return (string) $this->actingAs($this->owner->fresh())->get(route('module.dashboard', 'accounts'))->assertOk()->getContent();
    }
}
