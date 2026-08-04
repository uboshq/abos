<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\IssuedNumber;
use App\Modules\Customer\Models\Customer;
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
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Customer
    {
        $this->assertBanglaNameIfRequired($data);

        return DB::transaction(function () use ($data) {
            // কোড না দিলে সিরিজ থেকে — নম্বর ইস্যু ট্রানজেকশনের ভেতরে,
            // নাহলে গ্রাহক সেভ ব্যর্থ হলেও কোডটা খরচ হয়ে যেত।
            $data['code'] = filled($data['code'] ?? null)
                ? trim($data['code'])
                : $this->numbers->next('CUS');

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

            // ইস্যু করা কোডটা কোন গ্রাহকে বসল, সেটা নম্বর-রেজিস্টারে
            // ফেরত লেখা হয় — নাহলে "CUS-0007 কার" প্রশ্নের উত্তর থাকত না।
            if (blank($data['code_was_given'] ?? null)) {
                IssuedNumber::query()
                    ->where('document_no', $customer->code)
                    ->whereNull('source_id')
                    ->update(['source_type' => Customer::drillSourceType(), 'source_id' => $customer->id]);
            }

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        $this->assertBanglaNameIfRequired($data, $customer);

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
