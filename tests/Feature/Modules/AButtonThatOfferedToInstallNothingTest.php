<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Http\Controllers\MasterListController;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * একটা বোতাম যা কিছুই বসাত না, আর একটা বার্তা যা মিথ্যা বলত।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * মাস্টার তালিকার পর্দা সতেরোটা তালিকা দেখায়। খালি হলেই উপরে একটা
 * বাক্স উঠত:
 *
 *   "তালিকাগুলো এখনো খালি। একক, কর, শর্ত ও কারণ কোড ছাড়া প্রথম বিলটাই
 *    লেখা যায় না। প্রমিত তালিকা বসিয়ে শুরু করুন।"  [প্রমিত তালিকা বসান]
 *
 * কিন্তু `installDefaults()` বসায় **ছয়টা** তালিকা। বাকি এগারোটায়
 * বোতামটা এসে কিছুই করত না।
 *
 * ২৫ আগস্ট ২০২৬-এ লাইভে Brands-এর পর্দায় ওই লেখাটা বসে ছিল — অথচ
 * এককে ছয়টা, করে চারটা, শর্তে চারটা সারি। **বার্তাটা সরাসরি মিথ্যা,
 * আর বোতামটা অকেজো।**
 *
 * ── আর নিয়মটা লেখাই ছিল ─────────────────────────────────────────────
 * কোডের ঠিক উপরের মন্তব্যে: *"সব তালিকা খালি হলে দেখানো হয় — একটাও
 * খালি না হলে নয়, নাহলে বোতামটা কিছুই করত না।"*
 *
 * কোডে লেখা ছিল `$records->isEmpty()` — **এই একটা** তালিকা খালি কি না।
 * মন্তব্যটা এমন একটা পাহারার কথা বলত যা কোথাও নেই। এই প্রকল্পে ঠিক এই
 * ভুলটাই বারবার ফেরে, তাই এবার মন্তব্যের বদলে একটা পরীক্ষা।
 *
 * ── কেন তালিকাটা হাতে মেলানো হয় না ──────────────────────────────────
 * `HAS_DEFAULTS`-এ ছয়টা নাম লেখা আছে, আর সেটাও একটা ঘোষণা — অর্থাৎ
 * সেটাও একদিন সরে যেতে পারে। তাই নিচের পরীক্ষা ঘোষণাটা পড়ে না;
 * সে **সত্যিই বোতামটা চেপে দেখে** কোন কোন তালিকা ভরে।
 */
class AButtonThatOfferedToInstallNothingTest extends TestCase
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
     * ঘোষণাটা সত্যিই যা ঘটে তার সাথে মেলে।
     *
     * প্রতিটা তালিকা খালি করে বোতামটা চাপা হয়, তারপর গোনা হয় কারা
     * ভরল। ওই তালিকাটাই `HAS_DEFAULTS`-এর সমান হতে হবে — কম বা বেশি
     * নয়।
     */
    public function test_what_the_button_installs_is_what_the_screen_promises(): void
    {
        $tables = $this->tablesByKind();

        // সব খালি করে শুরু — নাহলে "কে ভরল" প্রশ্নটাই করা যায় না
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ($tables as $table) {
            DB::table($table)->where('company_id', $this->company->id)->delete();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        app(MasterListService::class)->installDefaults();

        $filled = [];

        foreach ($tables as $kind => $table) {
            if (DB::table($table)->where('company_id', $this->company->id)->exists()) {
                $filled[] = $kind;
            }
        }

        sort($filled);

        $promised = MasterListService::HAS_DEFAULTS;
        sort($promised);

        $this->assertSame($promised, $filled, implode("\n", [
            'বোতামটা যা বসায় আর পর্দা যা প্রতিশ্রুতি দেয় — দুইটা আলাদা।',
            'বসেছে: '.implode(', ', $filled),
            'ঘোষণা: '.implode(', ', $promised),
            '',
            'MasterListService::HAS_DEFAULTS ঠিক করুন, নাহলে কোনো পর্দায়',
            'বোতামটা এসে কিছুই করবে না — আর সাথের লেখাটা মিথ্যা বলবে।',
        ]));
    }

    /**
     * যে তালিকার প্রমিত সারি নেই, সেখানে বোতামটাই ওঠে না।
     *
     * ── কেন পর্দা ধরে মাপা ───────────────────────────────────────────
     * উপরের পরীক্ষাটা সেবাটা মাপে। এটা মাপে **ব্যবহারকারী কী দেখেন** —
     * আর ভুলটা ছিল ঠিক ওখানে, সেবায় নয়।
     */
    public function test_an_empty_list_without_defaults_is_not_offered_the_button(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        DB::table('mdm_brands')->where('company_id', $this->company->id)->delete();

        $note = __('master_data::message.empty_lists_note');

        // ব্র্যান্ডে প্রমিত সারি নেই — খালি হলেও প্রস্তাবটা আসে না
        $this->actingAs($owner)
            ->get(route('master_data.brand.index'))
            ->assertOk()
            ->assertDontSee($note, false);

        // অথচ এককে আছে, তাই ওখানে খালি হলে আসে
        DB::table('mdm_units')->where('company_id', $this->company->id)->delete();

        $this->actingAs($owner)
            ->get(route('master_data.unit.index'))
            ->assertOk()
            ->assertSee($note, false);
    }

    /**
     * তালিকা => টেবিল, `KINDS` ঘোষণা থেকেই।
     *
     * @return array<string, string>
     */
    private function tablesByKind(): array
    {
        $reflection = new \ReflectionClass(MasterListController::class);

        /** @var array<string, array{model: class-string}> $kinds */
        $kinds = $reflection->getConstant('KINDS');

        $out = [];

        foreach ($kinds as $kind => $spec) {
            $model = $spec['model'];

            $out[$kind] = (new $model)->getTable();
        }

        return $out;
    }
}
