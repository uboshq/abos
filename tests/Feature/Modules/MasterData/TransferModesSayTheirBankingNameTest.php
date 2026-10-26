<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Console\Commands\FixTransferModeNames;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\TransferMode;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ NPSB, BEFTN, RTGS — বাংলাতেও ব্যাংকিং পরিভাষা, লিপ্যন্তর নয় (fe, ২৪ অক্টোবর ২০২৬; আদায়ের পর্দা লাইভে যাওয়ার আগে)।
 *
 * seed এখন ঠিক নাম বসায়; পুরনো কোম্পানির সারি [[FixTransferModeNames]] বদলায় — কেবল ঠিক পুরনো লিপ্যন্তর, হাতে বসানো নাম
 * কখনো নয়, `--dry` কিছু লেখে না, আর প্রতিটা বদল নিরীক্ষায়। শেষে আদায়ের পর্দায় "NPSB"।
 */
class TransferModesSayTheirBankingNameTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        // ⓘ seed খালি তালিকাতেই বসায় ("একটাও থাকলে থামো"), তাই আগে এই কোম্পানির মোডগুলো সরিয়ে seed নতুন করে
        TransferMode::query()->withTrashed()->forceDelete();
        app(MasterListService::class)->installDefaults();
    }

    private function bn(string $code): ?string
    {
        return TransferMode::query()->where('code', $code)->value('name_bn');
    }

    public function test_the_seed_writes_the_banking_names(): void
    {
        $this->assertSame(['NPSB', 'BEFTN', 'RTGS'], [$this->bn('NPSB'), $this->bn('BEFTN'), $this->bn('RTGS')],
            '⛔ নতুন কোম্পানির ট্রান্সফার মোড এখনো লিপ্যন্তরে ("এনপিএসবি"…) — seed ব্যাংকিং নাম বসায়নি');
    }

    public function test_the_command_fixes_only_the_old_transliterations_audits_them_and_dry_writes_nothing(): void
    {
        // ⓘ লাইভের মতো: দুটো পুরনো লিপ্যন্তরে, একটা কেউ হাতে বসিয়েছেন
        TransferMode::query()->where('code', 'NPSB')->update(['name_bn' => FixTransferModeNames::OLD['NPSB']]);
        TransferMode::query()->where('code', 'RTGS')->update(['name_bn' => FixTransferModeNames::OLD['RTGS']]);
        TransferMode::query()->where('code', 'BEFTN')->update(['name_bn' => 'বেফটেন (আমাদের ব্যাংকের নাম)']);
        $trails = fn (): int => AuditTrail::query()->where('auditable_type', TransferMode::class)->count();
        $before = $trails();
        // ⓘ লাইভের মতো: কমান্ড চলে কনসোলে, কেউ লগইন করা নেই — নিরীক্ষা তবুও লিখতে হবে (fe গুনবেন ৯টা সারি)
        \Illuminate\Support\Facades\Auth::forgetUser();

        $this->artisan('abos:fix-transfer-mode-names', ['--dry' => true])->assertSuccessful();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->assertSame([FixTransferModeNames::OLD['NPSB'], FixTransferModeNames::OLD['RTGS']], [$this->bn('NPSB'), $this->bn('RTGS')],
            '⛔ --dry নাম বদলে দিল — শুকনো চালানো কিছু লেখে না');
        $this->assertSame($before, $trails(), '⛔ --dry নিরীক্ষায় লিখল');

        $this->artisan('abos:fix-transfer-mode-names')->assertSuccessful();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->assertSame(['NPSB', 'RTGS'], [$this->bn('NPSB'), $this->bn('RTGS')], '⛔ পুরনো লিপ্যন্তর বদলায়নি — "এনপিএসবি" থেকে গেল');
        $this->assertSame('বেফটেন (আমাদের ব্যাংকের নাম)', $this->bn('BEFTN'), '⛔ হাতে বসানো নাম বদলে গেল — কমান্ড কেবল পুরনো লিপ্যন্তর ছোঁয়');
        $this->assertSame($before + 2, $trails(), '⛔ দুটো নাম বদলের নিরীক্ষা নেই (বা হাতে বসানোটাও লেখা হলো) — কনসোলে, লগইন ছাড়া');
        $npsb = (int) TransferMode::query()->where('code', 'NPSB')->value('id');
        $change = \App\Models\AuditFieldChange::query()->where('field', 'name_bn')->whereIn('audit_trail_id',
            AuditTrail::query()->where('auditable_type', TransferMode::class)->where('auditable_id', $npsb)->select('id'))->latest('id')->first();
        $this->assertSame([FixTransferModeNames::OLD['NPSB'], 'NPSB'], [$change?->old_value, $change?->new_value],
            '⛔ নিরীক্ষায় NPSB-এর পুরনো আর নতুন নাম নেই');

        $page = $this->actingAs($this->owner)->get(route('sales.collection.create'))->assertOk();
        $page->assertSee('NPSB', false);
        $page->assertDontSee(FixTransferModeNames::OLD['NPSB'], false);
    }
}
