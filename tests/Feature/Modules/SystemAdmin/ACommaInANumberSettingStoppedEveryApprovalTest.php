<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\PlainNumber;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সংখ্যার সেটিংয়ে "10,000" লিখলে প্রতিটা অনুমোদন ভাঙত — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ────────────────────────────────────────────────────
 * Control Panel সংখ্যার ঘর যাচাই ছাড়াই বসাত (`default => $raw`)। "10,000" টেক্সট
 * হয়ে জমত, আর [[ApprovalEngine]]-এর `bccomp()` ঐ লেখায় ব্যতিক্রম ছুঁড়ত।
 */
final class ACommaInANumberSettingStoppedEveryApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'approval.self_limit';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_lakh_commas_and_bengali_digits_are_saved_as_a_plain_number(): void
    {
        foreach (['10,000' => '10000', '১,০০,০০০' => '100000', ' 2500.50 ' => '2500.50'] as $typed => $stored) {
            $this->put(route('system_admin.control-panel.update'), [
                'scope' => [self::KEY],
                'settings' => [self::KEY => $typed],
            ])->assertRedirect()->assertSessionHasNoErrors();

            app(SettingsService::class)->flush();

            $this->assertSame($stored, (string) app(SettingsService::class)->get(self::KEY),
                "⛔ \"{$typed}\" যেমন লেখা তেমনই বসল — অনুমোদনের ইঞ্জিন এটা পড়তে পারে না।");
        }
    }

    public function test_something_that_is_not_a_number_is_refused_with_its_name_and_nothing_is_saved(): void
    {
        app(SettingsService::class)->set(self::KEY, '5000');

        foreach (['ten thousand', '1e5', '10,000 টাকা'] as $typed) {
            $this->put(route('system_admin.control-panel.update'), [
                'scope' => [self::KEY],
                'settings' => [self::KEY => $typed],
            ])->assertRedirect()->assertSessionHasErrors('settings');

            app(SettingsService::class)->flush();
            $this->assertSame('5000', (string) app(SettingsService::class)->get(self::KEY), "⛔ \"{$typed}\" সংখ্যা না হয়েও বসে গেল।");
        }
    }

    /** ⓘ লাইভে আগেই জমে থাকা "10,000" — ইঞ্জিন সেটাও পড়ে, ভাঙে না। */
    public function test_the_engine_reads_a_comma_number_already_stored_before_the_fix(): void
    {
        app(SettingsService::class)->set(self::KEY, '10,000');
        app(SettingsService::class)->flush();

        $limit = (fn () => $this->selfLimit())->call(app(ApprovalEngine::class));

        $this->assertSame('10000', $limit);
        $this->assertSame(1, bccomp('10001', $limit, 4));
    }

    public function test_the_reader_itself(): void
    {
        $this->assertSame('10000', PlainNumber::from('১০,০০০'));
        $this->assertNull(PlainNumber::from('1e5'));
        $this->assertNull(PlainNumber::from(['10']));
        $this->assertNull(PlainNumber::from(''));
    }
}
