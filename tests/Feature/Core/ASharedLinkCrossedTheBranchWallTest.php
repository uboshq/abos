<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Print\PaperSize;
use App\Core\Services\CompanyProvisioner;
use App\Core\Services\DataScope;
use App\Core\Services\PaperTrail;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Models\DocumentShare;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * গ্রাহকের লিংকটা শাখার দেয়াল পেরিয়ে গিয়েছিল — অডিট ২৭ সেপ্টেম্বর ২০২৬, §৩।
 *
 * ── ⛔ যা ভাঙা ছিল ─────────────────────────────────────────────────────
 * লিংক বানানোর সময় নথিটা **খোঁজাই হত না** — কেবল ছাপার রুটের চাবি মাপা
 * হত, আর বাইরে থেকে আসা `params` যেমন এল তেমনই সারিতে বসত। ⓘ আর লিংক
 * খোলার সময় কেউ লগ-ইন নেই, তাই শাখার ছাঁকনি ([[ScopedToUserBranch]])
 * ঘুমিয়ে থাকে — কেবল কোম্পানির দেয়াল দাঁড়িয়ে।
 *
 * ⚠️ ফল দুইটা: ময়মনসিংহে আটকানো একজন হিসাবরক্ষক নেত্রকোনার ভাউচারের
 * খোলা লিংক বানাতে পারতেন; আর নিজের ভাউচারের নাম দিয়ে `params`-এ অন্য
 * নম্বর বসিয়ে দিলে লিংকটা **অন্য কাগজ** আঁকত।
 *
 * ⭐ প্রতিটা দরজার দাবি **একই মানুষ দুইবার** — চাবি/শাখা ছাড়া বন্ধ, ঐ
 * মানুষকেই শাখা দিলে খোলা। দুইজন আলাদা মানুষ হলে বন্ধ হওয়াটা অন্য কোনো
 * কারণেও হতে পারত, আর পরীক্ষা সেটা আলাদা করতে পারত না।
 */
final class ASharedLinkCrossedTheBranchWallTest extends TestCase
{
    use RefreshDatabase;

    private const KIND = 'accounts_voucher';

    private const ROUTE = 'accounts.voucher.print';

    private Company $company;

    private Company $other;

    private Branch $mymensingh;

    private Branch $netrokona;

    /** দুই কোম্পানিতেই ভাউচার দেখার চাবি, কোনো শাখার সীমা নেই */
    private User $owner;

    private User $clerk;

    /** ময়মনসিংহের ভাউচার — হিসাবরক্ষকের নিজের শাখা */
    private Voucher $ours;

    /** নেত্রকোনার ভাউচার — হিসাবরক্ষকের নাগালের বাইরে */
    private Voucher $theirs;

    /**
     * ⓘ ডেমো বীজ নয়, নিজের হাতে দুইটা কোম্পানি — [[CompanyProvisioner]]
     * দিয়ে, আসল নতুন কোম্পানি যেভাবে বসে। ⚠️ এই পরীক্ষার দরকার কেবল
     * দুইটা শাখা, দুইটা কোম্পানি আর ভাউচার; ডেমোর বাকি সব (গ্রাহক, পণ্য,
     * বিক্রয়) অন্য মডিউলের বদলে এই দেয়ালের পরীক্ষা লাল করত — অথচ দেয়ালের
     * কিছুই বদলায়নি।
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'SLW', 'name_en' => 'Shared Link Wall']);
        $this->other = Company::create(['code' => 'SLX', 'name_en' => 'Across The Wall']);

        $year = [
            'name' => 'wall-year',
            'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->endOfYear()->toDateString(),
        ];

        foreach ([$this->company, $this->other] as $company) {
            CompanyContext::forCompany($company->id, fn () => app(PermissionSyncer::class)->sync());
        }

        app(CompanyProvisioner::class)->setUp($this->company, [
            ['code' => 'MMS', 'name_en' => 'Mymensingh', 'is_default' => true],
            ['code' => 'NTK', 'name_en' => 'Netrokona'],
        ], $year);
        app(CompanyProvisioner::class)->setUp($this->other, [
            ['code' => 'MAIN', 'name_en' => 'Main', 'is_default' => true],
        ], $year);

        $this->mymensingh = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->netrokona = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();

        $this->owner = $this->member([$this->company, $this->other], $this->mymensingh);
        $this->clerk = $this->member([$this->company], $this->mymensingh);

        $this->asOwnerIn($this->company);
        app(StandardChart::class)->install();

        $this->ours = $this->postedVoucher($this->mymensingh);
        $this->theirs = $this->postedVoucher($this->netrokona);

        $this->limitClerkTo($this->mymensingh);
    }

    /**
     * ⛔ অন্য শাখার কাগজের লিংক — ৪০৪; ⭐ ঐ মানুষকেই শাখাটা দিলে — লিংক হয়।
     *
     * ⓘ প্রথমে ছাপার দরজাটাই মাপা হয়: দরজা যা দেখায় না, লিংকও তা দেখাবে না।
     * ⚠️ ৪০৩ নয়, ৪০৪ — নাহলে "আছে, কিন্তু আপনার নয়" বলে দেওয়া হত।
     */
    public function test_a_branch_limited_clerk_cannot_mint_a_link_to_another_branchs_paper_until_given_that_branch(): void
    {
        $this->actingAs($this->clerk)->get(route(self::ROUTE, $this->theirs))->assertNotFound();

        $this->mint($this->clerk, $this->theirs)->assertNotFound();

        $this->assertSame(0, DocumentShare::query()->count(),
            'শাখায় আটকানো মানুষ নাগালের বাইরের ভাউচারের খোলা লিংক বানিয়ে ফেলেছেন।');

        // ⭐ একই মানুষ, এবার নেত্রকোনাও তাঁর নাগালে
        $this->allowClerk($this->netrokona);

        $this->mint($this->clerk, $this->theirs)->assertRedirect()->assertSessionHas('shared_link');

        $share = DocumentShare::query()->latest('id')->firstOrFail();
        $this->assertSame($this->theirs->id, (int) $share->document_id);
        $this->assertSame(['voucher' => $this->theirs->id], $share->route_params,
            'সারিতে বসা প্যারামিটার নথির নিজের নয়।');
    }

    /**
     * ⭐ নিজের শাখার কাগজ — লিংক হয়, আর লগইন ছাড়া গ্রাহক ঠিক ঐ নম্বরটাই পান।
     */
    public function test_a_clerk_mints_for_their_own_branch_and_the_customer_sees_that_paper_without_a_login(): void
    {
        $this->mint($this->clerk, $this->ours)->assertRedirect()->assertSessionHas('shared_link');

        $share = DocumentShare::query()->latest('id')->firstOrFail();

        auth()->logout();

        $this->assertRendered($this->get(route('paper.shared', $share->token)), $this->ours);
    }

    /**
     * ⛔ `params`-এ পাচার — নিজের নথির নাম, অন্য নথির নম্বর বা বাড়তি ঘর।
     *
     * ⓘ যাচাইয়ে ফেরত (ওয়েবে যাচাইয়ের ব্যর্থতা ফিরে যায় ভুলসহ — এই অ্যাপে
     * ৪২২ কেবল `api/*`-এ), চুপচাপ ফেলে দেওয়া নয় — কারণটা কন্ট্রোলারে লেখা।
     */
    public function test_smuggled_params_are_refused_and_mint_nothing(): void
    {
        foreach ([
            'অন্য নথির নম্বর' => [['voucher' => $this->theirs->id], 'params.voucher'],
            'বাড়তি মাপ' => [['voucher' => $this->ours->id, 'paper' => PaperSize::THERMAL_80], 'params'],
            'বাড়তি নামানো' => [['voucher' => $this->ours->id, 'download' => '1'], 'params'],
            'ভুল নামের ঘর' => [['id' => $this->ours->id], 'params'],
        ] as $case => [$params, $field]) {
            $this->mint($this->clerk, $this->ours, ['params' => $params])
                ->assertSessionHasErrors($field);

            $this->assertSame(0, DocumentShare::query()->count(), 'পাচার করা params-এ লিংক বসেছে: '.$case);
        }

        // ⭐ একই মানুষ, একই নথি, ঠিক ঘরটা — লিংক হয়; দেয়ালটা params-এর, মানুষের নয়
        $this->mint($this->clerk, $this->ours)->assertSessionHasNoErrors()->assertSessionHas('shared_link');
        $this->assertSame(1, DocumentShare::query()->count());
    }

    /**
     * ⛔ আগের নিয়মে বসা পাচারের লিংক — এখন কিছুই খোলে না, খোলাও গোনে না।
     *
     * ⓘ সারিটা [[PaperTrail::share()]] দিয়ে সরাসরি বসানো, ঠিক যেমন পুরনো
     * দরজা বসাত: নাম নিজের ভাউচারের, প্যারামিটার নেত্রকোনার।
     */
    public function test_a_link_carrying_smuggled_params_opens_nothing(): void
    {
        $share = $this->smuggledLink();

        auth()->logout();

        $this->get(route('paper.shared', $share->token))->assertNotFound();

        $this->assertSame(0, (int) $share->fresh()->opened_count,
            'ফিরিয়ে দেওয়া লিংকের খোলাও গোনা হয়েছে।');
        $this->assertSame(0,
            DocumentDelivery::query()->where('how', DocumentDelivery::PRINTED)->where('document_id', $this->theirs->id)->count(),
            'পাচারের লিংক নেত্রকোনার ভাউচার এঁকে দিয়েছে।');
    }

    /**
     * ⛔ আবার পাঠালে পুরনো পাচারের লিংকটাই ফিরে আসে না।
     *
     * ⓘ [[PaperTrail::share()]] বেঁচে থাকা লিংক ফেরত দেয় — তাই পুরনো একটা
     * বাঁকা সারি থাকলে নতুন সব পাঠানো ঐ মরা লিংকটাই পেত।
     */
    public function test_minting_again_does_not_hand_back_a_smuggled_link(): void
    {
        $bent = $this->smuggledLink();

        $this->mint($this->clerk, $this->ours)->assertRedirect()->assertSessionHas('shared_link');

        $share = DocumentShare::query()->latest('id')->firstOrFail();

        $this->assertNotSame($bent->token, $share->token, 'পুরনো পাচারের লিংকটাই আবার দেওয়া হয়েছে।');
        $this->assertNotNull($bent->fresh()->revoked_at, 'পাচারের লিংকটা বাতিল হয়নি।');

        auth()->logout();

        $this->assertRendered($this->get(route('paper.shared', $share->token)), $this->ours);
    }

    /**
     * ⛔ অন্য কোম্পানির কাগজ — ৪০৪; ⭐ একই মালিক ঐ কোম্পানিতে গেলে — লিংক হয়।
     */
    public function test_another_companys_paper_cannot_be_minted_from_this_company(): void
    {
        $other = $this->other;

        $this->asOwnerIn($other);
        app(StandardChart::class)->install();
        $foreign = $this->postedVoucher(null);

        $this->owner->forceFill([
            'current_company_id' => $this->company->id,
            'current_branch_id' => $this->mymensingh->id,
        ])->save();

        $this->mint($this->owner->fresh(), $foreign)->assertNotFound();

        $this->assertSame(0, DocumentShare::query()->withoutGlobalScopes()->count(),
            'অন্য কোম্পানির ভাউচারের লিংক বসেছে।');

        // ⭐ একই মালিক, এবার ঐ কোম্পানিতে বসে
        $this->owner->forceFill([
            'current_company_id' => $other->id,
            'current_branch_id' => $other->defaultBranch()?->id,
        ])->save();

        $this->mint($this->owner->fresh(), $foreign)->assertRedirect()->assertSessionHas('shared_link');

        $this->assertSame(1, DocumentShare::query()->withoutGlobalScopes()->count());
    }

    /**
     * ⛔ খোলা লিংকের ঠিকানায় কিছু জুড়ে কাগজ, মাপ বা ধরন বদলানো যায় না।
     */
    public function test_the_public_link_cannot_be_steered_by_its_own_address(): void
    {
        $this->mint($this->clerk, $this->ours)->assertRedirect();

        $share = DocumentShare::query()->latest('id')->firstOrFail();

        auth()->logout();

        $opened = $this->get(route('paper.shared', $share->token).'?'.http_build_query([
            'voucher' => $this->theirs->id,
            'paper' => PaperSize::THERMAL_80,
            'download' => 1,
        ]));

        $this->assertRendered($opened, $this->ours);
        $this->assertStringStartsWith('inline', (string) $opened->headers->get('Content-Disposition'),
            'ঠিকানা থেকে "নামানো" চাপানো গেছে।');
        $this->assertSame(PaperSize::A4,
            DocumentDelivery::query()->where('how', DocumentDelivery::PRINTED)->latest('id')->value('paper'),
            'ঠিকানা থেকে মাপ বদলানো গেছে।');
    }

    /**
     * ⭐ শাখা হারানোর আগে বানানো লিংক — যা বানানো হয়েছিল, কেবল সেটাই।
     *
     * ⓘ সিদ্ধান্ত: লিংকটা সেই মুহূর্তের ছবি, পরে আবার মাপা নয় — কারণটা
     * [[App\Http\Controllers\SharedPaperController]]-এ লেখা। ⛔ কিন্তু মানুষটা
     * নিজে আর দরজা দিয়ে ঢুকতে পারেন না, নতুন লিংকও বানাতে পারেন না।
     */
    public function test_a_link_minted_before_the_clerk_lost_the_branch_still_shows_only_what_was_minted(): void
    {
        $this->allowClerk($this->netrokona);

        $this->mint($this->clerk, $this->theirs)->assertRedirect()->assertSessionHas('shared_link');

        $share = DocumentShare::query()->latest('id')->firstOrFail();

        // ⛔ নেত্রকোনা কেড়ে নেওয়া হলো
        UserDataScope::query()->withoutGlobalScopes()
            ->where('user_id', $this->clerk->id)
            ->where('scope_id', $this->netrokona->id)
            ->delete();
        app(DataScope::class)->forget();

        $this->actingAs($this->clerk)->get(route(self::ROUTE, $this->theirs))->assertNotFound();
        $this->mint($this->clerk, $this->theirs)->assertNotFound();

        auth()->logout();

        $this->assertRendered($this->get(route('paper.shared', $share->token)), $this->theirs);
    }

    /**
     * ⛔ লিংক বাতিল আর ছাপার ইতিহাস — কাগজটা নাগালে না থাকলে ৪০৪ (পুরো-ERP অডিট, নিরাপত্তা ও সিস্টেম; fe, ১০ অক্টোবর ২০২৬;
     * [[PaperShareController::visibleDocument()]])। ⓘ একই মানুষ দুইবার: শাখা ছাড়া বন্ধ, শাখা দিলে খোলা।
     */
    public function test_a_branch_limited_clerk_cannot_revoke_or_read_the_history_of_another_branchs_paper_until_given_that_branch(): void
    {
        $this->mint($this->owner, $this->theirs)->assertRedirect()->assertSessionHas('shared_link');
        $share = DocumentShare::query()->latest('id')->firstOrFail();
        $history = route('paper.history', ['type' => self::KIND, 'id' => $this->theirs->id]);

        app(DataScope::class)->forget();
        $this->actingAs($this->clerk)->post(route('paper.revoke', $share))->assertNotFound();
        $this->assertNull($share->fresh()->revoked_at, '⛔ নাগালের বাইরের কাগজের লিংক মেরে ফেলা গেল।');
        $this->actingAs($this->clerk)->get($history)->assertNotFound();

        $this->allowClerk($this->netrokona);
        $this->actingAs($this->clerk->fresh())->get($history)->assertOk();
        $this->actingAs($this->clerk->fresh())->post(route('paper.revoke', $share))->assertRedirect();
        $this->assertNotNull($share->fresh()->revoked_at, '⛔ শাখা পেয়েও লিংক বাতিল হলো না।');
    }

    // ── সাহায্য ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function mint(User $user, Voucher $voucher, array $overrides = []): TestResponse
    {
        app(DataScope::class)->forget();

        $payload = [
            'route' => self::ROUTE,
            'params' => ['voucher' => $voucher->id],
            'document_type' => self::KIND,
            'document_id' => $voucher->id,
            'document_no' => $voucher->document_no,
            'paper' => PaperSize::A4,
            ...$overrides,
        ];

        $this->actingAs($user);

        return $this->post(route('paper.share'), $payload);
    }

    /**
     * ⭐ খোলা পাতাটা ঠিক এই নথিই আঁকল — নাম (ফাইলের নাম = নথির নম্বর) আর
     * ছাপার খাতার সারি, দুইটাই।
     */
    private function assertRendered(TestResponse $response, Voucher $voucher): void
    {
        $response->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('filename="'.$voucher->document_no.'.pdf"',
            (string) $response->headers->get('Content-Disposition'),
            'খোলা লিংক অন্য নথি এঁকেছে।');
        $this->assertSame($voucher->id,
            (int) DocumentDelivery::query()->where('how', DocumentDelivery::PRINTED)->latest('id')->value('document_id'),
            'ছাপার খাতায় অন্য নথি বসেছে।');
    }

    /**
     * পুরনো দরজা যেমন বসাত: নাম ময়মনসিংহের ভাউচারের, প্যারামিটার নেত্রকোনার।
     */
    private function smuggledLink(): DocumentShare
    {
        $this->asOwnerIn($this->company);

        return app(PaperTrail::class)->share(
            routeName: self::ROUTE,
            routeParams: ['voucher' => $this->theirs->id],
            documentType: self::KIND,
            documentId: $this->ours->id,
            paper: PaperSize::A4,
            documentNo: $this->ours->document_no,
        );
    }

    /**
     * এই কোম্পানিগুলোর একজন — প্রতিটাতে কেবল ভাউচার ছাপার চাবি
     * (`accounts.report`, ঐ রুটের নিজের `can:`)।
     *
     * @param  list<Company>  $companies
     */
    private function member(array $companies, Branch $home): User
    {
        $user = User::factory()->create([
            'current_company_id' => $companies[0]->id,
            'current_branch_id' => $home->id,
            'is_active' => true,
        ]);

        foreach ($companies as $company) {
            $user->companies()->attach($company->id, ['is_active' => true]);

            CompanyContext::forCompany($company->id,
                fn () => $user->givePermissionTo(Permission::findOrCreate('accounts.report', 'web')));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function asOwnerIn(Company $company): void
    {
        $this->actingAs($this->owner);
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    private function limitClerkTo(Branch $branch): void
    {
        UserDataScope::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->clerk->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $branch->id,
        ]);
        app(DataScope::class)->forget();
    }

    private function allowClerk(Branch $branch): void
    {
        $this->limitClerkTo($branch);
    }

    private function postedVoucher(?Branch $branch): Voucher
    {
        $vouchers = app(VoucherService::class);
        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();

        return $vouchers->post($vouchers->create(
            [
                'type' => Voucher::JOURNAL,
                'trx_date' => now()->toDateString(),
                'narration' => 'shared link wall',
                'branch_id' => $branch?->id,
            ],
            [
                ['account_id' => $cash->id, 'debit' => '1000', 'credit' => '0'],
                ['account_id' => $capital->id, 'debit' => '0', 'credit' => '1000'],
            ],
        ));
    }
}
