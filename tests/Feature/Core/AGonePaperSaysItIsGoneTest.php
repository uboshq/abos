<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Notifications\PhoneNoticeTarget;
use App\Core\Services\DataScope;
use App\Core\Services\NotificationService;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Documents\MakesDocuments;
use Tests\TestCase;

/**
 * ⛔ মোছা কাগজের খবর খুললে ৪০৪, নয়তো "অনুমতি নেই" — কোনোটাই সত্যি কথা নয় (fe-র নিয়ম, ২৪ অক্টোবর ২০২৬: "খবর যাঁকে কাগজে
 * পাঠায় তিনি সেটা খোলেন; না পারলে কারণটা বাংলায় দেখেন — খালি ৪০৩/৪০৪ কখনো নয়")।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 *  · খোঁজা যায় এমন কাগজ ([[Drillable]] — টিল, চালান, ভাউচার) মুছলে [[NotificationService::mayOpen()]] "না" বলত, আর পাতা বলত
 *    "এই কাগজটা আর আপনার নাগালে নেই" — মানুষ প্রশাসকের কাছে অনুমতি চাইতে যেতেন, অথচ খোলার মতো কিছুই নেই।
 *  · ডকুমেন্টের খবর কাগজ চিনত না (ডকুমেন্ট ড্রিলের তালিকায় নেই) — মোছা ডকুমেন্টের খবর সোজা কাগজের লিংকে পাঠাত, আর সেখানে ৪০৪।
 *
 * ── ⭐ দাবি ──────────────────────────────────────────────────────────
 * দুই রকম কাগজেই, মোছার আগে খবর কাগজে পাঠায়; মোছার পরে খবর খুললে ২০০ পাতা, লেখা "কাগজটা আর নেই" (অনুমতির কথা নয়), আর খবর
 * পড়া হিসেবে বসে। ডকুমেন্টের কাগজ-চেনাটা ফোনের `paper.type`-এ যায় না — ওটা কেবল ড্রিলের নাম ([[PhoneNoticeTarget]])।
 */
final class AGonePaperSaysItIsGoneTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_a_deleted_till_says_it_is_gone_not_out_of_reach(): void
    {
        $reader = $this->person('till-reader@abos.test', ['accounts.till.view']);
        $this->as($this->owner);

        $till = app(CashTillService::class)->create([
            'code' => 'GONE-1', 'name_en' => 'Gone Box', 'name_bn' => 'মুছে যাওয়া বাক্স', 'branch_id' => $this->main->id,
        ]);
        $url = route('accounts.till.show', $till);
        $note = app(NotificationService::class)->send($reader, 'approval.approved', 'বাক্সের খবর', null, $url, about: $till);
        $this->assertNotNull($note, 'প্রস্তুতিটাই ভুল — বাক্সের খবরটাই বসেনি।');
        $this->assertSame(CashTill::drillSourceType(), $note->subject_type, 'প্রস্তুতিটাই ভুল — খবর বাক্সটা চেনে না।');

        $this->as($reader);
        $this->opensBeforeItIsGone($note, $url);

        DB::table($till->getTable())->where('id', $till->id)->update(['deleted_at' => now()]);
        $this->assertNull(CashTill::acrossBranches()->find($till->id), 'প্রস্তুতিটাই ভুল — বাক্সটা মোছেনি।');

        $this->saysItIsGone($note);
    }

    public function test_a_deleted_document_says_it_is_gone_instead_of_a_404(): void
    {
        $keeper = $this->person('gone-keeper@abos.test', ['documents.view']);
        $document = $this->upload('Gone shop lease', extra: ['owner_id' => $keeper->id]);

        $note = Notification::query()->where('user_id', $keeper->id)->where('type', DocumentNotices::NEW)->first();
        $this->assertNotNull($note, 'প্রস্তুতিটাই ভুল — মালিক নতুন কাগজের খবর পাননি।');
        $this->assertNull(app(PhoneNoticeTarget::class)->of($note, $keeper)['paper']['type'],
            '⛔ ডকুমেন্টের খবরের কাগজ-চেনা ফোনের paper.type-এ গেল — ওটা ফোনের চুক্তির নাম নয়, অ্যাপ চিনবে না।');

        $this->as($keeper);
        $this->opensBeforeItIsGone($note, route('documents.show', $document));

        DB::table($document->getTable())->where('id', $document->id)->update(['deleted_at' => now()]);
        $this->assertNull(Document::acrossBranches()->find($document->id), 'প্রস্তুতিটাই ভুল — কাগজটা মোছেনি।');

        $this->saysItIsGone($note);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** মোছার আগে — খবর কাগজেই পাঠায়; তারপর খবরটা আবার না-পড়া, যাতে পরের খোলাটা নিজে পড়া বসায় */
    private function opensBeforeItIsGone(Notification $note, string $paper): void
    {
        $opened = $this->call('GET', route('notifications.open', $note));
        $this->assertSame(302, $opened->status(), 'প্রস্তুতিটাই ভুল — মোছার আগেই খবর কাগজে পাঠায় না।');
        $this->assertSame($paper, $opened->headers->get('Location'), 'প্রস্তুতিটাই ভুল — মোছার আগে খবর অন্য ঠিকানায় পাঠায়।');

        Notification::query()->whereKey($note->id)->update(['read_at' => null]);
        $this->assertNull($note->fresh()->read_at, 'প্রস্তুতিটাই ভুল — খবরটা আবার না-পড়া হয়নি।');
    }

    private function saysItIsGone(Notification $note): void
    {
        $opened = $this->call('GET', route('notifications.open', $note));
        $this->assertSame(200, $opened->status(),
            '⛔ মোছা কাগজের খবর খুলতে গিয়ে পাতা ভাঙল, ৪০৪ দিল, বা মোছা কাগজের দিকে পাঠাল।');

        $html = (string) $opened->getContent();
        $this->assertStringContainsString('কাগজটা আর নেই', $html, '⛔ মোছা কাগজের খবর "কাগজটা আর নেই" বলে না।');
        $this->assertStringNotContainsString(trans('core.notify.no_access_title', [], 'bn'), $html,
            '⛔ মোছা কাগজের খবর "নাগালে নেই" বলে — মানুষ অনুমতি চাইতে যাবেন, অথচ খোলার মতো কিছুই নেই।');
        $this->assertNotNull($note->fresh()->read_at, '⛔ মোছা কাগজের খবর খুলে পড়া হিসেবে বসেনি — ঘণ্টায় ঝুলে থাকে।');
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh());
        $this->useCompany();
        app(DataScope::class)->forget();
    }
}
