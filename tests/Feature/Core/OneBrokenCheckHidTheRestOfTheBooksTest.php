<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Integrity\IntegrityCheck;
use App\Core\Integrity\IntegrityRegistry;
use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

/**
 * একটা যাচাই ভুলে থামলে বাকি খাতা আর দেখাই হত না।
 *
 * ── ⛔ কী ভেঙেছিল (ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬) ──────────
 * `abos:books-check`-এ কোনো `catch` ছিল না: প্রথম কোম্পানির একটা যাচাই
 * SQL ভুলে থামলে ঐ কোম্পানির বাকি যাচাই **আর পরের সব কোম্পানি** সে রাতে
 * দেখাই হত না। পর্দায় (`/accounts/books-check`) একই ভুল গোটা পাতাটাকে
 * ৫০০ বানাত।
 *
 * ⭐ এখন ভুলটা নিজেই একটা ভাঙা যাচাই — নাম ধরে, লাল — আর বাকিটা চলে।
 */
final class OneBrokenCheckHidTheRestOfTheBooksTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> যে কোম্পানিগুলোয় পাশের যাচাইটা চলেছে */
    public static array $ranIn = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        self::$ranIn = [];
    }

    /** প্রথম কোম্পানিতে ভুলে থামে এমন একটা যাচাই, আর একটা যে কেবল লিখে রাখে কোথায় চলল। */
    private function plantABrokenCheck(?int $boomIn): void
    {
        $this->app->instance(IntegrityRegistry::class, new class(app(ModuleRegistry::class), $boomIn) extends IntegrityRegistry
        {
            public function __construct(ModuleRegistry $modules, private readonly ?int $boomIn)
            {
                parent::__construct($modules);
            }

            public function all(): array
            {
                $boom = new IntegrityCheck('x.boom', 'Boom', 'q', 'w', 'accounts.report', function (): array {
                    if ($this->boomIn === null || CompanyContext::id() === $this->boomIn) {
                        throw new RuntimeException('boom');
                    }

                    return [];
                });

                $witness = new IntegrityCheck('x.witness', 'Witness', 'q', 'w', 'accounts.report', function (): array {
                    OneBrokenCheckHidTheRestOfTheBooksTest::$ranIn[] = (string) Company::query()
                        ->whereKey(CompanyContext::id())->value('code');

                    return [];
                });

                return ['x.boom' => $boom, 'x.witness' => $witness] + parent::all();
            }
        });
    }

    public function test_a_check_that_throws_does_not_hide_the_next_check_or_company(): void
    {
        $codes = Company::query()->orderBy('code')->pluck('code', 'id')->all();
        $this->assertGreaterThan(1, count($codes), 'ডেমোতে একটার বেশি কোম্পানি নেই।');

        $first = (int) array_key_first($codes);
        $this->plantABrokenCheck($first);

        $exit = Artisan::call('abos:books-check');
        $said = Artisan::output();

        $this->assertNotSame(0, $exit, 'একটা যাচাই চলেইনি, তবু কমান্ড সবুজ।');
        $this->assertStringContainsString('x.boom', $said, 'কোন যাচাই চলেনি তা বলা হয়নি।');
        $this->assertStringContainsString($codes[$first], $said, 'কোন কোম্পানিতে চলেনি তা বলা হয়নি।');

        $this->assertSame(array_values($codes), self::$ranIn,
            'একটা যাচাই ভুলে থামায় পরের যাচাই বা পরের কোম্পানি দেখাই হয়নি।');
    }

    public function test_the_page_shows_a_check_that_throws_as_a_red_row(): void
    {
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->plantABrokenCheck(null);

        $this->get(route('accounts.integrity'))
            ->assertOk()
            ->assertSee(__('accounts::message.check_did_not_run'))
            ->assertSee('Witness');
    }
}
