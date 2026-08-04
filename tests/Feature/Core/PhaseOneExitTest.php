<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Module\ModuleDefinition;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 1 শেষ হওয়ার শর্ত — প্ল্যান সেকশন ৬।
 *
 * "দুই কোম্পানি সুইচ হচ্ছে ও রিলোডে টিকছে; একটা ডামি মডেলে posting +
 * approval + audit + drill-down + দুই ভাষা — পাঁচটাই কাজ করছে।"
 *
 * এই একটা টেস্ট পুরো শর্তটা এক জায়গায় যাচাই করে, যাতে "Phase 1 শেষ" বলার
 * আগে কেউ অনুমান করতে না হয়।
 */
class PhaseOneExitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_all_eight_engines_are_wired_and_working(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $owner->switchCompany($alpha->id);
        CompanyContext::set($alpha->id, $owner->fresh()->current_branch_id);

        // ১. Module Registry — মডিউল ফোল্ডার থেকে, নির্ভরতার ক্রমে
        $registry = app(ModuleRegistry::class);
        $this->assertTrue($registry->has('accounts'));
        $this->assertTrue($registry->has('customer'));
        $this->assertTrue($registry->has('system_admin'));
        $this->assertSame(
            'accounts',
            array_key_first($registry->all()),
            'Accounts has no dependencies, so it must be built first.',
        );

        // ২. Number Series — row lock, কখনো দুইবার এক নম্বর নয়
        $numbers = app(NumberSeriesEngine::class);
        // JV — সেলস মডিউল এখনো নেই, তাই SI বলেও কিছু নেই
        $first = $numbers->next('JV', sourceType: 'journal_voucher', sourceId: 1);
        $second = $numbers->next('JV', sourceType: 'journal_voucher', sourceId: 2);
        $this->assertNotSame($first, $second);
        $this->assertSame('JRN-2026-2027-0001', $first);

        // ৩. Posting — ডেবিট = ক্রেডিট, নাহলে কিছুই বসে না
        app(PostingEngine::class)->post('journal_voucher', 1, '2026-08-04', [
            ['account_id' => 1101, 'debit' => 11500, 'party_type' => 'customer', 'party_id' => 7],
            ['account_id' => 4001, 'credit' => 10000],
            ['account_id' => 2201, 'credit' => 1500],
        ], documentNo: $first);

        $this->assertSame(3, LedgerEntry::query()->count());
        $this->assertEquals(
            LedgerEntry::query()->sum('debit'),
            LedgerEntry::query()->sum('credit'),
        );

        // ৪. Drill-down — প্রতিটা সংখ্যা তার উৎসে ফিরতে পারে (নিয়ম ১)
        $entry = LedgerEntry::query()->first();
        $this->assertSame('journal_voucher', $entry->source_type);
        $described = app(DrillResolver::class)->describe($entry->source_type, $entry->source_id);
        $this->assertSame('journal_voucher', $described['type']);

        // ৫. Approval — সীমার উপরে অনুমোদন লাগে, নিজেরটা নিজে দেওয়া যায় না
        $approvals = app(ApprovalEngine::class);
        $document = Branch::query()->first();

        $this->assertNull(
            $approvals->request($document, 'sales', 'discount', '500', userId: $salesman->id),
            'A small discount needs nobody.',
        );

        $large = $approvals->request($document, 'sales', 'discount', '2500', userId: $salesman->id);
        $this->assertNotNull($large);
        $this->assertSame('approved', $approvals->approve($large, $owner)->status);

        // ৬. Attachment — ফাইল ডিস্কে, ডাটাবেজে নয়
        $attachment = app(AttachmentEngine::class)->store(
            UploadedFile::fake()->create('চুক্তি.pdf', 40, 'application/pdf'),
            'customer',
            'Customer',
            7,
        );
        $this->assertSame(1, Attachment::query()->count());
        $this->assertArrayNotHasKey('file_data', $attachment->getAttributes());

        // ৭. Print — তিন কাগজ, দুই ভাষা
        $print = app(PrintEngine::class);
        foreach (PaperSize::all() as $paper) {
            foreach (['bn', 'en'] as $locale) {
                $this->assertStringStartsWith('%PDF-', $print->render('voucher', [
                    'title' => 'বিক্রয় চালান',
                    'voucher' => [
                        'document_no' => $first,
                        'date' => '০৪/০৮/২০২৬',
                        'total_debit' => '11,500.00',
                        'total_credit' => '11,500.00',
                        'lines' => [['account' => 'নগদ', 'debit' => '11,500.00', 'credit' => '']],
                    ],
                ], $paper, $locale));
            }
        }

        // ৮. Report — যোগফল পুরো ফলের উপর, প্রতিটা সারি ক্লিকযোগ্য
        $result = app(ReportEngine::class)->run(
            'accounts.day_book',
            ['from' => '2026-08-01', 'to' => '2026-08-31'],
        );
        $this->assertSame(3, $result->totalRows);
        $this->assertSame('11500.00', $result->totals['debit']);
    }

    public function test_the_cross_cutting_rules_hold(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        $owner->switchCompany($alpha->id);
        CompanyContext::set($alpha->id);

        app(PostingEngine::class)->post('journal_voucher', 1, '2026-08-04', [
            ['account_id' => 1101, 'debit' => 500],
            ['account_id' => 4001, 'credit' => 500],
        ]);

        // নিয়ম ৪ (অলঙ্ঘনীয় শর্ত ৪) — অন্য কোম্পানি কিছুই দেখে না
        CompanyContext::forCompany($beta->id, function () {
            $this->assertSame(0, LedgerEntry::query()->count());
        });

        // নিয়ম ৭ — প্রতিটা ঐচ্ছিক ফিল্ডের সুইচ, মডিউল থেকে ঘোষিত
        $settings = app(SettingsService::class);
        $this->assertTrue($settings->enabled('customer.credit_limit_enabled'));
        $this->assertArrayHasKey('customer.show_photo_on_print', $settings->group('customer', 'print'));

        // নিয়ম ৯ — দুই ভাষা, প্রতিটা লেখা
        foreach (['bn', 'en'] as $locale) {
            $this->assertNotSame('core.status.draft', __('core.status.draft', [], $locale));
            $this->assertNotSame('accounts::menu.ledger', __('accounts::menu.ledger', [], $locale));
        }

        // অনুমতি ডাটাবেজে — নাহলে মেনু ফাঁকা
        $this->assertSame([], app(PermissionSyncer::class)->drift()['unregistered']);

        // মেনু module.php থেকে, স্থির ক্রমে।
        //
        // কোনো মডিউলের নাম ধরে নয়: যে স্ক্রিন এখনো তৈরি হয়নি তার সারি
        // মেনুতে আসে না, তাই accounts-এর গ্রুপগুলো ধরে লিখলে পরীক্ষাটা
        // নিয়ম ভাঙার বদলে অগ্রগতির কারণে ভেঙে পড়ত।
        $menu = app(MenuBuilder::class)->forUser($owner);
        $this->assertNotEmpty($menu);

        foreach ($menu as $module) {
            $shown = array_keys($module['groups']);

            $this->assertSame(
                array_values(array_intersect(ModuleDefinition::MENU_GROUPS, $shown)),
                $shown,
            );
        }
    }

    public function test_switching_company_survives_a_reload(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        $owner->switchCompany($beta->id);

        // সেশনে নয়, রেকর্ডে — DMS-এ এই একটা পার্থক্যের কারণেই সুইচ
        // রিলোডে মুছে যেত।
        $this->assertSame($beta->id, User::query()->find($owner->id)->current_company_id);

        $this->actingAs($owner->fresh())->get('/')->assertOk()->assertSee('ফ্যামিলি মার্ট', false);
    }
}
