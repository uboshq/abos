<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * বাইরের জগতের কী — প্রতিটা টেবিলে, নিজে থেকে।
 *
 * ভেতরে bigint, বাইরে UUIDv7। API, webhook, ইভেন্ট, ইমপোর্ট/এক্সপোর্ট —
 * বাইরের কেউ কখনো `id` দেখে না, কারণ ক্রমিক সংখ্যা গোনা যায়: "আমার
 * আগে কতজন গ্রাহক ছিল", "গত মাসে কয়টা বিল হয়েছে"।
 *
 * এখানকার আসল কাজ ভবিষ্যতের পাহারা। আজ প্রতিটা টেবিলে কলামটা আছে; তিন
 * মাস পর নতুন টেবিল যোগ হলে কেউ ভুলে যাবে, আর ভোলা টেবিলটা কোনো ভুল
 * দেখাবে না — শুধু API-তে অদৃশ্য থাকবে।
 */
class PublicIdTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ফ্রেমওয়ার্ক ও প্যাকেজের নিজস্ব টেবিল — বাইরের কী লাগে না।
     *
     * @var list<string>
     */
    private const FRAMEWORK = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'sessions', 'password_reset_tokens',
        'model_has_permissions', 'model_has_roles', 'role_has_permissions',

        /*
         * Sanctum-এর নিজের টেবিল, মোবাইল অ্যাপের টোকেনের জন্য
         * (২ সেপ্টেম্বর ২০২৬)। মাইগ্রেশনটা প্যাকেজের ভেতর থেকে আসে,
         * তাই `publicId()` যোগ করার জায়গা আমাদের হাতে নেই — করতে হলে
         * প্যাকেজের মাইগ্রেশন publish করে রিপোতে টেনে আনতে হত, আর
         * তখন Sanctum আপডেট হলে আমাদের কপিটা পিছিয়ে থাকত।
         *
         * আর দরকারও নেই: বাইরের কী লাগে সেই সারিগুলোর যেগুলো API-তে
         * নাম ধরে চাওয়া হয়। একটা টোকেনের সারি কেউ কখনো নাম ধরে চায়
         * না — টোকেনটা নিজেই তার একমাত্র পরিচয়, আর সেটা ক্রমিক নয়।
         */
        'personal_access_tokens',
    ];

    /**
     * ব্যবসায়িক ডেটার প্রতিটা টেবিলে public_id আছে।
     *
     * তালিকাটা স্কিমা থেকেই আসে, হাতে লেখা নয় — হাতে লিখলে নতুন টেবিল
     * যোগ হওয়ার দিন এই টেস্টটাও চুপ করে যেত, অর্থাৎ ঠিক যখন দরকার তখন।
     */
    public function test_every_business_table_carries_a_public_id(): void
    {
        $missing = [];

        // চলতি স্কিমা স্পষ্ট করে বলা — নাহলে পাশের ডেটাবেসের টেবিলও
        // তালিকায় আসে, আর এখানে নেই এমন নাম নিয়ে টেস্ট ভুল অভিযোগ করে
        $listing = Schema::getTableListing(
            schema: Schema::getCurrentSchemaName(),
            schemaQualified: false,
        );

        foreach ($listing as $table) {
            if (in_array($table, self::FRAMEWORK, true)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'public_id')) {
                $missing[] = $table;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'এই টেবিলগুলোয় বাইরের কী নেই।',
            'মাইগ্রেশনে $table->publicId() যোগ করুন — ম্যাক্রোটা AppServiceProvider-এ।',
        ]));
    }

    /**
     * প্রতিটা মডেলে trait-টা বসানো আছে।
     *
     * কলাম থাকা যথেষ্ট নয় — trait না থাকলে নতুন সারিতে কী বসত না, আর
     * সেটা ধরা পড়ত অনেক পরে, যখন কোনো API সারিটা খুঁজে পেত না।
     */
    public function test_every_model_generates_its_own_public_id(): void
    {
        $missing = [];

        foreach ($this->modelFiles() as $file) {
            $source = File::get($file);

            // abstract বা যে ক্লাস Model বাড়ায় না, সেগুলো বাদ
            if (! preg_match('/class \w+ extends (Model|Authenticatable)/', $source)) {
                continue;
            }

            if (! str_contains($source, 'HasPublicId')) {
                $missing[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertSame([], $missing, 'এই মডেলগুলোয় HasPublicId নেই।');
    }

    public function test_a_new_record_gets_a_uuid_without_being_asked(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $supplier = app(SupplierService::class)->create([
            'name_en' => 'UUID Test',
            'credit_limit' => 0,
            'credit_days' => 0,
        ]);

        $this->assertNotNull($supplier->public_id);

        // UUIDv7 — সময়-ক্রমানুসারী, তাই ইউনিক ইনডেক্সটা ক্রমে ভরে।
        // v4 হলে সেই এলোমেলো ইনসার্টের সমস্যা ফিরে আসত।
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $supplier->public_id,
            'কী-টা UUIDv7 নয়।',
        );
    }

    public function test_two_records_never_share_a_public_id(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $service = app(SupplierService::class);

        $ids = [];

        foreach (range(1, 5) as $i) {
            $ids[] = $service->create([
                'name_en' => "Unique {$i}",
                'credit_limit' => 0,
                'credit_days' => 0,
            ])->public_id;
        }

        $this->assertCount(5, array_unique($ids));
    }

    public function test_a_record_can_be_found_by_its_public_id(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $supplier = app(SupplierService::class)->create([
            'name_en' => 'Findable',
            'credit_limit' => 0,
            'credit_days' => 0,
        ]);

        $found = Supplier::findByPublicId($supplier->public_id);

        $this->assertNotNull($found);
        $this->assertSame($supplier->id, $found->id);
    }

    /**
     * ওয়েব রুট আগের মতোই id দিয়ে চলে।
     *
     * getRouteKeyName() বদলালে প্রতিটা লিংক, রিডাইরেক্ট ও টেস্ট একদিনে
     * ভাঙত, অথচ লাভ কিছুই হত না — ব্রাউজারের ঠিকানায় সংখ্যা দেখা কোনো
     * সমস্যা নয়। সমস্যা বাইরের সিস্টেমকে সংখ্যা দেওয়া।
     */
    public function test_web_routes_still_use_the_numeric_id(): void
    {
        $this->assertSame('id', (new Supplier)->getRouteKeyName());
    }

    /** @return list<string> */
    private function modelFiles(): array
    {
        $files = [];

        foreach ([app_path('Models'), app_path('Modules')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                if (! str_contains($file->getPathname(), 'Models')) {
                    continue;
                }

                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
