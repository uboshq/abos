<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ অনুমোদন ভাগ ৩, দফা ২০ — সহজে ব্যবহার (পরিকল্পনার পাতা ৭, সারি ২০):
 * "ট্যাব আর পপ-আপ পর্দা-পাঠকে ঠিক পড়া যায়। কীবোর্ড: ← / → পরের বা আগের, A অনুমোদন, S সংশোধনে ফেরত, R প্রত্যাখ্যান।"
 *
 * ⛔ আগে: "পুরো কাগজ" পপ-আপের কোনো নাম ছিল না (পর্দা-পাঠক কেবল "ডায়ালগ" বলত); কোনো পপ-আপ খুললে ফোকাস ভেতরে যেত না;
 * বোতামগুলো নিজের চাবি বলত না; আর A, S, R বাংলা লেআউটে মরা ছিল। চাবি আর ফোকাসের আচরণের দাবি `approval-keys.test.js`-এ
 * (vitest); এখানে পাতার দাবি — যা সার্ভার আঁকে।
 *
 * দাবি:
 *   ২০ক — ইনবক্স আর কাগজের পাতায় প্রতিটা পপ-আপ `aria-modal`, নিজের নামসহ (`aria-labelledby` আসল, অক্ষরওয়ালা ঘরে), আর ফোকাসের
 *          পাহারায় (`data-dialog-focus`); যে বোতাম খোলে সে বলে "পপ-আপ খোলে" (`aria-haspopup`);
 *   ২০খ — A, S, R বোতাম আর ← → লিংক নিজের চাবি বলে (`aria-keyshortcuts` = `data-key`), মন্তব্যের ঘর Control+K;
 *   ২০গ — ট্যাব সারির নাম আছে, ঠিক একটা ট্যাব "এই পাতা" (`aria-current="page"`), আর লিংক-ট্যাব `role="tab"` সাজে না;
 *   ২০ঘ — চাবি একটাই জায়গা থেকে চলে: বান্ডেলের `approvalKeys()`; পাতায় নিজের keydown স্ক্রিপ্ট নেই (দুইটা থাকলে A-তে দুইবার
 *          "নিশ্চিত?")।
 */
final class TheInboxReadsAloudAndAnswersTheKeysTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        app()->setLocale('bn');

        $this->signer = $this->signer('দফা ২০-এর অনুমোদনকারী');

        ApprovalFlow::query()->where('module', 'accounts')->where('action', 'journal')->delete();
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
        ApprovalEngine::flowsChanged();
    }

    public function test_dofa20a_every_popup_is_named_modal_and_keeps_the_focus(): void
    {
        [, $middle] = $this->threePapers();

        foreach ([
            'ইনবক্স' => route('approval.inbox.index', ['open' => $middle->id]),
            'কাগজের পাতা' => route('approval.inbox.show', $middle->id),
        ] as $where => $url) {
            $page = $this->xpath($this->actingAs($this->signer)->get($url)->assertOk()->getContent());

            // ⓘ কাগজের ভেতরের পপ-আপগুলো — খোলসের নিজের পপ-আপ (খোঁজা, স্ক্যানার) নিজের পাহারায়
            $dialogs = $page->query('//*[@data-approval-detail]//*[@role="dialog"]');
            $this->assertGreaterThanOrEqual(3, $dialogs->length, "প্রস্তুতিটাই ভুল — {$where}-এ প্রত্যাখ্যান, ফেরত আর পুরো কাগজের পপ-আপ নেই।");
            $this->assertSame(1, $page->query('//*[@data-signing-sheet]//*[@role="dialog"]')->length,
                "প্রস্তুতিটাই ভুল — {$where}-এ \"পুরো কাগজ\" পপ-আপ নেই।");

            foreach ($dialogs as $dialog) {
                $this->assertInstanceOf(DOMElement::class, $dialog);
                $id = $dialog->getAttribute('aria-labelledby');
                $named = $id !== '' ? $page->query('//*[@id="'.$id.'"]') : null;

                $this->assertTrue($named !== null && $named->length === 1 && trim((string) $named->item(0)?->textContent) !== '',
                    "⛔ দফা ২০: {$where}-এ একটা পপ-আপের নাম নেই — পর্দা-পাঠক কেবল \"ডায়ালগ\" বলে (aria-labelledby=\"{$id}\")।");
                $this->assertSame('true', $dialog->getAttribute('aria-modal'), "⛔ দফা ২০: {$where}-এ পপ-আপ aria-modal নয় — পেছনের পাতাও পড়া হয়।");
                $this->assertTrue($dialog->hasAttribute('data-dialog-focus'),
                    "⛔ দফা ২০: {$where}-এ \"{$named->item(0)?->textContent}\" পপ-আপে ফোকাসের পাহারা নেই — খুললে ফোকাস পেছনে থাকে।");
            }

            foreach (['//*[@data-ask="reject"]', '//*[@data-ask="fix"]', '//*[@data-signing-sheet]//button[@data-opens-dialog]'] as $opener) {
                $button = $page->query($opener)->item(0);
                $this->assertInstanceOf(DOMElement::class, $button, "প্রস্তুতিটাই ভুল — {$where}-এ {$opener} নেই।");
                $this->assertSame('dialog', $button->getAttribute('aria-haspopup'),
                    "⛔ দফা ২০: {$where}-এ পপ-আপ খোলার বোতাম বলে না যে পপ-আপ খোলে ({$opener})।");
            }
        }
    }

    public function test_dofa20b_each_key_is_announced_on_its_own_control(): void
    {
        [, $middle] = $this->threePapers();

        $page = $this->xpath($this->actingAs($this->signer)->get(route('approval.inbox.index', ['open' => $middle->id]))->assertOk()->getContent());

        foreach (['a' => 'A', 's' => 'S', 'r' => 'R'] as $key => $said) {
            $button = $page->query('//*[@data-approval-decide]//*[@data-key="'.$key.'"]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $button, "প্রস্তুতিটাই ভুল — {$said}-এর বোতাম নেই।");
            $this->assertSame($said, $button->getAttribute('aria-keyshortcuts'),
                "⛔ দফা ২০: \"".trim($button->textContent)."\" বোতাম নিজের চাবি ({$said}) বলে না।");
        }

        foreach (['data-approval-prev' => 'ArrowLeft', 'data-approval-next' => 'ArrowRight'] as $mark => $said) {
            $link = $page->query('//a[@'.$mark.']')->item(0);
            $this->assertInstanceOf(DOMElement::class, $link, "প্রস্তুতিটাই ভুল — মাঝের কাগজে {$mark} নেই।");
            $this->assertSame($said, $link->getAttribute('aria-keyshortcuts'), "⛔ দফা ২০: আগের/পরের লিংক নিজের চাবি ({$said}) বলে না।");
        }

        $remarks = $page->query('//textarea[@data-approval-remarks]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $remarks);
        $this->assertSame('Control+K', $remarks->getAttribute('aria-keyshortcuts'), '⛔ দফা ২০: মন্তব্যের ঘর নিজের চাবি (Control+K) বলে না।');
    }

    public function test_dofa20c_the_tabs_say_which_one_is_open(): void
    {
        $this->threePapers();

        foreach (['waiting' => null, 'done' => 'done'] as $tab => $query) {
            $page = $this->xpath($this->actingAs($this->signer)
                ->get(route('approval.inbox.index', array_filter(['tab' => $query])))->assertOk()->getContent());

            $nav = $page->query('//nav[@data-list-tabs]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $nav, 'প্রস্তুতিটাই ভুল — ইনবক্সে ট্যাবের সারি নেই।');
            $this->assertSame(__('approval::field.tabs'), $nav->getAttribute('aria-label'), '⛔ দফা ২০: ট্যাবের সারির নাম নেই।');

            $current = $page->query('//nav[@data-list-tabs]//a[@aria-current="page"]');
            $this->assertSame(1, $current->length, "⛔ দফা ২০: \"{$tab}\" খোলা থাকতে ঠিক একটা ট্যাব \"এই পাতা\" বলে না।");
            $this->assertInstanceOf(DOMElement::class, $current->item(0));
            $this->assertSame($tab, $current->item(0)->getAttribute('data-tab'), "⛔ দফা ২০: \"এই পাতা\" বলা ট্যাবটা খোলা ট্যাব নয়।");

            $this->assertSame(0, $page->query('//nav[@data-list-tabs]//*[@role="tab" or @role="tablist"]')->length,
                '⛔ দফা ২০: লিংক-ট্যাব role="tab" সাজে — পর্দা-পাঠক ←/→ চাপতে বলে, অথচ ট্যাবগুলো আলাদা পাতা।');
        }
    }

    public function test_dofa20d_the_keys_run_from_one_place(): void
    {
        $this->ask('500', now()->subHour());

        $page = $this->xpath($this->actingAs($this->signer)->get(route('approval.inbox.index'))->assertOk()->getContent());
        $this->assertSame(1, $page->query('//*[@data-approval-decide]')->length, 'প্রস্তুতিটাই ভুল — ইনবক্সে অনুমোদনের বাক্স নেই।');

        foreach ($page->query('//script[not(@src)]') as $script) {
            $this->assertDoesNotMatchRegularExpression('/data-approval-(decide|prev|next|remarks)|data-bulk-button/', (string) $script->textContent,
                '⛔ দফা ২০: ইনবক্সে নিজের চাবির স্ক্রিপ্ট — বান্ডেলেরটার সাথে দুইটা চলে, A চাপলে দুইবার "নিশ্চিত?"।');
        }

        $app = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertMatchesRegularExpression("/^import \\{ approvalKeys \\} from '\\.\\/approval-keys\\.js'$/m", $app,
            '⛔ দফা ২০: বান্ডেল approval-keys.js আনে না।');
        $this->assertMatchesRegularExpression('/^approvalKeys\(\)$/m', $app, '⛔ দফা ২০: বান্ডেল approvalKeys() ডাকে না — চাবি একটাও চলে না।');
    }

    /** @return array{0: Approval, 1: Approval, 2: Approval} পুরনো থেকে নতুন — মাঝেরটার আগে আর পরে দুইটাই থাকে */
    private function threePapers(): array
    {
        return [$this->ask('500', now()->subHours(3)), $this->ask('700', now()->subHours(2)), $this->ask('900', now()->subHour())];
    }

    private function xpath(string|false $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.(string) $html);

        return new DOMXPath($dom);
    }

    private function signer(string $name): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'bn', 'name' => $name]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), ['approval.view', 'approval.decide'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function draft(string $amount): Voucher
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        return app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'দফা ২০-এর পরীক্ষা', 'branch_id' => CompanyContext::branchId()],
            [['account_id' => $a->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => $amount]],
        );
    }

    private function ask(string $amount, \Illuminate\Support\Carbon $at): Approval
    {
        $this->actingAs($this->owner);
        $asked = app(VoucherWriter::class)->post($this->draft($amount));
        $this->assertNotNull($asked, 'প্রস্তুতিটাই ভুল — অনুমোদন চাওয়া হয়নি।');
        $asked->forceFill(['requested_at' => $at])->save();

        return $asked->fresh();
    }
}
