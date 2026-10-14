<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * কাগজের "সরান" বোতাম এমন মানুষকেও দেখাত যাঁকে সার্ভার পরে ৪০৩ দিত — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬।
 *
 * ⛔ বোতামটা বসত ধরনের `create` চাবি দেখে; সার্ভার সরাতে দেয় কেবল আপলোডকারীকে (সই শুরুর আগে) বা কাগজ সম্পাদনার চাবিধারীকে।
 * ⓘ দুইজন একই চাবির হিসাবরক্ষক: যিনি তুলেছেন তিনি বোতাম দেখেন, অন্যজন দেখেন না — আর সম্পাদনার চাবি পেলে দেখেন।
 */
final class TheRemoveButtonShowedToPeopleItRefusedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private ?string $temp = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->company = Company::query()->findOrFail($this->owner->current_company_id);
        CompanyContext::set($this->company->id, $this->owner->current_branch_id ?? $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        if ($this->temp !== null) {
            @unlink($this->temp);
        }

        parent::tearDown();
    }

    public function test_the_remove_button_shows_only_to_whoever_the_server_lets_remove(): void
    {
        [$a, $b] = Account::query()->where('company_id', $this->company->id)
            ->postable()->active()->whereNull('money_kind')->orderBy('code')->take(2)->get()->all();
        $voucher = app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => 'REMOVE-BUTTON',
        ], [
            ['account_id' => $a->id, 'debit' => '500', 'credit' => '0'],
            ['account_id' => $b->id, 'debit' => '0', 'credit' => '500'],
        ]);

        $uploader = $this->userWith(['accounts.voucher.create', 'accounts.report']);
        $colleague = $this->userWith(['accounts.voucher.create', 'accounts.report']);

        $this->temp = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($this->temp, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        $this->actingAs($uploader)->post(route('attachment.store'), [
            'source_type' => Voucher::drillSourceType(),
            'source_id' => $voucher->id,
            'file' => new UploadedFile($this->temp, 'their-slip.pdf', 'application/pdf', null, true),
        ])->assertRedirect();
        $paper = Attachment::query()->where('original_name', 'their-slip.pdf')->firstOrFail();
        $remove = 'action="'.route('attachment.destroy', $paper).'"';

        $this->actingAs($uploader)->get(route('accounts.voucher.show', $voucher))->assertOk()
            ->assertSee('their-slip.pdf')->assertSee($remove, false);

        $this->actingAs($colleague)->get(route('accounts.voucher.show', $voucher))->assertOk()
            ->assertSee('their-slip.pdf')->assertDontSee($remove, false);

        $colleague->givePermissionTo(Permission::findOrCreate('accounts.voucher.update', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($colleague->fresh())->get(route('accounts.voucher.show', $voucher))->assertOk()
            ->assertSee($remove, false);
    }

    /** @param  list<string>  $keys */
    private function userWith(array $keys): User
    {
        $user = User::factory()->create([
            'current_company_id' => $this->company->id,
            'current_branch_id' => $this->owner->current_branch_id,
            'is_active' => true,
        ]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        foreach ($keys as $key) {
            $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
