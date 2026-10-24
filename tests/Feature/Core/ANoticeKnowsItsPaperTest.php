<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AdjustingReversals;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Documents\Services\DocumentNotices;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DueNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Documents\MakesDocuments;
use Tests\TestCase;

/**
 * ⛔ খবর জানত না সে কোন কাগজের — তাই খোলার আগে কেউ কাগজটা দেখত না (২৪ অক্টোবর ২০২৬)।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * [[NotificationService::mayOpen()]] খবর খোলার আগে কাগজটা দেখে — আছে কি না, আর তার শাখা প্রাপকের নাগালে কি না — কিন্তু
 * কেবল যখন খবরে কাগজটা বসানো থাকে (`about:`)। ⚠️ ডকুমেন্টের খবর, অর্থের তাগাদা (জমা, ঋণ, বীমা, ভাড়া) আর আটকে থাকা
 * উল্টো জাবেদার খবর কাগজ ছাড়াই পাঠানো হত: খবরের শাখা খালি, তাই শাখা বদলে যাওয়া মানুষও খবর খুলতেন, আর লিংক তাঁকে
 * ৪০৪/৪০৩-এ ফেলত — "দেখার অধিকার নেই" পাতা আর নিরীক্ষার সারি কোনোদিন আসত না।
 * ⚠️ আর উল্টো জাবেদার খবর যেত মাস বন্ধের চাবিওয়ালাদের, অথচ ভাউচারের পাতা খোলে `accounts.report` থাকলে — চাবিওয়ালা খবর
 * পেতেন, খুলতে পারতেন না।
 *
 * ── ⭐ দাবি ──────────────────────────────────────────────────────────
 * তিন রকম খবরেই সারিতে কাগজের শাখা (আর যে কাগজ খোঁজা যায় তার ধরন ও আইডি) বসে; নাগালের মানুষ খুলে কাগজের পাতায় যান (২০০),
 * আর যাঁর নাগাল পরে সরে গেছে তিনি "দেখার অধিকার নেই" পাতা পান — কাগজটা নয়। উল্টো জাবেদার খবর যায় কেবল যিনি জাবেদাটা খুলতে পারেন।
 *
 * ⓘ ডকুমেন্ট ড্রিলের তালিকায় নেই ([[Drillable]] নয়) — তাকে তালিকায় তুললে খোঁজ আর ফোনের সিঙ্ক গোপন কাগজের নাম দেখাত। তাই
 * দেয়ালটা ডকুমেন্টের শাখা দিয়ে, যেটা খবরে বসে; খবরে মডেলের নিজের নাম বসে কেবল "কাগজটা আছে কি" দেখার জন্য
 * ([[AGonePaperSaysItIsGoneTest]]), ড্রিলের নাম হিসেবে নয়।
 */
final class ANoticeKnowsItsPaperTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();
    }

    public function test_a_document_notice_carries_its_branch_and_stays_behind_the_wall(): void
    {
        $keeper = $this->person('lease-keeper@abos.test', ['documents.view']);
        $document = $this->upload('North shop lease', extra: ['owner_id' => $keeper->id]);
        $this->assertSame((int) $this->main->id, (int) $document->branch_id, 'প্রস্তুতিটাই ভুল — কাগজটা প্রধান শাখায় নয়।');

        $note = Notification::query()->where('user_id', $keeper->id)->where('type', DocumentNotices::NEW)->first();
        $this->assertNotNull($note, 'প্রস্তুতিটাই ভুল — মালিক নতুন কাগজের খবর পাননি।');
        $this->assertSame((int) $this->main->id, (int) $note->branch_id,
            '⛔ ডকুমেন্টের খবরে কাগজের শাখা নেই — খোলার আগে দেয়াল কিছুই দেখে না।');

        // ⓘ নাগালে — কাগজের পাতায়, আর পাতা খোলে
        $this->as($keeper);
        $page = $this->opens($note, route('documents.show', $document));
        $this->assertStringContainsString((string) $document->document_no, (string) $page->getContent(),
            '⛔ খবর খুলে কাগজের পাতায় কাগজের নম্বর নেই।');

        // ⛔ নাগাল সরে গেল — খবর আর কাগজে নেয় না
        $this->limit($keeper, $this->netrakona);
        $this->refused($note);
    }

    public function test_a_finance_due_notice_knows_its_deposit_and_stays_behind_the_wall(): void
    {
        $ntk = $this->netrakona;
        $clerk = $this->person('deposit-clerk@abos.test', ['finance.deposit.view']);
        $deposit = $this->deposit('FDR-NTK-9', $ntk);

        $this->morning(fn () => app(DueNotices::class)->depositsAboutToMature());

        $note = Notification::query()->where('user_id', $clerk->id)->where('type', DueNotices::MATURING_SOON)->first();
        $this->assertNotNull($note, 'প্রস্তুতিটাই ভুল — কর্মী জমার মেয়াদের খবর পাননি।');
        $this->assertSame(Deposit::drillSourceType(), $note->subject_type, '⛔ জমার খবরে কাগজের ধরন নেই — খোলার আগে কেউ জমাটা দেখে না।');
        $this->assertSame((int) $deposit->id, (int) $note->subject_id, '⛔ জমার খবরে কাগজের আইডি নেই।');
        $this->assertSame((int) $ntk->id, (int) $note->branch_id, '⛔ জমার খবরে জমার শাখা নেই।');

        $url = route('finance.deposit.show', ['issuer' => $deposit->kind->issuer, 'deposit' => $deposit->id]);

        $this->as($clerk);
        $page = $this->opens($note, $url);
        $this->assertStringContainsString('FDR-NTK-9', (string) $page->getContent(), '⛔ খবর খুলে জমার পাতায় জমার নম্বর নেই।');

        $this->limit($clerk, $this->main);
        $this->refused($note);

        // ⓘ মালিক — সব শাখা, নিজের খবর খোলে
        $mine = Notification::query()->where('user_id', $this->owner->id)->where('type', DueNotices::MATURING_SOON)->first();
        $this->assertNotNull($mine, 'প্রস্তুতিটাই ভুল — মালিক জমার মেয়াদের খবর পাননি।');
        $this->as($this->owner);
        $this->opens($mine, $url);
    }

    public function test_a_stuck_reversal_notice_knows_its_voucher_and_reaches_only_who_can_open_it(): void
    {
        $ntk = $this->netrakona;
        $holder = $this->person('month-closer@abos.test', [AdjustingReversals::WHO_HEARS, 'accounts.report']);
        // ⓘ মাস বন্ধের চাবি আছে, ভাউচার দেখার চাবি নেই — ভাউচারের পাতা তাঁকে খোলে না
        $closesOnly = $this->person('closes-only@abos.test', [AdjustingReversals::WHO_HEARS]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $voucher = $this->journalIn($ntk);
        $this->morning(function () use ($voucher): void {
            (new \ReflectionMethod(AdjustingReversals::class, 'tellStuck'))
                ->invoke(app(AdjustingReversals::class), (int) $voucher->id, 'the month is closed');
        });

        $count = fn (User $u) => Notification::query()->where('user_id', $u->id)->where('type', AdjustingReversals::STUCK)->count();
        $this->assertSame(0, $count($closesOnly),
            '⛔ যিনি জাবেদাটা খুলতে পারেন না, তিনিও আটকে থাকা উল্টোর খবর পেলেন — চাপলে ৪০৩।');
        $this->assertSame(1, $count($this->owner), '⛔ মালিক আটকে থাকা উল্টোর খবর পাননি।');

        $note = Notification::query()->where('user_id', $holder->id)->where('type', AdjustingReversals::STUCK)->first();
        $this->assertNotNull($note, '⛔ মাস বন্ধের আর ভাউচার দেখার চাবিওয়ালা খবর পাননি।');
        $this->assertSame(Voucher::drillSourceType(), $note->subject_type, '⛔ উল্টোর খবরে কাগজের ধরন নেই।');
        $this->assertSame((int) $voucher->id, (int) $note->subject_id, '⛔ উল্টোর খবরে কাগজের আইডি নেই।');
        $this->assertSame((int) $ntk->id, (int) $note->branch_id, '⛔ উল্টোর খবরে জাবেদার শাখা নেই।');

        $this->as($holder);
        $page = $this->opens($note, route('accounts.voucher.show', $voucher));
        $this->assertStringContainsString((string) $voucher->document_no, (string) $page->getContent(), '⛔ খবর খুলে জাবেদার নম্বর নেই।');

        $this->limit($holder, $this->main);
        $this->refused($note);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** খবর খোলার দরজা — কাগজের পাতায় পাঠায়, আর পাতা খোলে */
    private function opens(Notification $note, string $paper): TestResponse
    {
        $opened = $this->call('GET', route('notifications.open', $note));
        $this->assertSame(302, $opened->status(), '⛔ নাগালের মানুষকে খবর কাগজে পাঠায়নি।');
        $this->assertSame($paper, $opened->headers->get('Location'), '⛔ খবর ভুল ঠিকানায় পাঠায়।');

        $page = $this->call('GET', $paper);
        $this->assertSame(200, $page->status(), '⛔ নাগালের মানুষ খবরের কাগজটা খুলতে পারেন না।');

        return $page;
    }

    /** নাগালের বাইরে — "দেখার অধিকার নেই" পাতা, কাগজ নয় */
    private function refused(Notification $note): void
    {
        $opened = $this->call('GET', route('notifications.open', $note));
        $this->assertSame(200, $opened->status(), '⛔ নাগালের বাইরের খবর খুলতে গিয়ে পাতা ভাঙল বা কাগজে পাঠাল।');
        $this->assertSame('notifications.no-access', $opened->original instanceof View ? $opened->original->name() : null,
            '⛔ যাঁর নাগালে কাগজটা আর নেই, তাঁকে খবর "দেখার অধিকার নেই" না বলে কাগজের দিকে পাঠাল।');
    }

    private function limit(User $user, Branch $to): void
    {
        UserDataScope::query()->create([
            'company_id' => $this->company->id, 'user_id' => $user->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $to->id,
        ]);
        app(DataScope::class)->forget();
        $this->as($user);
    }

    /** সময়সূচির মতো — কেউ লগইন নেই */
    private function morning(\Closure $send): void
    {
        $this->app['auth']->forgetGuards();
        $this->useCompany();
        app(DataScope::class)->forget();

        $send();

        $this->useCompany();
    }

    private function deposit(string $no, Branch $branch): Deposit
    {
        return Deposit::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id,
            'document_no' => $no, 'kind_id' => DepositKind::query()->where('code', 'FDR')->value('id'),
            'institution' => 'সোনালী ব্যাংক', 'held_by' => Deposit::BUSINESS, 'principal' => '100000',
            'profit_rate' => '8', 'tax_rate' => '10', 'return_word' => 'interest',
            'opened_on' => now()->subYear()->toDateString(),
            'matures_on' => now()->addDays(5)->toDateString(),
            'account_id' => StandardChart::find(StandardChart::DEPOSITS_AND_INVESTMENTS)->id,
            'status' => Deposit::ACTIVE,
        ])->load('kind');
    }

    private function journalIn(Branch $branch): Voucher
    {
        $this->as($this->owner);
        CompanyContext::set($this->company->id, $branch->id);

        $accounts = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get();
        $this->assertCount(2, $accounts, 'প্রস্তুতিটাই ভুল — দুইটা খরচের খাত নেই।');

        $vouchers = app(VoucherService::class);
        $voucher = $vouchers->post($vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'stuck reversal probe', 'branch_id' => $branch->id],
            [['account_id' => $accounts[0]->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $accounts[1]->id, 'debit' => '0', 'credit' => '100']],
        ));

        $this->useCompany();
        $voucher = Voucher::acrossBranches()->findOrFail($voucher->id);
        $this->assertSame((int) $branch->id, (int) $voucher->branch_id, 'প্রস্তুতিটাই ভুল — জাবেদাটা চাওয়া শাখায় বসেনি।');

        return $voucher;
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh());
        $this->useCompany();
    }
}
