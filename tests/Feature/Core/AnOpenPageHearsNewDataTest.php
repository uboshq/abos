<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\LiveStamp;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ রিয়েল-টাইম সিঙ্ক, ওয়েবের দিক — মালিক, ১০ অক্টোবর ২০২৬: "Real Time sync app r web dutotei koro", পথ (ক)।
 *
 * পাতা জানে সে কোন মুহূর্তের তথ্য দেখাচ্ছে; অন্য কেউ কিছু লিখলে কুড়ি সেকেন্ডের জিজ্ঞাসায় তা ধরা পড়ে
 * ([[LiveStamp]], [[LiveController]], `resources/js/live.js`)। ⛔ জিজ্ঞাসা সেশন জাগায় না, আর সই ছাড়া
 * অন্য কোম্পানির চিহ্ন পাওয়া যায় না।
 */
final class AnOpenPageHearsNewDataTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'LV', 'name_en' => 'Live Co']);
    }

    public function test_a_write_moves_the_stamp_and_the_question_hears_it_without_a_session(): void
    {
        $clerk = $this->member();
        $stamps = app(LiveStamp::class);
        $before = $stamps->read($this->company->id);

        $this->actingAs($clerk)->post(route('notifications.read-all'))->assertRedirect();

        $after = $stamps->read($this->company->id);
        $this->assertGreaterThan($before, $after, 'লেখার পরেও চিহ্ন নড়ল না — খোলা পাতা খবর পাবে না।');

        $this->flushSession();
        $response = $this->get(route('live.pulse', ['key' => $stamps->key($this->company->id)]))
            ->assertOk()
            ->assertExactJson(['s' => $after]);

        $this->assertSame([], $response->headers->getCookies(), '⛔ জিজ্ঞাসা কুকি বসাল — খোলা পাতা সেশন জাগিয়ে রাখবে।');
    }

    public function test_a_read_does_not_move_the_stamp(): void
    {
        $clerk = $this->member();
        $stamps = app(LiveStamp::class);
        $before = $stamps->read($this->company->id);

        $this->actingAs($clerk)->get(route('notifications.settings'));

        $this->assertSame($before, $stamps->read($this->company->id));
    }

    public function test_another_companys_stamp_needs_its_own_signature(): void
    {
        $other = Company::create(['code' => 'OX', 'name_en' => 'Other']);
        $mine = app(LiveStamp::class)->key($this->company->id);
        $forged = 'c'.$other->id.substr($mine, strpos($mine, '.'));

        $this->get('/live/'.$forged)->assertNotFound();
        $this->assertSame($this->company->id, app(LiveStamp::class)->companyOf($mine));
        $this->assertNull(app(LiveStamp::class)->companyOf($forged));
    }

    public function test_the_page_carries_its_stamp_and_its_question(): void
    {
        $clerk = $this->member();
        $stamps = app(LiveStamp::class);

        $this->actingAs($clerk)->get(route('notifications.settings'))
            ->assertOk()
            ->assertSee('name="abos-live"', false)
            ->assertSee(route('live.pulse', ['key' => $stamps->key($this->company->id)]), false);
    }

    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user->fresh();
    }
}
