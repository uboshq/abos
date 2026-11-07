<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Core\Services\SettingsService;
use App\Core\Services\SignedSettings;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\SettingChange;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⓘ দাবির প্রস্তুতি: জরুরি সেটিংয়ের (অনুমোদন, বাকির সীমা, বেতন) অপেক্ষার বদল আরেক প্রশাসক অনুমোদন দেন —
 * কন্ট্রোল প্যানেল দফা ৭ (৭ নভেম্বর ২০২৬; [[SignedSettings]], [[AKeySettingWaitsForASecondHandTest]])।
 *
 * ⓘ যে দাবি অন্য কিছু মাপে (সুইচের সারি, কোম্পানির দেয়াল, সুপার অ্যাডমিনের ক্ষমতা) কিন্তু জরুরি সুইচ টেপে,
 * সে আগে দেখে বদলটা এক হাতে বসেনি, তারপর দ্বিতীয় হাতে বসায় — যাতে পুরনো দাবিটা পুরো নতুন পথ হেঁটে মাপে।
 */
trait SignsAWaitingSetting
{
    /** @var array<int, int> কোম্পানি → দ্বিতীয় হাতের ব্যবহারকারী (এই পরীক্ষার ভিতরেই) */
    private array $secondHands = [];

    /** অপেক্ষার বদলটা আছে আর এখনও বসেনি — তারপর অন্য একজন অনুমোদন দেন; শেষে আগের লোক আবার লগইন থাকেন */
    protected function anotherHandSigns(string $key, Company $company): SettingChange
    {
        $back = auth()->user();
        $change = SettingChange::query()->where('key', $key)->where('status', SettingChange::PENDING)->latest('id')->first();
        $this->assertNotNull($change, "⛔ {$key} টেপা হলো, অথচ আরেকজনের অনুমোদনের অপেক্ষায় গেল না।");
        app(SettingsService::class)->flush();
        $this->assertNotSame((string) $change->new_value, SignedSettings::stored(app(SettingsService::class)->get($key)),
            "⛔ {$key} এক হাতেই বসে গেল।");

        $second = $this->aSecondHandStandsBy($company);

        $this->actingAs($second->fresh())->post(route('system_admin.setting-change.sign', $change->public_id))->assertRedirect();
        app(SettingsService::class)->flush();

        if ($back !== null) {
            $this->actingAs($back);
        }

        return $change->fresh();
    }

    /**
     * ⓘ সেটিংয়ের অনুমোদনের চাবিওয়ালা দ্বিতীয় একজন — **টেপার আগে** ডাকুন: আর কেউ না থাকলে বদলটা অপেক্ষায়ই যায় না,
     * সোজা আটকে যায় ([[SignedSettings::propose()]]; Mac, ৭ নভেম্বর ২০২৬)। একবারই বানানো হয়।
     */
    protected function aSecondHandStandsBy(Company $company): User
    {
        if (isset($this->secondHands[$company->id])) {
            return User::query()->findOrFail($this->secondHands[$company->id]);
        }

        $second = User::factory()->create(['is_active' => true]);
        $second->companies()->attach($company, ['is_active' => true]);
        $second->forceFill(['current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($company->id, function () use ($second): void {
            $second->givePermissionTo(Permission::findOrCreate(SignedSettings::KEY, 'web'));
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->secondHands[$company->id] = (int) $second->id;

        return $second->fresh();
    }
}
