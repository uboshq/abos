<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\ProfileRequest;
use App\Modules\Sales\Http\Controllers\PortalProfileController;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ গ্রাহকের তথ্য বদল অফিসে অনুরোধ হয়ে যায়, আর কাগজ (ট্রেড লাইসেন্স / NID / BIN) সংযুক্তির খাতায় — গ্রাহক পোর্টাল,
 * ধাপ ১ (১১ অক্টোবর ২০২৬)।
 *
 * ── দাবিগুলো ─────────────────────────────────────────────────────────────
 *   · অনুরোধ গ্রাহকের রেকর্ড বদলায় না — অফিস "বসানো হয়েছে" বললেও না
 *   · অনুরোধে কেবল যা সত্যিই বদলাতে চাওয়া হলো, আর তখন কী ছিল
 *   · কাগজ কেবল ছবি/PDF; নিজের কাগজ খোলা যায় (খাতায় গ্রাহকের নামে), অন্যেরটা ৪০৪
 *   · অফিসের তালিকা `customer.update` চাবিতে; "হলো না"-তে কারণ লাগে, আর গ্রাহক সেটা পোর্টালে দেখেন
 */
final class ThePortalCustomerAsksTheOfficeToChangeTheirDetailsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $me;

    private Customer $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->me = $this->customer('ASK-ME', '01711000222');
        $this->other = $this->customer('ASK-OTHER', '01811000333');
    }

    public function test_a_contact_change_becomes_a_request_and_the_record_stays_as_it_was(): void
    {
        $this->asMe()->post(route('sales.portal.profile.request'), [
            'owner_name' => 'Rahman Mia', 'phone' => '01999000111', 'email' => '', 'address_bn' => '', 'address_en' => '',
            'note' => 'নতুন নম্বর',
        ])->assertSessionHasNoErrors()->assertRedirect(route('sales.portal.profile'));

        $request = ProfileRequest::query()->withoutGlobalScopes()->where('customer_id', $this->me->id)->sole();
        $this->assertSame([ProfileRequest::CONTACT, ProfileRequest::SUBMITTED], [$request->kind, $request->status]);
        $this->assertSame(['phone' => '01999000111'], $request->asked, '⛔ অনুরোধে না-বদলানো ঘরও এল।');
        $this->assertSame(['phone' => '01711000222'], $request->was);
        $this->assertSame('01711000222', $this->me->fresh()->phone, '⛔ অনুরোধ গ্রাহকের রেকর্ড নিজেই বদলে দিল।');

        $this->asMe()->get(route('sales.portal.profile'))->assertOk()
            ->assertSee('data-portal-my-requests', false)->assertSee('01999000111')->assertSee('অফিসে পাঠানো');

        // ⓘ কিছুই না বদলালে অনুরোধ নয়
        $this->asMe()->post(route('sales.portal.profile.request'), ['owner_name' => 'Rahman Mia', 'phone' => '01711000222'])
            ->assertSessionHasErrors('contact');
        $this->assertSame(1, ProfileRequest::query()->withoutGlobalScopes()->count());
    }

    public function test_a_paper_is_an_image_or_pdf_kept_with_its_request_and_opens_only_for_its_owner(): void
    {
        $this->asMe()->post(route('sales.portal.profile.paper'), ['paper_kind' => 'trade_licence', 'paper' => $this->png('licence.png')])
            ->assertSessionHasNoErrors();

        $paper = ProfileRequest::query()->withoutGlobalScopes()->where('customer_id', $this->me->id)->sole();
        $this->assertSame([ProfileRequest::PAPER, 'trade_licence'], [$paper->kind, $paper->paper_kind]);
        $file = Attachment::query()->withoutGlobalScopes()->where('source_entity', ProfileRequest::class)->where('source_entity_id', $paper->id)->sole();
        $this->assertNull($file->uploaded_by, '⛔ গ্রাহকের তোলা কাগজ কোনো কর্মীর নামে বসল।');

        // ⛔ ছবি/PDF ছাড়া অন্য কিছু নয়
        $this->asMe()->post(route('sales.portal.profile.paper'), ['paper_kind' => 'nid', 'paper' => UploadedFile::fake()->create('nid.php', 3, 'text/x-php')])
            ->assertSessionHasErrors('paper');
        // ⓘ সাধারণ সংযুক্তিতে চলে এমন লেখার ফাইলও নয় — কাগজ মানে ছবি বা PDF
        $this->asMe()->post(route('sales.portal.profile.paper'), ['paper_kind' => 'bin', 'paper' => UploadedFile::fake()->createWithContent('bin.txt', 'BIN 0001')])
            ->assertSessionHasErrors('paper');
        $this->asMe()->post(route('sales.portal.profile.paper'), ['paper_kind' => 'passport', 'paper' => $this->png('x.png')])
            ->assertSessionHasErrors('paper_kind');
        $this->assertSame(1, ProfileRequest::query()->withoutGlobalScopes()->count());

        $this->asMe()->get(route('sales.portal.profile.paper.show', $paper->public_id))->assertOk();
        $log = DocumentDelivery::query()->where('document_type', PortalProfileController::PAPER)->sole();
        $this->assertSame([(int) $paper->id, (int) $this->me->id], [(int) $log->document_id, (int) $log->customer_id]);

        $this->actingAs($this->other->fresh(), 'portal')->get(route('sales.portal.profile.paper.show', $paper->public_id))->assertNotFound();
    }

    public function test_the_office_marks_the_request_and_a_refusal_needs_a_reason_the_customer_then_reads(): void
    {
        $this->asMe()->post(route('sales.portal.profile.request'), ['phone' => '01999000111']);
        $this->actingAs($this->other->fresh(), 'portal')->post(route('sales.portal.profile.request'), ['phone' => '01888000999']);
        [$mine, $theirs] = ProfileRequest::query()->withoutGlobalScopes()->orderBy('id')->get()->all();

        $this->actingAs($this->member([]))->get(route('customer.profile_request.index'))->assertForbidden();

        $clerk = $this->member(['customer.update']);
        $this->actingAs($clerk)->get(route('customer.profile_request.index'))->assertOk()
            ->assertSee('data-profile-requests', false)->assertSee('01999000111')->assertSee('01711000222')->assertSee('01888000999');

        $this->actingAs($clerk)->post(route('customer.profile_request.decide', $mine), ['status' => 'refused'])
            ->assertSessionHasErrors('decision_note');
        $this->actingAs($clerk)->post(route('customer.profile_request.decide', $mine), ['status' => 'refused', 'decision_note' => 'নম্বরটা মেলেনি'])
            ->assertSessionHasNoErrors();
        $this->actingAs($clerk)->post(route('customer.profile_request.decide', $theirs), ['status' => 'done'])->assertSessionHasNoErrors();

        $this->assertSame([ProfileRequest::REFUSED, $clerk->id], [$mine->fresh()->status, (int) $mine->fresh()->decided_by]);
        $this->assertSame(ProfileRequest::DONE, $theirs->fresh()->status);
        $this->assertSame('01811000333', $this->other->fresh()->phone, '⛔ "বসানো হয়েছে" চিহ্নই রেকর্ড বদলে দিল — বদলটা অফিসের সম্পাদনায়।');

        $this->actingAs($clerk)->post(route('customer.profile_request.decide', $mine), ['status' => 'done'])
            ->assertSessionHasErrors('status');

        $this->asMe()->get(route('sales.portal.profile'))->assertOk()->assertSee('নম্বরটা মেলেনি')->assertSee('হলো না');
    }

    // ── খুঁটিনাটি ────────────────────────────────────────────────────

    private function asMe(): static
    {
        return $this->actingAs($this->me->fresh(), 'portal');
    }

    private function customer(string $code, string $phone): Customer
    {
        $customer = Customer::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'code' => $code,
            'name_en' => 'Ask '.$code, 'owner_name' => 'Rahman Mia', 'phone' => $phone,
            'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $customer->forceFill(['portal_enabled' => true, 'portal_password' => 'shop-pass-2026'])->save();

        return $customer;
    }

    /** @param  list<string>  $keys */
    private function member(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function png(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));
        $path = tempnam(sys_get_temp_dir(), 'paper');
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
