<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * সংরক্ষিত ফাইলের ঠিকানা মূল-আপেক্ষিক হতে হবে, পরম নয়।
 *
 * এই পরীক্ষাটা আছে একটা আসল ভুলের কারণে: public ডিস্কের url ছিল
 * APP_URL ধরে, তাই প্রোফাইল ছবি abos.test-এ দেখা যেত কিন্তু 127.0.0.1-এ
 * নয়। ABOS চলবে অফিসের LAN সার্ভারে, যেখানে একই অ্যাপে ঢোকা হয় নামে,
 * localhost-এ, আর ফোন থেকে সার্ভারের IP-তে — তিন ঠিকানার দুইটাতেই
 * প্রতিটা ছবি ভাঙা থাকত।
 *
 * ভুলটা চোখে পড়েছিল ব্রাউজারে naturalWidth শূন্য দেখে; HTML-এ src
 * ঠিকই ছিল, তাই "assertSee(src)" ধরনের পরীক্ষা এটা ধরত না।
 */
class StorageUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_disk_emits_root_relative_urls(): void
    {
        $url = Storage::disk('public')->url('avatars/x.jpg');

        $this->assertSame('/storage/avatars/x.jpg', $url);
        $this->assertStringStartsNotWith('http', $url, 'পরম URL হলে অন্য হোস্ট থেকে ছবিটা আসবে না।');
    }

    public function test_a_company_logo_url_is_relative_and_null_when_the_file_is_gone(): void
    {
        Storage::fake('public');

        $company = Company::create(['code' => 'LG', 'name_en' => 'Logo Co', 'logo_path' => 'logos/x.png']);

        // রেকর্ডে পথ আছে, ফাইল নেই
        $this->assertNull($company->logoUrl());

        Storage::disk('public')->put('logos/x.png', 'bytes');

        $this->assertSame('/storage/logos/x.png', $company->fresh()->logoUrl());
    }

    public function test_a_user_avatar_url_is_relative(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/1.jpg', 'bytes');

        $user = User::factory()->create(['avatar_path' => 'avatars/1.jpg']);

        $this->assertSame('/storage/avatars/1.jpg', $user->avatarUrl());
    }
}
