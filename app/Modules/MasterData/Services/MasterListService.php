<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
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
final class MasterListService
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $data
     */
    public function create(string $model, array $data): Model
    {
        return DB::transaction(function () use ($model, $data) {
            $code = trim((string) ($data['code'] ?? ''));

            $this->assertCodeIsFree($model, $code);

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
            $code = isset($data['code']) ? trim((string) $data['code']) : $record->code;

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

        if (bccomp((string) $unit->factor, '0', 6) <= 0) {
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
    public function installDefaults(): array
    {
        $made = [];

        $made['units'] = $this->seed(Unit::class, [
            ['PCS', 'Piece', 'পিস', ['factor' => 1]],
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
            ['DEALER', 'Dealer', 'ডিলার', ['applies_to' => PartyType::CUSTOMER]],
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

        return $made;
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

        foreach ($rows as $index => [$code, $en, $bn, $extra]) {
            $record = $this->create($model, [
                'code' => $code,
                'name_en' => $en,
                'name_bn' => $bn,
                ...$extra,
            ]);

            // প্রথমটাই ডিফল্ট — কোনটা ডিফল্ট হবে সেটা তালিকার ক্রমেই বলা
            if ($index === 0 && $record::supportsDefault()) {
                $record->makeDefault();
            }
        }

        return count($rows);
    }
}
