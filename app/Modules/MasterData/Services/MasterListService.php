<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Services;

use App\Core\Contracts\ProvisionsCompany;
use App\Core\Engines\Coding\CodeSuggester;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Designation;
use App\Modules\MasterData\Models\EmploymentType;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Models\VehicleType;
use App\Modules\MasterData\Support\CodeConventions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ছয়টা সরল মাস্টার তালিকা — একই নিয়মে।
 *
 * একক, কর, শর্ত, দর তালিকা, পক্ষের ধরন ও কারণ কোড — ছয়টাতেই একই
 * তিনটা নিয়ম: কোড অনন্য, ডিফল্ট একটাই, আর ব্যবহৃত রেকর্ড মোছা যায় না।
 * ছয়বার লিখলে একদিন একটায় ফাঁক থাকত।
 *
 * যা এখানে নেই: প্রতিটার নিজস্ব যাচাই (এককের চক্র, করের হারের সীমা)।
 * ওগুলো নিচে আলাদা পদ্ধতিতে, কারণ ওগুলো সত্যিই আলাদা।
 */
final class MasterListService implements ProvisionsCompany
{
    /**
     * নতুন কোম্পানি হলে ডিফল্ট তালিকাগুলো বসে।
     *
     * seed() প্রতিটা তালিকায় আগে দেখে নেয় কিছু আছে কি না, তাই বারবার
     * ডাকলেও দুইবার বসে না।
     */
    public function provisionCompany(): void
    {
        $this->installDefaults();
    }

    public function __construct(
        private readonly CodeSuggester $codes,
    ) {}

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $data
     * @param  string  $kind  URL-এ যে নামে তালিকাটা চেনা যায় — `units`, `currencies`
     */
    public function create(string $model, array $data, string $kind = ''): Model
    {
        return DB::transaction(function () use ($model, $data, $kind) {
            $code = trim((string) ($data['code'] ?? ''));

            /*
             * কোড না লিখলে নিজে থেকে বসে — মালিকের নিয়ম, ২ সেপ্টেম্বর ২০২৬।
             *
             * ── কেন সিরিজ নয় ─────────────────────────────────────
             * এখানে `UNIT-0001` বসানো নিষেধ, আর কারণটা কাগজে: এককের
             * কোড চালানে ছাপা হয়। নামটাই উৎস — `Kilogram` → `KG`,
             * `Value Added Tax` → `VAT`। যেগুলোর প্রচলিত রূপ নিয়মে আসে
             * না ([[CodeConventions]]) সেগুলো অভিধান থেকে।
             *
             * ── ইংরেজি নামটাই কেন ───────────────────────────────────
             * কোড ASCII, আর বাংলা নাম থেকে ASCII সংক্ষেপ বের হয় না।
             * ইংরেজি নামও খালি থাকলে তালিকার নিজের নাম উপসর্গ হয়
             * (`units` → `UNI`), যাতে ঘরটা কখনো খালি না থাকে।
             */
            if ($code === '') {
                $code = $this->codes->fromName(
                    $model,
                    $data['name_en'] ?? null,
                    CodeConventions::forKind($kind),
                    $kind === '' ? null : substr($kind, 0, 3),
                );
            }

            $this->assertCodeIsFree($model, $code);

            /*
             * যে তালিকায় "ডিফল্ট" বলে কিছু নেই, সেখানে ঘরটা ফেলে দেওয়া।
             *
             * ফর্মটা সব তালিকার জন্য একটাই, তাই গাড়ির পর্দা থেকেও
             * is_default আসে — অথচ mdm_vehicles-এ ওই ঘরই নেই। নিচের
             * spread ওটা বয়ে নিয়ে যেত, আর Eloquent চুপ করে ফেলে দিত।
             * ফেলে দেওয়াই ঠিক, কিন্তু চুপ করে নয়: strict mode চালু
             * হওয়ার পর ওটাই পুরো সংরক্ষণ ভেঙে দিত।
             */
            if (! $model::supportsDefault()) {
                unset($data['is_default']);
            }

            $record = $model::create([
                ...$data,
                'code' => $code,
                'is_active' => $data['is_active'] ?? true,
                /*
                 * ডিফল্ট আলাদা করে বসানো হয়, কারণ একটাই থাকতে পারে —
                 * কিন্তু শুধু সেই তালিকাগুলোয় যাদের ঘরটা আছে। একক ও
                 * কারণ-কোডে নেই: ওখানে "ডিফল্ট একক" বলে কিছু নেই।
                 *
                 * শর্তটা ছাড়া কোয়েরিতে অস্তিত্বহীন কলাম যেত। সাধারণ
                 * অবস্থায় mass assignment ওটা ফেলে দিত, কিন্তু সিডারে
                 * গার্ড বন্ধ থাকে — আর তখন প্রতিটা ইনসার্ট ভাঙত।
                 */
                ...($model::supportsDefault() ? ['is_default' => false] : []),
                'created_by' => auth()->id(),
            ]);

            $this->afterSave($record, $data);

            return $record->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            /*
             * সম্পাদনায় ফাঁকা মানে "বদলাবেন না", "মুছে দিন" নয়।
             *
             * ── কেন এই লাইনটা লাগল (২ সেপ্টেম্বর ২০২৬) ────────────
             * ঘরটা থেকে `required` তুলে নেওয়ার আগে ফাঁকা কোড ফর্ম
             * থেকেই আসতে পারত না। এখন পারে — আর তখন `trim('')` গিয়ে
             * **কোডটা মুছে দিত**, নীরবে। তৈরির সময় ফাঁকা মানে "তুমি
             * বসাও", সম্পাদনার সময় ফাঁকা মানে "হাত দিও না"।
             */
            $code = trim((string) ($data['code'] ?? '')) !== ''
                ? trim((string) $data['code'])
                : $record->code;

            if ($code !== $record->code) {
                $this->assertCodeIsFree($record::class, $code, $record->getKey());
            }

            // ডিফল্ট এখানে বসে না — makeDefault() দিয়ে, নাহলে দুইটা
            // ডিফল্ট থাকার একটা মুহূর্ত তৈরি হত
            $wantsDefault = (bool) ($data['is_default'] ?? false);
            unset($data['is_default']);

            $record->update([...$data, 'code' => $code]);

            $this->afterSave($record, $data);

            if ($wantsDefault) {
                $record->makeDefault();
            }

            return $record->fresh();
        });
    }

    /**
     * নিষ্ক্রিয় করা — মোছা নয় (নিয়ম ৫)।
     *
     * ডিফল্ট রেকর্ড নিষ্ক্রিয় করা যায় না: ডিফল্ট না থাকলে নতুন
     * লেনদেনে কোনটা বসবে তা নির্ধারিত থাকত না, আর তখন ব্যবহারকারী
     * প্রতিবার হাতে বাছতে বাধ্য হত — অথচ সে জানত না কেন।
     */
    public function deactivate(Model $record): Model
    {
        if (($record->is_default ?? false) === true) {
            throw ValidationException::withMessages([
                'is_active' => __('master_data::validation.default_cannot_deactivate'),
            ]);
        }

        $record->refresh()->forceFill(['is_active' => false])->save();

        return $record->fresh();
    }

    /**
     * ফেরার পথ — নিষ্ক্রিয় করা একমুখী দরজা হতে পারে না।
     *
     * ── কেন এটা আলাদা করে লিখতে হল ─────────────────────────────────
     * এতদিন কেবল deactivate ছিল। ভুল করে একটা একক বা কারণ কোড বন্ধ
     * করে ফেললে ফেরানোর কোনো উপায় ছিল না — তালিকায় সারিটা ধূসর হয়ে
     * পড়ে থাকত, আর নতুন করে বানাতে গেলে একই কোড দুইবার বসাতে গিয়ে
     * আটকাত। গুদামে ঠিক এই ফাঁদটাই আগে ধরা পড়েছিল, আর সেখানে ফেরার
     * পথ বানানো হয়েছিল; এই তালিকাগুলোয় রয়ে গিয়েছিল।
     *
     * মুছে ফেলা হয় না, নিষ্ক্রিয় করা হয় — কারণ ব্যবহার হয়ে যাওয়া একটা
     * একক সত্যিই মুছে দিলে পুরনো প্রতিটা পণ্য, চালান আর বিলের সারি
     * এমন কিছুর দিকে দেখাত যা আর নেই।
     */
    public function activate(Model $record): Model
    {
        $record->refresh()->forceFill(['is_active' => true])->save();

        return $record->fresh();
    }

    /**
     * মুছে ফেলা — কেবল মালিকের চাবিতে।
     *
     * ── নিষ্ক্রিয় করা আর মোছার তফাত ─────────────────────────────────
     * নিষ্ক্রিয় = তালিকায় থাকে, ধূসর হয়ে; নতুন কাগজে আসে না।
     * মোছা      = তালিকা থেকেও চলে যায়।
     *
     * প্রথমটা রোজকার কাজ (সরবরাহ বন্ধ, ঋতু শেষ)। দ্বিতীয়টা ভুল সংশোধন
     * — ভুল করে বানানো একটা সারি, যেটা কারো চোখে পড়ারই দরকার নেই।
     * তাই দ্বিতীয়টার চাবি আলাদা, আর সেটা মালিকের (মালিকের সিদ্ধান্ত,
     * ২০২৬-০৮-০৯)।
     *
     * ── একটাই বোতাম, দুইটা আচরণ ────────────────────────────────────
     * মালিকের নিয়ম (২০২৬-০৮-০৯): "কোথাও ব্যবহার না হলে হার্ড ডিলিট
     * হবে, নাহলে ইনঅ্যাকটিভ হবে।"
     *
     * ব্যবহার হয়নি → সত্যিই মুছে যায়। ভুল করে বানানো একটা সারি
     * তালিকায় চিরকাল পড়ে থাকার কোনো কারণ নেই, আর কোনো কাগজ ওটার দিকে
     * দেখাচ্ছে না বলে হারানোর কিছুও নেই।
     *
     * ব্যবহার হয়েছে → নিষ্ক্রিয় হয়, মোছে না। সত্যিকারের মোছা এখানে
     * নীরবে ক্ষতি করত, আর ডাটাবেজ আটকাত না: `unit_id` ঘোষিত
     * `nullOnDelete()` দিয়ে, অর্থাৎ একক মুছলে ওটা ব্যবহার করা **প্রতিটা
     * পণ্যের একক নীরবে খালি হয়ে যেত** — ধরা পড়ত ছয় মাস পর, কোনো চালান
     * ছাপতে গিয়ে।
     *
     * নিষ্ক্রিয় করা বেছে নেওয়া হয়েছে সফট ডিলিটের বদলে, যদিও দুইটাই
     * মালিক বলেছেন: সফট ডিলিটে সারিটা তালিকা থেকেও হারিয়ে যায়, আর তখন
     * ওই একক ব্যবহার করা পুরনো পণ্যটা সম্পাদনা করতে গেলে ড্রপডাউনে
     * এককটাই থাকত না। নিষ্ক্রিয় সারি তালিকায় থাকে, ধূসর হয়ে — পুরনো
     * কাগজ অক্ষত, নতুন কাগজে আসে না।
     *
     * @return bool সত্যিই মুছেছে কি না (মিথ্যা মানে নিষ্ক্রিয় হয়েছে)
     */
    public function delete(Model $record): bool
    {
        /*
         * ডিফল্ট সারির নিজের বার্তা।
         *
         * আগে নিষ্ক্রিয় করার বার্তাটাই ব্যবহার হত — "The default cannot
         * be deactivated"। কাজটা ঠিকই আটকাত, কিন্তু মানুষ Delete চেপে
         * "deactivated" পড়ে ভাবতেন অন্য কিছু ঘটেছে। যে বার্তা যে কাজের
         * কথা বলে না, সেটা ভুল বার্তা।
         */
        if (($record->is_default ?? false) === true) {
            throw ValidationException::withMessages([
                'code' => __('master_data::validation.default_cannot_delete'),
            ]);
        }

        if ($this->referencesTo($record) !== null) {
            $this->deactivate($record);

            return false;
        }

        // forceDelete, delete নয় — মডেলে SoftDeletes আছে, তাই সাধারণ
        // delete কেবল deleted_at বসাত আর সারিটা টেবিলে থেকেই যেত
        $record->forceDelete();

        return true;
    }

    /**
     * এই সারিটার দিকে কেউ দেখাচ্ছে কি না — দেখালে কোন টেবিল।
     *
     * ── তালিকাটা হাতে লেখা নয় ──────────────────────────────────────
     * কোন কোন টেবিল এই টেবিলটার দিকে দেখায়, সেটা ডাটাবেজকেই জিজ্ঞেস
     * করা হয়। হাতে লিখলে একদিন নতুন একটা টেবিল যোগ হত আর তালিকাটা
     * পুরনো থেকে যেত — আর তখন পাহারাটা **থাকা অবস্থাতেই** ডাটা নষ্ট
     * হত, যা পাহারা না থাকার চেয়েও খারাপ: সবাই ভাবত পাহারা আছে।
     *
     * @return string|null প্রথম যে টেবিলে ব্যবহার পাওয়া গেল
     */
    private function referencesTo(Model $record): ?string
    {
        $links = DB::select(
            'SELECT TABLE_NAME AS child, COLUMN_NAME AS child_column
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_NAME = ?',
            [$record->getTable()],
        );

        foreach ($links as $link) {
            if (DB::table($link->child)->where($link->child_column, $record->getKey())->exists()) {
                return $link->child;
            }
        }

        return null;
    }

    /**
     * প্রতিটা মাস্টারের নিজস্ব যাচাই — সংরক্ষণের পর।
     *
     * @param  array<string, mixed>  $data
     */
    private function afterSave(Model $record, array $data): void
    {
        if ($record instanceof Unit) {
            $this->assertUnitHasNoCycle($record);
        }

        if ($record instanceof Tax) {
            $this->assertRateIsSane($record);
        }
    }

    /**
     * একক নিজের ভিত্তি হতে পারে না, ঘুরেও নয়।
     *
     * "কার্টন = ১২ পিস, পিস = ০.০৮ কার্টন" লিখতে দিলে toBase() কখনো
     * থামত না। সেখানে গভীরতার সীমা আছে বটে, কিন্তু সীমা দিয়ে ভুল
     * ঢাকা হয় — এখানে ভুলটাই ঠেকানো হয়।
     */
    private function assertUnitHasNoCycle(Unit $unit): void
    {
        $seen = [$unit->id];
        $node = $unit->baseUnit;

        while ($node !== null) {
            if (in_array($node->id, $seen, true)) {
                throw ValidationException::withMessages([
                    'base_unit_id' => __('master_data::validation.unit_cycle'),
                ]);
            }

            $seen[] = $node->id;
            $node = $node->baseUnit;
        }

        /*
         * খালি রূপান্তরকে ১ ধরা হয়, ছোড়া হয় না।
         *
         * আগে সরাসরি bccomp((string) $unit->factor, ...) ডাকা হত। খালি
         * হলে সেটা bccomp('') হয়ে যেত, আর PHP 8 ওতে ValueError ছোড়ে —
         * ভ্যালিডেশনের বার্তা নয়, সাদা ৫০০।
         *
         * "রূপান্তর বলা হয়নি" মানে "১" — এই এককই মূল একক। ওটাই ধরে
         * নেওয়া হয়, কারণ অন্য কোনো অর্থ হয় না।
         */
        $factor = trim((string) $unit->factor);

        if ($factor === '') {
            $unit->forceFill(['factor' => 1])->save();

            return;
        }

        if (bccomp($factor, '0', 6) <= 0) {
            throw ValidationException::withMessages([
                'factor' => __('master_data::validation.factor_must_be_positive'),
            ]);
        }
    }

    /**
     * করের হার ০ থেকে ১০০-র মধ্যে।
     *
     * ১০০-র বেশি হার গাণিতিকভাবে সম্ভব, ব্যবসায়িকভাবে নয় — আর
     * দামের ভেতরের করে ১০০% মানে শূন্য দিয়ে ভাগ।
     */
    private function assertRateIsSane(Tax $tax): void
    {
        if (bccomp((string) $tax->rate, '0', 4) < 0 || bccomp((string) $tax->rate, '100', 4) >= 0) {
            throw ValidationException::withMessages([
                'rate' => __('master_data::validation.rate_out_of_range'),
            ]);
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function assertCodeIsFree(string $model, string $code, int|string|null $exceptId = null): void
    {
        if ($code === '') {
            throw ValidationException::withMessages([
                'code' => __('master_data::validation.code_required'),
            ]);
        }

        $taken = $model::query()
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
     * প্রমিত তালিকাগুলো — নতুন কোম্পানির শুরুর অবস্থা।
     *
     * এগুলো ছাড়া প্রথম বিলটাই লেখা যায় না: একক নেই মানে পরিমাণের
     * কোনো এককই নেই, শর্ত নেই মানে বকেয়ার তারিখ নেই। খালি রেখে
     * "ব্যবহারকারী নিজে বানাবে" বলাটা কাজ ঠেলে দেওয়া, নকশা নয়।
     *
     * @return array<string, int>
     */
    /**
     * যে তালিকাগুলোর প্রমিত সারি আছে — `installDefaults()` ঠিক এগুলোই বসায়।
     *
     * ── কেন এই ঘোষণাটা লাগল ─────────────────────────────────────────
     * পর্দাটা সতেরোটা তালিকা দেখায়, আর "প্রমিত তালিকা বসান" বোতামটা
     * উঠত **যেকোনো** খালি তালিকায়। কিন্তু নিচের পদ্ধতি বসায় ছয়টা।
     * বাকি এগারোটায় বোতামটা এসে **কিছুই করত না**।
     *
     * ধরা পড়েছে ২৫ আগস্ট ২০২৬: Brands-এর পর্দায় লেখা উঠেছিল
     * *"তালিকাগুলো এখনো খালি — একক, কর, শর্ত ও কারণ কোড ছাড়া প্রথম
     * বিলটাই লেখা যায় না"*, অথচ এককে ছয়টা, করে চারটা, শর্তে চারটা
     * সারি বসে আছে। বার্তাটা মিথ্যা বলছিল, আর বোতামটা অকেজো ছিল।
     *
     * কোডের ঠিক উপরে মন্তব্যে নিয়মটা লেখাই ছিল — "একটাও খালি না হলে
     * নয়, নাহলে বোতামটা কিছুই করত না" — কিন্তু কোড সেটা মানত না।
     *
     * ── যে পাঁচটা এখানে নেই ─────────────────────────────────────────
     * `cost-centers`, `brands`, `product-categories`, `payment-methods`,
     * `vehicles` — এগুলোর কোনো প্রমিত সারি নেই, আর থাকার কথাও নয়:
     * ব্র্যান্ড, শ্রেণি ও খরচ-কেন্দ্র প্রতিটা ব্যবসার নিজের, আর
     * পেমেন্ট মেথড কোন হিসাবের খাতে বসবে তা কোম্পানি ছাড়া কেউ জানে না।
     *
     * তাই ওই পাঁচটায় খালি অবস্থাই স্বাভাবিক, আর প্রস্তাবটা আসে না।
     *
     * ── কেন এটা হাতে লেখা তালিকা, তবু নিরাপদ ────────────────────────
     * `AButtonThatOfferedToInstallNothingTest` ঘোষণাটা পড়ে না — সে
     * সত্যিই বোতামটা চেপে দেখে কারা ভরে, তারপর মেলায়। প্রথম রানেই
     * সে আমার লেখা ছয়টার তালিকা ভুল প্রমাণ করেছে: আসলে এগারোটা।
     *
     * @var list<string>
     */
    public const HAS_DEFAULTS = [
        'units', 'taxes', 'payment-terms', 'party-types', 'price-lists',
        'reason-codes', 'currencies', 'departments', 'designations',
        'employment-types', 'vehicle-types',
    ];

    public function installDefaults(): array
    {
        $made = [];

        $made['units'] = $this->seed(Unit::class, [
            /*
             * ⚠️ 'pcs', not 'Piece' — the owner's instruction (3 Sep 2026):
             * *"Piece = pcs"*.
             *
             * The unit's name is not read on its own; it is read **after a
             * number**, in the tightest row on the busiest screen —
             * "Available 1775 pcs". A trade document has said "pcs" for a
             * century, and the full word cost four extra characters in the
             * one place where width is scarcest.
             *
             * ⓘ This is only the **starting** row a new company gets. Units
             * are settings rows, so any buyer can rename it — which is why
             * the change is here and not hardcoded on the screen.
             *
             * ⓘ The other five still carry full words; the owner named this
             * one. Shortening the rest is his call, not a tidy-up.
             */
            ['PCS', 'pcs', 'পিস', ['factor' => 1]],
            ['DOZ', 'Dozen', 'ডজন', ['factor' => 12]],
            ['CTN', 'Carton', 'কার্টন', ['factor' => 1]],
            ['KG', 'Kilogram', 'কেজি', ['factor' => 1, 'allows_fraction' => true]],
            ['LTR', 'Litre', 'লিটার', ['factor' => 1, 'allows_fraction' => true]],
            ['BAG', 'Bag', 'বস্তা', ['factor' => 1]],
        ]);

        $made['taxes'] = $this->seed(Tax::class, [
            // বাংলাদেশের প্রচলিত হারগুলো — ব্যবসা নিজের হার যোগ করবে
            ['VAT15', 'VAT 15%', 'ভ্যাট ১৫%', ['rate' => 15, 'kind' => 'vat']],
            ['VAT75', 'VAT 7.5%', 'ভ্যাট ৭.৫%', ['rate' => 7.5, 'kind' => 'vat']],
            ['VAT5', 'VAT 5%', 'ভ্যাট ৫%', ['rate' => 5, 'kind' => 'vat']],
            ['NIL', 'No VAT', 'ভ্যাট নেই', ['rate' => 0, 'kind' => 'vat']],
        ]);

        $made['terms'] = $this->seed(PaymentTerm::class, [
            ['CASH', 'Cash', 'নগদ', ['days' => 0]],
            ['NET7', '7 days', '৭ দিন', ['days' => 7]],
            ['NET15', '15 days', '১৫ দিন', ['days' => 15]],
            ['NET30', '30 days', '৩০ দিন', ['days' => 30]],
        ]);

        $made['party_types'] = $this->seed(PartyType::class, [
            ['RETAIL', 'Retail', 'খুচরা', ['applies_to' => PartyType::CUSTOMER]],
            ['WHOLE', 'Wholesale', 'পাইকারি', ['applies_to' => PartyType::CUSTOMER]],
            /*
             * ডিলারই ডিফল্ট — মালিকের সিদ্ধান্ত (২০২৬-০৮-০৯)।
             *
             * এটা পরিবেশক ডিপো, খুচরা দোকান নয়: দিনের প্রায় প্রতিটা
             * গ্রাহকই ডিলার। খুচরা ডিফল্ট থাকলে নতুন গ্রাহক বসানোর সময়
             * প্রতিবার ঘরটা বদলাতে হত, আর যেদিন কেউ ভুলত সেদিন ওই
             * গ্রাহক খুচরা দরে মাল পেত।
             */
            ['DEALER', 'Dealer', 'ডিলার', ['applies_to' => PartyType::CUSTOMER, 'is_default' => true]],
            ['INST', 'Institution', 'প্রতিষ্ঠান', ['applies_to' => PartyType::BOTH]],
            ['VENDOR', 'Vendor', 'সরবরাহকারী', ['applies_to' => PartyType::SUPPLIER]],
        ]);

        $made['price_lists'] = $this->seed(PriceList::class, [
            ['RETAIL', 'Retail Price', 'খুচরা দর', []],
            ['WHOLE', 'Wholesale Price', 'পাইকারি দর', []],
        ]);

        $made['reasons'] = $this->seed(ReasonCode::class, [
            ['DAMAGE', 'Damaged goods', 'ক্ষতিগ্রস্ত পণ্য',
                ['context' => ReasonCode::SALES_RETURN, 'returns_to_stock' => false]],
            ['EXPIRED', 'Expired', 'মেয়াদোত্তীর্ণ',
                ['context' => ReasonCode::SALES_RETURN, 'returns_to_stock' => false]],
            ['WRONG', 'Wrong item delivered', 'ভুল পণ্য দেওয়া হয়েছে',
                ['context' => ReasonCode::SALES_RETURN, 'returns_to_stock' => true]],
            ['UNSOLD', 'Not sold', 'বিক্রি হয়নি',
                ['context' => ReasonCode::SALES_RETURN, 'returns_to_stock' => true]],
            ['COUNT', 'Counting difference', 'গণনার পার্থক্য',
                ['context' => ReasonCode::STOCK_ADJUSTMENT, 'returns_to_stock' => true]],
            ['LOST', 'Lost or stolen', 'হারানো বা চুরি',
                ['context' => ReasonCode::STOCK_ADJUSTMENT, 'returns_to_stock' => false,
                    'needs_approval' => true]],
            ['MISTAKE', 'Entry mistake', 'এন্ট্রির ভুল',
                ['context' => ReasonCode::CANCELLATION, 'returns_to_stock' => true]],

            /*
             * বিক্রি ছাড়া মাল বেরোনোর তিনটা কারণ, তিনটা আলাদা খাত।
             *
             * খাতগুলো এখানে বসে না — কোড থেকে খাতের id জানা যায় না, আর
             * প্রতিষ্ঠানভেদে খরচের খাত আলাদাও হতে পারে। বসানো হয় ছক
             * ইনস্টল হওয়ার পর (CompanyProvisioner-এর ক্রম), আর সেটাই
             * ReasonCode::linkStandardAccounts() করে।
             *
             * তৃতীয়টার খাত ৩২০০ উত্তোলন — খরচ নয়। মালিকের নিজের
             * ব্যবহারকে খরচ লিখলে ব্যবসার মুনাফা কম দেখায় আর বছরশেষে
             * কে কত নিল তা বলার উপায় থাকে না।
             */
            ['ENTERTAIN', 'Office entertainment', 'অফিসের আপ্যায়ন',
                ['context' => ReasonCode::STOCK_ISSUE, 'returns_to_stock' => false]],
            ['GIFT', 'Gift given', 'উপহার দেওয়া হয়েছে',
                ['context' => ReasonCode::STOCK_ISSUE, 'returns_to_stock' => false,
                    'needs_approval' => true]],
            ['OWNUSE', 'Owner personal use', 'মালিকের ব্যক্তিগত ব্যবহার',
                ['context' => ReasonCode::STOCK_ISSUE, 'returns_to_stock' => false,
                    'needs_approval' => true]],
            ['SAMPLE', 'Sample given', 'নমুনা দেওয়া হয়েছে',
                ['context' => ReasonCode::STOCK_ISSUE, 'returns_to_stock' => false]],

            /*
             * মাল আটকে রাখার কারণ — তিনটা, আর তৃতীয়টা বাকি দুইটার মতো নয়।
             *
             * প্রথম দুইটা সমস্যা: মাল নষ্ট, বা ফেরত এসেছে আর দেখা হয়নি।
             * তৃতীয়টা সিদ্ধান্ত — মালিক দাম বাড়ার অপেক্ষায় মাল ছাড়ছেন না।
             * একই "আটকানো" সংখ্যার নিচে তিনটাই থাকে, তাই কারণ আলাদা না
             * রাখলে "৪০ বস্তা আটকানো" দেখে মালিক ভাবতেন তার মালে সমস্যা,
             * অথচ ৩৫ বস্তা তিনি নিজেই আটকে রেখেছেন।
             *
             * এই তিনটা না থাকলে আটকানোর ফর্মের কারণের ঘর ফাঁকা থাকত, আর
             * কারণ ছাড়া আটকানো যায় না — মানে সুবিধাটাই অচল থাকত।
             */
            ['HOLD-DMG', 'Damaged, awaiting decision', 'ক্ষতিগ্রস্ত, সিদ্ধান্তের অপেক্ষায়',
                ['context' => ReasonCode::HOLD, 'returns_to_stock' => false]],
            ['HOLD-RET', 'Returned, awaiting check', 'ফেরত এসেছে, যাচাইয়ের অপেক্ষায়',
                ['context' => ReasonCode::HOLD, 'returns_to_stock' => true]],
            ['HOLD-PRICE', 'Held back for a better price', 'দাম বাড়ার অপেক্ষায় আটকানো',
                ['context' => ReasonCode::HOLD, 'returns_to_stock' => true]],
        ]);

        /*
         * মুদ্রা ও গাড়ির ধরন — সুইচ বন্ধ থাকলেও বসে।
         *
         * সুইচ খোলার দিনটা তালিকা বানানোর দিন হওয়া উচিত নয়: যে
         * প্রতিষ্ঠান আজ ডলারে বিল করতে শুরু করল, তাকে প্রথমে "টাকা"
         * নামের একটা সারি হাতে বানাতে বললে কাজটা সেখানেই থামত।
         *
         * টাকাই ডিফল্ট — ভিত্তি মুদ্রা, আর বাকি সবার হার এর সাপেক্ষে।
         */
        $made['currencies'] = $this->seed(Currency::class, [
            ['BDT', 'Bangladeshi Taka', 'বাংলাদেশি টাকা', ['symbol' => '৳', 'decimal_places' => 2]],
            ['USD', 'US Dollar', 'মার্কিন ডলার', ['symbol' => '$', 'decimal_places' => 2]],
            ['EUR', 'Euro', 'ইউরো', ['symbol' => '€', 'decimal_places' => 2]],
            ['INR', 'Indian Rupee', 'ভারতীয় রুপি', ['symbol' => '₹', 'decimal_places' => 2]],
        ]);

        $base = Currency::query()->where('code', 'BDT')->first();

        if ($base !== null && ! Currency::query()->where('is_default', true)->exists()) {
            $base->makeDefault();
        }

        /*
         * প্রতিষ্ঠানের গড়ন।
         *
         * প্রথম কর্মীটা যোগ করার সময় বিভাগ ও পদবির ঘর খালি থাকলে কাজটা
         * সেখানেই থামত — আর মানুষ তখন "সাধারণ" নামে একটা বিভাগ বানিয়ে
         * সবাইকে তাতে ফেলে দিত, যা পরে আর ঠিক হত না।
         */
        $made['departments'] = $this->seed(Department::class, [
            ['SALES', 'Sales', 'বিক্রয়', []],
            ['STORE', 'Warehouse', 'গুদাম', []],
            ['ACCT', 'Accounts', 'হিসাব', []],
            ['ADMIN', 'Administration', 'প্রশাসন', []],
            ['DELIV', 'Delivery', 'সরবরাহ', []],
        ]);

        $made['designations'] = $this->seed(Designation::class, [
            ['MGR', 'Manager', 'ব্যবস্থাপক', []],
            ['ASTMGR', 'Assistant Manager', 'সহকারী ব্যবস্থাপক', []],
            ['SR', 'Sales Representative', 'বিক্রয় প্রতিনিধি', []],
            ['STOREKP', 'Storekeeper', 'গুদামরক্ষী', []],
            ['ACCT', 'Accountant', 'হিসাবরক্ষক', []],
            ['DRIVER', 'Driver', 'চালক', []],
            ['HELPER', 'Helper', 'সহকারী', []],
        ]);

        /*
         * দৈনিক কর্মী আলাদা: তার বেতন হাজিরার সাথে বাঁধা, মাসের সাথে নয়।
         * তাই ধরনটা কেবল একটা লেবেল নয়, বেতনের হিসাবের শর্ত।
         */
        $made['employment_types'] = $this->seed(EmploymentType::class, [
            ['PERM', 'Permanent', 'স্থায়ী', []],
            ['PROB', 'Probation', 'শিক্ষানবিশ', []],
            ['CONTRACT', 'Contract', 'চুক্তিভিত্তিক', []],
            ['DAILY', 'Daily wage', 'দৈনিক মজুরি', []],
            ['PART', 'Part time', 'খণ্ডকালীন', []],
        ]);

        $made['vehicle_types'] = $this->seed(VehicleType::class, [
            ['TRUCK', 'Truck', 'ট্রাক', []],
            ['PICKUP', 'Pickup', 'পিকআপ', []],
            ['VAN', 'Van', 'ভ্যান', []],
            ['CNG', 'CNG / Auto', 'সিএনজি', []],
            ['RICKSHAW', 'Rickshaw Van', 'রিকশা ভ্যান', []],
            ['BOAT', 'Boat', 'নৌকা', []],
        ]);

        $this->linkIssueReasonsToAccounts();

        return $made;
    }

    /**
     * বিক্রি ছাড়া মাল বেরোনোর কারণগুলোকে তাদের খাতে জোড়া।
     *
     * ── কেন এটা seed()-এর ভেতরে নয় ─────────────────────────────────
     * seed() কোড আর নাম নিয়ে কাজ করে; খাতের id সে জানে না, আর জানার
     * কথাও নয় — খাতগুলো তৈরি হয় ছক বসার সময়, যেটা আলাদা একটা ধাপ
     * (CompanyProvisioner: series → chart → lists)।
     *
     * খাত না পেলে কারণটা জোড়াহীন থেকে যায়, আর তখন মাল বেরোলে টাকাটা
     * মজুদ ঘাটতিতে যাবে — ভুল জায়গা, কিন্তু হিসাব তবু মেলে। চুপচাপ
     * একটা খাত বানিয়ে ফেলার চেয়ে সেটাই ভালো: ছকটা প্রতিষ্ঠানের নিজের,
     * আর কোন খরচ কোন খাতে যাবে তা তারাই ঠিক করে।
     */
    private function linkIssueReasonsToAccounts(): void
    {
        $map = [
            'ENTERTAIN' => StandardChart::ENTERTAINMENT,
            'GIFT' => StandardChart::GIFTS_AND_DONATIONS,
            'OWNUSE' => StandardChart::DRAWINGS,
            'SAMPLE' => StandardChart::MARKETING,
        ];

        foreach ($map as $code => $accountCode) {
            $reason = ReasonCode::query()
                ->where('code', $code)
                ->whereNull('account_id')
                ->first();

            if ($reason === null) {
                continue;
            }

            $account = Account::query()->where('code', $accountCode)->first();

            if ($account !== null) {
                $reason->forceFill(['account_id' => $account->id])->save();
            }
        }
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<array{0:string,1:string,2:string,3:array<string,mixed>}>  $rows
     */
    private function seed(string $model, array $rows): int
    {
        if ($model::query()->exists()) {
            return 0;
        }

        /*
         * কোনটা ডিফল্ট, সেটা এখন আলাদা করে বলা — ক্রম দিয়ে নয়।
         *
         * আগে নিয়ম ছিল "প্রথমটাই ডিফল্ট"। তাতে দুইটা আলাদা প্রশ্নের
         * একটাই উত্তর দিতে হত: তালিকায় কোনটা আগে দেখাবে, আর নতুন
         * কাগজে কোনটা আপনা থেকে বসবে। মালিক ডিলারকে ডিফল্ট চাইলেন
         * (২০২৬-০৮-০৯) — এটা ডিপো, খুচরা দোকান নয় — আর তাতে ডিলারকে
         * তালিকার মাথায় তুলতে হত, যদিও পড়ার স্বাভাবিক ক্রম খুচরা →
         * পাইকারি → ডিলার।
         *
         * কেউ চিহ্নিত না থাকলে আগের নিয়মই — প্রথমটা।
         */
        $marked = null;

        foreach ($rows as $index => [, , , $extra]) {
            if ($extra['is_default'] ?? false) {
                $marked = $index;
                break;
            }
        }

        foreach ($rows as $index => [$code, $en, $bn, $extra]) {
            unset($extra['is_default']);

            $record = $this->create($model, [
                'code' => $code,
                'name_en' => $en,
                'name_bn' => $bn,
                ...$extra,
            ]);

            if ($index === ($marked ?? 0) && $record::supportsDefault()) {
                $record->makeDefault();
            }
        }

        return count($rows);
    }
}
