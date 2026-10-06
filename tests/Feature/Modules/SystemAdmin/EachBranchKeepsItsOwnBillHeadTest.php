<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\BranchSettings;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Support\InvoicePrintLook;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রতিটা শাখার বিলের মাথা নিজের — মালিক, ৬ অক্টোবর ২০২৬ (ছবি: "কার জন্য: কোম্পানি / গোল্ড / জাবেদ / লায়ন / সুপার / হোলসেল",
 * এক শাখায় বদলালে সব শাখায় বদলায়)।
 *
 * দাবি — একই মালিক, একই পাতা: সুপারে নাম বদলালে লায়নের বিল আগের মতো; সুপারের বিলে সুপারের নাম; শাখায় খালি থাকলে
 * কোম্পানির নাম; কোম্পানিতেও খালি থাকলে প্রোফাইলের নাম।
 */
final class EachBranchKeepsItsOwnBillHeadTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $super;

    private Branch $lion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->super = $this->branch('SUPER', 'Super');
        $this->lion = $this->branch('LION', 'Lion');
    }

    public function test_a_change_in_one_branch_stays_in_that_branch(): void
    {
        $profile = (string) $this->company->name('en');

        // ⓘ কোনো শাখায় কিছু নেই — প্রোফাইলের নাম সবখানে
        $this->assertSame($profile, $this->headIn($this->lion)['name'], 'প্রস্তুতিটাই ভুল — প্রোফাইলের নাম আসেনি।');

        // ⭐ কোম্পানির মাথা — শাখায় খালি থাকলে সেটাই
        $this->save(null, ['sales.print.header.name' => 'Company Head']);
        $this->assertSame('Company Head', $this->headIn($this->lion)['name']);
        $this->assertSame('Company Head', $this->headIn($this->super)['name']);

        // ⛔ সুপারে বদলানো — কেবল সুপারে
        $this->save($this->super->id, ['sales.print.header.name' => 'Super Store', 'sales.print.header.phone' => '01700-000001']);
        $this->assertSame('Super Store', $this->headIn($this->super)['name'], '⛔ সুপারের বিলে সুপারের নাম নেই।');
        $this->assertSame('01700-000001', $this->headIn($this->super)['phone']);
        $this->assertSame('Company Head', $this->headIn($this->lion)['name'], '⛔ সুপারে বদলাতেই লায়নের বিলের নাম বদলে গেল।');
        $this->assertNotSame('01700-000001', $this->headIn($this->lion)['phone'], '⛔ সুপারের ফোন লায়নের বিলে উঠল।');
        $this->assertSame('Company Head', $this->headIn(null)['name'], '⛔ সুপারে বদলাতেই কোম্পানির নাম বদলে গেল।');

        // ⭐ লায়নের পাতা আবার খুলে সংরক্ষণ — কিছু না বদলে — সুপারের মান ছোঁয় না, লায়ন খালিই থাকে
        $this->get(route('system_admin.print_control.invoice_info', ['branch' => $this->lion->id]))->assertOk();
        $this->save($this->lion->id, ['sales.print.header.name' => '']);
        $this->assertSame('Super Store', $this->headIn($this->super)['name'], '⛔ লায়নে সংরক্ষণ করতেই সুপারের নাম হারাল।');
        $this->assertSame('Company Head', $this->headIn($this->lion)['name']);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array{name: string, address: string, phone: string, email: string, website: string} */
    private function headIn(?Branch $branch): array
    {
        return app(BranchSettings::class)->during($branch?->id,
            fn () => app(InvoicePrintLook::class)->header($this->company->fresh()));
    }

    /** @param  array<string, string>  $settings */
    private function save(?int $branch, array $settings): void
    {
        $this->put(route('system_admin.print_control.invoice_info.update'), array_filter([
            'branch' => $branch,
            'settings' => $settings,
        ], fn ($v) => $v !== null))->assertSessionHasNoErrors();
    }

    private function branch(string $code, string $name): Branch
    {
        return Branch::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'code' => $code],
            ['name_en' => $name, 'name_bn' => $name, 'is_active' => true],
        );
    }
}
