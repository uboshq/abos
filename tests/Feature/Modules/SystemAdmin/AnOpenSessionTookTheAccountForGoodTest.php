<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * খোলা একটা সেশন চলতি পাসওয়ার্ড ছাড়াই নিজের পাসওয়ার্ড বদলে অ্যাকাউন্ট দখল করত — সিস্টেম-অ্যাডমিন লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ ব্যবহারকারী-পর্দায় নিজের ফর্মে নতুন পাসওয়ার্ড বসত চলতিটা না চেয়েই; এই সেশন থেকে যেত, বাকি সব কেটে যেত। প্রোফাইলের পাতা
 * আর মালিকানা হস্তান্তর ঠিক এই কারণেই চলতি পাসওয়ার্ড চায়। ⭐ এখন এই দরজাও চায়।
 */
final class AnOpenSessionTookTheAccountForGoodTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_your_own_password_here_needs_the_current_one(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $hash = (string) $owner->password;

        $this->actingAs($owner)->put(route('system_admin.user.update', $owner), [
            'name' => $owner->name, 'email' => $owner->email, 'locale' => 'bn', 'is_active' => '1',
            'roles' => [PermissionSyncer::SUPER_ADMIN_ROLE], 'companies' => [$company->id],
            'password' => 'Taken-over-2026x', 'password_confirmation' => 'Taken-over-2026x',
        ])->assertSessionHasErrors('current_password');

        $this->assertSame($hash, (string) $owner->fresh()->password, 'চলতি পাসওয়ার্ড ছাড়াই নিজের পাসওয়ার্ড বদলে গেল।');
        $this->assertFalse(Hash::check('Taken-over-2026x', (string) $owner->fresh()->password));
    }
}
