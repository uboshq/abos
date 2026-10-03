<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ⭐ ক্রেডিট / ডেবিট নোটের "কাকে" ঘরে খোঁজা — মালিক, ৩ অক্টোবর ২০২৬।
 *
 * ── ⛔ অভিযোগ ─────────────────────────────────────────────────────────
 * *"ডেবিট নোট পার্টি সার্চ দেয়ার অপশন নাই"*। ঘরটা একটা লম্বা সাধারণ
 * `<select>` ছিল — ইউবি-তে ~৪১৪ জন গ্রাহক, আর একই নামের দুইটা দোকান
 * ("M/S. Bismillah Store") নাম দেখে আলাদা করা যেত না।
 *
 * ── ⓘ এই পরীক্ষা যা ধরে ───────────────────────────────────────────────
 * ১. দুই দিকেই খোঁজার ঘর আছে, তার কোনো `name` নেই, আর পুরনো `<select>` নেই।
 * ২. পক্ষের কোড-পয়েন্টের ছোট লাইন পর্দায় পৌঁছায় — দিক ধরে ছাঁকা তালিকা থেকেই।
 * ৩. ফর্ম আগের নামেই `party_id` পাঠায়, আর নোটে সেই পক্ষই বসে।
 * ৪. ভুল জমার পরে (আসল ফেরত-পথে) বাছা নামটা লুকানো ঘরে ফেরে।
 *
 * ⚠️ খোঁজা, কীবোর্ড আর ১০০-র সীমার দাবি JS-এর: `resources/js/party-search.test.js`।
 */
final class TheNotePartyListHadNoSearchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_both_directions_offer_a_search_box_and_no_plain_select(): void
    {
        foreach ([Note::CREDIT => 'customer', Note::DEBIT => 'supplier'] as $direction => $type) {
            $party = $this->firstOption($type);
            $html = $this->page($direction)->assertOk()->getContent();
            $xpath = $this->xpath((string) $html);

            $search = $xpath->query('//form//input[@data-party-search]');
            $this->assertSame(1, $search->length, "⛔ {$direction}: \"কাকে\" ঘরে খোঁজার ঘর নেই।");

            /** @var DOMElement $box */
            $box = $search->item(0);
            $this->assertFalse($box->hasAttribute('name'),
                "⛔ {$direction}: খোঁজার ঘরের `name` আছে — লেখাটা ফর্মের সাথে চলে যেত।");

            $this->assertSame(1, $xpath->query('//form//button[@data-party-picker]')->length,
                "⛔ {$direction}: নাম বাছার বোতাম নেই।");
            $this->assertSame(0, $xpath->query('//select[@name="party_id"]')->length,
                "⛔ {$direction}: পুরনো খোঁজা-ছাড়া `<select>`-টা এখনো আছে।");

            $sent = $xpath->query('//form//input[@name="party_id"]');
            $this->assertSame(1, $sent->length, "⛔ {$direction}: `party_id` ফর্মের সাথে যায় না।");

            /** @var DOMElement $hidden */
            $hidden = $sent->item(0);
            $this->assertSame('hidden', $hidden->getAttribute('type'));
            $this->assertSame('', $hidden->getAttribute('value'), "⛔ {$direction}: নতুন নোটে আগে থেকেই কেউ বাছা।");

            // ⓘ ছোট লাইনটা (কোড · পয়েন্ট · মোবাইল) পর্দায় — একই নামের দুই দোকান আলাদা করার একমাত্র উপায়
            $this->assertNotSame('', $party['hint'], 'ডেমোর পক্ষের কোড আছে — পরীক্ষার পূর্বশর্ত।');
            $this->assertStringContainsString(json_encode($party['hint'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $this->pickerData($xpath), "⛔ {$direction}: পক্ষের কোড-পয়েন্ট পর্দায় পৌঁছায়নি।");
        }
    }

    public function test_the_picked_party_is_posted_under_its_old_name_and_saved(): void
    {
        foreach ([Note::CREDIT => 'customer', Note::DEBIT => 'supplier'] as $direction => $type) {
            $party = $this->firstOption($type);

            $this->post(route('accounts.note.store'), [
                'direction' => $direction,
                'party_id' => (string) $party['id'],
                'trx_date' => now()->toDateString(),
                'amount' => '250',
                'reason' => 'price_correction',
            ])->assertSessionHasNoErrors()->assertRedirect();

            $note = Note::query()->latest('id')->firstOrFail();
            $this->assertSame($direction, $note->direction);
            $this->assertSame($type, $note->party_type);
            $this->assertSame($party['id'], (int) $note->party_id, "⛔ {$direction}: বাছা পক্ষটা নোটে বসেনি।");
        }
    }

    public function test_a_failed_save_comes_back_with_the_party_still_picked(): void
    {
        foreach ([Note::CREDIT => 'customer', Note::DEBIT => 'supplier'] as $direction => $type) {
            $party = $this->firstOption($type);
            $before = Note::query()->count();

            // ⓘ আসল ফেরত-পথ: শূন্য টাকায় জমা → ভুল → একই পাতায় ফেরা
            $html = (string) $this->followingRedirects()
                ->from(route('accounts.note.create', ['direction' => $direction]))
                ->post(route('accounts.note.store'), [
                    'direction' => $direction,
                    'party_id' => (string) $party['id'],
                    'trx_date' => now()->toDateString(),
                    'amount' => '0',
                    'reason' => 'price_correction',
                ])
                ->assertOk()
                ->getContent();

            $this->assertSame($before, Note::query()->count(), 'ভুল জমায় নোট বসার কথা নয়।');

            $xpath = $this->xpath($html);
            $this->assertSame(1, $xpath->query('//input[@data-party-search]')->length, 'ফেরা পাতাটা নোটের ফর্ম নয়।');

            /** @var DOMElement $kept */
            $kept = $xpath->query('//form//input[@name="party_id"]')->item(0);
            $this->assertSame((string) $party['id'], $kept->getAttribute('value'),
                "⛔ {$direction}: ভুল জমার পরে বাছা নামটা হারিয়ে যায়।");

            // ⓘ Alpine-এর শুরুর মানও একই — নাহলে পাতা খুলতেই x-bind লুকানো ঘরটা খালি করে দিত
            $this->assertStringContainsString('value: "'.$party['id'].'"', $this->pickerData($xpath),
                "⛔ {$direction}: খোঁজার কম্পোনেন্ট আগের বাছাইটা জানে না।");
        }
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** @return array{id: int, label: string, hint: string, find: string} */
    private function firstOption(string $type): array
    {
        $group = collect(app(PartyRegistry::class)->forPicker())->firstWhere('type', $type);
        $this->assertNotEmpty($group['options'] ?? [], "ডেমোতে এই শাখায় {$type} আছে — পরীক্ষার পূর্বশর্ত।");

        return $group['options'][0];
    }

    private function page(string $direction): TestResponse
    {
        return $this->get(route('accounts.note.create', ['direction' => $direction]));
    }

    /** পিকারের `x-data` — ব্রাউজার যেমন পড়ে, `&quot;` খুলে */
    private function pickerData(DOMXPath $xpath): string
    {
        /** @var DOMElement $root */
        $root = $xpath->query('//*[@data-party-picker]/ancestor::*[@x-data][1]')->item(0);
        $this->assertStringStartsWith('partySearch(', $root->getAttribute('x-data'));

        return $root->getAttribute('x-data');
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
