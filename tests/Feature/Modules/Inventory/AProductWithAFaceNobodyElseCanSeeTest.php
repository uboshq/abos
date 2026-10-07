<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ProductImageService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * পণ্যের একটা মুখ — কিন্তু সেটা যেন কেবল নিজের লোকই দেখে।
 *
 * ── কেন ছবি `storage/app/private`-এ, `public`-এ নয় ───────────────────
 * `public` ডিস্কে রাখলে ছবিটা `public/storage/...` পথে **যে কারও জন্য
 * খোলা** — লগইন ছাড়া, অন্য কোম্পানির লোকও, শুধু URL অনুমান করে।
 *
 * এটা বহু-টেন্যান্ট পণ্য আর **ক্রেতারা একে অপরের প্রতিযোগী**। পণ্যের
 * ছবি নিরীহ জিনিস নয়: কে কী বেচে, কোন ব্র্যান্ড ধরে, নতুন কী তুলছে।
 * CLAUDE.md-এর ভাষায় — বহু-টেন্যান্ট বলেই টেন্যান্ট বিচ্ছিন্নতা সুবিধা
 * নয়, **বাধ্যবাধকতা**।
 *
 * ⚠️ ── আর যে ফাঁদটা এই ফাইলের আসল কারণ ────────────────────────────────
 * ডাটাবেসে ছবির পরিচয় বসে, আর ফাইলটা বসে ডিস্কে — **দুইটা আলাদা
 * জায়গা**। সারিটা থেকে ফাইলটা না থাকলে কোড বিশ্বাস করে ছবিটা আছে, আর
 * পর্দায় আসে একটা ভাঙা চিত্র। কোথাও কোনো ত্রুটি হয় না।
 *
 * তাই নিচে **সারি গোনা হয় না, ফাইলটা ডিস্কে আছে কি না দেখা হয়**।
 */
class AProductWithAFaceNobodyElseCanSeeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->firstOrFail();
        $this->actingAs($this->owner);

        $this->product = Product::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function image(string $name = 'front.jpg'): UploadedFile
    {
        // সত্যিকারের একটা ছবি — `fake()->image()` GD দিয়ে বানায়, তাই
        // ভিতরটা পড়লেও ওটা সত্যিই ছবি (mime যাচাই এটাই দেখে)
        return UploadedFile::fake()->image($name, 40, 40);
    }

    public function test_the_picture_lands_on_the_private_disk_not_the_public_one(): void
    {
        $attachment = app(ProductImageService::class)
            ->replace($this->product, $this->image(), $this->owner->id);

        /*
         * ⚠️ সারি গুনে সন্তুষ্ট হওয়া যাবে না — সারি থাকা আর ফাইল থাকা
         * দুইটা আলাদা কথা, আর ঠিক ওই ফাঁকেই DMS-এ ছবি হারিয়েছিল।
         */
        $this->assertTrue(
            Storage::disk('local')->exists($attachment->stored_path),
            'ফাইলটা private ডিস্কে নেই — সারি আছে, ছবি নেই।',
        );

        // আর public-এ যেন কিছুই না যায়
        $this->assertFalse(
            Storage::disk('public')->exists($attachment->stored_path),
            'ছবিটা public ডিস্কে গেছে — URL অনুমান করেই যে কেউ দেখতে পারবে।',
        );
    }

    public function test_the_column_says_which_one_is_the_face(): void
    {
        $attachment = app(ProductImageService::class)
            ->replace($this->product, $this->image(), $this->owner->id);

        $fresh = $this->product->fresh();

        $this->assertSame($attachment->id, $fresh->primary_image_id);
        $this->assertSame($attachment->id, $fresh->primaryImage?->id);
    }

    /**
     * নতুন ছবি এলে পুরনোটা **মুছে যায় না**।
     *
     * ভুল ছবি তুলে ফেললে ফেরার পথ থাকা দরকার, আর "ছবিটা কে কখন বদলাল"
     * প্রশ্নের উত্তরও। এই রিপোর নিয়মই তাই — hard delete নেই, কোথাও।
     */
    public function test_replacing_keeps_the_old_one(): void
    {
        $service = app(ProductImageService::class);

        $first = $service->replace($this->product, $this->image('old.jpg'), $this->owner->id);
        $second = $service->replace($this->product->fresh(), $this->image('new.jpg'), $this->owner->id);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($second->id, $this->product->fresh()->primary_image_id);

        // পুরনো সারিটা আছে, আর সে জানে কে তাকে সরিয়েছে
        $this->assertNotNull(Attachment::query()->find($first->id));
        $this->assertSame($first->id, $second->replaces_id);

        // ⚠️ আর পুরনো ফাইলটাও ডিস্কে আছে — সারি রেখে ফাইল মুছলে
        //    "ফেরার পথ আছে" কথাটা মিথ্যা হত
        $this->assertTrue(Storage::disk('local')->exists($first->stored_path));
    }

    /**
     * ⚠️ নাম ঠিক, ভিতরটা ছবি নয় — সবচেয়ে জরুরি পাহারা।
     *
     * ব্রাউজারের পাঠানো ধরনটা **ক্লায়েন্ট লেখে**, তাই যে কেউ একটা
     * স্ক্রিপ্টকে `image/png` বলে পাঠাতে পারেন। এখানে ফাইলের ভিতরটা
     * পড়া হয় (finfo), আর সেটাই একমাত্র উত্তর যা আক্রমণকারী লিখতে
     * পারেন না।
     */
    public function test_a_file_that_only_claims_to_be_an_image_is_refused(): void
    {
        /*
         * ⚠️ `UploadedFile::fake()` এখানে **ব্যবহার করা যায় না**, আর
         * কারণটা এই টেস্টের গোটা বিষয়বস্তু।
         *
         * `Illuminate\Http\Testing\File::getMimeType()` ধরনটা **নামের
         * এক্সটেনশন দেখে** অনুমান করে — ফাইলের ভিতরে যা-ই থাক।
         * মেপে দেখা (৩ সেপ্টেম্বর ২০২৬):
         *
         *   fake()->createWithContent('front.png', '<?php …')  →  image/png
         *   ঠিক একই বিষয়বস্তুর আসল ফাইল, finfo দিয়ে           →  text/x-php
         *
         * অর্থাৎ fake দিয়ে লেখা এই টেস্টটা **পাহারা থাকুক বা না থাকুক
         * একই ফল দিত** — একটা নিখুঁত অন্ধ গার্ড। প্রথমে ঠিক সেভাবেই
         * লেখা হয়েছিল, আর সে **সবুজ নয়, লাল** হয়েছিল; ওই লালটাই
         * ধরিয়ে দেয় যে ভুলটা টেস্টে, কোডে নয়।
         *
         * তাই এখানে একটা **সত্যিকারের ফাইল** বানানো হয়, আর
         * `UploadedFile`-কে বলা হয় এটা পরীক্ষার ফাইল (`$test = true`)
         * — তখন `getMimeType()` সত্যিই finfo চালায়।
         */
        $path = tempnam(sys_get_temp_dir(), 'liar').'.png';
        file_put_contents($path, '<?php echo "hello"; ?>');

        try {
            $liar = new UploadedFile($path, 'front.png', 'image/png', null, true);

            // ⓘ ব্রাউজার যা দাবি করে সেটাই দ্বিতীয় আর্গুমেন্টে — আর
            //    সেটাই মিথ্যা। কোড ওটা না দেখে ভিতরটা পড়ে।
            $this->assertFalse(app(ProductImageService::class)->looksLikeAnImage($liar));
        } finally {
            @unlink($path);
        }
    }

    public function test_a_real_image_is_accepted(): void
    {
        // ⚠️ উপরেরটার জোড়া: শুধু "না" পাহারা দিলে একটা সবসময়-না ফাংশনও
        //    সবুজ থাকত, আর তখন কোনো ছবিই তোলা যেত না
        $this->assertTrue(app(ProductImageService::class)->looksLikeAnImage($this->image()));
    }

    /**
     * ছবি না পাঠালে পুরনোটা থেকে যায়।
     *
     * সম্পাদনার ফর্মে ছবির ঘর প্রতিবার ভরা থাকে না — মানুষ দাম বদলাতে
     * এসে ছবিতে হাত দেন না। খালি ঘর মানে "বদলাব না", **"সরিয়ে দাও" নয়**।
     */
    public function test_saving_without_a_picture_does_not_wipe_the_old_one(): void
    {
        $attachment = app(ProductImageService::class)
            ->replace($this->product, $this->image(), $this->owner->id);

        $this->put(route('inventory.product.update', $this->product), [
            'code' => $this->product->code,
            'name_en' => $this->product->name_en,
            'unit_id' => $this->product->unit_id,
            'purchase_price' => '10',
            'sale_price' => '12',
        ]);

        $this->assertSame($attachment->id, $this->product->fresh()->primary_image_id);
    }
}
