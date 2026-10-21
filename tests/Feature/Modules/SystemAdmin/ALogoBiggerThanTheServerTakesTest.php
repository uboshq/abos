<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Core\Support\UploadLimit;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ⛔ লাইভে post_max_size ৮ MB, অথচ লোগোর দরজা লিখত "১২ MB পর্যন্ত" — মাঝের ফাইল পাঠালে PHP গোটা POST ফেলে দিত (`_token`-সহ),
 * আর মানুষ পেতেন ৪১৯ বা কিছুই না (fe-র মাপা লাইভ PHP, ২১ অক্টোবর ২০২৬)।
 *
 * ⭐ এখন সীমা সার্ভার থেকে গোনা ([[UploadLimit]]): min(১২ MB, post_max_size, upload_max_filesize) − ২৫৬ KB।
 * ⓘ পরীক্ষা সীমাটা বসায় `abos.uploads.post_max_size` দিয়ে — চলমান PHP-তে upload-এর ini নামানো যায় না; `UploadLimit` আর
 * POST-মাপের পাহারা ([[ValidatePostSizeFromLimit]]) দুইটাই কনফিগটা আগে পড়ে।
 *
 * দাবি, আসল পাতা আর আসল দরজা দিয়ে (কোম্পানির তৈরি আর সম্পাদনা):
 *  - ঘরের পাশে "সর্বোচ্চ X MB" — গোনা সংখ্যাটাই (২M → 1.7, ৮M → 7.7), আর ফাইল-ঘরে ব্রাউজারের আগাম সীমা একই বাইট;
 *  - সীমার বেশি ছবি → বাংলা বার্তা "ফাইলটা X MB-র বেশি — ছোট করে আবার দিন", আর কিছুই বদলায় না (নাম, লোগো, অডিট; তৈরিতে
 *    কোম্পানিই নয়);
 *  - post_max_size-এর বেশি POST (CONTENT_LENGTH) → ফর্মে ফেরে, বার্তাসহ — ৪১৯ নয়, নীরব ফেরা নয়।
 */
final class ALogoBiggerThanTheServerTakesTest extends TestCase
{
    use RefreshDatabase;

    private const TOO_BIG = 'ফাইলটা 1.7 MB-র বেশি — ছোট করে আবার দিন';

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'bn'])->save();
        Storage::fake('public');

        // ⓘ ২ MB-র সার্ভার: ছবির সীমা ২ MB − ২৫৬ KB = ১৭৯২ KB → "1.7"
        config(['abos.uploads.post_max_size' => '2M', 'abos.uploads.upload_max_filesize' => '50M']);
    }

    public function test_the_hint_and_the_pickers_limit_show_the_computed_figure(): void
    {
        $this->assertSame(1792 * 1024, UploadLimit::pictureBytes(), 'প্রস্তুতিটাই ভুল — সীমা গোনা ভুল।');

        foreach ([route('system_admin.company.edit', $this->company), route('system_admin.company.create')] as $url) {
            $html = (string) $this->actingAs($this->owner)->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/data-logo-limit[^>]*>\s*সর্বোচ্চ 1\.7 MB/u', $html, "⛔ {$url}: ঘরের পাশে গোনা সীমা নেই।");
            $this->assertStringContainsString('data-max-bytes="'.(1792 * 1024).'"', $html, "⛔ {$url}: ব্রাউজারের আগাম সীমা দরজার সীমা নয়।");
        }

        // ⓘ লাইভের মতো ৮ MB → 7.7
        config(['abos.uploads.post_max_size' => '8M']);
        $html = (string) $this->actingAs($this->owner)->get(route('system_admin.company.edit', $this->company))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-logo-limit[^>]*>\s*সর্বোচ্চ 7\.7 MB/u', $html, '⛔ লাইভের ৮ MB-তে পর্দার সংখ্যা 7.7 নয়।');
    }

    public function test_a_picture_over_the_limit_is_refused_in_bengali_and_nothing_changes(): void
    {
        Storage::disk('public')->put('logos/before.png', 'png');
        $this->company->forceFill(['logo_path' => 'logos/before.png'])->save();
        $name = $this->company->name_en;
        $audits = AuditTrail::query()->withoutGlobalScopes()->forRecord(Company::class, (int) $this->company->id)->count();

        $this->actingAs($this->owner)
            ->from(route('system_admin.company.edit', $this->company))
            ->put(route('system_admin.company.update', $this->company), [
                'code' => $this->company->code,
                'name_en' => 'Renamed With A Big Logo',
                'name_bn' => (string) $this->company->name_bn,
                'logo' => UploadedFile::fake()->image('photo.jpg', 400, 300)->size(3 * 1024),
            ])
            ->assertSessionHasErrors(['logo' => self::TOO_BIG]);

        $after = $this->company->fresh();
        $this->assertSame('logos/before.png', $after->logo_path, '⛔ সীমার বাইরের ছবিতে লোগো বদলে গেল।');
        $this->assertSame($name, $after->name_en, '⛔ ফেরত যাওয়া ফর্মের নাম তবু রাখা হলো।');
        $this->assertSame($audits, AuditTrail::query()->withoutGlobalScopes()->forRecord(Company::class, (int) $this->company->id)->count(),
            '⛔ ফেরত যাওয়া ফর্মে তবু অডিট সারি।');

        // ⓘ তৈরিতে — কোম্পানিই তৈরি হয় না
        $before = Company::query()->count();
        $this->actingAs($this->owner)
            ->from(route('system_admin.company.create'))
            ->post(route('system_admin.company.store'), [
                'code' => 'BIGCO', 'name_en' => 'Big Logo Ltd', 'branch_code' => 'MAIN', 'branch_name_en' => 'Head Office',
                'year_name' => '2026-2027', 'year_starts_on' => '2026-07-01', 'year_ends_on' => '2027-06-30',
                'logo' => UploadedFile::fake()->image('photo.jpg', 400, 300)->size(3 * 1024),
            ])
            ->assertSessionHasErrors(['logo' => self::TOO_BIG]);
        $this->assertSame($before, Company::query()->count(), '⛔ সীমার বাইরের লোগো সত্ত্বেও কোম্পানি তৈরি হলো।');
    }

    public function test_an_oversized_post_returns_to_the_form_with_the_message_not_419(): void
    {
        $doors = [
            route('system_admin.company.update', $this->company) => route('system_admin.company.edit', $this->company),
            route('system_admin.company.store') => route('system_admin.company.create'),
        ];

        foreach ($doors as $door => $form) {
            // ⓘ PHP দেহ ফেলে দিলে যা আসে: খালি POST, `_token` নেই, কেবল বড় CONTENT_LENGTH
            $response = $this->actingAs($this->owner)->call('POST', $door, [], [], [], ['CONTENT_LENGTH' => (string) (3 * 1024 * 1024)]);

            $this->assertNotSame(419, $response->getStatusCode(), "⛔ {$door}: বড় POST-এ ৪১৯।");
            $response->assertRedirect($form.'?file_too_big=1');

            $this->actingAs($this->owner)->get($form.'?file_too_big=1')->assertOk()
                ->assertSee('data-file-too-big', false)
                ->assertSee(self::TOO_BIG);
        }

        $this->assertNull(Company::query()->where('code', 'BIGCO')->first());
    }
}
