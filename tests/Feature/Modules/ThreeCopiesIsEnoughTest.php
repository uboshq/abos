<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * তিনটা কপিই যথেষ্ট।
 *
 * ── কী ছিল, আর কেন সেটা যথেষ্ট নয় ───────────────────────────────────
 * গোনা ও DUPLICATE ছাপ আগে থেকেই ছিল, কিন্তু দুইটাই **নিষ্ক্রিয়**:
 * তারা বলে দেয় কাগজটা দ্বিতীয়বার ছাপা, কেউ আটকায় না। কর্মী চাইলে
 * বিশবার ছাপতে পারতেন, আর প্রতিটাতেই DUPLICATE বসত — যেটা কেউ পড়ে না,
 * কারণ সব কপিতেই লেখা।
 *
 * DUPLICATE বসানোর কারণটাই ছিল কর্মীর দ্বিতীয়বার টাকা নেওয়া ঠেকানো।
 * ছাপা না আটকালে ওই কারণটার অর্ধেকই কেবল পূরণ হয়।
 */
class ThreeCopiesIsEnoughTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => '500']],
        );

        $this->invoice = app(SalesInvoiceService::class)->confirm($invoice);
    }

    private function limit(int $times): void
    {
        app(SettingsService::class)->set('sales.reprint_limit', $times);
    }

    /** একটা কাগজ ছাপা — যতবার বলা হয়। */
    private function printIt(int $times = 1): TestResponse
    {
        $response = null;

        for ($i = 0; $i < $times; $i++) {
            $response = $this->get(route('sales.print.invoice', [
                'invoice' => $this->invoice->id,
                'paper' => 'a4',
            ]));
        }

        return $response;
    }

    private function job(): ?PrintJob
    {
        return PrintJob::query()->where('document_id', $this->invoice->id)->first();
    }

    // ── ডিফল্টে কিছুই বদলায়নি ───────────────────────────────────────

    /**
     * সীমা বসানো না থাকলে যতবার খুশি।
     *
     * ── কেন এটাই ডিফল্ট ─────────────────────────────────────────────
     * চালু ব্যবস্থায় হঠাৎ সীমা বসালে যিনি রোজ তিনটা কপি ছাপেন তাঁর
     * কাজ কাল সকালে থামত, আর তিনি ভাবতেন আপগ্রেডে কিছু ভেঙেছে।
     */
    public function test_with_no_limit_set_nothing_changes(): void
    {
        $this->printIt(5)->assertOk();

        $this->assertSame(5, $this->job()->printed_count);
    }

    // ── সীমাটা সত্যিই আটকায় ─────────────────────────────────────────

    /** সীমা পর্যন্ত ছাপা যায়। */
    public function test_printing_up_to_the_limit_works(): void
    {
        $this->limit(3);

        $this->printIt(3)->assertOk();

        $this->assertSame(3, $this->job()->printed_count);
    }

    /**
     * সীমার পরের চেষ্টাটা থেমে যায়।
     *
     * এটাই পুরো কাজটা — আগে গোনাটা বাড়ত আর DUPLICATE বসত, কিন্তু
     * কাগজটা ঠিকই বেরোত।
     */
    public function test_the_next_one_is_refused(): void
    {
        $this->limit(2);

        $this->printIt(2)->assertOk();

        /*
         * ক্যাশিয়ার হিসেবে, মালিক হিসেবে নয়।
         *
         * ── কেন এটা গুরুত্বপূর্ণ ────────────────────────────────────
         * মালিকের হাতে সব চাবি, তাই ছাড়ানোর চাবিটাও — অর্থাৎ তাঁর
         * বেলায় সীমাটা কোনোদিন আটকাবে না, আর সেটাই ঠিক। কিন্তু
         * পরীক্ষাটা মালিক দিয়ে লিখলে সে কিছুই প্রমাণ করত না: সবুজ
         * থাকত সীমাটা সম্পূর্ণ ভাঙা অবস্থাতেও।
         *
         * সীমাটা যাঁর জন্য বানানো — যিনি কাউন্টারে দাঁড়িয়ে বিশবার
         * ছাপতে পারতেন — পরীক্ষাটাও তাঁকে দিয়েই।
         */
        $this->actingAs($this->clerk());

        $this->expectException(ValidationException::class);

        $this->withoutExceptionHandling()->printIt();
    }

    /**
     * আটকে যাওয়া চেষ্টায় গোনাটা বাড়ে না।
     *
     * ── কেন এটা আলাদা করে পরীক্ষা ───────────────────────────────────
     * থামানোটা যদি PDF তৈরির পরে হত, তবে কাগজটা তৈরি হয়ে যেত, শুধু
     * ফেরত দেওয়া হত না — আর গোনাটা বেড়ে যেত এমন একটা কাগজের জন্য
     * যেটা কেউ পায়নি। তখন সীমাটা আসলে যা লেখা তার চেয়ে কম হত।
     */
    public function test_a_refused_attempt_does_not_count(): void
    {
        $this->limit(2);
        $this->printIt(2)->assertOk();

        $this->actingAs($this->clerk());

        try {
            $this->withoutExceptionHandling()->printIt();
        } catch (ValidationException) {
            // আটকানোই উদ্দেশ্য
        }

        $this->assertSame(2, $this->job()->printed_count,
            'আটকে যাওয়া চেষ্টাটাও গোনা হয়েছে — সীমাটা লেখার চেয়ে কম হয়ে গেছে।');
    }

    // ── ছাড়ানোর পথ ──────────────────────────────────────────────────

    /** @param  list<string>  $extra */
    private function clerk(array $extra = []): User
    {
        foreach (['sales.invoice.view', 'sales.reprint.override'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();
        $user->givePermissionTo(array_merge(['sales.invoice.view'], $extra));

        return $user->fresh();
    }

    /**
     * অনুমতি থাকলে সীমার পরেও ছাপা যায়।
     *
     * প্রিন্টার কাগজ চিবোয়, কালি ফুরায়, ক্রেতা কপি হারান। কেউই ছাড়াতে
     * না পারলে ওই কাগজটা আর কোনোদিন ছাপা যেত না — আর তখন কেউ বিলটা
     * বাতিল করে নতুন বিল কাটতেন, যেটা অনেক বেশি ক্ষতিকর।
     */
    public function test_someone_with_the_key_can_go_past_it(): void
    {
        $this->limit(1);
        $this->printIt()->assertOk();

        $this->actingAs($this->clerk(['sales.reprint.override']))
            ->get(route('sales.print.invoice', ['invoice' => $this->invoice->id, 'paper' => 'a4']))
            ->assertOk();

        $this->assertSame(2, $this->job()->printed_count);
    }

    /** অনুমতি ছাড়া নয় — নাহলে সীমাটার কোনো মানে থাকত না। */
    public function test_someone_without_it_cannot(): void
    {
        $this->limit(1);
        $this->printIt()->assertOk();

        $this->actingAs($this->clerk());

        $this->expectException(ValidationException::class);

        $this->withoutExceptionHandling()
            ->get(route('sales.print.invoice', ['invoice' => $this->invoice->id, 'paper' => 'a4']));
    }

    /**
     * মালিকের বেলায় সীমাটা আটকায় না — আর সেটাই ঠিক।
     *
     * মালিকের হাতে সব চাবি, তাই ছাড়ানোর চাবিটাও। এটা লিখে রাখা হলো
     * কারণ নাহলে পরের কেউ এটাকে ফাঁক ভেবে "সারাতে" বসতেন — আর তখন
     * প্রিন্টার বিগড়ানোর দিনে মালিক নিজেও কাগজ ছাপতে পারতেন না।
     */
    public function test_the_owner_is_never_stopped(): void
    {
        $this->limit(1);

        $this->printIt(3)->assertOk();

        $this->assertSame(3, $this->job()->printed_count);
    }

    // ── সংখ্যাটা মালিকের ────────────────────────────────────────────

    /**
     * সীমাটা প্রতি কোম্পানির নিজের — কন্ট্রোল প্যানেলে।
     *
     * এক ডিপোর "যথেষ্ট" আরেকটার "কম"। কোডে বসালে বদলাতে ডেভেলপার লাগত।
     */
    public function test_the_number_is_the_owners_to_set(): void
    {
        $this->assertSame(0, (int) app(SettingsService::class)->get('sales.reprint_limit', 0));

        $this->limit(4);

        $this->assertSame(4, (int) app(SettingsService::class)->get('sales.reprint_limit', 0));
    }

    /** বার্তাটা বলে কতবার হয়েছে — নাহলে কেউ বুঝত না কী করতে হবে। */
    public function test_the_message_says_how_many_times_it_was_printed(): void
    {
        $this->limit(1);
        $this->printIt()->assertOk();

        $this->actingAs($this->clerk());

        try {
            $this->withoutExceptionHandling()->printIt();

            $this->fail('সীমা পেরিয়েও কাগজটা বেরিয়েছে।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($this->invoice->document_no, $e->getMessage());
            $this->assertStringContainsString('1', $e->getMessage());
        }
    }
}
