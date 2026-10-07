<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\AuditFieldChange;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\MasterData\Models\Vehicle;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * অডিট — Global Features-এর প্রথম নিয়ম।
 *
 * ── কেন এটার পরীক্ষা এত কড়া ─────────────────────────────────────────
 * অডিটের ভুল কোনো ভুল দেখায় না। একটা মডিউলে লগ বাদ পড়লে পর্দা ঠিকই
 * চলে, রিপোর্ট ঠিকই মেলে — শুধু ইতিহাসে একটা ফাঁক থাকে। সেটা ধরা পড়ে
 * বছর পরে, যেদিন কেউ প্রশ্ন করে "এটা কে বদলেছিল?" আর উত্তরটা থাকে না।
 */
class AuditTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::query()->create([
            'code' => 'V-01',
            'name_en' => 'Blue Truck',
            'registration_no' => 'DHAKA METRO TA 11-2233',
            'capacity_kg' => 3000,
            'owner_type' => 'own',
        ]);
    }

    // ── লেখা হয় কি না ─────────────────────────────────────────────────

    public function test_creating_a_record_is_logged_with_who_and_what(): void
    {
        $vehicle = $this->vehicle();

        $trail = AuditTrail::query()->forRecord(Vehicle::class, $vehicle->id)->firstOrFail();

        $this->assertSame(AuditTrail::CREATED, $trail->action);
        $this->assertSame($this->user->id, $trail->user_id);
        $this->assertSame($this->company->id, $trail->company_id);
        $this->assertSame('V-01', $trail->document_no);
    }

    /**
     * সম্পাদনায় প্রতিটা ঘরের পুরাতন ও নতুন মান।
     *
     * এটাই স্পেকের কেন্দ্রীয় দাবি — "Qty ১০ → ১৫"।
     */
    public function test_an_edit_records_the_old_and_the_new_value_of_every_field(): void
    {
        $vehicle = $this->vehicle();

        $vehicle->update(['capacity_kg' => 4500, 'driver_name' => 'Jamal Uddin']);

        $trail = AuditTrail::query()
            ->forRecord(Vehicle::class, $vehicle->id)
            ->where('action', AuditTrail::UPDATED)
            ->with('changes')
            ->firstOrFail();

        $byField = $trail->changes->keyBy('field');

        $this->assertSame('3000.0000', $byField['capacity_kg']->old_value);
        $this->assertSame('4500', $byField['capacity_kg']->new_value);
        $this->assertNull($byField['driver_name']->old_value);
        $this->assertSame('Jamal Uddin', $byField['driver_name']->new_value);
    }

    /** কিছু না বদলে সংরক্ষণ করলে কোনো সারি বসে না। */
    public function test_saving_without_changing_anything_writes_nothing(): void
    {
        $vehicle = $this->vehicle();

        $before = AuditTrail::query()->count();
        $vehicle->update(['code' => 'V-01']);

        $this->assertSame($before, AuditTrail::query()->count());
    }

    /**
     * নম্বর এগোনো অডিটে যায় না, কিন্তু উপসর্গ বদলানো যায়।
     *
     * ── কেন এটা ধরা দরকার ───────────────────────────────────────────
     * ধরা পড়েছিল অডিটের CSV চোখে দেখে: অর্ধেক সারি ছিল "সম্পাদনা —
     * next_number: ৬ → ৭", প্রতিটা ডকুমেন্ট তৈরির পিছুপিছু একটা। কোনো
     * টেস্ট ভাঙেনি, কারণ লেখাটা তো ঠিকই হচ্ছিল — শুধু ওই সারিগুলোর
     * ভিড়ে আসল পরিবর্তনগুলো খুঁজে পাওয়া যেত না।
     *
     * উল্টো দিকটাও এখানে: উপসর্গ মানুষের সিদ্ধান্ত, আর সেটা লগ হবেই।
     * নাহলে "বিলের নম্বর হঠাৎ অন্যরকম কেন" প্রশ্নের উত্তর থাকত না।
     */
    public function test_a_number_series_logs_the_prefix_but_not_the_counter(): void
    {
        $series = NumberSeries::query()->firstOrFail();

        $before = AuditTrail::query()->count();
        $series->update(['next_number' => $series->next_number + 1]);

        $this->assertSame($before, AuditTrail::query()->count(), 'নম্বর এগোনো অডিটে বসেছে।');

        $series->update(['prefix' => 'XX']);

        $trail = AuditTrail::query()->latest('id')->firstOrFail();

        $this->assertSame(NumberSeries::class, $trail->auditable_type);
        $this->assertSame('prefix', $trail->changes()->firstOrFail()->field);
    }

    /**
     * বাতিল আর সাধারণ সম্পাদনা আলাদা করে চেনা যায়।
     *
     * দুইটাই status-এর বদল, কিন্তু তালিকায় এক দেখালে "কে বিলটা বাতিল
     * করেছিল" প্রশ্নের উত্তর খুঁজতে প্রতিটা সারি খুলে দেখতে হত।
     */
    public function test_a_cancellation_is_logged_as_a_cancellation_not_an_edit(): void
    {
        /*
         * টাকা স্থানান্তর বেছে নেওয়ার কারণ: এটাতেই status ও cancel_reason
         * দুইটাই আছে, আর বানাতে কোনো লাইন বা মজুদ লাগে না।
         */
        $transfer = MoneyTransfer::query()->create([
            'document_no' => 'MT-TEST-1',
            'financial_year_id' => FinancialYear::query()->value('id'),
            'trx_date' => now()->toDateString(),
            'amount' => '500',
            'status' => DocumentStatus::CONFIRMED,
        ]);

        $transfer->forceFill([
            'status' => DocumentStatus::CANCELLED,
            'cancel_reason' => 'ভুল করে বসানো হয়েছিল',
        ])->save();

        $trail = AuditTrail::query()
            ->forRecord(MoneyTransfer::class, $transfer->id)
            ->where('action', AuditTrail::CANCELLED)
            ->firstOrFail();

        $this->assertSame('ভুল করে বসানো হয়েছিল', $trail->reason);
    }

    /** নরম-মোছা ফেরানো আলাদা ঘটনা, সাধারণ সম্পাদনা নয়। */
    public function test_restoring_is_logged_as_a_restore(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->delete();
        $vehicle->restore();

        $actions = AuditTrail::query()
            ->forRecord(Vehicle::class, $vehicle->id)
            ->pluck('action')
            ->all();

        $this->assertContains(AuditTrail::DELETED, $actions);
        $this->assertContains(AuditTrail::RESTORED, $actions);
    }

    /** মুছে ফেলা রেকর্ডের ইতিহাস থেকে যায়, আর নম্বরটাও পড়া যায়। */
    public function test_a_deleted_record_keeps_its_history(): void
    {
        $vehicle = $this->vehicle();
        $id = $vehicle->id;

        $vehicle->delete();

        $trails = AuditTrail::query()->forRecord(Vehicle::class, $id)->get();

        $this->assertGreaterThanOrEqual(2, $trails->count());
        $this->assertSame('V-01', $trails->first()->document_no,
            'রেকর্ড না থাকলেও নম্বরটা সারিতেই থাকার কথা');
    }

    // ── অক্ষততা ───────────────────────────────────────────────────────

    /**
     * অডিটের সারি বদলানো যায় না।
     *
     * অনুমতি দিয়ে নয়, মডেলেই আটকানো — অনুমতি কেউ বদলাতে পারে, আর
     * কনসোল বা টিঙ্কার অনুমতির ধার ধারে না।
     */
    public function test_an_audit_row_cannot_be_edited(): void
    {
        $this->vehicle();

        $trail = AuditTrail::query()->firstOrFail();

        $this->expectException(RuntimeException::class);

        $trail->update(['action' => AuditTrail::DELETED]);
    }

    public function test_an_audit_row_cannot_be_deleted(): void
    {
        $this->vehicle();

        $trail = AuditTrail::query()->firstOrFail();

        $this->expectException(RuntimeException::class);

        $trail->delete();
    }

    public function test_a_field_change_cannot_be_edited_either(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->update(['driver_name' => 'Jamal']);

        $change = AuditFieldChange::query()->firstOrFail();

        $this->expectException(RuntimeException::class);

        $change->update(['new_value' => 'someone else']);
    }

    // ── গোপনীয়তা ─────────────────────────────────────────────────────

    /**
     * পাসওয়ার্ড কখনো অডিটে যায় না।
     *
     * গেলে অডিটটাই একটা ফাঁস হয়ে যেত — আর অডিট পড়ার অনুমতি সাধারণত
     * বেশি লোকের থাকে।
     */
    public function test_secrets_never_reach_the_audit(): void
    {
        foreach (['password', 'remember_token', 'api_token'] as $secret) {
            $this->assertContains($secret, AuditEngine::NEVER_LOGGED);
        }

        $engine = app(AuditEngine::class);

        $user = User::factory()->make(['password' => 'hashed-secret']);

        $this->assertArrayNotHasKey('password', $engine->attributesOf($user));
    }

    // ── কভারেজ ────────────────────────────────────────────────────────

    /**
     * কোম্পানির প্রতিটা মডেল হয় অডিটেড, নয় নাম-সহ ব্যতিক্রম।
     *
     * ── কেন এই পরীক্ষাটা ফাইল হাঁটে ─────────────────────────────────
     * "প্রতিটা মডিউলে অডিট বাধ্যতামূলক" নিয়মটা মনে রাখার উপর ছেড়ে
     * দিলে একদিন কেউ নতুন একটা মডেল লিখে ট্রেইট বসাতে ভুলত। ভুলটা
     * কোনো ভুল দেখাত না — শুধু ওই মডেলের ইতিহাস থাকত না।
     *
     * তাই তালিকাটা কোড থেকেই বের করা হয়, হাতে লেখা হয় না।
     */
    public function test_every_company_scoped_model_is_either_audited_or_a_named_exception(): void
    {
        $missing = [];

        foreach ($this->modelFiles() as $file => $source) {
            if (! str_contains($source, 'extends Model')) {
                continue;
            }

            $isScoped = str_contains($source, 'use BelongsToCompany;');
            $isLine = str_ends_with($file, 'Line.php');

            if (! $isScoped && ! $isLine) {
                continue;
            }

            $class = $this->classNameOf($file);

            if (array_key_exists($class, $this->exempt())) {
                continue;
            }

            if (! str_contains($source, 'use IsAudited;')) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing,
            'এই মডেলগুলোয় অডিট বসেনি, আর ব্যতিক্রমের তালিকাতেও নেই: '.implode(', ', $missing));
    }

    /** প্রতিটা ব্যতিক্রমের একটা লিখিত কারণ আছে। */
    public function test_every_exception_carries_a_reason(): void
    {
        foreach ($this->exempt() as $class => $reason) {
            $this->assertTrue(class_exists($class), "ব্যতিক্রমের তালিকায় অচেনা ক্লাস: {$class}");
            $this->assertNotSame('', trim($reason), "কারণ ছাড়া ব্যতিক্রম: {$class}");
        }
    }

    /**
     * অডিটের ব্যতিক্রম — কোরের ও প্রতিটা মডিউলের, একসাথে।
     *
     * ── কেন দুই জায়গা ───────────────────────────────────────────────
     * কোরের নিজের মডেলগুলো (অডিট টেবিল, খতিয়ান, নম্বর ইস্যু) কোরেই
     * থাকে। মডিউলের মডেল মডিউলেই — নইলে কোর ওই মডিউলের নাম জেনে
     * ফেলত (§১৯.৭), আর সবাই যার উপর দাঁড়ায় সে কারও উপর দাঁড়ালে
     * সীমানাটাই থাকে না।
     *
     * পাহারাটা একটুও দুর্বল হয়নি: দুইটা তালিকা এখানে মিলিয়ে দেখা হয়,
     * তাই নতুন মডেলে অডিট বসাতে ভুলে গেলে আগের মতোই ধরা পড়ে।
     *
     * @return array<class-string, string>
     */
    private function exempt(): array
    {
        $exempt = AuditEngine::NOT_AUDITED;

        foreach (app(ModuleRegistry::class)->all() as $module) {
            $exempt = [...$exempt, ...$module->auditExempt];
        }

        return $exempt;
    }

    // ── পর্দা ─────────────────────────────────────────────────────────

    public function test_the_governance_screens_open_and_show_the_change(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->update(['driver_name' => 'Jamal Uddin']);

        $this->get(route('governance.audit.index'))
            ->assertOk()
            ->assertSee('V-01')
            ->assertSee('Jamal Uddin');

        $trail = AuditTrail::query()->latest('id')->firstOrFail();

        $this->get(route('governance.audit.show', $trail->id))->assertOk()->assertSee('driver_name');
        $this->get(route('governance.audit.record', $trail->id))->assertOk()->assertSee('V-01');
    }

    public function test_the_filters_narrow_the_list(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->update(['driver_name' => 'Jamal Uddin']);

        // শুধু তৈরি — সম্পাদনার সারিটা বাদ পড়ার কথা
        $this->get(route('governance.audit.index', ['action' => AuditTrail::CREATED]))
            ->assertOk()
            ->assertDontSee('Jamal Uddin');

        $this->get(route('governance.audit.index', ['action' => AuditTrail::UPDATED]))
            ->assertOk()
            ->assertSee('Jamal Uddin');
    }

    /** অডিট পড়ার নিজের অনুমতি লাগে। */
    public function test_the_audit_needs_its_own_permission(): void
    {
        $clerk = User::factory()->create();
        $clerk->companies()->attach($this->company->id);

        $this->actingAs($clerk)->get(route('governance.audit.index'))->assertForbidden();
    }

    /** অন্য কোম্পানির পরিবর্তন এই কোম্পানির অডিটে দেখা যায় না। */
    public function test_the_audit_never_crosses_companies(): void
    {
        $vehicle = $this->vehicle();

        $mine = AuditTrail::query()->forRecord(Vehicle::class, $vehicle->id)->firstOrFail();

        $other = Company::query()->where('code', '!=', 'TDEPOT')->firstOrFail();

        CompanyContext::set($other->id);

        /*
         * মোট গোনা দিয়ে যাচাই হয় না: সিডার দুইটা কোম্পানিরই ডেটা বানায়,
         * আর এখন প্রতিটা সারিই অডিটেড — তাই অন্য কোম্পানিতেও নিজের সারি
         * থাকে। প্রশ্নটা "কিছু আছে কি না" নয়, "আমারটা ওখান থেকে দেখা
         * যায় কি না"।
         */
        $this->assertNull(AuditTrail::query()->find($mine->id),
            'এক কোম্পানির অডিট অন্য কোম্পানি থেকে দেখা যাওয়ার কথা নয়');
    }

    /**
     * ফাইলগুলো একবার পড়া — প্রতিটা পরীক্ষায় আবার হাঁটা নয়।
     *
     * @return array<string, string>
     */
    private function modelFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }

        return $files;
    }

    private function classNameOf(string $path): string
    {
        $relative = str_replace([app_path().DIRECTORY_SEPARATOR, '/', '.php'], ['', '\\', ''], $path);
        $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        return 'App\\'.$relative;
    }
}
