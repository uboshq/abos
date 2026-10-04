<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\SalesChannel;
use App\Modules\MasterData\Services\SalesChannelDefaults;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রতিটা কোম্পানি নিজের বিক্রয়-পথের তালিকা নিয়ে শুরু করে — NEXUS §২৮।
 *
 * ── যা দাবি করা হচ্ছে ─────────────────────────────────────────────────
 *   ⓵ নতুন কোম্পানিতে দশটা পথ বসে, আর পরিবেশক ডিফল্ট
 *   ⓶ সিঙ্ক বারবার চালালে কিছু নষ্ট হয় না — মুছে ফেলা পথ ফেরে না, বদলানো
 *      নাম ছোঁয়া হয় না, কোম্পানির বাছা ডিফল্ট উল্টায় না
 *   ⓷ এক কোম্পানির পথ অন্য কোম্পানির চোখে পড়ে না — তালিকায়ও না, ঠিকানাতেও না
 *   ⓸ গ্রাহকের ফর্মে অন্য কোম্পানির বা বন্ধ করা পথ বসানো যায় না
 *
 * ⚠️ ⓵, ⓷-এর পর্দা আর ⓸ WIRING-এর উপর দাঁড়ায় ([[MasterListService::installDefaults()]]-এর
 * এক লাইন, KINDS-এর সারি, গ্রাহকের যাচাই) — ওয়্যারিং ছাড়া এগুলো লাল হওয়াই সঠিক।
 */
final class EveryCompanyStartsWithItsOwnSalesChannelsTest extends TestCase
{
    use RefreshDatabase;

    private Company $depot;

    private Company $mart;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->depot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->mart = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->depot->id, $this->depot->defaultBranch()?->id);
        $this->actingAs($this->owner);
    }

    // ── ⓵ নতুন কোম্পানি ───────────────────────────────────────────────

    /**
     * ⚠️ এটা পরিষেবাটা হাতে ডাকে না — ডেমো সিডার কোম্পানি খোলে
     * CompanyProvisioner দিয়ে, আর ওটা MasterListService::installDefaults()
     * চালায়। ⛔ ওখানকার লাইনটা বাদ পড়লে লাইভের প্রতিটা নতুন কোম্পানির
     * পথের তালিকা খালি থাকত, আর কিছুই লাল হত না।
     */
    public function test_a_new_company_opens_with_the_ten_channels_and_distributor_as_default(): void
    {
        $codes = SalesChannel::query()->orderBy('code')->pluck('code')->all();

        $expected = array_map(fn (array $row) => $row[0], SalesChannelDefaults::ROWS);
        sort($expected);

        $this->assertSame($expected, $codes,
            'নতুন কোম্পানিতে পথের তালিকা বসেনি — MasterListService::installDefaults() SalesChannelDefaults ডাকে তো?');

        $this->assertSame(['DISTRIB'], SalesChannel::query()->where('is_default', true)->pluck('code')->all(),
            'ডিফল্ট ঠিক একটা, আর সেটা পরিবেশক — এটা পরিবেশক ডিপো।');

        // অন্য কোম্পানিতেও, নিজের সারি হিসেবে
        $martCount = CompanyContext::forCompany($this->mart->id, fn () => SalesChannel::query()->count());
        $this->assertSame(count(SalesChannelDefaults::ROWS), $martCount);
    }

    // ── ⓶ সিঙ্ক কারো কাজ নষ্ট করে না ──────────────────────────────────

    public function test_running_the_sync_again_adds_nothing_and_undoes_nothing(): void
    {
        $service = app(SalesChannelDefaults::class);
        $service->installMissing();

        // কোম্পানির নিজের তিনটা সিদ্ধান্ত
        SalesChannel::query()->where('code', 'OTHER')->firstOrFail()->delete();
        SalesChannel::query()->where('code', 'RETAIL')->firstOrFail()
            ->forceFill(['name_en' => 'Shop floor', 'name_bn' => 'দোকানের মেঝে'])->save();
        SalesChannel::query()->where('code', 'COUNTER')->firstOrFail()->makeDefault();

        $added = $service->installMissing();

        $this->assertSame(0, $added);
        $this->assertNull(SalesChannel::query()->where('code', 'OTHER')->first(),
            '⛔ কোম্পানি যে পথ মুছেছেন সেটা সিঙ্কে ফিরে এসেছে।');
        $this->assertSame(1, SalesChannel::withTrashed()->where('code', 'OTHER')->count(),
            'মোছা সারির পাশে একটা নতুন "OTHER" বসেছে।');
        $this->assertSame('Shop floor', SalesChannel::query()->where('code', 'RETAIL')->value('name_en'),
            '⛔ কোম্পানির বদলানো নাম সিঙ্কে ফিরে গেছে।');
        $this->assertSame(['COUNTER'], SalesChannel::query()->where('is_default', true)->pluck('code')->all(),
            '⛔ কোম্পানির বাছা ডিফল্ট সিঙ্কে উল্টে গেছে।');
    }

    /** একটা পথ হারালে কেবল সেটাই ফেরে — বাকিগুলো দুইবার বসে না। */
    public function test_a_missing_channel_is_filled_in_without_doubling_the_rest(): void
    {
        $service = app(SalesChannelDefaults::class);
        $service->installMissing();

        SalesChannel::withTrashed()->where('code', 'ECOM')->forceDelete();

        $this->assertSame(1, $service->installMissing());
        $this->assertSame(count(SalesChannelDefaults::ROWS), SalesChannel::query()->count());
        $this->assertSame(1, SalesChannel::query()->where('code', 'ECOM')->count());
    }

    // ── ⓷ কোম্পানির দেয়াল ──────────────────────────────────────────────

    public function test_another_companys_channel_is_invisible_in_the_list_and_at_its_address(): void
    {
        $theirs = CompanyContext::forCompany($this->mart->id, fn () => SalesChannel::query()->create([
            'code' => 'MARTONLY',
            'name_en' => 'Mart only road',
            'name_bn' => 'কেবল মার্টের পথ',
        ]));

        $this->assertNull(SalesChannel::query()->find($theirs->id),
            '⛔ ডিপোর প্রসঙ্গে মার্টের পথ পড়া গেছে।');

        $ours = SalesChannel::query()->where('code', 'DEALER')->firstOrFail();

        // নিজেরটা খোলে — নাহলে নিচের ৪০৪ কিছুই প্রমাণ করত না
        $this->get(route('master_data.sales_channel.edit', ['id' => $ours->id]))->assertOk();

        $this->get(route('master_data.sales_channel.edit', ['id' => $theirs->id]))->assertNotFound();

        $this->get(route('master_data.sales_channel.index'))
            ->assertOk()
            ->assertSee('DEALER')
            ->assertDontSee('MARTONLY');
    }

    // ── ⓸ গ্রাহকের ফর্ম ────────────────────────────────────────────────

    public function test_a_customer_takes_a_channel_from_its_own_company_only(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $dealer = SalesChannel::query()->where('code', 'DEALER')->firstOrFail();

        $this->put(route('customer.update', $customer), [
            'name_en' => $customer->name_en,
            'channel_id' => $dealer->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($dealer->id, (int) $customer->fresh()->channel_id);

        // ⛔ বিপজ্জনক ইনপুট: অন্য কোম্পানির পথের id
        $theirs = CompanyContext::forCompany($this->mart->id,
            fn () => SalesChannel::query()->where('code', 'RETAIL')->firstOrFail());

        $this->put(route('customer.update', $customer), [
            'name_en' => $customer->name_en,
            'channel_id' => $theirs->id,
        ])->assertSessionHasErrors('channel_id');

        $this->assertSame($dealer->id, (int) $customer->fresh()->channel_id,
            '⛔ অন্য কোম্পানির পথ গ্রাহকের গায়ে বসে গেছে।');
    }

    /**
     * বন্ধ করা পথ নতুন করে বসানো যায় না — কিন্তু যার গায়ে আগেই আছে,
     * তার অন্য ঘর সম্পাদনা আটকায় না।
     */
    public function test_an_inactive_channel_cannot_be_newly_chosen_but_does_not_lock_the_customer(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $dealer = SalesChannel::query()->where('code', 'DEALER')->firstOrFail();
        $online = SalesChannel::query()->where('code', 'ONLINE')->firstOrFail();

        $customer->forceFill(['channel_id' => $dealer->id])->save();

        $online->forceFill(['is_active' => false])->save();
        $dealer->forceFill(['is_active' => false])->save();

        // ⛔ বিপজ্জনক ইনপুট: বন্ধ করা পথ, যেটা গ্রাহকের নিজের নয়
        $this->put(route('customer.update', $customer), [
            'name_en' => $customer->name_en,
            'channel_id' => $online->id,
        ])->assertSessionHasErrors('channel_id');

        // নিজের পুরনো পথটা (এখন বন্ধ) রেখে অন্য ঘর বদলানো চলে
        $this->put(route('customer.update', $customer), [
            'name_en' => 'Renamed while channel is closed',
            'channel_id' => $dealer->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed while channel is closed', $customer->fresh()->name_en);
        $this->assertSame($dealer->id, (int) $customer->fresh()->channel_id);
    }
}
