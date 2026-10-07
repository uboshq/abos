<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\ErrorJournal;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\ErrorEvent;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * কোম্পানিহীন লগ — কেবল সুপার অ্যাডমিনের চোখে (অডিট ২৭ সেপ্টেম্বর ২০২৬, §৩)।
 *
 * ── ⛔ যা ভাঙা ছিল ──────────────────────────────────────────────────
 * ভুলের খাতা আর ঢোকার খাতায় `company_id` খালি থাকে যখন ঘটনাটা কোনো
 * কোম্পানি বসার **আগে** ঘটে — অচেনা নামে লগইন, লগইনের পর্দায় ভাঙন।
 * ⚠️ দুইটা পর্দাই ঐ সারিগুলো **প্রতিটা কোম্পানির** খাতা-দেখার লোককে
 * দেখাত। ⓘ কোম্পানিহীন ভুলের বার্তায় অন্য কোম্পানির তথ্য থাকতে পারে,
 * আর অচেনা নামের চেষ্টায় থাকে আক্রমণকারীর আইপি — ওটা গোটা ব্যবস্থার
 * কথা, কোনো একটা কোম্পানির কেরানির নয়।
 *
 * ── ⭐ কেন একই মানুষ, চাবি বন্ধ তারপর খোলা ─────────────────────────
 * দুইজন আলাদা মানুষ দিলে "দেখা গেল না" অন্য কোনো কারণেও হতে পারত
 * (সদস্যপদ, অনুমতি, সুইচ)। ⓘ একই ব্যবহারকারী, একই সারি — কেবল
 * `super_admin` ভূমিকাটা যোগ হয়; তখনই সারিটা ফেরে। তাই পার্থক্যটা
 * ঐ ভূমিকারই, আর কিছুর নয়।
 */
final class ALogWithNoCompanyWasEveryonesTest extends TestCase
{
    use RefreshDatabase;

    private const UNKNOWN_NAME = 'nobody-anywhere-q7x';

    private const PASSWORD = 'the-right-password-91';

    private Company $a;

    private Company $b;

    /** কোম্পানি ক-এর খাতা-দেখার লোক — `Security Watch`, সুপার অ্যাডমিন নন। */
    private User $watcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Company::create(['code' => 'NLA', 'name_en' => 'No-Log A Ltd']);
        $this->b = Company::create(['code' => 'NLB', 'name_en' => 'No-Log B Ltd']);

        /*
         * ⓘ spatie teams — প্রতিটা কোম্পানির নিজের ভূমিকা লাগে, তাই দুই
         * কোম্পানিতেই আলাদা করে বসানো।
         */
        CompanyContext::forCompany($this->a->id, fn () => app(PermissionSyncer::class)->sync());
        CompanyContext::forCompany($this->b->id, fn () => app(PermissionSyncer::class)->sync());

        $this->watcher = $this->member($this->a, 'watcher@nologs.test');
        CompanyContext::forCompany($this->a->id, fn () => $this->watcher->assignRole('Security Watch'));

        CompanyContext::set($this->a->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ঢোকার খাতা ─────────────────────────────────────────────────

    public function test_a_login_with_no_company_is_hidden_from_a_company_admin_until_the_same_person_holds_the_top_role(): void
    {
        $rows = $this->seedLogins();

        // ── চাবি বন্ধ: সাধারণ অ্যাডমিন ─────────────────────────────
        $response = $this->actingAs($this->watcher->fresh())
            ->get(route('governance.login.index', ['only' => 'failed']))
            ->assertOk();

        $seen = $this->ids($response);
        $this->assertContains($rows['mine'], $seen, 'নিজের কোম্পানির সারিটাও নেই — পর্দাটাই অন্ধ, দাবিটা কিছু প্রমাণ করে না।');
        $this->assertNotContains($rows['unknown'], $seen, 'অচেনা নামের কোম্পানিহীন চেষ্টা সাধারণ অ্যাডমিন দেখছেন।');
        $this->assertNotContains($rows['theirs'], $seen, 'কোম্পানি খ-এর সারি ক-তে এসেছে।');
        $this->assertNotContains($rows['theirs_no_company'], $seen, 'খ-এর মানুষের কোম্পানিহীন চেষ্টা ক-তে এসেছে।');
        $response->assertDontSee(self::UNKNOWN_NAME);
        $this->assertSame(1, $response->viewData('failedToday'), 'মাথার সংখ্যায় কোম্পানিহীন চেষ্টা গোনা হয়েছে।');

        // ── চাবি খোলা: একই মানুষ, এখন সুপার অ্যাডমিন ────────────────
        $this->makeTopRole($this->watcher);

        $response = $this->actingAs($this->watcher->fresh())
            ->get(route('governance.login.index', ['only' => 'failed']))
            ->assertOk();

        $seen = $this->ids($response);
        $this->assertContains($rows['mine'], $seen);
        $this->assertContains($rows['unknown'], $seen, 'সুপার অ্যাডমিনও অচেনা নামের চেষ্টা দেখেন না — পাহারাটা হারিয়ে গেছে।');
        $this->assertNotContains($rows['theirs'], $seen, 'সুপার অ্যাডমিন হলেও খ-এর সারি ক-তে নয়।');
        $this->assertNotContains($rows['theirs_no_company'], $seen, 'খ-এর মানুষের নাম সুপার অ্যাডমিনের কাছেও নয়।');
        $response->assertSee(self::UNKNOWN_NAME);
        $this->assertSame(2, $response->viewData('failedToday'));
    }

    // ── ভুলের খাতা ─────────────────────────────────────────────────

    public function test_a_fault_with_no_company_is_hidden_from_a_company_admin_until_the_same_person_holds_the_top_role(): void
    {
        $rows = $this->seedFaults();

        $response = $this->actingAs($this->watcher->fresh())
            ->get(route('governance.error.index'))
            ->assertOk();

        $seen = $this->ids($response);
        $this->assertContains($rows['mine'], $seen, 'নিজের কোম্পানির ভুলটাও নেই — পর্দাটাই অন্ধ।');
        $this->assertNotContains($rows['none'], $seen, 'কোম্পানিহীন ভুল সাধারণ অ্যাডমিন দেখছেন।');
        $this->assertNotContains($rows['theirs'], $seen, 'কোম্পানি খ-এর ভুল ক-তে এসেছে।');
        $response->assertDontSee('companyless-fault-3k');
        $this->assertSame(1, $response->viewData('freshCount'), 'মাথার সংখ্যায় কোম্পানিহীন ভুল গোনা হয়েছে।');

        $this->makeTopRole($this->watcher);

        $response = $this->actingAs($this->watcher->fresh())
            ->get(route('governance.error.index'))
            ->assertOk();

        $seen = $this->ids($response);
        $this->assertContains($rows['mine'], $seen);
        $this->assertContains($rows['none'], $seen, 'সুপার অ্যাডমিনও কোম্পানিহীন ভুল দেখেন না — জরুরি ভুলটা কারো চোখেই নেই।');
        $this->assertNotContains($rows['theirs'], $seen, 'সুপার অ্যাডমিন হলেও খ-এর ভুল ক-তে নয়।');
        $this->assertSame(2, $response->viewData('freshCount'));
    }

    public function test_a_company_admin_cannot_mark_a_fault_with_no_company_as_seen_until_the_same_person_holds_the_top_role(): void
    {
        $rows = $this->seedFaults();

        $this->actingAs($this->watcher->fresh())
            ->post(route('governance.error.acknowledge', ErrorEvent::query()->findOrFail($rows['none'])))
            ->assertNotFound();
        $this->assertNull(ErrorEvent::query()->findOrFail($rows['none'])->acknowledged_at,
            'সাধারণ অ্যাডমিন কোম্পানিহীন ভুল "দেখেছি" বলে চাপা দিলেন।');

        $this->makeTopRole($this->watcher);

        $this->actingAs($this->watcher->fresh())
            ->post(route('governance.error.acknowledge', ErrorEvent::query()->findOrFail($rows['none'])))
            ->assertRedirect();
        $this->assertNotNull(ErrorEvent::query()->findOrFail($rows['none'])->acknowledged_at);

        // ⛔ অন্য কোম্পানির ভুল — সুপার অ্যাডমিনও নয়
        $this->actingAs($this->watcher->fresh())
            ->post(route('governance.error.acknowledge', ErrorEvent::query()->findOrFail($rows['theirs'])))
            ->assertNotFound();
        $this->assertNull(ErrorEvent::query()->findOrFail($rows['theirs'])->acknowledged_at);
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────

    private function member(Company $company, string $email, bool $current = true): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make(self::PASSWORD)]);
        $user->companies()->attach($company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $current ? $company->id : null])->save();

        return $user->fresh();
    }

    private function makeTopRole(User $user): void
    {
        CompanyContext::forCompany($this->a->id, fn () => $user->fresh()->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE));
    }

    /**
     * আসল লগইনের দরজা দিয়ে চারটা ব্যর্থ চেষ্টা — প্রতিটার পূর্বশর্ত যাচাই করে।
     *
     * @return array{unknown: int, mine: int, theirs: int, theirs_no_company: int}
     */
    private function seedLogins(): array
    {
        $mine = $this->member($this->a, 'a-person@nologs.test');
        $theirs = $this->member($this->b, 'b-person@nologs.test');
        $drifter = $this->member($this->b, 'b-drifter@nologs.test', current: false);

        $this->tryLogin(self::UNKNOWN_NAME);
        $this->tryLogin($mine->email);
        $this->tryLogin($theirs->email);
        $this->tryLogin($drifter->email);

        $row = fn (string $who) => LoginAttempt::query()->where('identifier', $who)->sole();

        $unknown = $row(self::UNKNOWN_NAME);
        $this->assertNull($unknown->company_id, 'পূর্বশর্ত: অচেনা নামের সারি কোম্পানিহীন হওয়ার কথা।');
        $this->assertNull($unknown->user_id);
        $this->assertFalse((bool) $unknown->succeeded);

        $this->assertSame($this->a->id, (int) $row($mine->email)->company_id);
        $this->assertSame($this->b->id, (int) $row($theirs->email)->company_id);
        $this->assertNull($row($drifter->email)->company_id, 'পূর্বশর্ত: চলতি কোম্পানিহীন মানুষের সারি কোম্পানিহীন।');

        CompanyContext::set($this->a->id);

        return [
            'unknown' => $unknown->id,
            'mine' => $row($mine->email)->id,
            'theirs' => $row($theirs->email)->id,
            'theirs_no_company' => $row($drifter->email)->id,
        ];
    }

    private function tryLogin(string $identifier): TestResponse
    {
        return $this->post(route('login.store'), [
            'identifier' => $identifier,
            'password' => 'not-the-password',
        ]);
    }

    /**
     * তিনটা ভুল — কোম্পানিহীনটা সত্যিকারের একটা ভাঙা অনুরোধ থেকে।
     *
     * @return array{none: int, mine: int, theirs: int}
     */
    private function seedFaults(): array
    {
        Route::get('/__nologs_break', function () {
            throw new RuntimeException('companyless-fault-3k');
        })->middleware('web');

        CompanyContext::clear();
        $this->get('/__nologs_break');

        $journal = app(ErrorJournal::class);
        CompanyContext::forCompany($this->a->id, fn () => $journal->record(new RuntimeException('fault-of-a-5m')));
        CompanyContext::forCompany($this->b->id, fn () => $journal->record(new RuntimeException('fault-of-b-8p')));

        $none = ErrorEvent::query()->where('message', 'companyless-fault-3k')->sole();
        $this->assertNull($none->company_id, 'পূর্বশর্ত: প্রসঙ্গহীন অনুরোধের ভুল কোম্পানিহীন হওয়ার কথা।');

        $mine = ErrorEvent::query()->where('message', 'fault-of-a-5m')->sole();
        $theirs = ErrorEvent::query()->where('message', 'fault-of-b-8p')->sole();
        $this->assertSame($this->a->id, (int) $mine->company_id);
        $this->assertSame($this->b->id, (int) $theirs->company_id);

        CompanyContext::set($this->a->id);

        return ['none' => $none->id, 'mine' => $mine->id, 'theirs' => $theirs->id];
    }

    /** @return list<int> */
    private function ids(TestResponse $response): array
    {
        return $response->viewData('rows')->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
