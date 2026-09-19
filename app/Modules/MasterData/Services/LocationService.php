<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Services;

use App\Core\Engines\Coding\CodeSuggester;
use App\Core\Support\CompanyContext;
use App\Modules\MasterData\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * এলাকার গাছ তৈরি ও রক্ষণাবেক্ষণ।
 *
 * মইয়ের নিয়মগুলো এখানে, কারণ এলাকা তৈরি হবে স্ক্রিন থেকে, প্রমিত
 * তালিকা বসানোর সময়, আর পরে ইমপোর্ট থেকেও।
 */
final class LocationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Location
    {
        return DB::transaction(function () use ($data) {
            $level = $this->assertLevel($data['level'] ?? null);
            $parent = $this->resolveParent($data['parent_id'] ?? null, $level);

            $code = trim((string) ($data['code'] ?? ''));

            /*
             * কোড না লিখলে নামের সংক্ষেপ — মালিকের নিয়ম, ২ সেপ্টেম্বর ২০২৬।
             *
             * এলাকার কোড মানুষ পড়ে ও মুখে বলে ("নেত্রকোনা রুট → NET"),
             * তাই এখানেও সিরিজ নয়, নাম। ধাপের নামটা উপসর্গ হিসেবে যায়
             * (`territory` → `TER`), যাতে ইংরেজি নাম খালি থাকলেও ঘরটা
             * খালি না থাকে — [[LocationLadder]]-এর সাতটা ধাপেই।
             */
            if ($code === '') {
                $code = app(CodeSuggester::class)->fromName(
                    Location::class,
                    $data['name_en'] ?? null,
                    [],
                    substr($level, 0, 3),
                );
            }

            $this->assertCodeIsFree($code);

            return Location::create([
                ...$data,
                'code' => $code,
                'level' => $level,
                'parent_id' => $parent?->id,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Location $location, array $data): Location
    {
        return DB::transaction(function () use ($location, $data) {
            // ফাঁকা মানে "বদলাবেন না" — উপরে দেখুন, একই কারণ
            if (trim((string) ($data['code'] ?? '')) !== ''
                && trim((string) $data['code']) !== $location->code) {
                $this->assertCodeIsFree(trim((string) $data['code']), $location->id);
            }

            /*
             * স্তর বদলানো যায় না।
             *
             * একটা রিজিয়নকে রুট বানালে তার নিচের এরিয়াগুলো এমন এক
             * বাবার নিচে পড়ত যে নিজেই সবচেয়ে নিচের স্তর — গাছটা তখন
             * আর মই থাকত না। বদলাতে হলে নতুন রেকর্ড, আর পুরনোটা
             * নিষ্ক্রিয়।
             */
            if (isset($data['level']) && $data['level'] !== $location->level) {
                throw ValidationException::withMessages([
                    'level' => __('master_data::validation.level_cannot_change'),
                ]);
            }

            unset($data['level']);

            $parent = $this->resolveParent(
                $data['parent_id'] ?? $location->parent_id,
                $location->level,
                $location,
            );

            /*
             * ফাঁকা কোড `$data`-তেই থেকে যেত আর নিচের spread ওটা বসিয়ে
             * **কোডটা মুছে দিত** — নীরবে। উপরের শর্তটা কেবল "খালি নয়
             * এমন কোড অন্য কারো কি না" দেখে; মুছে ফেলাটা আটকায় না।
             */
            if (trim((string) ($data['code'] ?? '')) === '') {
                unset($data['code']);
            }

            $location->update([...$data, 'parent_id' => $parent?->id]);

            return $location->fresh();
        });
    }

    /**
     * নিষ্ক্রিয় করা — মোছা নয় (নিয়ম ৫)।
     *
     * নিচের সবগুলোও: একটা সক্রিয় রুট নিষ্ক্রিয় এরিয়ার নিচে ঝুললে
     * ড্রপডাউনে দেখা যেত কিন্তু গাছে খুঁজে পাওয়া যেত না।
     */
    public function deactivate(Location $location): Location
    {
        return DB::transaction(function () use ($location) {
            foreach ($location->selfAndDescendants() as $node) {
                $node->refresh()->forceFill(['is_active' => false])->save();
            }

            return $location->fresh();
        });
    }

    public function activate(Location $location): Location
    {
        return DB::transaction(function () use ($location) {
            foreach ($location->ancestors()->push($location) as $node) {
                $node->refresh()->forceFill(['is_active' => true])->save();
            }

            return $location->fresh();
        });
    }

    /**
     * সত্যিই মুছে ফেলা — ১৯ সেপ্টেম্বর ২০২৬, মালিকের নির্দেশ:
     * প্রতিটা স্তরের ট্যাবে "মুছুন"।
     *
     * ── ⛔ দুইটা জিনিস থাকলে মোছা নয়, আর কারণটা বলা ─────────────────
     * ① **নিচে সন্তান আছে** — একটা রিজিয়ন মুছলে তার এরিয়াগুলো বাবাহীন
     *    ঝুলত, গাছে আর খুঁজে পাওয়া যেত না। ⚠️ তাই আগে নিচেরগুলো মুছতে বা
     *    সরাতে বলা হয়, সংখ্যা আর স্তরের নাম সহ ("নিচে ৩টা পয়েন্ট আছে")।
     * ② **কোথাও ব্যবহার হয়েছে** — গ্রাহকের পয়েন্ট, গাড়ির চালানের রুট।
     *    ⛔ মুছলে পুরনো কাগজ এমন কিছুর দিকে দেখাত যা আর নেই। ⓘ ওখানে
     *    উত্তর নিষ্ক্রিয় করা (নিয়ম ৫) — বার্তাটাও তাই বলে।
     *
     * ── ⓘ কোন টেবিল দেখে, সেটা হাতে লেখা নয় ──────────────────────
     * [[MasterListService::referencesTo()]]-এর মতোই ডাটাবেজকে জিজ্ঞেস
     * করা হয় কে কে এই টেবিলের দিকে দেখায়। ⚠️ হাতে লিখলে একদিন নতুন
     * একটা টেবিল যোগ হত আর পাহারাটা থাকা অবস্থাতেই ফাঁক গলে যেত।
     *
     * ⓘ `forceDelete`, `delete` নয়: সাধারণ delete কেবল `deleted_at`
     * বসায়, আর কোডটা [[assertCodeIsFree()]]-এ চিরকাল আটকে থাকত — ভুল
     * করে বানানো এরিয়া মুছে ঠিকটা একই কোডে আর বানানো যেত না।
     */
    public function purge(Location $location): void
    {
        $children = Location::query()->withTrashed()
            ->where('parent_id', $location->id)
            ->orderBy('id')
            ->get(['id', 'level']);

        if ($children->isNotEmpty()) {
            throw ValidationException::withMessages([
                'location' => __('master_data::validation.location_has_children', [
                    'name' => $location->name(),
                    'count' => $this->digits($children->count()),
                    'level' => __('master_data::level.'.$children->first()->level),
                ]),
            ]);
        }

        $links = DB::select(
            'SELECT TABLE_NAME AS child, COLUMN_NAME AS child_column
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_NAME = ?
                AND TABLE_NAME <> ?',
            [$location->getTable(), $location->getTable()],
        );

        foreach ($links as $link) {
            $count = DB::table($link->child)->where($link->child_column, $location->id)->count();

            if ($count > 0) {
                $where = __('master_data::validation.location_used_by.'.$link->child);

                throw ValidationException::withMessages([
                    'location' => __('master_data::validation.location_in_use', [
                        'name' => $location->name(),
                        'count' => $this->digits($count),
                        // অচেনা টেবিল হলে কাঁচা নামটাই — চুপ থাকার চেয়ে ভালো
                        'where' => str_contains($where, 'location_used_by') ? $link->child : $where,
                    ]),
                ]);
            }
        }

        $location->forceDelete();
    }

    /**
     * সংখ্যাটা পাঠকের অঙ্কে — বাংলায় "৩টা", "3টা" নয়।
     *
     * ⓘ `Number::format()` intl চায়, আর লাইভ সার্ভারে সেটা আছে কি না
     * নিশ্চিত নয়; ⚠️ না থাকলে ঠিক মোছার মুহূর্তে পাতা ৫০০ দিত। তাই সোজা
     * অঙ্ক-বদল।
     */
    private function digits(int $n): string
    {
        return app()->getLocale() === 'bn'
            ? strtr((string) $n, ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'])
            : (string) $n;
    }

    /**
     * বাবা ঠিক আছে কি না।
     *
     * তিনটা নিয়ম: বাবা থাকতে হবে (দেশ ছাড়া), বাবার স্তর ঠিক উপরের
     * চালু স্তর হতে হবে, আর নিজের নিচে নিজেকে বসানো যাবে না।
     */
    private function resolveParent(mixed $parentId, string $level, ?Location $moving = null): ?Location
    {
        $expected = Location::parentLevelOf($level);

        if ($expected === null) {
            /*
             * সবচেয়ে উপরের স্তর — দেশের কোনো বাবা নেই।
             *
             * তবু কেউ একটা বাবা পাঠালে সেটা নীরবে ফেলে দেওয়া হয় না।
             * চুপচাপ উপেক্ষা করলে ব্যবহারকারী ভাবত কাজটা হয়েছে, আর
             * পরে গাছে খুঁজে না পেয়ে বুঝত না কেন। না বলাটা বেশি সৎ।
             */
            if (filled($parentId)) {
                throw ValidationException::withMessages([
                    'parent_id' => __('master_data::validation.top_level_has_no_parent', [
                        'level' => __('master_data::level.'.$level),
                    ]),
                ]);
            }

            return null;
        }

        if (blank($parentId)) {
            throw ValidationException::withMessages([
                'parent_id' => __('master_data::validation.parent_required', [
                    'level' => __('master_data::level.'.$expected),
                ]),
            ]);
        }

        $parent = Location::query()->find($parentId);

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => __('master_data::validation.parent_not_found'),
            ]);
        }

        if ($parent->level !== $expected) {
            throw ValidationException::withMessages([
                'parent_id' => __('master_data::validation.wrong_parent_level', [
                    'expected' => __('master_data::level.'.$expected),
                    'given' => __('master_data::level.'.$parent->level),
                ]),
            ]);
        }

        if ($moving !== null) {
            $descendants = $moving->selfAndDescendants()->pluck('id')->all();

            if (in_array($parent->id, $descendants, true)) {
                throw ValidationException::withMessages([
                    'parent_id' => __('master_data::validation.parent_cannot_be_own_descendant'),
                ]);
            }
        }

        return $parent;
    }

    private function assertLevel(mixed $level): string
    {
        if (! in_array($level, Location::LADDER, true)) {
            throw ValidationException::withMessages([
                'level' => __('master_data::validation.unknown_level'),
            ]);
        }

        /*
         * বন্ধ স্তরে কিছু বসানো যায় না।
         *
         * সেটিংসে এরিয়া বন্ধ থাকলে এরিয়া তৈরি করতে দিলে সেটা গাছে
         * থাকত অথচ ড্রপডাউনে আসত না — আর তার নিচের সব হারিয়ে যেত।
         */
        if (! in_array($level, Location::activeLadder(), true)) {
            throw ValidationException::withMessages([
                'level' => __('master_data::validation.level_disabled', [
                    'level' => __('master_data::level.'.$level),
                ]),
            ]);
        }

        return $level;
    }

    private function assertCodeIsFree(string $code, ?int $exceptId = null): void
    {
        $taken = Location::query()
            ->where('code', $code)
            ->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))
            ->withTrashed()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'code' => __('master_data::validation.code_taken', ['code' => $code]),
            ]);
        }
    }

    /**
     * বাংলাদেশের আটটা বিভাগ — শুরু করার জন্য।
     *
     * প্রতিটা প্রতিষ্ঠানকে দেশ ও বিভাগ হাতে লিখতে বলার কোনো মানে নেই:
     * ওগুলো সবার জন্য এক, আর ভুল বানানে ঢুকলে পরে রিপোর্টে দুইটা
     * "ময়মনসিংহ" দেখা যেত।
     *
     * এরিয়া থেকে নিচে প্রতিষ্ঠান নিজে বানাবে — ওগুলো ব্যবসার নিজের ছক।
     *
     * @return int কতগুলো তৈরি হল
     */
    public function installBangladesh(): int
    {
        if (Location::query()->exists()) {
            return 0;
        }

        return DB::transaction(function () {
            $country = $this->create([
                'code' => 'BD',
                'name_en' => 'Bangladesh',
                'name_bn' => 'বাংলাদেশ',
                'level' => Location::COUNTRY,
            ]);

            $divisions = [
                ['DHA', 'Dhaka', 'ঢাকা'],
                ['CTG', 'Chattogram', 'চট্টগ্রাম'],
                ['KHU', 'Khulna', 'খুলনা'],
                ['RAJ', 'Rajshahi', 'রাজশাহী'],
                ['BAR', 'Barishal', 'বরিশাল'],
                ['SYL', 'Sylhet', 'সিলেট'],
                ['RNG', 'Rangpur', 'রংপুর'],
                ['MYM', 'Mymensingh', 'ময়মনসিংহ'],
            ];

            foreach ($divisions as [$code, $en, $bn]) {
                $this->create([
                    'code' => $code,
                    'name_en' => $en,
                    'name_bn' => $bn,
                    'level' => Location::DIVISION,
                    'parent_id' => $country->id,
                ]);
            }

            return count($divisions) + 1;
        });
    }

    public function assertCompanyContext(): void
    {
        if (CompanyContext::id() === null) {
            throw new \RuntimeException('No company in context. Locations are per company.');
        }
    }
}
