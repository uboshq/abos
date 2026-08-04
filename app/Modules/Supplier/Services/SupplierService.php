<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * সরবরাহকারী তৈরি ও সম্পাদনা।
 *
 * CustomerService-এর গঠন হুবহু এক, আর সেটা ইচ্ছাকৃত: দুইটা মডিউল একই
 * নিয়মে চললে একটা শিখলে অন্যটা চেনা যায়। কিন্তু কোড ভাগ করা হয়নি —
 * পক্ষ দুইটা ভিন্ন দিকে যায় (পাওনা বনাম দেনা), আর একটা শেয়ার্ড
 * "PartyService" বানালে প্রতিটা পদ্ধতিতে "গ্রাহক না সরবরাহকারী" শর্ত
 * বসত, আর তিন মডিউল পরে ওটা আর পড়ার মতো থাকত না (সেকশন ১৯.৮)।
 */
final class SupplierService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly SettingsService $settings,
        private readonly OpeningBalanceService $openings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Supplier
    {
        $this->assertBanglaNameIfRequired($data);
        $this->assertBinIfRequired($data);

        return DB::transaction(function () use ($data) {
            // কোড না দিলে সিরিজ থেকে — নম্বর ইস্যু ট্রানজেকশনের ভেতরে,
            // নাহলে সেভ ব্যর্থ হলেও কোডটা খরচ হয়ে যেত
            $givenCode = filled($data['code'] ?? null);

            $data['code'] = $givenCode ? trim((string) $data['code']) : $this->numbers->next('SUP');

            $this->assertCodeIsFree($data['code']);

            $supplier = Supplier::create([
                ...$data,
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'status' => DocumentStatus::CONFIRMED,
                // ডাটাবেজে ডিফল্ট true আছে, তবু এখানে বসানো: ডিফল্টটা
                // শুধু সারিতে বসে, ফেরত দেওয়া মডেলে নয়
                'is_active' => $data['is_active'] ?? true,
                'created_by' => auth()->id(),
            ]);

            // ইস্যু করা কোডটা কোন সরবরাহকারীতে বসল — "SUP-০০০৭ কার"
            if (! $givenCode) {
                IssuedNumber::query()
                    ->where('document_no', $supplier->code)
                    ->whereNull('source_id')
                    ->update([
                        'source_type' => Supplier::drillSourceType(),
                        'source_id' => $supplier->id,
                    ]);
            }

            /*
             * খোলা ব্যালেন্স খাতায়ও যায়, শুধু সারিতে নয়।
             *
             * না গেলে সরবরাহকারীর পাতায় "প্রদেয় ১,২৫,০০০" দেখাত অথচ
             * প্রদেয় তালিকায় তার নাম থাকত না — ওই রিপোর্ট লেজার থেকে
             * গোনে। ট্রায়াল ব্যালেন্সেও অঙ্কটা কোথাও থাকত না।
             *
             * ট্রানজেকশনের ভেতরে: দাখিলা বসাতে না পারলে সরবরাহকারীও
             * তৈরি হবে না, নাহলে অর্ধেক কাজ হয়ে থাকত।
             */
            $this->openings->forPayable(
                Supplier::drillSourceType(),
                $supplier->id,
                $supplier->code,
                (string) $supplier->opening_balance,
                $supplier->opening_date,
            );

            return $supplier;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Supplier $supplier, array $data): Supplier
    {
        $this->assertBanglaNameIfRequired($data, $supplier);
        $this->assertBinIfRequired($data, $supplier);

        if (isset($data['code']) && trim((string) $data['code']) !== $supplier->code) {
            $this->assertCodeIsFree(trim((string) $data['code']), $supplier->id);
        }

        // খোলা ব্যালেন্স সম্পাদনায় বদলায় না — গ্রাহকের ক্ষেত্রেও একই
        // নিয়ম, একই কারণে: লেজার আর এই সংখ্যা দুই রকম বললে কোনটা সত্যি
        // তা বলার উপায় থাকে না। বদলাতে জাবেদা ভাউচার।
        unset($data['opening_balance'], $data['opening_date']);

        $supplier->update($data);

        return $supplier->fresh();
    }

    /**
     * নিষ্ক্রিয় করা — মোছা নয় (নিয়ম ৫)।
     *
     * বকেয়া থাকা অবস্থায়ও নিষ্ক্রিয় করা যায়, গ্রাহকের মতোই: সরবরাহকারীর
     * সাথে সম্পর্ক শেষ হলেও পুরনো দেনা থেকে যায়, আর সেটা শোধ করতে হবে।
     * আটকে দিলে ব্যবহারকারী বাধ্য হত একটা ভুয়া ভাউচার দিয়ে হিসাবটা
     * শূন্য করতে — যা আসল সমস্যাটা লুকিয়ে ফেলত।
     */
    public function deactivate(Supplier $supplier): Supplier
    {
        $supplier->refresh()->forceFill(['is_active' => false])->save();

        return $supplier->fresh();
    }

    public function activate(Supplier $supplier): Supplier
    {
        $supplier->refresh()->forceFill(['is_active' => true])->save();

        return $supplier->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertBanglaNameIfRequired(array $data, ?Supplier $existing = null): void
    {
        if (! $this->settings->enabled('supplier.require_bn_name')) {
            return;
        }

        $value = $data['name_bn'] ?? $existing?->name_bn;

        if (blank($value)) {
            throw ValidationException::withMessages([
                'name_bn' => __('supplier::validation.bn_name_required'),
            ]);
        }
    }

    /**
     * BIN বাধ্যতামূলক কি না।
     *
     * সুইচ, কারণ সব সরবরাহকারীর BIN থাকে না — গ্রামের একটা দোকান
     * থেকেও মাল আসে। কিন্তু যে প্রতিষ্ঠান নিয়মিত উৎসে ভ্যাট কাটে,
     * তাদের কাছে BIN ছাড়া সরবরাহকারী মানে বিলের সময় আটকে যাওয়া।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertBinIfRequired(array $data, ?Supplier $existing = null): void
    {
        if (! $this->settings->enabled('supplier.require_bin')) {
            return;
        }

        $value = $data['bin'] ?? $existing?->bin;

        if (blank($value)) {
            throw ValidationException::withMessages([
                'bin' => __('supplier::validation.bin_required'),
            ]);
        }
    }

    private function assertCodeIsFree(string $code, ?int $exceptId = null): void
    {
        $taken = Supplier::query()
            ->where('code', $code)
            ->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))
            // মুছে ফেলা সরবরাহকারীর কোডও ধরা হয়: soft delete মানে সারিটা
            // আছে, আর unique ইনডেক্সও সেটা দেখে
            ->withTrashed()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'code' => __('supplier::validation.code_taken', ['code' => $code]),
            ]);
        }
    }
}
