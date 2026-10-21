<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Console\Commands\MoveFacesToPrivate;
use App\Core\Services\AvatarService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ পুরনো মুখগুলো public ডিস্কেই রয়ে গিয়েছিল — `/storage/avatars/…`, লগইন ছাড়াই খোলা (fe-র হ্যাঁ, a6, ২০ অক্টোবর ২০২৬;
 * [[MoveFacesToPrivate]], [[AvatarService::moveToPrivate()]])।
 *
 * ⭐ দাবি: `--dry-run` কিছুই লেখে না আর ঠিক গোনে; আসল চালানো দুইটা মুখ সরায় — public থেকে উধাও, পাহারা-দেওয়া দরজায়
 * একই বাইট, সারিতে নতুন পথ; দ্বিতীয়বার কিছুই সরে না; ফাইল-হারানো জন জানানো হন আর তাঁর সারি অক্ষত; ফিরে-পড়া না মিললে
 * public ফাইল থাকে; খাতায় সারাংশ, কারও পথ ছাড়া।
 */
final class TheOldFacesStayedOnThePublicDiskTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;

    private User $accounts;

    private User $owner;

    private User $already;

    /** @var array<int, string> */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        DB::table('users')->update(['avatar_path' => null]);

        $this->sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->accounts = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        // ⓘ দুইজনের পুরনো public ছবি আছে
        foreach ([$this->sales, $this->accounts] as $user) {
            $path = 'avatars/'.$user->id.'-00112233aabbccdd.jpg';
            $this->original[$user->id] = $this->jpeg(64 + $user->id);
            Storage::disk('public')->put($path, $this->original[$user->id]);
            $user->forceFill(['avatar_path' => $path])->save();
        }

        // ⓘ মালিকের সারিতে পথ আছে, ফাইল নেই
        $this->owner->forceFill(['avatar_path' => 'avatars/'.$this->owner->id.'-ffffffffffffffff.jpg'])->save();

        // ⓘ একজন আগেই ব্যক্তিগত
        $this->already = User::factory()->create(['current_company_id' => $company->id]);
        $this->already->companies()->attach($company->id, ['is_active' => true]);
        Storage::disk('local')->put('faces/'.$this->already->id.'-0123456789abcdef.jpg', $this->jpeg(40));
        $this->already->forceFill(['avatar_path' => 'faces/'.$this->already->id.'-0123456789abcdef.jpg'])->save();
    }

    public function test_a_dry_run_counts_right_and_writes_nothing(): void
    {
        $before = DB::table('users')->orderBy('id')->pluck('avatar_path', 'id')->all();

        $this->artisan('abos:faces-private', ['--dry-run' => true])
            ->expectsOutputToContain('would move #'.$this->sales->id)
            ->expectsOutputToContain('missing    #'.$this->owner->id)
            ->expectsOutputToContain('would_move=2 already_private=1 skipped=0 missing=1 failed=0')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('users')->orderBy('id')->pluck('avatar_path', 'id')->all(), '⛔ শুকনো চালানোয় সারি বদলাল');
        $this->assertCount(2, Storage::disk('public')->allFiles('avatars'), '⛔ শুকনো চালানোয় public ফাইল সরল');
        $this->assertCount(1, Storage::disk('local')->allFiles('faces'), '⛔ শুকনো চালানোয় ব্যক্তিগত ডিস্কে লেখা হলো');
        $this->assertSame(0, $this->summaries()->count(), '⛔ শুকনো চালানোয় খাতায় লেখা হলো');
    }

    public function test_a_real_run_moves_two_faces_and_a_second_run_moves_none(): void
    {
        $this->artisan('abos:faces-private')
            ->expectsOutputToContain('moved=2 already_private=1 skipped=0 missing=1 failed=0')
            ->assertSuccessful();

        foreach ([$this->sales, $this->accounts] as $user) {
            $path = (string) $user->fresh()->avatar_path;

            $this->assertStringStartsWith('faces/'.$user->id.'-', $path, '⛔ সারিতে নতুন পথ বসেনি');
            $this->assertSame(hash('sha256', $this->original[$user->id]), hash('sha256', Storage::disk('local')->get($path)));

            // ⭐ পাহারা-দেওয়া দরজায় একই মুখ — সহকর্মী মালিকের চোখে
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
            $seen = $this->get('/api/v1/users/'.$user->public_id.'/photo')->assertOk();
            $this->assertSame(hash('sha256', $this->original[$user->id]), hash('sha256', (string) $seen->getContent()));
        }

        $this->assertSame([], Storage::disk('public')->allFiles('avatars'), '⛔ public কপি রয়ে গেল — লগইন ছাড়াই খোলা');

        // ⛔ ফাইল-হারানো জনের সারি অক্ষত
        $this->assertSame('avatars/'.$this->owner->id.'-ffffffffffffffff.jpg', $this->owner->fresh()->avatar_path);
        // ⓘ আগেই-ব্যক্তিগত জন ছোঁয়া হয়নি
        $this->assertSame('faces/'.$this->already->id.'-0123456789abcdef.jpg', $this->already->fresh()->avatar_path);

        // ⭐ খাতায় সারাংশ — গোনা আছে, কারও পথ নেই
        $rows = $this->summaries()->get();
        $this->assertGreaterThanOrEqual(1, $rows->count(), '⛔ সারাংশ খাতায় বসেনি');
        $this->assertSame(Company::query()->count(), $rows->count(), 'কোম্পানি প্রতি একটা সারি');
        foreach ($rows as $row) {
            $this->assertStringContainsString('moved=2', (string) $row->reason);
            $this->assertStringContainsString('missing=1', (string) $row->reason);
            $this->assertStringNotContainsString('avatars/', (string) $row->reason);
            $this->assertStringNotContainsString('faces/', (string) $row->reason);
        }

        // ⭐ দ্বিতীয়বার — কিছুই সরে না
        $after = DB::table('users')->orderBy('id')->pluck('avatar_path', 'id')->all();
        $this->artisan('abos:faces-private')
            ->expectsOutputToContain('moved=0 already_private=3 skipped=0 missing=1 failed=0')
            ->assertSuccessful();
        $this->assertSame($after, DB::table('users')->orderBy('id')->pluck('avatar_path', 'id')->all());
        $this->assertCount(3, Storage::disk('local')->allFiles('faces'), '⛔ দ্বিতীয় চালানো নতুন কপি বানাল');
    }

    public function test_a_copy_that_does_not_verify_keeps_the_public_file_and_the_row(): void
    {
        // ⛔ ব্যক্তিগত ডিস্ক অর্ধেক লেখে — যেন ডিস্ক ভরা বা লেখা মাঝপথে কাটা
        $real = Storage::disk('local');
        Storage::set('local', new class($real->getDriver(), $real->getAdapter(), $real->getConfig()) extends FilesystemAdapter
        {
            public function put($path, $contents, $options = [])
            {
                return parent::put($path, is_string($contents) ? substr($contents, 0, 40) : $contents, $options);
            }
        });

        $before = DB::table('users')->orderBy('id')->pluck('avatar_path', 'id')->all();

        $this->artisan('abos:faces-private')
            ->expectsOutputToContain('failed     #'.$this->sales->id)
            ->expectsOutputToContain('moved=0 already_private=1 skipped=0 missing=1 failed=2')
            ->assertFailed();

        $this->assertSame($before, DB::table('users')->orderBy('id')->pluck('avatar_path', 'id')->all(), '⛔ যাচাই না মেলা কপিতে সারি বদলাল');
        $this->assertCount(2, Storage::disk('public')->allFiles('avatars'), '⛔ যাচাই না মেলায় public ফাইল মুছে গেল — মুখ হারাল');
        $this->assertCount(1, Storage::disk('local')->allFiles('faces'), '⛔ অর্ধেক লেখা কপি পড়ে রইল');
    }

    public function test_the_service_alone_leaves_an_already_private_face_alone(): void
    {
        // ⛔ সেবার নিজের পাহারা — কমান্ড আগে গুনে বাদ দেয়, কিন্তু অন্য কোনো ডাকিয়ে তা না-ও করতে পারে।
        // ⓘ ফাঁদ: public ডিস্কে একই নামের একটা ফাইল — পাহারা না থাকলে সেবা সেটাকেই "পুরনো ছবি" ভেবে সরাত
        $path = (string) $this->already->fresh()->avatar_path;
        Storage::disk('public')->put($path, $this->jpeg(30));

        $this->assertSame(AvatarService::MOVE_SKIPPED, app(AvatarService::class)->moveToPrivate($this->already->fresh()));
        $this->assertSame($path, $this->already->fresh()->avatar_path, '⛔ আগেই-ব্যক্তিগত মুখের সারি বদলাল');
        $this->assertCount(1, Storage::disk('local')->allFiles('faces'), '⛔ আগেই-ব্যক্তিগত মুখের কপি বানাল');
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_photo_changed_while_the_copy_was_written_wins(): void
    {
        $old = (string) $this->sales->fresh()->avatar_path;
        $theirs = 'faces/'.$this->sales->id.'-9999999999999999.jpg';
        $userId = (int) $this->sales->id;
        $bytes = $this->jpeg(50);

        // ⛔ লেখার ঠিক আগে মানুষটা নিজেই নতুন ছবি দিলেন (অন্য অনুরোধে) — সারি এখন তাঁর নতুন পথে
        $real = Storage::disk('local');
        $racing = new class($real->getDriver(), $real->getAdapter(), $real->getConfig()) extends FilesystemAdapter
        {
            public ?\Closure $before = null;

            public function put($path, $contents, $options = [])
            {
                if ($this->before !== null) {
                    $race = $this->before;
                    $this->before = null;
                    $race();
                }

                return parent::put($path, $contents, $options);
            }
        };
        $racing->before = function () use ($racing, $theirs, $userId, $bytes): void {
            $racing->put($theirs, $bytes);
            DB::table('users')->where('id', $userId)->update(['avatar_path' => $theirs]);
        };
        Storage::set('local', $racing);

        $this->assertSame(AvatarService::MOVE_SKIPPED, app(AvatarService::class)->moveToPrivate($this->sales->fresh()));

        // ⭐ মানুষটার সিদ্ধান্তই থাকে; আমাদের কপি যায়
        $this->assertSame($theirs, $this->sales->fresh()->avatar_path, '⛔ সরানোর কাজ মানুষটার নতুন ছবি মুছে পুরনোটা বসাল');
        $this->assertEqualsCanonicalizing([$theirs, 'faces/'.$this->already->id.'-0123456789abcdef.jpg'], Storage::disk('local')->allFiles('faces'),
            '⛔ ফেলে-দেওয়া কপি পড়ে রইল');
        // ⓘ লেখা অনুযায়ী: public ফাইলটা এখানে থাকে — বাস্তবে মানুষটার নিজের আপলোড ([[AvatarService::store()]]) ওটা মোছে
        Storage::disk('public')->assertExists($old);
    }

    private function summaries()
    {
        return AuditTrail::query()->withoutGlobalScopes()->where('action', MoveFacesToPrivate::ACTION);
    }

    private function jpeg(int $edge): string
    {
        $gd = imagecreatetruecolor($edge, $edge);
        imagefilledrectangle($gd, 0, 0, $edge, $edge, imagecolorallocate($gd, 30, 120, 60));

        ob_start();
        imagejpeg($gd, null, 90);

        return (string) ob_get_clean();
    }
}
