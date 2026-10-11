<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\RealAccounts;
use Tests\TestCase;

/**
 * রিপোর্টে ভুল তারিখে ৫০০, আর খুঁজলে চলমান জের ভাঙত — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ────────────────────────────────────────────────────
 * ① `Carbon::parse()` যাচাই ছাড়া — `from=abc` লিখলেই রিপোর্টের পাতা ৫০০।
 * ② খুঁজে দ্বিতীয় পাতায় গেলে শুরুর জের আসত **না-খোঁজা** সারিগুলো থেকে,
 *    অথচ পর্দার সারিগুলো খোঁজা ফল — তাই চলমান জের মিথ্যা।
 */
final class AReportBrokeOnABadDateAndASearchTest extends TestCase
{
    use RealAccounts;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id);
    }

    private function phone(string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail(), [AuthController::APP]);

        return $this->get($url, ['Accept' => 'application/json']);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * ⓘ ফোনের দরজায় (JSON, `api/*`) ৪২২ — বার্তা বাংলায়, তারিখের ঘরের নামে।
     */
    public function test_a_date_that_is_not_a_date_is_a_422_on_the_phone_not_a_500(): void
    {
        foreach (['abc', '2026-02-30', '31-12-2026'] as $bad) {
            $this->phone('/api/v1/reports/accounts.ledger?'.http_build_query(['from' => $bad, 'to' => '2026-08-31']))
                ->assertStatus(422)
                ->assertJsonValidationErrors('from');

            $this->assertStringNotContainsString('must match', (string) $this->phone('/api/v1/reports/accounts.ledger?from='.$bad)->json('errors.from.0'),
                '⛔ ফোনের বার্তা ইংরেজিতে — মালিক কেবল বাংলা পড়েন।');
        }

        $this->phone('/api/v1/reports/accounts.ledger?'.http_build_query(['from' => '2026-08-01', 'to' => ['x']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    /**
     * ⓘ ওয়েবের পর্দায় এই প্রকল্পের নিয়মে বৈধতার ভুল মানে পেছনে ফেরা, বার্তাসহ — ৫০০ নয়।
     */
    public function test_a_date_that_is_not_a_date_is_a_message_on_the_web_not_a_500(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->get(route('accounts.report.show', ['slug' => 'ledger', 'from' => 'abc', 'to' => '2026-08-31']))
            ->assertRedirect()
            ->assertSessionHasErrors(['from' => __('validation.report_bad_date')]);
    }

    public function test_the_engine_refuses_a_bad_date_with_the_fields_name(): void
    {
        try {
            app(ReportEngine::class)->run('accounts.ledger', ['from' => 'yesterday-ish', 'to' => '2026-08-31']);
            $this->fail('ভুল তারিখ মেনে নেওয়া হলো।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('from', $e->errors());
        }

        // ⛔ শুরু না দিয়ে কেবল ভুল "শেষ" — শুরুর তারিখ শেষ থেকে গোনা হয়, তাই যাচাই তার আগে (cb-র রিভিউ, ১১ অক্টোবর ২০২৬)
        foreach (['abc', ['x']] as $bad) {
            try {
                app(ReportEngine::class)->run('accounts.ledger', ['to' => $bad]);
                $this->fail('শুরু ছাড়া ভুল শেষ-তারিখ মেনে নেওয়া হলো।');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('to', $e->errors());
            }
        }
    }

    /**
     * চালান ১,০০০ থেকে ৮,০০০; বিজোড়গুলো ALPHA, জোড়গুলো BETA।
     * "ALPHA" খুঁজে দুই সারির পাতায়: প্রথম পাতা ১,০০০ + ৩,০০০ = ৪,০০০, দ্বিতীয় পাতার প্রথম সারি ৫,০০০ যোগে ৯,০০০।
     * ⛔ খোঁজা না মানলে আগের পাতা হত ১,০০০ + ২,০০০, আর দেখাত ৮,০০০।
     */
    public function test_the_running_balance_of_a_later_page_counts_only_the_searched_rows(): void
    {
        $posting = app(PostingEngine::class);

        foreach (range(1, 8) as $i) {
            $posting->post('sales_invoice', $i, sprintf('2026-08-%02d', $i), [
                ['account_id' => $this->cashAccountId(), 'debit' => 1000 * $i],
                ['account_id' => $this->salesAccountId(), 'credit' => 1000 * $i],
            ], documentNo: sprintf('%s-%04d', $i % 2 === 1 ? 'ALPHA' : 'BETA', $i));
        }

        $filters = ['from' => '2026-08-01', 'to' => '2026-08-31', 'account_id' => $this->cashAccountId(), 'q' => 'ALPHA'];
        $reports = app(ReportEngine::class);

        $page1 = $reports->run('accounts.ledger', $filters, page: 1, perPage: 2)->rows;
        $page2 = $reports->run('accounts.ledger', $filters, page: 2, perPage: 2)->rows;

        $this->assertSame(['ALPHA-0001', 'ALPHA-0003'], array_column($page1, 'document_no'), 'খোঁজাটাই কাজ করেনি — দাবি অন্ধ।');
        $this->assertSame(0, bccomp((string) $page1[1]['balance'], '4000', 2));
        $this->assertSame('ALPHA-0005', $page2[0]['document_no']);
        $this->assertSame(0, bccomp((string) $page2[0]['balance'], '9000', 2),
            '⛔ দ্বিতীয় পাতার শুরুর জের না-খোঁজা সারি থেকে গোনা হলো।');
    }
}
