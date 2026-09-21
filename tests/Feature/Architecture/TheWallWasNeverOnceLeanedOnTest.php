<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Concerns\ScopedToUserWarehouse;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * দেয়ালটা তোলা ছিল, কিন্তু কেউ কোনোদিন তাতে হেলান দেয়নি।
 *
 * ── ⛔ লাইভে ধরা পড়ল, ২১ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * `/sales/lots/trace` দুই কোম্পানিতেই ৫০০ দিচ্ছিল:
 *
 *     Unknown column 'inv_batches.warehouse_id' in 'WHERE'
 *
 * ⓘ লটের সারণিতে গুদামের ঘরটা নেই, থাকার কথাও নয় — একটা লট একসাথে
 * কয়েকটা গুদামে পড়ে থাকতে পারে।
 *
 * ── ⚠️ আসল খবরটা ভুলটা নয়, তার আগের নীরবতা ──────────────────────────
 * এই ছাঁকনি দুইটা কেবল তখনই চলে **যখন কারও সীমা বসানো থাকে**।
 * ⛔ লাইভে কারও বসানো ছিল না, তাই পাতাটা মাসখানেক সবুজ দেখিয়েছে —
 * আর ভেঙেছে ঠিক প্রথম সীমা বসানোর দিনে। ⓘ অর্থাৎ নিরাপত্তার ঘরটা
 * কোনোদিন খাটেইনি, অথচ সবাই ধরে নিয়েছিল ওটা কাজ করে।
 *
 * ⚠️ একটা মডেল সারিয়ে দিলে বাকি **আঠারোটা** মডেলের একই ফাঁদ যেখানে
 * ছিল সেখানেই থাকে — ওদেরও কেউ পরখ করেনি। তাই এই পরীক্ষাটা একটা
 * মডেলের কথা বলে না, বলে **যন্ত্রটার** কথা।
 *
 * ── ⭐ কেন কলাম গুনে দেখা যথেষ্ট নয় ─────────────────────────────────
 * "ঘরটা আছে কি না" জিজ্ঞেস করলে `warehouseScopeColumn()`-এর ওভাররাইড,
 * সম্পর্কের মধ্য দিয়ে বসা ছাঁকনি, বা জয়েনের অস্পষ্ট নাম — কিছুই ধরা
 * পড়ত না। ⓘ তাই এখানে সীমা **সত্যিই বসানো হয়**, আর প্রতিটা কোয়েরি
 * **সত্যিই চালানো হয়** — ঠিক যেভাবে লাইভে চলেছিল। একটা সবুজ পাহারা
 * যা কখনো তাকায়নি, সেটা পাহারা নয়।
 */
final class TheWallWasNeverOnceLeanedOnTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    /**
     * ⭐ সীমা বসিয়ে প্রতিটা দেয়াল-ঘেরা মডেল সত্যিই কোয়েরি হয়।
     *
     * ⚠️ দাবিটা "ফল ঠিক এল কি না" নয় — "কোয়েরিটা আদৌ চলল কি না"।
     * ⓘ লাইভে যেটা ভেঙেছিল সেটা ফলের ভুল নয়, SQL-ই তৈরি হয়নি।
     */
    public function test_every_walled_model_still_answers_when_a_limit_is_set(): void
    {
        $models = $this->walledModels();

        /*
         * ⚠️ গোনাটা আঁটসাঁট, "দশের বেশি" নয়।
         *
         * ⓘ ২২ সেপ্টেম্বর ২০২৬-এ মাপা: ঠিক উনিশটা। ⛔ ঢিলা শর্তটা
         * উনিশ থেকে বারোয় নেমে গেলেও চুপ থাকত — অর্থাৎ সাতটা মডেলের
         * দেয়াল নীরবে খসে গেলেও পরীক্ষা সবুজই থাকত।
         *
         * ⭐ সংখ্যাটা **বাড়লে** কিছু ভাঙে না; কমলে ভাঙে, আর ভাঙাই উচিত —
         * একটা দেয়াল সরানো সচেতন সিদ্ধান্ত হওয়া চাই, দুর্ঘটনা নয়।
         */
        $this->assertGreaterThanOrEqual(
            19,
            count($models),
            'দেয়াল-ঘেরা মডেলের সংখ্যা কমে গেছে ('.count($models).'টা, আগে ছিল ১৯)। '
            .'কোনো মডেল থেকে ছাঁকনিটা সরানো হয়েছে, নাকি খোঁজার কলটাই ভেঙেছে?',
        );

        $this->actingAs($this->aUserWithLimits());

        $broken = [];

        foreach ($models as $model => $walls) {
            try {
                $model::query()->limit(1)->get();
            } catch (Throwable $e) {
                $broken[] = class_basename($model).' ('.implode(' + ', $walls).') — '
                    .$this->firstLine($e->getMessage());
            }
        }

        $this->assertSame([], $broken, implode(PHP_EOL, [
            'সীমা বসানো একজন ব্যবহারকারীর জন্য এই মডেলগুলোর কোয়েরিই তৈরি হয় না:',
            '',
            implode(PHP_EOL, $broken),
            '',
            'ⓘ সীমা না বসানো থাকলে ছাঁকনিটা চলেই না, তাই পাতাগুলো এতদিন',
            'সবুজ দেখিয়েছে — ভাঙবে ঠিক প্রথম সীমা বসানোর দিনে।',
            '',
            'ঘরটা সত্যিই ঐ সারণিতে না থাকলে মডেলে `warehouseScopeColumn()`',
            'বা `applyWarehouseScope()` ওভাররাইড করুন — যেমন Batch করেছে,',
            'যার গুদাম বলে তার চলাচলগুলো, নিজের কোনো ঘর নয়।',
        ]));
    }

    /**
     * ⛔ আর ছাঁকনিটা সত্যিই কামড়ায় — অন্তত একটা মডেলে, মেপে দেখা।
     *
     * ⚠️ উপরের দাবিটা একা থাকলে ছাঁকনি দুইটা **পুরোপুরি মুছে দিলেও**
     * সবুজ থাকত: কোনো কোয়েরিই তখন ভাঙে না। ⓘ অর্থাৎ ঐ দাবিটা বলে
     * "ভাঙেনি", এটা বলে "আটকায়"। দুইটা এক কথা নয়।
     */
    public function test_the_wall_actually_stops_something(): void
    {
        $mine = Warehouse::query()->withoutGlobalScopes()->firstOrFail();

        $theirs = Warehouse::query()->withoutGlobalScopes()
            ->whereKeyNot($mine->id)->first()
            ?? $this->aSecondWarehouse();

        $this->actingAs($this->aUserWithLimits($mine->id));

        $seen = Warehouse::query()->pluck('id')->all();

        $this->assertContains($mine->id, $seen, 'নিজের গুদামটাই দেখা যাচ্ছে না।');
        $this->assertNotContains($theirs->id, $seen, 'অন্যের গুদামটা দেয়াল পেরিয়ে এসেছে।');
    }

    /**
     * ⭐ আর দেয়ালটা সত্যিকারের কাগজও আটকায় — গুদামের তালিকা নয়।
     *
     * ── ⚠️ কেন উপরের দাবিটা যথেষ্ট নয় ───────────────────────────────
     * ওখানে ছাঁকনিটা মাপা হয় [[Warehouse]]-এ, আর গুদামের **নিজের**
     * তালিকায় ছাঁকনি বসে `id`-তে — উনিশটা মডেলের মধ্যে ওটাই সবচেয়ে
     * অ-প্রতিনিধিত্বমূলক, একমাত্র ব্যতিক্রম। ⛔ `warehouse_id` ঘর ধরে
     * আসল ছাঁকনিটা ওখানে কখনো চলেই না।
     *
     * ⓘ abos-8b-র আজকের অভিজ্ঞতা এক ধাপ এগিয়ে: *"কামড়ায়" দাবিটাও
     * অন্ধ হতে পারে যদি কামড়ানোর মতো ডেটাই না থাকে*। ওঁদের বেলায়
     * ডেমোতে গুদাম একটাই ছিল, তাই দাবিটা এড়িয়ে যেত — দ্বিতীয় গুদামটা
     * পরীক্ষা নিজে বানানোর পরেই ভাঙা দেয়ালটা ধরা পড়ে।
     *
     * ⚠️ তাই এখানে **সীমার বাইরের সারিটা নিজে হাতে বসানো হয়**, আর
     * তার পরেই দাবি করা হয় সে দেখা যায় না। নাহলে খালি সারণিতে
     * দাবিটা সবুজ হত কিছু না ছেঁকেই।
     */
    public function test_the_wall_stops_a_real_document_not_just_the_warehouse_list(): void
    {
        $mine = Warehouse::query()->withoutGlobalScopes()->firstOrFail();
        $theirs = $this->aSecondWarehouse();

        $here = $this->aMovementIn($mine);
        $there = $this->aMovementIn($theirs);

        $this->actingAs($this->aUserWithLimits($mine->id));

        $seen = StockMovement::query()->pluck('id')->all();

        $this->assertContains($here->id, $seen, 'নিজের গুদামের চলাচলটাই দেখা যাচ্ছে না।');
        $this->assertNotContains($there->id, $seen, 'অন্য গুদামের চলাচলটা দেয়াল পেরিয়ে এসেছে।');
    }

    // ── মাপার যন্ত্রপাতি ─────────────────────────────────────────────

    /**
     * যে মডেলগুলোয় শাখা বা গুদামের দেয়াল বসানো আছে।
     *
     * @return array<class-string<Model>, list<string>>
     */
    private function walledModels(): array
    {
        $found = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

            /*
             * ⚠️ `/Models/` ফোল্ডারটা শর্ত নয় — এটাই ছিল অন্ধ জায়গা।
             *
             * ⓘ abos-f9-র সূত্র, ২২ সেপ্টেম্বর ২০২৬: *পাহারা যখন লেখা
             * বা পথ ঘেঁটে মান খোঁজে, সে প্রায়ই পাশের ঘরটা পড়ে — গঠনটা
             * লোড করা গেলে ঘেঁটে বের করবেন না।*
             *
             * ⛔ আগে শর্তটা ছিল "পথে `/Models/` আছে"। আজ উনিশটাই ওখানে
             * বসে, কিন্তু কোনোদিন একটা দেয়াল-ঘেরা মডেল অন্য ফোল্ডারে
             * বসলে সে **নীরবে বাদ পড়ত** — আর নিচের গোনার পাহারাটাও
             * (>১০) একটা কমে যাওয়া টের পেত না।
             *
             * ⭐ তাই শর্তটা এখন গঠনের: ক্লাসটা সত্যিই [[Model]] কি না।
             */
            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }

            $class = 'App\\'.str_replace('/', '\\', substr($path, strpos($path, '/app/') + 5, -4));

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            // ⓘ বিমূর্ত ভিত্তি-ক্লাসের নিজের টেবিল নেই, `query()` ছুঁড়ত
            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $walls = [];

            foreach ([ScopedToUserBranch::class => 'শাখা', ScopedToUserWarehouse::class => 'গুদাম'] as $trait => $name) {
                if ($this->uses($class, $trait)) {
                    $walls[] = $name;
                }
            }

            if ($walls !== []) {
                $found[$class] = $walls;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * ⓘ ট্রেইটটা মূল ক্লাসে না থেকে তার বাবার গায়েও থাকতে পারে।
     *
     * @param  class-string  $class
     */
    private function uses(string $class, string $trait): bool
    {
        for ($c = $class; $c !== false; $c = get_parent_class($c)) {
            if (in_array($trait, class_uses($c) ?: [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * একজন ব্যবহারকারী, যাঁর শাখা ও গুদাম — দুইটারই সীমা বসানো।
     *
     * ⚠️ দুইটাই লাগে। ⓘ কেবল একটা বসালে যে মডেলগুলোয় অন্য দেয়ালটা
     * আছে তাদের ছাঁকনি চলত না, আর তারা নীরবে পরীক্ষার বাইরে থেকে
     * যেত — ঠিক লাইভে যা হয়েছিল।
     */
    private function aUserWithLimits(?int $warehouseId = null): User
    {
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $rows = [
            UserDataScope::BRANCH => Branch::query()->withoutGlobalScopes()
                ->where('company_id', $this->company->id)->value('id'),
            UserDataScope::WAREHOUSE => $warehouseId
                ?? Warehouse::query()->withoutGlobalScopes()->value('id'),
        ];

        foreach ($rows as $type => $id) {
            if ($id === null) {
                continue;
            }

            DB::table('user_data_scopes')->insert([
                'company_id' => $this->company->id,
                'user_id' => $user->id,
                'scope_type' => $type,
                'scope_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ⚠️ স্কোপের উত্তরটা অনুরোধ-জীবনকালের ক্যাশে বসে — না ভাঙলে
        // এইমাত্র বসানো সীমাটা এই পরীক্ষায় কখনো দেখা যেত না
        app()->forgetInstance(DataScope::class);

        return $user;
    }

    private function aSecondWarehouse(): Warehouse
    {
        $first = Warehouse::query()->withoutGlobalScopes()->firstOrFail();

        return Warehouse::query()->withoutGlobalScopes()->create([
            'company_id' => $first->company_id,
            'branch_id' => $first->branch_id,
            'code' => 'WH-WALL',
            'name_en' => 'Wall Test Warehouse',
            'name_bn' => 'দেয়াল পরীক্ষার গুদাম',
            'is_active' => true,
        ]);
    }

    /**
     * ঐ গুদামে একটা মজুদের চলাচল — দেয়ালের দুই পাশে একটা করে।
     *
     * ⚠️ `withoutGlobalScopes()` দিয়ে বসানো, কারণ সারিটা বসানোর সময়
     * এখনো কেউ লগইন করেননি — আর লগইনের পরে বসাতে গেলে সীমার বাইরের
     * সারিটা নিজেই ছাঁকনিতে আটকে যেত, অর্থাৎ বসতই না।
     */
    private function aMovementIn(Warehouse $warehouse): StockMovement
    {
        return StockMovement::query()->withoutGlobalScopes()->create([
            'company_id' => $warehouse->company_id,
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouse->id,
            'product_id' => Product::query()->withoutGlobalScopes()->value('id'),
            'trx_date' => now()->toDateString(),
            'floor_change' => '1.0000',
            'source_type' => 'wall_test',
            'source_id' => 0,
            'narration' => 'দেয়াল পরীক্ষা',
        ]);
    }

    private function firstLine(string $message): string
    {
        return trim(explode("\n", str_replace(['(Connection: mysql, SQL:'], [' — SQL:'], $message))[0]);
    }
}
