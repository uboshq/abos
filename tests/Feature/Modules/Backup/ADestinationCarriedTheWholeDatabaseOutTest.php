<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backup;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Backup\Http\Controllers\DestinationController;
use App\Modules\Backup\Models\BackupDestination;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * গন্তব্য দিয়ে পুরো ডাটাবেজ বাইরে যেত — ব্যাকআপ লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ ব্যাকআপে সব কোম্পানির পুরো ডাটাবেজ, তাই "নামান" কেবল সব কোম্পানির মালিকের ([[WholeDatabaseAccess]])। কিন্তু গন্তব্য
 * বসানো চাইত কেবল `backup.configure` চাবি — যে কেউ নিজের পেনড্রাইভ বা সার্ভারের কোনো ফোল্ডার বসিয়ে "এখনই ব্যাকআপ" চাপলে সব
 * কোম্পানির ডাম্প সেখানে কপি হত। ⓘ দাবি দরজা ধরে ডাকে, মধ্যস্থ চাবি-পাহারা বাদ দিয়ে — প্রশ্নটা চাবির নয়, পুরো ডাটাবেজের।
 */
final class ADestinationCarriedTheWholeDatabaseOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_owner_of_every_company_may_add_or_test_a_destination(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'accounts@abos.test')->firstOrFail());
        $before = BackupDestination::query()->count();

        $request = Request::create('/backup/destinations', 'POST', [
            'name' => 'My pen drive', 'driver' => 'local', 'kind' => 'offline', 'path' => sys_get_temp_dir(),
        ]);
        $request->setUserResolver(fn () => auth()->user());

        try {
            app(DestinationController::class)->store($request);
            $this->fail('সব কোম্পানির মালিক নন এমন কেউ গন্তব্য বসালেন — পুরো ডাটাবেজ সেখানে কপি হত।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('destination', $e->errors());
        }

        $this->assertSame($before, BackupDestination::query()->count(), 'থামা কাজেও গন্তব্যের সারি বসেছে।');
    }
}
