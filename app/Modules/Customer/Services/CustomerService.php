<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\DuplicateGuard;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\PartyType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * গ্রাহক তৈরি ও সম্পাদনা — সেকশন ১৯.৬ অনুযায়ী লজিক এখানে, কন্ট্রোলারে নয়।
 *
 * কন্ট্রোলার শুধু অনুরোধ নেয় ও উত্তর দেয়; কোড কীভাবে তৈরি হয়, বাংলা নাম
 * বাধ্যতামূলক কি না, খোলা ব্যালেন্স হিসাবে কীভাবে বসে — সব এখানে। ফলে
 * একই কাজ পরে API বা ইমপোর্ট থেকে ডাকলে নিয়মগুলো আবার লিখতে হয় না।
 */
final class CustomerService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly SettingsService $settings,
        private readonly OpeningBalanceService $openings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Customer
    {
        $this->assertBanglaNameIfRequired($data);
        $this->assertNotADuplicate($data);
        $this->assertOnlyOneDistributorPerPoint($data);

        return DB::transaction(function () use ($data) {
            // কোড না দিলে সিরিজ থেকে — নম্বর ইস্যু ট্রানজেকশনের ভেতরে,
            // নাহলে গ্রাহক সেভ ব্যর্থ হলেও কোডটা খরচ হয়ে যেত।
            $givenCode = filled($data['code'] ?? null);

            $data['code'] = $givenCode ? trim($data['code']) : $this->numbers->next('CUS');

            $this->assertCodeIsFree($data['code']);

            $customer = Customer::create([
                ...$data,
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'status' => DocumentStatus::CONFIRMED,
                // ডাটাবেজে ডিফল্ট true আছে, তবু এখানে বসানো হয়: ডিফল্টটা
                // শুধু সারিতে বসে, ফেরত দেওয়া মডেলে নয়। ফলে যে কোড এই
                // মডেলটা ধরে is_active দেখত, সে null পেত — আর null মিথ্যা
                // বলেই গ্রাহকটাকে নিষ্ক্রিয় ভাবত।
                'is_active' => $data['is_active'] ?? true,
                'created_by' => auth()->id(),
            ]);

            /*
             * ইস্যু করা কোডটা কোন গ্রাহকে বসল, সেটা নম্বর-রেজিস্টারে
             * ফেরত লেখা হয় — নাহলে "CUS-0007 কার" প্রশ্নের উত্তর থাকত না।
             *
             * শর্তটা আগে $data['code_was_given'] দেখত, অথচ ওই কী কেউ
             * কোথাও বসাত না — মানে শর্তটা সবসময় সত্যি ছিল। হাতে লেখা
             * কোডের জন্য রেজিস্টারে সারি থাকে না বলে ক্ষতি হয়নি, কিন্তু
             * শর্তটা কিছুই বাছাই করছিল না।
             */
            if (! $givenCode) {
                IssuedNumber::query()
                    ->where('document_no', $customer->code)
                    ->whereNull('source_id')
                    ->update(['source_type' => Customer::drillSourceType(), 'source_id' => $customer->id]);
            }

            /*
             * খোলা ব্যালেন্স খাতায়ও যায়, শুধু গ্রাহকের সারিতে নয়।
             *
             * না গেলে গ্রাহকের পাতায় পাওনা দেখাত, অথচ ট্রায়াল ব্যালেন্স
             * বা বকেয়া তালিকায় অঙ্কটা কোথাও থাকত না — ওরা লেজার থেকে
             * গোনে। দুই জায়গা থেকে দুই সংখ্যা মানে একদিন অমিল।
             */
            $this->openings->forReceivable(
                Customer::drillSourceType(),
                $customer->id,
                $customer->code,
                (string) $customer->opening_balance,
                $customer->opening_date,
            );

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        $this->assertBanglaNameIfRequired($data, $customer);
        $this->assertNotADuplicate($data, $customer->id);
        $this->assertOnlyOneDistributorPerPoint($data, $customer);

        if (isset($data['code']) && $data['code'] !== $customer->code) {
            $this->assertCodeIsFree($data['code'], $customer->id);
        }

        // খোলা ব্যালেন্স বদলানো হিসাবের কাজ, সম্পাদনার নয়: গ্রাহকের
        // পাওনা লেজার থেকে আসে, আর এখানে সংখ্যাটা বদলালে লেজার ও তালিকা
        // দুই রকম বলত। বদলাতে হলে একটা জাবেদা ভাউচার লাগবে।
        unset($data['opening_balance'], $data['opening_date']);

        $customer->update($data);

        return $customer->fresh();
    }

    /**
     * নিষ্ক্রিয় করা — মোছা নয় (নিয়ম ৫)।
     *
     * যে গ্রাহকের বিল বা আদায় আছে তাকে মুছে ফেললে ওই লেনদেনগুলো কার,
     * সেই প্রশ্নের উত্তর হারিয়ে যায়।
     */
    public function deactivate(Customer $customer): Customer
    {
        $customer->update(['is_active' => false]);

        return $customer->fresh();
    }

    public function activate(Customer $customer): Customer
    {
        /*
         * ⚠️ সক্রিয় করাই সেই বাঁকে যেখানে নিয়মটা ভাঙতে পারে।
         *
         * ⓘ নিষ্ক্রিয় থাকা পরিবেশক কারো জায়গা নেন না, তাই তৈরি বা সম্পাদনার
         * সময় তাঁকে আটকানো হয় না। ⛔ কিন্তু এই এক ক্লিকেই তিনি আবার
         * বসে পড়তে পারেন — আর তখন এক পয়েন্টে দুইজন হয়ে যেত।
         */
        $this->assertOnlyOneDistributorPerPoint(['is_active' => true], $customer);

        $customer->update(['is_active' => true]);

        return $customer->fresh();
    }

    /**
     * বাংলা নাম বাধ্যতামূলক কি না — Control Panel থেকে (নিয়ম ৭)।
     *
     * ডিফল্টে নয়: বাধ্যতামূলক করলে ডাটা এন্ট্রি দ্বিগুণ ভারী হয়, আর
     * অনেক প্রতিষ্ঠান ইংরেজিতেই কাজ করে (সেকশন ১৮.৩)।
     *
     * @param  array<string, mixed>  $data
     */
    /**
     * সেভ না করে দেখা — সারিটা গ্রহণযোগ্য কি না।
     *
     * ইমপোর্টের যাচাই-পর্দার জন্য। ওখানে একই নিয়ম আলাদা করে লিখলে একদিন
     * একটা বদলে যেত আর অন্যটা পুরনো থেকে যেত — তখন পর্দায় সারিটা সবুজ
     * দেখাত, আর বসানোর সময় ব্যর্থ হত।
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function assertImportable(array $data): void
    {
        $this->assertBanglaNameIfRequired($data);

        /*
         * ⭐ পরিবেশকের নিয়মটাও যাচাই-পর্দায় দেখা যাক, ১৫ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ এটা না থাকলে একশো সারির ফাইলে দুইটা পরিবেশক একই পয়েন্টে
         * থাকলে পর্দায় সব সবুজ দেখাত, আর বসানোর সময় ঠিক মাঝপথে ভেঙে
         * পড়ত। ⛔ তখন কিছু সারি বসে গেছে, কিছু বসেনি — আর কোনগুলো
         * বসেছে সেটা ব্যবহারকারীকে হাতে মিলিয়ে দেখতে হত।
         */
        $this->assertOnlyOneDistributorPerPoint($data);
    }

    private function assertBanglaNameIfRequired(array $data, ?Customer $existing = null): void
    {
        if (! $this->settings->enabled('customer.require_bn_name')) {
            return;
        }

        $bangla = $data['name_bn'] ?? $existing?->name_bn;

        if (blank($bangla)) {
            throw ValidationException::withMessages([
                'name_bn' => __('customer::validation.bn_name_required'),
            ]);
        }
    }

    /**
     * এই গ্রাহকটা কি আগে থেকেই খাতায় আছেন।
     *
     * ফোন মিললে আটকায় — একই নম্বর মানে প্রায় নিশ্চিতভাবে একই মানুষ, আর
     * দুইটা সারি হলে তাঁর বকেয়া দুই ভাগ হয়ে যায়, কেউ মোট পাওনা জানে না।
     *
     * নাম মিললে আটকায় না, কেবল বলে। "রহিম স্টোর" নামে দুই বাজারে দুইটা
     * আলাদা দোকান সত্যিই থাকতে পারে; ওটা আটকালে সৎ ব্যবহারকারী কাজই
     * করতে পারতেন না। তিনি `allow_duplicate` টিক দিয়ে এগোতে পারেন, আর
     * সেই সিদ্ধান্তটা সারিতে বসে থাকে।
     *
     * `$data` রেফারেন্সে নেওয়া, কারণ শেষে `allow_duplicate` মুছে ফেলতে
     * হয়। ওটা একটা সিদ্ধান্তের ঘর, গ্রাহকের কোনো কলাম নয় — রেখে দিলে
     * `Customer::create()` mass-assignment-এ ছুঁড়ে ফেলত, আর প্রতিটা
     * জেনেশুনে বসানো নকল সেভ হওয়ার আগেই ভেঙে পড়ত।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNotADuplicate(array &$data, ?int $exceptId = null): void
    {
        $guard = app(DuplicateGuard::class);

        $allowed = (bool) ($data['allow_duplicate'] ?? false);
        unset($data['allow_duplicate']);

        $guard->assertPhoneIsFree(Customer::class, ['phone'], $data['phone'] ?? null, $exceptId);

        if ($allowed) {
            return;
        }

        $matches = $guard->nameMatches(
            Customer::class,
            ['name_en', 'name_bn'],
            $data['name_en'] ?? null,
            $exceptId,
        );

        if ($matches->isNotEmpty()) {
            throw ValidationException::withMessages([
                'name_en' => __('core.duplicate.name_matches').' '.__('core.duplicate.confirm_hint'),
            ]);
        }
    }

    /**
     * ⭐ এক পয়েন্টে একজনই সক্রিয় পরিবেশক — আর পরিবেশকের পয়েন্ট লাগবেই।
     *
     * ── ⛔ মালিকের নিয়ম, ১৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * *"গ্রাহকের ধরন যদি পরিবেশক হয় তাহলে পয়েন্ট বাধ্যতামূলক। আর এক
     * পয়েন্টে দুজন সক্রিয় পরিবেশক হবে না — দুজন থাকলে একটা নিষ্ক্রিয়
     * করে আরেকটা সক্রিয় করতে হবে। কেন? **এক এলাকায় একজনই পরিবেশক হয়।**"*
     *
     * ── ⚠️ কেন এটা সার্ভিসে, ভ্যালিডেশনে নয় ────────────────────────────
     * গ্রাহক তিনটা দরজা দিয়ে ঢোকে: ফর্ম, ইমপোর্ট, আর মোবাইল সিংক। ⛔
     * নিয়মটা [[CustomerRequest]]-এ লিখলে কেবল **প্রথম** দরজাটা পাহারা
     * পেত, আর বাকি দুইটা দিয়ে এক পয়েন্টে দুই পরিবেশক দিব্যি ঢুকে যেত।
     *
     * ⓘ কোডের অনন্যতাও ঠিক এই কারণেই এখানে ([[assertCodeIsFree]]) —
     * একই যুক্তি, একই জায়গা।
     *
     * ── ⓘ "সক্রিয়" শব্দটা এখানে মূল কথা ────────────────────────────────
     * পুরনো পরিবেশক ইতিহাসে থেকে যান, নইলে তাঁর নামের বিলগুলো অনাথ হত।
     * ⭐ তাই বাধাটা কেবল **সক্রিয়** সারির উপর: পুরনোজনকে নিষ্ক্রিয় করে
     * নতুনজনকে বসানো যায়, আর দুইজন একসাথে সক্রিয় থাকতে পারেন না।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertOnlyOneDistributorPerPoint(array $data, ?Customer $existing = null): void
    {
        $typeId = array_key_exists('party_type_id', $data)
            ? $data['party_type_id']
            : $existing?->party_type_id;

        if (blank($typeId) || ! PartyType::query()->whereKey($typeId)->first()?->isDistributor()) {
            return;
        }

        $pointId = array_key_exists('location_id', $data)
            ? $data['location_id']
            : $existing?->location_id;

        /*
         * ⛔ পয়েন্ট ছাড়া পরিবেশক হয় না।
         *
         * ⓘ বাকি সব ধরনে পয়েন্টটা ঐচ্ছিক (নতুন দোকান বসানোর সময় এলাকা
         * ভাগ ঠিক না-ও থাকতে পারে)। ⚠️ কিন্তু পরিবেশকের পুরো সংজ্ঞাটাই
         * এলাকা ধরে — পয়েন্ট না জানলে "এক এলাকায় একজন" নিয়মটা কীসের
         * উপর দাঁড়াবে?
         */
        if (blank($pointId)) {
            throw ValidationException::withMessages([
                'location_id' => __('customer::validation.distributor_needs_a_point'),
            ]);
        }

        // নিষ্ক্রিয় পরিবেশক কারো জায়গা নেন না — তাই তাঁকে আটকানোর কিছু নেই
        $willBeActive = (bool) (array_key_exists('is_active', $data)
            ? $data['is_active']
            : ($existing?->is_active ?? true));

        if (! $willBeActive) {
            return;
        }

        $sitting = Customer::query()
            ->where('location_id', $pointId)
            ->where('is_active', true)
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
            ->whereHas('partyType', fn ($q) => $q->where('code', PartyType::DISTRIBUTOR))
            ->first();

        if ($sitting !== null) {
            throw ValidationException::withMessages([
                'location_id' => __('customer::validation.point_already_has_a_distributor', [
                    'name' => $sitting->name(),
                    'code' => $sitting->code,
                ]),
            ]);
        }
    }

    private function assertCodeIsFree(string $code, ?int $exceptId = null): void
    {
        $taken = Customer::query()
            ->where('code', $code)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            // মুছে ফেলা গ্রাহকের কোডও দখলে থাকে: সফট ডিলিট মানে রেকর্ডটা
            // এখনো আছে, আর একই কোডে দুইটা রেকর্ড থাকলে লেজারের ড্রিল-ডাউন
            // কোনটায় যাবে বলা যেত না।
            ->withTrashed()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'code' => __('customer::validation.code_taken', ['code' => $code]),
            ]);
        }
    }
}
