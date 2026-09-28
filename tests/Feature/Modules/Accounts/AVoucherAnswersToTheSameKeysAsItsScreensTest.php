<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ভাউচারের নীতি পর্দার দরজার চাবিই মানে — ২৮ সেপ্টেম্বর ২০২৬ ([[VoucherPolicy]])।
 *
 * ── ⛔ কী ছিল ────────────────────────────────────────────────────────────
 * ভাউচারের কোনো নীতি ছিল না: `can('view'|'create'|…, ভাউচার)` সবার জন্য —
 * মালিকেরও — মিথ্যা। ⚠️ তাই ভাউচারের পাতায় কাগজ তোলার ফর্ম কোনোদিন আঁকা
 * হয়নি, আর কাগজ নামানোর দরজা সবসময় ৪০৩ — কিছুই লাল হত না।
 *
 * ⭐ প্রতিটা দাবি **একই মানুষ, একই ভাউচার** — কেবল চাবিটা বন্ধ, তারপর খোলা।
 * দুইজন আলাদা মানুষ দিয়ে মাপলে কোম্পানি বা শাখার সদস্যপদও ৪০৩ বানাতে
 * পারত, আর নীতিটা আদৌ কিছু বলছে কি না জানা যেত না।
 */
class AVoucherAnswersToTheSameKeysAsItsScreensTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Voucher $voucher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        // ⓘ জাবেদা — টাকার খাত নেই, তাই নগদ-পাহারা বা অনুমোদন-ছক কিছুই মাঝে আসে না
        $this->voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'KEY-DOOR'],
            [
                ['account_id' => StandardChart::find(StandardChart::BANK_CHARGES)->id, 'debit' => '100', 'credit' => '0'],
                ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => '0', 'credit' => '100'],
            ],
        );
    }

    /** দেখা — `accounts.report`, ভাউচারের পাতার দরজার চাবি। */
    public function test_seeing_a_voucher_follows_the_report_key(): void
    {
        $user = $this->withEverythingBut('accounts.report');

        $this->assertFalse($user->can('view', $this->voucher), '⛔ চাবি ছাড়াই নীতি "দেখতে পারেন" বলছে।');
        $this->actingAs($user)->get(route('accounts.voucher.show', $this->voucher))->assertForbidden();

        $user = $this->grant($user, 'accounts.report');

        $this->assertTrue($user->can('view', $this->voucher),
            '⛔ চাবি পেয়েও নীতি "না" বলছে — তাহলে উপরের "না" কিছুই প্রমাণ করে না।');
        $this->actingAs($user)->get(route('accounts.voucher.show', $this->voucher))->assertOk();
    }

    /** কাগজ তোলা — `accounts.voucher.create`; ফর্মটা কেবল তখনই পাতায় আসে। */
    public function test_the_upload_form_appears_only_with_the_create_key(): void
    {
        $user = $this->withEverythingBut('accounts.voucher.create');
        $form = 'action="'.route('attachment.store').'"';

        $this->assertFalse($user->can('create', Voucher::class));
        $this->actingAs($user)->get(route('accounts.voucher.show', $this->voucher))
            ->assertOk()
            ->assertDontSee($form, false);

        $user = $this->grant($user, 'accounts.voucher.create');

        $this->assertTrue($user->can('create', Voucher::class));
        $this->actingAs($user)->get(route('accounts.voucher.show', $this->voucher))
            ->assertOk()
            ->assertSee($form, false)
            // ⚠️ ফর্মের source_type এমন নাম হতে হবে যা খুঁজে পাওয়া যায় — নাহলে জমা ৪০৪ দিত
            ->assertSee('name="source_type" value="'.Voucher::drillSourceType().'"', false);
    }

    /** বদল আর বাতিল — কন্ট্রোলারের `update` আর `delete` চাবির সাথে হুবহু। */
    public function test_update_and_delete_follow_their_own_keys(): void
    {
        foreach (['update' => 'accounts.voucher.update', 'delete' => 'accounts.voucher.delete'] as $ability => $key) {
            $user = $this->withEverythingBut($key);

            $this->assertFalse($user->can($ability, $this->voucher), "⛔ '{$key}' ছাড়াই নীতি '{$ability}'-এ হ্যাঁ বলছে।");

            $user = $this->grant($user, $key);

            $this->assertTrue($user->can($ability, $this->voucher), "⛔ '{$key}' পেয়েও নীতি '{$ability}'-এ না বলছে।");
        }
    }

    /**
     * দরজাগুলো নীতি জিজ্ঞেস করে — প্রতিটা দরজা তার আগের চাবিতেই খোলে (২৮ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ মিডলওয়্যার `can:accounts.…` থেকে `can:ability,voucher`-এ সরল। ⚠️ সরানোয়
     * কোনো দরজার চাবি বদলালে ঠিক এখানে লাল হয়: একই মানুষ, চাবি বন্ধ → ৪০৩, খোলা → খোলে।
     */
    public function test_every_voucher_door_still_opens_with_its_own_key(): void
    {
        $doors = [
            'accounts.report' => fn () => $this->get(route('accounts.voucher.index', ['type' => Voucher::JOURNAL])),
            'accounts.voucher.create' => fn () => $this->get(route('accounts.voucher.create', ['type' => Voucher::JOURNAL])),
            'accounts.voucher.update' => fn () => $this->get(route('accounts.voucher.edit', $this->voucher)),
            'accounts.voucher.delete' => fn () => $this->post(route('accounts.voucher.cancel', $this->voucher),
                ['cancel_reason' => 'KEY-DOOR বাতিল']),
        ];

        foreach ($doors as $key => $knock) {
            $user = $this->withEverythingBut($key);
            $this->actingAs($user);
            $this->assertSame(403, $knock()->status(), "⛔ '{$key}' ছাড়াই দরজাটা খুলল।");

            $user = $this->grant($user, $key);
            $this->actingAs($user);
            $this->assertNotSame(403, $knock()->status(),
                "⛔ '{$key}' পেয়েও দরজা বন্ধ — চাবি সরানোর সময় বদলে গেছে।");
        }
    }

    /** কাগজ নামানো — ভাউচার দেখার চাবি ছাড়া ৪০৩, চাবি পেলে খোলে। */
    public function test_a_paper_on_a_voucher_opens_with_the_view_key(): void
    {
        Storage::fake('local');

        $paper = app(AttachmentEngine::class)->store(
            file: UploadedFile::fake()->createWithContent('note.txt', 'a plain note'),
            module: 'accounts',
            entity: Voucher::drillSourceType(),
            entityId: (int) $this->voucher->id,
            userId: $this->owner->id,
        );

        $user = $this->withEverythingBut('accounts.report');

        $this->actingAs($user)->get(route('attachment.download', $paper))->assertForbidden();

        $user = $this->grant($user, 'accounts.report');

        $this->actingAs($user)->get(route('attachment.download', $paper))->assertOk();
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** ঐ একটা চাবি ছাড়া বাকি সব — ৪০৩ হলে দায়ী কেবল ঐ চাবিটা। */
    private function withEverythingBut(string $key): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id);
        $user->givePermissionTo(Permission::query()->where('name', '<>', $key)->get());

        $user = $user->fresh();
        $this->assertFalse($user->can($key), "প্রস্তুতিটাই ভুল — '{$key}' না দিয়েও হাতে আছে।");

        return $user;
    }

    private function grant(User $user, string $key): User
    {
        $user->givePermissionTo($key);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $user = $user->fresh();
        $this->assertTrue($user->can($key), "প্রস্তুতিটাই ভুল — '{$key}' দিয়েও হাতে আসেনি।");

        return $user;
    }
}
