<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Search\StartingPoints;
use App\Core\Services\DataScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Peek;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * পিক — "পিক শেষ করা", ডিজাইন-চেকলিস্ট ধাপ ৭ · ৫ (২ অক্টোবর ২০২৬)।
 *
 * ── ⓘ কেন পিক একবার তুলে রাখা হয়েছিল ────────────────────────────────────
 * `82a157bb`: *"৩০২ পাতার লেআউট, চালুর আগের রাতে নয়"*। ⚠️ ভয়টা ছিল একটা
 * পাতা পপআপে ভুল চেহারায় আসা — আর মাপলে দেখা গেল ঠিক সেই দরজাটা খোলা:
 * জানালা যা আসত তা-ই বসাত, তাই লেআউট-ছাড়া যেকোনো উত্তর (লগইনের পাতা,
 * ত্রুটির পাতা) পপআপের ভিতরে আরেকটা গোটা অ্যাপ বসাত।
 *
 * ⭐ এখন সার্ভারের টুকরো একটা চিহ্ন বহন করে ([[Peek::FRAGMENT]]), আর
 * জানালা কেবল সেটাই বসায় (peek.test.js-এ মাপা)। এই ফাইল সার্ভারের দিকটা
 * মাপে: প্রতিটা ঘোষিত কাগজের পাতা পিকে চিহ্নসহ একটা টুকরো, খোলস ছাড়া;
 * দরজা পিকেও একই; আর কোম্পানির সুইচ বন্ধ করলে পিক পুরোপুরি বন্ধ।
 */
final class APeekCouldCarryAWholePageTest extends TestCase
{
    use RefreshDatabase;

    private const SEE = 'accounts.report';

    private Company $company;

    private Company $other;

    private Branch $home;

    private Branch $away;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->other = Company::query()->where('code', 'FMART')->firstOrFail();

        $this->home = Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->away = Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->switchCompany($this->company->id);

        CompanyContext::set($this->company->id, $this->home->id);
        $this->actingAs($this->owner->fresh());

        app(StandardChart::class)->install();

        $this->clerk = User::factory()->create([
            'current_company_id' => $this->company->id,
            'current_branch_id' => $this->home->id,
            'is_active' => true,
        ]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->grant(self::SEE);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ প্রতিটা কাগজের পাতা পিকে একটা চিহ্নওয়ালা টুকরো ──────────────

    public function test_every_declared_paper_page_peeks_as_one_marked_fragment_without_the_shell(): void
    {
        /*
         * ⓘ কোন পাতা "কাগজের পাতা", সেটা মডিউলের ঘোষণা থেকে
         * ([[StartingPoints::papersByRoute()]]) — হাতে লেখা তালিকা নয়। ডেমোতে
         * যে ধরনের একটাও সারি নেই, সেটা বাদ; বাকি প্রতিটা মাপা হয়।
         */
        $measured = [];

        foreach (app(StartingPoints::class)->papersByRoute() as $route => [$class, $param]) {
            $record = $class::query()->orderBy((new $class)->getKeyName())->first();

            if ($record === null) {
                continue;
            }

            $url = route($route, [$param => $record->getKey()]);
            $res = $this->peek($this->owner, $url);

            /* ⓘ দরজা যদি মালিককেও ফেরায় (পর্দা বন্ধ), ওটা পিকের প্রশ্ন নয় */
            if ($res->getStatusCode() !== 200) {
                continue;
            }

            $this->assertFragment($res->getContent(), $route);
            $measured[] = $route;
        }

        $this->assertGreaterThanOrEqual(5, count($measured), implode(PHP_EOL, [
            'মাত্র '.count($measured).'টা কাগজের পাতা মাপা গেল: '.implode(', ', $measured),
            '⚠️ এত কম হলে দাবিটা প্রায় কিছুই দেখেনি।',
        ]));
    }

    public function test_the_peek_opens_the_paper_that_was_asked_for(): void
    {
        $a = $this->voucher($this->home, 'PEEK-A');
        $b = $this->voucher($this->home, 'PEEK-B');

        foreach ([$a, $b] as $paper) {
            $html = $this->peek($this->owner, route('accounts.voucher.show', $paper))->assertOk()->getContent();

            $this->assertFragment($html, 'accounts.voucher.show');

            /* ⛔ কাগজের নিজের বিবরণ — আর অন্যটার নেই */
            $other = $paper->is($a) ? $b : $a;
            $this->assertStringContainsString($paper->narration, $html, 'পিকে চাওয়া কাগজটা আসেনি।');
            $this->assertStringNotContainsString($other->narration, $html, 'পিকে অন্য কাগজের বিবরণ।');
        }
    }

    // ── ⛔ দরজা পিকেও একই — একই মানুষ দুইবার ───────────────────────────

    public function test_a_paper_of_another_company_is_refused_in_the_peek_too(): void
    {
        $ours = $this->voucher($this->home, 'PEEK-OURS');
        $theirs = $this->voucherIn($this->other, 'PEEK-THEIRS');

        $this->peek($this->owner, route('accounts.voucher.show', $ours))->assertOk();

        $res = $this->peek($this->owner, route('accounts.voucher.show', $theirs));
        $res->assertNotFound();
        $this->assertStringNotContainsString('PEEK-THEIRS', (string) $res->getContent(),
            '⛔ অন্য কোম্পানির কাগজের লেখা পিকের উত্তরে।');
    }

    public function test_a_paper_of_a_branch_out_of_reach_is_refused_in_the_peek_until_the_branch_is_given(): void
    {
        $there = $this->voucher($this->away, 'PEEK-AWAY');
        $url = route('accounts.voucher.show', $there);

        $this->scope([$this->home]);
        $this->peek($this->clerk, $url)->assertNotFound();

        /* ⭐ একই মানুষ, একই কাগজ — শাখাটা পেলে খোলে */
        $this->scope([$this->home, $this->away]);
        $this->assertFragment($this->peek($this->clerk, $url)->assertOk()->getContent(), 'accounts.voucher.show');
    }

    public function test_a_paper_without_the_key_is_refused_in_the_peek_until_the_key_is_given(): void
    {
        $url = route('accounts.voucher.show', $this->voucher($this->home, 'PEEK-KEY'));

        $this->peek($this->clerk, $url)->assertOk();

        $this->revoke(self::SEE);
        $this->peek($this->clerk, $url)->assertForbidden();

        $this->grant(self::SEE);
        $this->peek($this->clerk, $url)->assertOk();
    }

    // ── ⭐ কোম্পানির সুইচ ───────────────────────────────────────────────

    public function test_the_company_switch_turns_the_peek_off_on_the_server_and_on_the_page(): void
    {
        $url = route('accounts.voucher.show', $this->voucher($this->home, 'PEEK-SWITCH'));

        /* ⓘ ডিফল্ট চালু — আজ লাইভে যেমন চলছে */
        $this->assertFragment($this->peek($this->owner, $url)->assertOk()->getContent(), 'accounts.voucher.show');
        $this->assertSame(1, substr_count($this->page($this->owner, $url), 'x-data="peek"'));

        $this->switchPeek(false);

        $whole = $this->peek($this->owner, $url)->assertOk()->getContent();
        $this->assertStringContainsString('<!DOCTYPE', $whole,
            '⛔ সুইচ বন্ধ, তবু হেডার পাঠালে খোলসহীন টুকরো আসছে।');
        $this->assertStringNotContainsString(Peek::FRAGMENT, $whole);
        $this->assertSame(0, substr_count($this->page($this->owner, $url), 'x-data="peek"'),
            '⛔ সুইচ বন্ধ, তবু পাতায় পিকের জানালা বসছে।');

        $this->switchPeek(true);
        $this->assertFragment($this->peek($this->owner, $url)->assertOk()->getContent(), 'accounts.voucher.show');
    }

    // ── সহায়ক ──────────────────────────────────────────────────────────

    private function assertFragment(string $html, string $where): void
    {
        $this->assertStringStartsWith('<div '.Peek::FRAGMENT.'>', ltrim($html),
            $where.': পিকের উত্তর চিহ্নওয়ালা একটা টুকরো দিয়ে শুরু হয় না।');
        $this->assertStringEndsWith('</div>', rtrim($html),
            $where.': চিহ্নওয়ালা টুকরোর পরে আরো কিছু আছে — মূল উপাদান একটা নয়।');
        $this->assertStringNotContainsString('<!DOCTYPE', $html, $where.': পিকে গোটা নথি।');
        $this->assertStringNotContainsString('x-data="peek"', $html, $where.': পিকের ভিতরে আরেকটা পিকের জানালা।');
        $this->assertStringNotContainsString('bottom-nav-item', $html, $where.': পিকের ভিতরে মেনু।');
    }

    private function peek(User $user, string $url): TestResponse
    {
        /* ⚠️ `withHeaders` টেস্টের পরের অনুরোধগুলোতেও থেকে যায় — তাই প্রতিবার মুছে নতুন করে */
        $this->flushHeaders();

        return $this->actingAs($user->fresh())->withHeaders([Peek::HEADER => '1'])->get($url);
    }

    private function page(User $user, string $url): string
    {
        $this->flushHeaders();

        return (string) $this->actingAs($user->fresh())->get($url)->assertOk()->getContent();
    }

    private function switchPeek(bool $on): void
    {
        CompanyContext::forCompany($this->company->id, function () use ($on) {
            $settings = app(SettingsService::class);
            $settings->set('system.document_peek', $on);
            $settings->flush();
        });
    }

    private function voucher(Branch $branch, string $narration): Voucher
    {
        [$first, $second] = Account::query()
            ->postable()->active()->whereNull('money_kind')
            ->orderBy('code')->limit(2)->get()->all();

        return app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => $narration,
            'branch_id' => $branch->id,
        ], [
            ['account_id' => $first->id, 'debit' => '1000', 'credit' => '0'],
            ['account_id' => $second->id, 'debit' => '0', 'credit' => '1000'],
        ]);
    }

    private function voucherIn(Company $company, string $narration): Voucher
    {
        return CompanyContext::forCompany($company->id, function () use ($company, $narration) {
            app(StandardChart::class)->install();

            $branch = Branch::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)->orderByDesc('is_default')->firstOrFail();

            return $this->voucher($branch, $narration);
        });
    }

    private function grant(string $permission): void
    {
        Permission::findOrCreate($permission, 'web');

        CompanyContext::forCompany($this->company->id, fn () => $this->clerk->givePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function revoke(string $permission): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $this->clerk->revokePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param  list<Branch>  $branches */
    private function scope(array $branches): void
    {
        UserDataScope::query()->withoutGlobalScopes()
            ->where('user_id', $this->clerk->id)
            ->where('scope_type', UserDataScope::BRANCH)
            ->delete();

        foreach ($branches as $branch) {
            UserDataScope::query()->create([
                'company_id' => $this->company->id,
                'user_id' => $this->clerk->id,
                'scope_type' => UserDataScope::BRANCH,
                'scope_id' => $branch->id,
            ]);
        }

        app(DataScope::class)->forget();
    }
}
