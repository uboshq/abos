<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Print\PaperSize;
use App\Core\Services\PaperTrail;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Models\DocumentShare;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * কাগজটা বেরিয়ে গেল, আর কেউ গুনে রাখল না।
 *
 * ── মালিকের দুইটা চাওয়া, ২০ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * ১. *"কি কাগজে প্রিন্ট করবো এটা নিজে নির্ধারণ করে দিব, একটা অটো-নির্ধারিত
 *    হচ্ছে — এটা যাতে না হয়"* — প্রতিটা কাগজের মাপ তিনি বসিয়ে দেবেন।
 * ২. *"কয়টা কাগজ প্রিন্ট হল কয়টা শেয়ার হইল এটা যাতে একটা হিসাব থাকে"*,
 *    আর পরে: *"sathe pdf o zate dwa zay"*।
 *
 * ⭐ গ্রাহককে পাঠানোর লিংকটা একটা মাত্র কাগজের, ৩০ দিনের, আর অনুমান করা
 * যায় না — এই ফাইল সেই তিনটাই পাহারা দেয়।
 *
 * ⓘ পরীক্ষাটা ভাউচার দিয়ে, কারণ যন্ত্রপাতি সব কাগজের জন্য এক: ভাউচারে
 * যা প্রমাণ হয়, বিল বা চালানেও তা-ই — পথটা একটাই ([[PaperTrail]])।
 */
final class TheBillWentOutAndNobodyKeptCountTest extends TestCase
{
    use RefreshDatabase;

    private const KIND = 'accounts_voucher';

    private Voucher $voucher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->voucher = $this->postedVoucher();
    }

    /**
     * ⭐ মাপটা মালিকের বসানো — ঠিকানায় কিছু না বললেও।
     */
    public function test_the_paper_is_the_one_the_owner_set(): void
    {
        app(SettingsService::class)->set('accounts.print.paper.voucher', PaperSize::THERMAL_80);

        $this->get(route('accounts.voucher.print', $this->voucher))->assertOk();

        $this->assertSame(PaperSize::THERMAL_80,
            DocumentDelivery::query()->latest('id')->value('paper'),
            'মালিকের বসানো মাপে ছাপা হয়নি — সিস্টেম নিজেই মাপ ঠিক করছে।');

        // ⓘ ঠিকানায় চাওয়া মাপ সেটিংয়ের উপরে — একবারের জন্য অন্য মেশিনে পাঠাতে
        $this->get(route('accounts.voucher.print', $this->voucher).'?paper='.PaperSize::A4)->assertOk();

        $this->assertSame(PaperSize::A4, DocumentDelivery::query()->latest('id')->value('paper'));
    }

    /**
     * ⭐ ছাপা আর নামানো আলাদা করে গোনা হয়, আর ফাইলটা ফাইল হয়েই আসে।
     */
    public function test_printing_and_downloading_are_counted_apart(): void
    {
        $this->get(route('accounts.voucher.print', $this->voucher))->assertOk();
        $this->get(route('accounts.voucher.print', $this->voucher))->assertOk();

        $file = $this->get(route('accounts.voucher.print', $this->voucher).'?download=1')->assertOk();

        $this->assertStringContainsString('attachment',
            (string) $file->headers->get('Content-Disposition'),
            'নামানোর কথা বলা সত্ত্বেও কাগজটা ব্রাউজারেই খুলেছে।');

        $counts = app(PaperTrail::class)->countsFor(self::KIND, $this->voucher->id);

        $this->assertSame(2, $counts[DocumentDelivery::PRINTED], 'ছাপার গোনা মেলেনি।');
        $this->assertSame(1, $counts[DocumentDelivery::DOWNLOADED], 'নামানোর গোনা মেলেনি।');
    }

    /**
     * ⭐ গোপন লিংক — গ্রাহক লগইন ছাড়াই ঐ একটা কাগজ পান, আর খোলাটা গোনা হয়।
     */
    public function test_a_secret_link_opens_that_one_paper_without_a_login(): void
    {
        $this->post(route('paper.share'), [
            'route' => 'accounts.voucher.print',
            'params' => ['voucher' => $this->voucher->id],
            'document_type' => self::KIND,
            'document_id' => $this->voucher->id,
            'document_no' => $this->voucher->document_no,
            'paper' => PaperSize::A4,
        ])->assertRedirect()->assertSessionHas('shared_link');

        $share = DocumentShare::query()->latest('id')->firstOrFail();

        $this->assertSame(64, strlen($share->token), 'চাবিটা যথেষ্ট লম্বা নয়।');
        $this->assertStringNotContainsString((string) $this->voucher->document_no, $share->token,
            'চাবির ভিতরে নথির নম্বর — অনুমান করার পথ খোলা।');

        // ⓘ গ্রাহকের লগইন নেই — সেটাই আসল পরীক্ষা
        auth()->logout();

        $opened = $this->get(route('paper.shared', $share->token));

        $opened->assertOk();
        $this->assertSame('application/pdf', $opened->headers->get('Content-Type'));

        $this->assertSame(1, $share->fresh()->opened_count, 'খোলার গোনা বসেনি।');

        $counts = app(PaperTrail::class)->countsFor(self::KIND, $this->voucher->id);
        $this->assertSame(1, $counts[DocumentDelivery::SHARED], '"পাঠানো হয়েছে" গোনা হয়নি।');
        $this->assertSame(1, $counts[DocumentDelivery::OPENED], '"খোলা হয়েছে" গোনা হয়নি।');
    }

    /**
     * ⛔ মেয়াদ শেষ হলে লিংকটা আর কিছু খোলে না — আর "মেয়াদ শেষ" বলেও না।
     */
    public function test_the_link_dies_by_itself(): void
    {
        $share = app(PaperTrail::class)->share(
            routeName: 'accounts.voucher.print',
            routeParams: ['voucher' => $this->voucher->id],
            documentType: self::KIND,
            documentId: $this->voucher->id,
            paper: PaperSize::A4,
        );

        $this->assertTrue(
            $share->expires_at->greaterThan(Carbon::now()->addDays(DocumentShare::LIVES_DAYS - 1)),
            'মেয়াদ ৩০ দিনের নয়।',
        );

        $share->forceFill(['expires_at' => Carbon::now()->subMinute()])->saveQuietly();

        auth()->logout();

        $this->get(route('paper.shared', $share->token))->assertNotFound();
    }

    /**
     * ⛔ লিংক বানানো যায় কেবল ছাপার রুটে — তালিকা বা ফর্মের রুটে নয়।
     */
    public function test_only_a_print_route_can_be_shared(): void
    {
        $this->post(route('paper.share'), [
            'route' => 'accounts.voucher.index',
            'params' => [],
            'document_type' => self::KIND,
            'document_id' => $this->voucher->id,
            'paper' => PaperSize::A4,
        ])->assertNotFound();

        $this->assertSame(0, DocumentShare::query()->count(), 'ছাপার নয় এমন রুটেও লিংক বসেছে।');
    }

    /**
     * ⭐ গোনাটা একটা দরজা — "কে ছেপেছে, কখন" তার পিছনেই।
     *
     * ⓘ মালিক গোনা চেয়েছিলেন জবাবদিহির জন্য, তথ্যের জন্য নয়। তাই
     * সংখ্যাটা যদি কোথাও না নিয়ে যায়, চাওয়াটার অর্ধেকই পূরণ হয়নি।
     */
    public function test_the_count_opens_who_printed_it_and_when(): void
    {
        $this->get(route('accounts.voucher.print', $this->voucher))->assertOk();

        $history = $this->get(route('paper.history', [
            'type' => self::KIND,
            'id' => $this->voucher->id,
        ]));

        $history->assertOk();
        $history->assertSee((string) $this->voucher->document_no);
        $history->assertSee(__('core.print.way_printed'));

        // ⭐ কে — নামটা না থাকলে "জবাবদিহি" কথাটার কোনো মানে নেই
        $history->assertSee(auth()->user()->name);

        /*
         * ⓘ আর ছাপার পাতা থেকে এই পাতায় আসার পথটাও থাকতে হবে।
         *
         * ⚠️ `escape: false` নয়: ঠিকানায় দুইটা ঘর, তাই মাঝে `&` — আর
         * পাতায় সেটা `&amp;` হয়ে বসে। কাঁচা ঠিকানা খুঁজলে কোনোদিন মিলত না,
         * আর ভুলটা লিংকের নয়, খোঁজার।
         */
        $this->get(route('accounts.voucher.show', $this->voucher))
            ->assertSee(route('paper.history', ['type' => self::KIND, 'id' => $this->voucher->id]));
    }

    private function postedVoucher(): Voucher
    {
        $vouchers = app(VoucherService::class);
        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();

        return $vouchers->post($vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'print test'],
            [
                ['account_id' => $cash->id, 'debit' => '1000', 'credit' => '0'],
                ['account_id' => $capital->id, 'debit' => '0', 'credit' => '1000'],
            ],
        ));
    }
}
