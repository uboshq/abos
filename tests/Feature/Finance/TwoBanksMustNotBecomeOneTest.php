<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Services\InstitutionLinkProposer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * দুইটা ব্যাংক যেন কোনোদিন এক না হয়ে যায়।
 *
 * ── ⚠️ কেন এই পরীক্ষাটা অন্যগুলোর চেয়ে জরুরি ────────────────────────
 * পুরনো আমানতে প্রতিষ্ঠানের নাম হাতে লেখা, আর সেগুলো এখন নাম মিলিয়ে
 * তালিকার প্রতিষ্ঠানে জোড়া হবে। ⛔ নাম মেলানো ঠিক এখানেই ভুল করে:
 * "Dhaka Bank" আর "DBBL" (Dutch-Bangla) দুইটা **আলাদা ব্যাংক**, কিন্তু
 * ঢিলে মিল ওদের এক করে ফেলে।
 *
 * ⓘ আর ভুলটা নীরব: জোড়া বসার পরে কেউ আর টাইপ করা নামটা দেখে না, তাই
 * "ডাচ্-বাংলায় ত্রিশ লাখ আমানত" লেখা থাকত আর কেউ প্রশ্ন করত না।
 *
 * ⭐ তাই নিয়মটা এক লাইনের: **সন্দেহ হলে জোড়া নয়** (abos-8b, ২০ সেপ্টেম্বর
 * ২০২৬: *"I'd rather half the rows stay unmatched than two banks become one"*)।
 */
final class TwoBanksMustNotBecomeOneTest extends TestCase
{
    use RefreshDatabase;

    private Collection $banks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        foreach ([
            ['Dhaka Bank Limited', 'ঢাকা ব্যাংক লিমিটেড', 'DBL'],
            ['Dutch-Bangla Bank Limited', 'ডাচ্-বাংলা ব্যাংক লিমিটেড', 'DBBL'],
            ['Islami Bank Bangladesh PLC', 'ইসলামী ব্যাংক বাংলাদেশ', 'IBBL'],
        ] as [$en, $bn, $code]) {
            Institution::query()->create([
                'kind' => Institution::BANK,
                'name_en' => $en,
                'name_bn' => $bn,
                'short_code' => $code,
                'is_active' => true,
            ]);
        }

        $this->banks = Institution::query()->whereIn('kind', [Institution::BANK, Institution::NBFI])->get();
    }

    /**
     * ⛔ আসল বিপদটা: এক ব্যাংকের নাম অন্য ব্যাংকে যেন না বসে।
     */
    public function test_dhaka_bank_never_becomes_dutch_bangla(): void
    {
        $match = app(InstitutionLinkProposer::class)->matchName('Dhaka Bank Limited', $this->banks);

        $this->assertNotNull($match['institution'], 'পরিষ্কার নামটাই মেলেনি।');
        $this->assertSame('Dhaka Bank Limited', $match['institution']->name_en,
            'ঢাকা ব্যাংকের আমানত অন্য ব্যাংকে বসেছে — ঠিক যেটা ঠেকানোর কথা।');

        $dbbl = app(InstitutionLinkProposer::class)->matchName('DBBL Mirpur Branch', $this->banks);

        $this->assertSame('Dutch-Bangla Bank Limited', $dbbl['institution']?->name_en,
            'DBBL সংক্ষেপটা ডাচ্-বাংলায় যায়নি।');
    }

    /**
     * ⭐ একই ব্যাংকের তিন রকম লেখা এক জায়গাতেই যায়।
     */
    public function test_one_bank_written_three_ways_is_one_bank(): void
    {
        $proposer = app(InstitutionLinkProposer::class);

        foreach (['IBBL', 'Islami Bank Bangladesh PLC', 'ইসলামী ব্যাংক বাংলাদেশ'] as $typed) {
            $match = $proposer->matchName($typed, $this->banks);

            $this->assertSame('Islami Bank Bangladesh PLC', $match['institution']?->name_en,
                "\"{$typed}\" ইসলামী ব্যাংকে যায়নি — একই ব্যাংক তিন জায়গায় ভাগ হয়ে থাকবে।");
        }
    }

    /**
     * ⛔ আধা-নাম জোড়া লাগে না — "ব্যাংক" লেখা থাকলেই কোনো ব্যাংক নয়।
     */
    public function test_half_a_name_is_left_alone(): void
    {
        $proposer = app(InstitutionLinkProposer::class);

        foreach (['Bank', 'ব্যাংক', 'Sonali', 'FDR 2023'] as $typed) {
            $match = $proposer->matchName($typed, $this->banks);

            $this->assertNull($match['institution'],
                "\"{$typed}\" থেকে একটা ব্যাংক আন্দাজ করা হয়েছে — অর্ধেক নাম যথেষ্ট নয়।");
        }
    }

    /**
     * ⚠️ দুইটা মিললে কোনোটাই নয় — আর সেটা "মেলেনি" নয়, "দ্ব্যর্থক"।
     *
     * ⓘ পার্থক্যটা মালিকের জন্য: "মেলেনি" মানে হাতে বসাতে হবে, আর
     * "দ্ব্যর্থক" মানে দুইটা প্রার্থী আছে, বেছে দিলেই হয়।
     */
    public function test_two_candidates_means_no_proposal_but_a_named_doubt(): void
    {
        Institution::query()->create([
            'kind' => Institution::BANK,
            'name_en' => 'Dhaka Bank',
            'name_bn' => 'ঢাকা ব্যাংক',
            'short_code' => 'DB2',
            'is_active' => true,
        ]);

        $banks = Institution::query()->whereIn('kind', [Institution::BANK, Institution::NBFI])->get();

        $match = app(InstitutionLinkProposer::class)->matchName('Dhaka Bank Limited', $banks);

        $this->assertNull($match['institution'], 'দুইটা প্রার্থী থাকা সত্ত্বেও একটা বেছে নেওয়া হয়েছে।');
        $this->assertSame('ambiguous', $match['why']);
        $this->assertCount(2, $match['candidates'], 'দুইটা প্রার্থীর নামই মালিককে দেখাতে হবে।');
    }
}
