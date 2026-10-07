<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * প্রতিষ্ঠানটা নিজের কাগজে নিজের লোগো বসাতে পারত না।
 *
 * ── কী ছিল, আর কী ছিল না — ৫ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * পড়ার দিকটা অনেক আগেই সম্পূর্ণ: `companies.logo_path` কলাম,
 * [[Company::logoUrl()]] পর্দার জন্য, আর [[Company::logoData()]] ছাপার
 * জন্য (base64, কারণ ফাইলের নামে স্পেস থাকলে mPDF পথটা মাঝপথে কেটে
 * ফেলত)।
 *
 * ⛔ **কিন্তু ভরার কোনো উপায় ছিল না।** গোটা রিপোতে `logo_path`-এ কিছু
 * লেখা হত এমন একটাও জায়গা ছিল না, কেবল ডেমো সিডার ছাড়া। অর্থাৎ যে
 * গ্রাহক ABOS কিনতেন, তাঁর প্রতিটা চালান-বিল ছাপা হত লোগো ছাড়া, আর
 * বসানোর কোনো পথও থাকত না।
 *
 * ⚠️ **এটা বিক্রির পণ্যে ছোট ফাঁক নয়** — এগারোটা শিল্পের যে কোনো
 * গ্রাহক প্রথম দিনেই এটা খুঁজবেন।
 */
class TheCompanyCouldNotPutItsOwnLogoOnItsOwnPapersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /*
         * ⓘ নকল ডিস্ক — আসল `storage/app/public`-এ পরীক্ষার আবর্জনা
         * জমতে দেওয়া হয় না, আর `Storage::fake()` পুরনো ফাইলগুলোও
         * আড়াল করে, তাই সিডারের বসানো পথগুলো এখানে "নেই" হয়ে যায়।
         */
        Storage::fake('public');
    }

    /** লোগো আপলোড হয়, আর কলামে পথটা বসে। */
    public function test_a_company_can_finally_upload_its_own_logo(): void
    {
        $this->actingAs($this->owner)
            ->put(route('system_admin.company.update', $this->company), [
                ...$this->identity(),
                'logo' => UploadedFile::fake()->image('মালিকের লোগো.png', 400, 120),
            ])
            ->assertRedirect();

        $company = $this->company->fresh();

        $this->assertNotNull($company->logo_path, 'লোগোর পথটা কলামে বসেনি।');
        Storage::disk('public')->assertExists($company->logo_path);

        /*
         * ⭐ নামটা নতুন করে বানানো হয়েছে — ব্যবহারকারীর দেওয়া নাম নয়।
         *
         * ⚠️ কারণটা পুরনো একটা বাগ: "Trade Depot.png"-এর মাঝের স্পেসে
         * mPDF পথটা কেটে ফেলত আর লোগো ভাঙা দেখাত। ⓘ নাম নিজে বানালে
         * ঐ শ্রেণির ভুল আর জন্মায়ই না — তাই স্পেস বা বাংলা অক্ষর
         * সংরক্ষিত নামে থাকতে পারে না।
         */
        $this->assertStringStartsWith('logos/'.$this->company->code.'-', $company->logo_path);
        $this->assertStringNotContainsString(' ', $company->logo_path,
            'সংরক্ষিত নামে স্পেস আছে — ছাপার পথটা আবার ভাঙবে।');
    }

    /**
     * ⭐ আর ছাপার কাগজও ওটা পায় — এটাই আসল প্রমাণ।
     *
     * ⓘ কলামে পথ বসা আর ছাপায় ছবি ওঠা এক জিনিস নয়: মাঝখানে ডিস্ক,
     * symlink আর base64 — তিনটাই ভাঙতে পারে।
     */
    public function test_the_printed_paper_actually_gets_it(): void
    {
        $this->actingAs($this->owner)
            ->put(route('system_admin.company.update', $this->company), [
                ...$this->identity(),
                'logo' => UploadedFile::fake()->image('logo.png', 400, 120),
            ])
            ->assertRedirect();

        $company = $this->company->fresh();

        $this->assertNotNull($company->logoUrl(), 'পর্দার জন্য কোনো ঠিকানা পাওয়া যায়নি।');
        $this->assertStringStartsWith('data:image/', (string) $company->logoData(),
            'ছাপার জন্য base64 ছবিটা তৈরি হয়নি — কাগজে লোগো উঠবে না।');
    }

    /**
     * ⛔ ফাইল না দিলে পুরনো লোগো মুছে যায় না।
     *
     * ⚠️ এটাই সবচেয়ে সহজ ভুল: কেউ কেবল ফোন নম্বরটা শুধরাতে এসে সেভ
     * চাপলেন, আর লোগোটা নীরবে চলে গেল। ⓘ পরের চালানে ধরা পড়ত, আর
     * কেউ বুঝত না কেন।
     */
    public function test_saving_without_a_file_keeps_the_logo(): void
    {
        $this->company->update(['logo_path' => 'logos/already-there.png']);
        Storage::disk('public')->put('logos/already-there.png', 'a file');

        $this->actingAs($this->owner)
            ->put(route('system_admin.company.update', $this->company), $this->identity())
            ->assertRedirect();

        $this->assertSame('logos/already-there.png', $this->company->fresh()->logo_path,
            'ফাইল না দিয়ে সেভ করায় লোগোটা মুছে গেছে।');
    }

    /** আর সরাতে চাইলে সরানো যায় — কিন্তু ইচ্ছে করে বললেই। */
    public function test_it_comes_off_only_when_asked(): void
    {
        $this->company->update(['logo_path' => 'logos/already-there.png']);

        $this->actingAs($this->owner)
            ->put(route('system_admin.company.update', $this->company), [
                ...$this->identity(),
                'remove_logo' => '1',
            ])
            ->assertRedirect();

        $this->assertNull($this->company->fresh()->logo_path);
    }

    /**
     * ⛔ SVG নেওয়া হয় না — নিরাপত্তার প্রশ্ন, সুবিধার নয়।
     *
     * ⚠️ SVG-তে স্ক্রিপ্ট বসানো যায়, আর ফাইলটা পরে সরাসরি ব্রাউজারে
     * পরিবেশিত হয়। ⓘ একজন গ্রাহকের কর্মী একটা লোগো আপলোড করে অন্য
     * ব্যবহারকারীর পর্দায় কোড চালাতে পারতেন।
     */
    public function test_a_script_bearing_image_is_refused(): void
    {
        /*
         * ⚠️ প্রত্যাখ্যানের পর ঘরটা **অপরিবর্তিত** থাকার কথা, `null`
         * নয় — আর এখানে আমি নিজেই ভুল প্রত্যাশা লিখেছিলাম।
         *
         * ⓘ ডেমো সিডার আগেই একটা লোগো বসিয়ে রাখে
         * (`logos/Trade Depot.png`), তাই "null হয়ে যাওয়া" মাপাটা
         * আসলে **উল্টো জিনিস** মাপত: একটা প্রত্যাখ্যাত আপলোড যেন
         * পুরনো লোগোটা মুছে না দেয়, সেটাই তো আসল দাবি।
         */
        $before = $this->company->logo_path;

        $this->actingAs($this->owner)
            ->from(route('system_admin.company.edit', $this->company))
            ->put(route('system_admin.company.update', $this->company), [
                ...$this->identity(),
                'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
            ])
            ->assertSessionHasErrors('logo');

        $this->assertSame($before, $this->company->fresh()->logo_path,
            'প্রত্যাখ্যাত আপলোডটা পুরনো লোগোটাও নিয়ে গেছে।');
    }

    /**
     * ⛔ আর পর্দায় ঘরটা সত্যিই আছে, `enctype` সহ।
     *
     * ⚠️ `enctype="multipart/form-data"` ছাড়া ব্রাউজার **কেবল নামটা
     * পাঠায়** — ফাইল নয়। ⓘ কোনো ত্রুটিও দেখায় না: সেভ হয়ে যায়, শুধু
     * লোগো বসে না। এই একটা ভুলে গোটা ফিচারটা নীরবে অকেজো।
     */
    public function test_the_screen_has_the_box_and_can_carry_a_file(): void
    {
        $this->actingAs($this->owner)
            ->get(route('system_admin.company.edit', $this->company))
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="logo"', false);
    }

    /** @return array<string, string> */
    private function identity(): array
    {
        return [
            'name_en' => $this->company->name_en,
            'name_bn' => (string) $this->company->name_bn,
        ];
    }
}
