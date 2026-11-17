<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ⭐ জমার খবর — স্লিপ কয়েক হাত ঘুরে আসে (মালিক, ১৬ নভেম্বর ২০২৬: *"ডিলার জমা দিয়ে এসআরের কাছে পাঠায় দেয় … আত্মীয় স্বজন … ম্যানেজারকে
 * পাঠায় … তারা আবার অ্যাপসে এন্ট্রি দেয়"*; *"ওয়েবে এবং অ্যাপস দুই জায়গায় শর্টকাট"*)।
 *
 *   - খবরের নিজের চাবি `sales.claim.submit` — কেবল সেটা থাকলে পাতা খোলে, কেবল আদায়ের চাবিতে নয়; ＋ মেনুতে শর্টকাট
 *   - "টাকা কে জমা দিলেন" খাতায় থাকে, অফিসের তালিকায় আর ফোনে দেখা যায়
 *   - হোয়াটসঅ্যাপে আবার আসা একই স্লিপ: পাঠানেওয়ালা সতর্কতা পান, অফিসের তালিকায় চিহ্ন — আটকায় না; অন্য স্লিপে কিছুই নয়
 */
final class TheSlipCameThroughThreeHandsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Account $bank;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-HANDS', 'name_en' => 'Hands bank', 'name_bn' => 'হাতের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);
        $this->dealer = Customer::query()->inViewedBranch()->orderBy('id')->firstOrFail();
    }

    public function test_the_report_has_its_own_key_and_a_shortcut(): void
    {
        $sr = $this->person(['sales.claim.submit']);
        $this->actingAs($sr)->get(route('sales.claim.request.create'))->assertOk();
        // ⓘ ＋ মেনুটা নিজেই আঁকা — বিক্রয়ের মেনুতেও একই ঠিকানা আছে, পুরো পাতা খুঁজলে শর্টকাট না থাকলেও সবুজ হত
        $this->actingAs($sr);
        $this->assertStringContainsString(route('sales.claim.request.create'), (string) $this->blade('<x-shell.create-menu />'),
            '⛔ ＋ মেনুতে "টাকা জমার খবর"-এর শর্টকাট নেই');

        // ⓘ কেবল আদায়ের চাবি — খবরের দরজা খোলে না, শর্টকাটও নেই (চাবি আলাদা)
        $collector = $this->person(['sales.collection.create']);
        $this->actingAs($collector)->get(route('sales.claim.request.create'))->assertForbidden();
        $this->assertStringNotContainsString(route('sales.claim.request.create'), (string) $this->blade('<x-shell.create-menu />'));
    }

    public function test_who_deposited_is_kept_and_shown(): void
    {
        $sr = $this->person(['sales.claim.submit']);
        $this->actingAs($sr)->post(route('sales.claim.request.store'), $this->form('TRX-H-1', $this->slip(1), ['depositor' => 'ডিলারের ছেলে রাকিব']))
            ->assertSessionHasNoErrors()->assertRedirect();

        $claim = DepositClaim::query()->latest('id')->firstOrFail();
        $this->assertSame('ডিলারের ছেলে রাকিব', $claim->depositor);

        $desk = $this->person(['sales.claim.view']);
        $this->actingAs($desk)->get(route('sales.claim.index'))->assertOk()->assertSee('ডিলারের ছেলে রাকিব');
    }

    public function test_the_same_slip_again_warns_and_marks_but_another_slip_does_not(): void
    {
        $sr = $this->person(['sales.claim.submit']);
        $this->actingAs($sr)->post(route('sales.claim.request.store'), $this->form('TRX-H-2', $this->slip(1)))->assertSessionHasNoErrors();
        $first = DepositClaim::query()->latest('id')->firstOrFail();

        $other = $this->actingAs($sr)->post(route('sales.claim.request.store'), $this->form('TRX-H-3', $this->slip(2)));
        $other->assertSessionHasNoErrors()->assertSessionMissing('warning');
        $this->assertNull(DepositClaim::query()->latest('id')->firstOrFail()->lookalike_claim_id, '⛔ অন্য স্লিপকে একই বলা হয়েছে');

        // ⭐ একই স্লিপ, হোয়াটসঅ্যাপে ছোট আর আবার চাপা — অন্য রেফারেন্সে এলেও চেহারা চেনে; আটকায় না
        $again = $this->actingAs($sr)->post(route('sales.claim.request.store'), $this->form('TRX-H-4', $this->slip(1, whatsapp: true)));
        $again->assertSessionHasNoErrors()->assertSessionHas('warning');
        $copy = DepositClaim::query()->latest('id')->firstOrFail();
        $this->assertSame($first->id, (int) $copy->lookalike_claim_id, '⛔ হোয়াটসঅ্যাপে আবার আসা একই স্লিপ চেনা যায়নি');
        $this->assertSame(DepositClaim::PENDING, $copy->status, '⛔ সতর্কতার বদলে আটকে দিয়েছে');

        $desk = $this->person(['sales.claim.view']);
        $this->actingAs($desk)->get(route('sales.claim.index'))->assertOk()->assertSee('data-slip-lookalike', false);
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->givePermissionTo($keys);

        return $user->fresh();
    }

    /** @return array<string, mixed> */
    private function form(string $reference, UploadedFile $slip, array $more = []): array
    {
        return [
            'customer' => (string) $this->dealer->id, 'claimed_on' => now()->subDay()->toDateString(), 'amount' => '45000',
            'method' => DepositClaim::BANK, 'reference' => $reference, 'bank_account_id' => $this->bank->id, 'slip' => $slip,
        ] + $more;
    }

    /** একটা স্লিপের ছবি — `$layout` বদলালে অন্য স্লিপ; `whatsapp` হলে ছোট আর আবার চাপা */
    private function slip(int $layout, bool $whatsapp = false): UploadedFile
    {
        mt_srand(77 + $layout);
        $image = imagecreatetruecolor(1600, 2200);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 238, 230));
        $ink = imagecolorallocate($image, 20, 30, 90);
        for ($i = 0; $i < 14; $i++) {
            $top = ($layout === 1 ? 200 : 900) + $i * ($layout === 1 ? 110 : 60);
            imagefilledrectangle($image, 160, $top, 1600 - mt_rand(200, 900), $top + 16, $ink);
        }
        if ($layout !== 1) {
            imagefilledellipse($image, 400, 400, 500, 500, imagecolorallocate($image, 170, 30, 40));
        }
        if ($whatsapp) {
            $image = imagescale($image, 931, 1280);
        }

        $path = tempnam(sys_get_temp_dir(), 'slip').'.jpg';
        imagejpeg($image, $path, $whatsapp ? 60 : 92);

        return new UploadedFile($path, 'slip.jpg', 'image/jpeg', null, true);
    }
}
