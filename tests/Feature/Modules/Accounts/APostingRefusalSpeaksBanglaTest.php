<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ⛔ পোস্টিংয়ের "না" মানুষের ভাষায় — পুরো-ERP অডিট, হিসাব ⓘ ("পোস্টিংয়ের 'না' ইংরেজিতে"; মালিক কেবল বাংলা পড়েন)।
 *
 * ⓘ আগে পর্দা আর ফোন পেত ইঞ্জিনের ইংরেজি বাক্য — "Line 0 of journal_voucher#7 posts to 1100, which is a group…"। এখন
 * পর্দা পায় বাংলা কারণ ([[PostingException::forPeople()]]); ইংরেজিটা `getMessage()`-এ থাকে, লগ আর পুরনো দাবির জন্য।
 */
final class APostingRefusalSpeaksBanglaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_engine_refusal_reaches_the_screen_and_the_phone_in_bangla(): void
    {
        $this->seed(DemoSeeder::class);
        app()->setLocale('bn');

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $group = StandardChart::find('1100');

        try {
            app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
                ['account_id' => $group->id, 'debit' => '100'],
                ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '100'],
            ]);
            $this->fail('দৃশ্যটাই বানানো যায়নি — দলে দাখিলা বসল');
        } catch (PostingException $e) {
            $refusal = $e;
        }

        $this->assertStringContainsString('which is a group', $refusal->getMessage(), 'লগের ইংরেজি কারণ হারিয়েছে');
        $this->assertSame(__('core.posting.group_account', ['line' => 1, 'code' => '1100']), $refusal->forPeople());
        $this->assertMatchesRegularExpression('/\p{Bengali}/u', $refusal->forPeople(), '⛔ কারণটা বাংলায় নয়');

        // ⓘ পর্দা আর ফোন — দুই পথেই বাংলা
        $handler = app(ExceptionHandler::class);

        $json = $handler->render(Request::create('/x', 'POST', server: ['HTTP_ACCEPT' => 'application/json']), $refusal);
        $this->assertSame(422, $json->getStatusCode());
        $this->assertSame($refusal->forPeople(), json_decode((string) $json->getContent(), true)['message'] ?? null, '⛔ ফোন ইংরেজি কারণ পেল');

        $page = $handler->render(Request::create('/x', 'POST'), $refusal);
        $this->assertSame(302, $page->getStatusCode());
        $this->assertSame($refusal->forPeople(), session('errors')?->first('posting'), '⛔ পর্দা ইংরেজি কারণ পেল');
    }

    public function test_an_unnamed_refusal_still_leads_in_bangla(): void
    {
        app()->setLocale('bn');

        $e = new PostingException('Opening balances need the standard chart.');

        $this->assertSame(__('core.posting.refused', ['reason' => 'Opening balances need the standard chart.']), $e->forPeople());
        $this->assertMatchesRegularExpression('/\p{Bengali}/u', $e->forPeople());
    }
}
