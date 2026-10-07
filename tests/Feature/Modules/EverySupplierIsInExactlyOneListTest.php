<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Models\Company;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ দুইটা তালিকা, আর প্রতিটা সারি ঠিক **একটাতে**।
 *
 * ── ⭐ মালিকের নির্দেশ, ১৬ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"সরবরাহকারীর পাশে আরও একটা বোতাম বানাও… সরবরাহকারী বাদে বাকিগুলো
 * ওই লিস্টে যাবে"*।
 *
 * ── ⚠️ এই পাহারাটার আসল কাজ ─────────────────────────────────────────
 * তালিকা ভাগ করার সবচেয়ে চুপচাপ ভুলটা হলো **হারিয়ে যাওয়া**: একটা সারি
 * কোনো তালিকাতেই পড়ে না, আর কেউ টের পায় না — কারণ খালি জায়গা দেখতে
 * ভুলের মতো লাগে না, কম কাজের মতো লাগে।
 *
 * ⛔ উল্টোটাও খারাপ: একই সারি দুই তালিকায় থাকলে গোনাগুনি দুইবার হয়।
 *
 * ⭐ তাই এখানে যোগফল মেলানো হয়: দুইটা তালিকার সংখ্যা = মোট সংখ্যা।
 */
final class EverySupplierIsInExactlyOneListTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'SP', 'name_en' => 'Split Co']);

        \App\Core\Support\CompanyContext::set($this->company->id);
    }

    private function partyType(string $code, string $en): PartyType
    {
        return PartyType::create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name_en' => $en,
            'name_bn' => $en,
            'applies_to' => 'supplier',
            'is_active' => true,
        ]);
    }

    private function supplier(string $name, ?PartyType $type): Supplier
    {
        return Supplier::create([
            'company_id' => $this->company->id,
            'code' => strtoupper(substr(md5($name), 0, 8)),
            'name_en' => $name,
            'party_type_id' => $type?->id,
            'is_active' => true,
        ]);
    }

    /**
     * ⛔ VENDOR মূল তালিকায়, বাকিরা সেবাদাতার তালিকায়, আর ধরনহীনরা মূলে।
     */
    public function test_the_two_lists_split_every_row_exactly_once(): void
    {
        $vendor = $this->partyType(Supplier::VENDOR_CODE, 'Vendor');
        $courier = $this->partyType('COURIER', 'Courier');
        $service = $this->partyType('SERVICE', 'Service Provider');

        $this->supplier('Asol Sorborahokari', $vendor);
        $this->supplier('Courier Ekta', $courier);
        $this->supplier('Service Ekta', $service);

        /*
         * ⚠️ ধরন বসানো নেই এমন একজন — ⓘ এটাই সবচেয়ে সহজে হারিয়ে যাওয়া
         * সারি, কারণ ধরনের ঘরটা ঐচ্ছিক আর পুরনো সারির অনেকগুলোই খালি।
         */
        $this->supplier('Dhoron Bosano Nei', null);

        $total = Supplier::query()->count();
        $suppliers = Supplier::query()->onlySuppliers()->count();
        $services = Supplier::query()->onlyServiceProviders()->count();

        $this->assertSame(4, $total, 'পরীক্ষার সারিগুলোই বসেনি।');

        $this->assertSame(2, $suppliers,
            'সরবরাহকারীর তালিকায় ভুল সংখ্যা — ধরনহীন সারিটা এখানেই থাকার কথা।');

        $this->assertSame(2, $services, 'সেবাদাতার তালিকায় ভুল সংখ্যা।');

        /*
         * ⭐ আসল দাবিটা এইটা: যোগফল মিলতেই হবে।
         *
         * ⛔ উপরের তিনটা আলাদা সংখ্যা ঠিক থেকেও এটা ভাঙতে পারত যদি
         * কোনো সারি দুই তালিকায় পড়ত। ⓘ যোগফল একই সাথে "হারায়নি"
         * আর "দুইবার গোনা হয়নি" — দুইটাই বলে।
         */
        $this->assertSame($total, $suppliers + $services,
            'দুই তালিকার যোগফল মোটের সমান নয় — কোনো সারি হয় হারিয়েছে, নয় দুইবার গোনা হয়েছে।');
    }

    /**
     * ⛔ নতুন কোনো ধরন যোগ হলে সে সেবাদাতার তালিকায় যায়।
     *
     * ⓘ প্রতিষ্ঠান নিজেই নতুন ধরন বানাতে পারে (ফর্মের মন্তব্যে লেখা)।
     * ⚠️ তখন নিয়মটা কী হবে সেটা আগে থেকেই ঠিক থাকা দরকার, নাহলে
     * প্রথমবার কেউ একটা ধরন বানালে সারিগুলো অপ্রত্যাশিত জায়গায় যেত।
     */
    public function test_a_brand_new_party_type_lands_with_the_service_providers(): void
    {
        $this->partyType(Supplier::VENDOR_CODE, 'Vendor');
        $notun = $this->partyType('NOTUN', 'Notun Dhoron');

        $this->supplier('Notun Dhoroner Keu', $notun);

        $this->assertSame(0, Supplier::query()->onlySuppliers()->count());
        $this->assertSame(1, Supplier::query()->onlyServiceProviders()->count());
    }
}
