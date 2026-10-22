<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\AuditFieldChange;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ⛔ নতুন কোম্পানির পর্দায় লোগোর ঘর ছিল, অথচ লোগোটা রাখা হত না — `store()` ফাইলটা পড়তই না (cb-র খোঁজ, fe-র আদেশে ঠিক,
 * ২১ অক্টোবর ২০২৬)। ⭐ এখন সম্পাদনার একই দরজা: একই নিয়ম (SVG ১ MB, ছবি ১২ MB), একই ছাঁকনি ([[SafeSvg]]), একই অডিট।
 *
 * দাবি, আসল ফর্মের POST দিয়ে (`system_admin.company.store`):
 *  - ছবি দিলে নতুন কোম্পানির লোগো থাকে, ডিস্কে আছে, পথ কোম্পানির কোড দিয়ে;
 *  - স্ক্রিপ্টওয়ালা SVG দিলে রাখা ফাইলে স্ক্রিপ্ট নেই, আঁকা আছে;
 *  - অডিটে নতুন কোম্পানির `logo_path` খালি → রাখা ফাইল;
 *  - পড়া যায় না এমন SVG হলে কোম্পানিটাই তৈরি হয় না (অর্ধেক-তৈরি কোম্পানি নয়) আর "লোগো" ঘরে ভুল দেখায়।
 */
final class ANewCompanyKeptItsLogoTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        Storage::fake('public');
    }

    public function test_a_picture_given_on_the_create_form_becomes_the_new_companys_logo(): void
    {
        $this->create('PICCO', UploadedFile::fake()->image('logo.png', 600, 200))->assertSessionHasNoErrors()->assertRedirect();

        $company = Company::query()->where('code', 'PICCO')->firstOrFail();
        $this->assertNotNull($company->logo_path, '⛔ তৈরির পর্দায় দেওয়া লোগো রাখা হয়নি।');
        $this->assertStringStartsWith('logos/PICCO-', (string) $company->logo_path);
        Storage::disk('public')->assertExists((string) $company->logo_path);
    }

    public function test_a_script_svg_on_the_create_form_is_cleaned_and_audited(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="40" onload="alert(1)"><script>alert(2)</script>'
            .'<rect id="mark" width="40" height="40" fill="teal"/></svg>';

        $this->create('SVGCO', UploadedFile::fake()->createWithContent('logo.svg', $svg))->assertSessionHasNoErrors()->assertRedirect();

        $company = Company::query()->where('code', 'SVGCO')->firstOrFail();
        $path = (string) $company->logo_path;
        $this->assertStringEndsWith('.svg', $path, '⛔ তৈরির পর্দায় দেওয়া SVG লোগো রাখা হয়নি।');

        $kept = (string) Storage::disk('public')->get($path);
        foreach (['<script', 'onload', 'alert'] as $danger) {
            $this->assertStringNotContainsStringIgnoringCase($danger, $kept, "⛔ নতুন কোম্পানির লোগোয় এখনো {$danger}।");
        }
        $this->assertStringContainsString('<rect id="mark"', $kept, '⛔ ছাঁটতে গিয়ে আঁকাটাই গেছে।');

        $trails = AuditTrail::query()->withoutGlobalScopes()->forRecord(Company::class, (int) $company->id)->pluck('id');
        $change = AuditFieldChange::query()->withoutGlobalScopes()->whereIn('audit_trail_id', $trails)->where('field', 'logo_path')->first();
        $this->assertNotNull($change, '⛔ নতুন কোম্পানির লোগোর অডিট সারি নেই।');
        $this->assertNull($change->old_value, '⛔ অডিটে আগের ফাইল খালি নয়।');
        $this->assertSame($path, $change->new_value, '⛔ অডিটে রাখা ফাইলটা নেই।');
    }

    public function test_an_unreadable_svg_stops_the_whole_create(): void
    {
        $before = Company::query()->count();

        $this->create('BADCO', UploadedFile::fake()->createWithContent('logo.svg', '<html><script>alert(1)</script></html>'))
            ->assertSessionHasErrors('logo');

        $this->assertSame($before, Company::query()->count(), '⛔ খারাপ লোগো সত্ত্বেও কোম্পানি তৈরি হয়ে গেল — লোগো ছাড়া, নীরবে।');
    }

    private function create(string $code, UploadedFile $logo): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner->fresh())
            ->from(route('system_admin.company.create'))
            ->post(route('system_admin.company.store'), [
                'code' => $code,
                'name_en' => $code.' Distribution',
                'branch_code' => 'MAIN',
                'branch_name_en' => 'Head Office',
                'year_name' => '2026-2027',
                'year_starts_on' => '2026-07-01',
                'year_ends_on' => '2027-06-30',
                'logo' => $logo,
            ]);
    }
}
