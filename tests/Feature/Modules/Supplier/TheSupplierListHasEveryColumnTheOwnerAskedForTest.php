<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সরবরাহকারীর তালিকায় মালিকের চাওয়া নয়টা কলামই আছে।
 *
 * ── ⭐ মালিকের নিজের ছক, ২১ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * *"SL#, Code, Name, Type, Address, Mobile, Payable, Status, Action"*
 *
 * ⓘ তিনটা ছিল না: **ক্রম**, **ঠিকানা** আর **কাজ**।
 *
 * ── ⚠️ কেন সারি থাকা জরুরি ─────────────────────────────────────────
 * কলামের শিরোনাম খালি তালিকাতেও আঁকা হয়। ⛔ তাই "কলামটা আছে" দেখে
 * থেমে গেলে দাবিটা কিছুই প্রমাণ করত না — আজ ঠিক ঐ ফাঁদে একবার পড়েছি
 * (বিলের তালিকায় কাজের কলাম বসিয়ে দেখেছিলাম শিরোনাম এসেছে, অথচ
 * ডেটাবেসে একটাও বিল ছিল না)।
 *
 * ⭐ তাই নিচের দাবিগুলো সারির **ভিতরের** জিনিস খোঁজে: ক্রমের সংখ্যা,
 * ঠিকানার লেখা, আর সম্পাদনার ঠিকানা।
 */
final class TheSupplierListHasEveryColumnTheOwnerAskedForTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_every_heading_the_owner_listed_is_there(): void
    {
        $text = $this->listText();

        $headings = [
            'core.table.serial',
            'supplier::field.code',
            'supplier::field.name',
            'supplier::field.party_type',
            'supplier::field.address',
            'supplier::field.phone',
            'supplier::field.payable',
            'supplier::field.state',
            'core.table.actions',
        ];

        foreach ($headings as $key) {
            $this->assertStringContainsString(__($key, [], 'bn'), $text,
                "⛔ '{$key}' কলামটা তালিকায় নেই — মালিকের ছকে আছে।");
        }
    }

    /**
     * ⭐ আর সারিগুলোয় সত্যিই জিনিসগুলো বসে — শিরোনাম একা যথেষ্ট নয়।
     */
    public function test_the_rows_carry_the_new_columns(): void
    {
        $supplier = Supplier::query()->firstOrFail();
        /*
         * ⚠️ দুইটা ঘরেই বসানো হয়: [[Supplier::address()]] বাংলা পর্দায়
         * `address_bn` আগে পড়ে আর সেটা ভরা থাকলে ইংরেজিটা ছোঁয়ও না।
         * ⓘ কেবল `address_en` বসিয়ে প্রথম চালে দাবিটা লাল হয়েছিল —
         * ভুলটা কোডের নয়, আমার অনুমানের।
         */
        $supplier->forceFill([
            'address_en' => 'Netrokona Bazar Road',
            'address_bn' => 'নেত্রকোনা বাজার রোড',
        ])->save();

        $text = $this->listText();

        $this->assertStringContainsString('নেত্রকোনা বাজার রোড', $text,
            '⛔ ঠিকানার কলামটা আছে, কিন্তু সারিতে ঠিকানাটা আসেনি।');

        $html = $this->listHtml();

        $this->assertStringContainsString(route('supplier.edit', $supplier), $html,
            '⛔ কাজের কলামে সম্পাদনার পথটা নেই।');

        $this->assertStringContainsString(route('supplier.show', $supplier), $html);
    }

    /**
     * ⛔ কাজের কলামে মোছা বা নিষ্ক্রিয় করার বোতাম নেই।
     *
     * ── ⓘ মোছা কেন নেই ────────────────────────────────────────────
     * যে সরবরাহকারীর নামে একটাও বিল আছে তাঁকে মুছলে ঐ বিলগুলোর মালিক
     * হারিয়ে যায় — খতিয়ানে টাকা থাকত, **কাকে দিতে হবে** তা থাকত না।
     *
     * ── ⚠️ নিষ্ক্রিয় করাটাও এখানে নেই, আর সেটা আলাদা কারণ ──────────
     * ⓘ অবস্থার কলামের পিলটাই সেই বোতাম। ⛔ দুই জায়গায় একই কাজ রাখলে
     * একদিন একটা বদলাত আর অন্যটা পুরনো আচরণে থেকে যেত।
     *
     * ⓘ `supplier.destroy` রুটটা **মোছে না, নিষ্ক্রিয় করে** (কারণটা
     * [[supplier::partials.state-badge]]-এ লেখা)। ⚠️ প্রথমে ওটার
     * অনুপস্থিতি দাবি করেছিলাম আর দাবিটা লাল হলো — পিলটা ঐ রুটই
     * ব্যবহার করে। নামটা দেখে অর্থ আন্দাজ করা আমার ভুল ছিল।
     */
    public function test_the_action_column_has_only_view_and_edit(): void
    {
        $supplier = Supplier::query()->firstOrFail();

        $actions = view('supplier::partials.row-actions', ['supplier' => $supplier])->render();

        $this->assertStringContainsString(route('supplier.show', $supplier), $actions);
        $this->assertStringContainsString(route('supplier.edit', $supplier), $actions);

        /*
         * ⚠️ ঠিকানা দিয়ে "নেই" প্রমাণ করা যায় না — `destroy` আর `show`
         * **একই ঠিকানা**, কেবল HTTP পদ্ধতি আলাদা (DELETE বনাম GET)।
         * ⛔ প্রথমে ঐভাবে লিখে দাবিটা লাল হয়েছিল, আর ভুলটা কোডের নয়:
         * নামটা আলাদা বলে ঠিকানাও আলাদা ধরে নেওয়া আমার অনুমান ছিল।
         *
         * ⭐ তাই মাপা হয় গড়নটা: কাজের কলামে কেবল দুইটা লিংক, একটাও
         * ফর্ম নয়। ⓘ অবস্থা বদলানো POST/DELETE লাগে, আর সেটা পিলের কাজ।
         */
        $this->assertStringNotContainsString('<form', $actions,
            '⛔ কাজের কলামে একটা ফর্ম এসেছে — অবস্থা বদলানো অবস্থার পিলের কাজ।');

        $this->assertSame(2, substr_count($actions, '<a href'),
            '⛔ কাজের কলামে দুইটার বেশি লিংক — ছকে কেবল দেখা আর সম্পাদনা।');
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের "নেই" দাবিটা চিরকাল সবুজ থাকত যদি তালিকাটাই খালি ফিরত।
     * ⚠️ তাই দেখা হয় সারি সত্যিই আঁকা হয়েছে — কোড ও ক্রমের সংখ্যাসহ।
     */
    public function test_the_list_really_drew_rows(): void
    {
        $supplier = Supplier::query()->firstOrFail();

        $text = $this->listText();

        $this->assertGreaterThan(0, Supplier::query()->count(),
            'ডেমোতে একটাও সরবরাহকারী নেই — দাবিগুলো তখন কিছুই প্রমাণ করে না।');

        $this->assertStringContainsString((string) $supplier->code, $text);
        $this->assertStringContainsString('1', $text, 'ক্রমের প্রথম সংখ্যাটাই নেই।');
    }

    private function listHtml(): string
    {
        return (string) $this->get(route('supplier.index'))->assertOk()->getContent();
    }

    private function listText(): string
    {
        return (string) preg_replace('/\s+/u', ' ', strip_tags($this->listHtml()));
    }
}
