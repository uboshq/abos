<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Messaging;

use App\Models\Company;
use App\Modules\Messaging\Models\Member;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\Thread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Messenger phone doors — threads (contract §0–§2): the key, direct threads, the role allow-list, groups, the list
 * filters, presence and the company wall.
 *
 * ⭐ Every refusal is claimed beside its "yes": the same door opens for the right person, so a 403/404 means the wall,
 * not a broken door.
 */
final class MessengerThreadsTest extends TestCase
{
    use MessengerKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMessenger();
    }

    // ── the key ──────────────────────────────────────────────────────────

    public function test_without_messaging_use_the_list_door_is_403_and_with_it_200(): void
    {
        $nobody = $this->person('No Key', use: false);
        $friend = $this->person('Friend');

        $this->phone($nobody, 'GET', '/api/v1/messenger/threads')->assertForbidden();
        $this->phone($nobody, 'POST', '/api/v1/messenger/direct', ['person' => (string) $friend->id])->assertForbidden();
        $this->assertSame(0, Thread::query()->count(), '⛔ চাবি ছাড়াই কথোপকথন তৈরি হয়েছে / a thread was made without messaging.use');

        $this->grant($nobody, ['messaging.use']);

        $this->phone($nobody, 'GET', '/api/v1/messenger/threads')->assertOk()->assertJsonPath('unread_total', 0);
    }

    // ── direct ───────────────────────────────────────────────────────────

    public function test_direct_is_created_once_then_the_same_thread_comes_back(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');

        $first = $this->phone($a, 'POST', '/api/v1/messenger/direct', ['person' => (string) $b->id])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'direct')
            ->assertJsonPath('data.person.id', (string) $b->id)
            ->json('data.id');

        $again = $this->phone($a, 'POST', '/api/v1/messenger/direct', ['person' => (string) $b->id])->assertOk()->json('data.id');
        $back = $this->phone($b, 'POST', '/api/v1/messenger/direct', ['person' => (string) $a->id])->assertOk()->json('data.id');

        $this->assertSame($first, $again, '⛔ একই জোড়ার দ্বিতীয় কথোপকথন / a second direct thread for the same pair');
        $this->assertSame($first, $back, '⛔ উল্টো দিক থেকে আলাদা কথোপকথন / the other side got another thread');
        $this->assertSame(1, Thread::query()->count());
        $this->assertSame(2, Member::query()->count());
    }

    public function test_a_third_person_cannot_read_write_or_list_a_direct_thread(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $c = $this->person('Chhoton');
        $thread = $this->direct($a, $b);
        $this->say($a, $thread, 'only for Bina');

        /* ⓘ the members open it — the 404 below is the wall, not a broken door */
        $this->phone($b, 'GET', "/api/v1/messenger/threads/{$thread}/messages")->assertOk();

        $this->phone($c, 'GET', "/api/v1/messenger/threads/{$thread}/messages")->assertNotFound();
        $this->phone($c, 'GET', "/api/v1/messenger/threads/{$thread}")->assertNotFound();
        $this->phone($c, 'POST', "/api/v1/messenger/threads/{$thread}/messages", ['kind' => 'text', 'text' => 'hi'])->assertNotFound();
        $this->phone($c, 'GET', "/api/v1/messenger/threads/{$thread}/members")->assertNotFound();
        $this->phone($c, 'POST', "/api/v1/messenger/threads/{$thread}/read", ['up_to' => 1])->assertNotFound();
        $this->phone($c, 'POST', "/api/v1/messenger/threads/{$thread}/typing")->assertNotFound();
        $this->phone($c, 'GET', "/api/v1/messenger/threads/{$thread}/media")->assertNotFound();
        $this->phone($c, 'GET', "/api/v1/messenger/threads/{$thread}/search?q=Bina")->assertNotFound();

        $this->assertNotContains($thread, $this->threadIds($c), '⛔ বাইরের মানুষের তালিকায় অন্যের কথোপকথন / an outsider lists the thread');
        $this->assertSame(1, Message::query()->count(), '⛔ বাইরের মানুষ বার্তা লিখে ফেলেছে / the outsider wrote into the thread');
    }

    public function test_no_one_opens_a_direct_thread_with_themselves(): void
    {
        $a = $this->person('Asif');

        $this->phone($a, 'POST', '/api/v1/messenger/direct', ['person' => (string) $a->id])
            ->assertForbidden()->assertJsonPath('error', 'may_not_talk');
    }

    // ── the role allow-list ──────────────────────────────────────────────

    public function test_the_allow_list_walls_starting_but_not_answering(): void
    {
        $sales = $this->person('Field Seller', role: 'Field Sales');
        $manager = $this->person('Manager Man', role: 'Manager');
        $accountant = $this->person('Account Ant', role: 'Accountant');

        /* ⓘ opened while the list is still empty (everyone may talk) */
        $managersThread = $this->direct($manager, $accountant);

        $this->allowList(['Field Sales' => ['Manager']]);

        $refused = $this->phone($sales, 'POST', '/api/v1/messenger/direct', ['person' => (string) $accountant->id])
            ->assertForbidden()->assertJsonPath('error', 'may_not_talk');
        $this->assertNotSame('', (string) $refused->json('message'), 'the refusal has no sentence for the app to show');

        $this->phone($sales, 'POST', '/api/v1/messenger/direct', ['person' => (string) $manager->id])->assertCreated();

        /* ⭐ the Accountant is in no rule, yet answers inside the thread the Manager opened */
        $this->say($accountant, $managersThread, 'জি স্যার');
        $this->assertSame($managersThread,
            $this->phone($accountant, 'POST', '/api/v1/messenger/direct', ['person' => (string) $manager->id])->assertOk()->json('data.id'),
            '⛔ উত্তর দেওয়ার পথ বন্ধ / the Accountant could not reach the existing thread');

        /* ⛔ but may not START with anyone */
        $this->phone($accountant, 'POST', '/api/v1/messenger/direct', ['person' => (string) $sales->id])
            ->assertForbidden()->assertJsonPath('error', 'may_not_talk');

        $people = array_column($this->phone($sales, 'GET', '/api/v1/messenger/people')->assertOk()->json('data'), 'id');
        $this->assertContains((string) $manager->id, $people);
        $this->assertNotContains((string) $accountant->id, $people, '⛔ নিষিদ্ধ মানুষ "নতুন বার্তা"-র তালিকায় / the people list offers a walled person');
    }

    // ── groups ───────────────────────────────────────────────────────────

    public function test_a_group_needs_its_own_key_and_starts_with_a_system_line(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $c = $this->person('Chhoton');

        $this->phone($a, 'POST', '/api/v1/messenger/groups', ['title' => 'Depot', 'people' => [(string) $b->id, (string) $c->id]])
            ->assertForbidden()->assertJsonPath('error', 'group_create_denied');
        $this->assertSame(0, Thread::query()->count());

        $this->grant($a, ['messaging.group.create']);

        $created = $this->phone($a, 'POST', '/api/v1/messenger/groups', ['title' => 'Depot', 'people' => [(string) $b->id, (string) $c->id]])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'group')
            ->assertJsonPath('data.title', 'Depot')
            ->assertJsonPath('data.member_count', 3)
            ->json('data.id');

        $rows = $this->fetch($b, $created)['data'];
        $this->assertCount(1, $rows);
        $this->assertSame('system', $rows[0]['kind']);
        $this->assertStringContainsString('Depot', $rows[0]['text'], 'the group line does not name the group');
        $this->assertNull($rows[0]['sender']);

        $members = $this->phone($b, 'GET', "/api/v1/messenger/threads/{$created}/members")->assertOk()->json('data');
        $admins = array_column(array_filter($members, fn (array $m): bool => $m['admin'] === true), 'id');
        $this->assertSame([(string) $a->id], array_values($admins), 'only the maker is the admin');
    }

    public function test_only_the_group_admin_adds_and_the_newcomer_inherits_no_unread_history(): void
    {
        $a = $this->person('Asif', ['messaging.group.create']);
        $b = $this->person('Bina');
        $d = $this->person('Dipu');
        $thread = $this->group($a, 'Depot', [$b]);
        $old = $this->say($a, $thread, 'old one');
        $this->say($a, $thread, 'old two');

        $this->phone($b, 'POST', "/api/v1/messenger/threads/{$thread}/members", ['people' => [(string) $d->id]])
            ->assertForbidden()->assertJsonPath('error', 'forbidden');
        $this->phone($d, 'GET', "/api/v1/messenger/threads/{$thread}/messages")->assertNotFound();

        $this->phone($a, 'POST', "/api/v1/messenger/threads/{$thread}/members", ['people' => [(string) $d->id]])
            ->assertOk()->assertJsonPath('data.member_count', 3);

        $rows = $this->fetch($a, $thread)['data'];
        $last = end($rows);
        $this->assertSame('system', $last['kind'], '⛔ যোগ করার লাইন নেই / no "members_added" line');
        $this->assertStringContainsString('Dipu', $last['text']);

        /* ⭐ the newcomer starts at the present — the two old messages are not "unread" for them */
        $row = $this->threadRow($d, $thread);
        $this->assertNotNull($row, 'the newcomer does not see the group');
        $this->assertSame(0, $row['unread'],
            '⛔ নতুন সদস্যের কাছে পুরনো বার্তা অপঠিত / the newcomer inherited unread history (system lines never count as unread)');
        $mark = Member::query()->where('user_id', $d->id)->firstOrFail();
        $this->assertGreaterThanOrEqual((int) $old['id'] + 1, $mark->read_id, 'the newcomer\'s read mark is behind the old history');
    }

    /**
     * ⓘ The maker's own "created the group" line — a system line has no sender, so it would count as unread for the
     * person who just made the group. WhatsApp shows the maker nothing unread.
     */
    public function test_the_maker_of_a_group_has_nothing_unread_in_it(): void
    {
        $a = $this->person('Asif', ['messaging.group.create']);
        $b = $this->person('Bina');
        $thread = $this->group($a, 'Depot', [$b]);

        $this->assertSame(0, $this->threadRow($a, $thread)['unread'] ?? null,
            '⛔ নিজের বানানো গ্রুপে নিজের কাছেই অপঠিত / the maker sees their own group as unread');
    }

    // ── list filters ─────────────────────────────────────────────────────

    public function test_the_list_filters_groups_unread_papers_and_search(): void
    {
        $a = $this->person('Asif', ['messaging.group.create']);
        $b = $this->person('Bina');
        $c = $this->person('Chhoton');

        $withB = $this->direct($a, $b);
        $this->say($b, $withB, 'dal er dam koto');

        $withC = $this->direct($a, $c);
        $thread = Thread::query()->wherePublicId($withC)->firstOrFail();
        /* ⓘ a paper line written straight in — the filter asks only "is there a paper message" */
        Message::query()->create(['thread_id' => $thread->id, 'sender_id' => $a->id, 'kind' => 'paper',
            'paper_type' => 'SalesInvoice', 'paper_id' => 'no-such-paper', 'paper_no' => 'INV-0517']);

        $group = $this->group($a, 'Depot Team', [$b, $c]);
        /* ⓘ the group's own system line is read, so only Bina's line is unread for Asif */
        $this->phone($a, 'POST', "/api/v1/messenger/threads/{$group}/read", ['up_to' => 999999999])->assertNoContent();

        $this->assertEqualsCanonicalizing([$withB, $withC, $group], $this->threadIds($a));
        $this->assertSame([$group], $this->threadIds($a, 'filter=groups'), 'filter=groups');
        $this->assertSame([$withB], $this->threadIds($a, 'filter=unread'), 'filter=unread');
        $this->assertSame([$withC], $this->threadIds($a, 'filter=papers'), 'filter=papers');
        $this->assertSame([$group], $this->threadIds($a, 'q=Depot'), 'q by group title');
        $this->assertSame([$withB], $this->threadIds($a, 'q=dal'), 'q by message text');
        $this->assertEqualsCanonicalizing([$withC, $group], $this->threadIds($a, 'q=Chhoton'), 'q by member name');

        $this->phone($a, 'GET', '/api/v1/messenger/threads?filter=everything')->assertUnprocessable();
    }

    // ── presence ─────────────────────────────────────────────────────────

    public function test_online_means_a_messenger_door_was_called_in_the_last_minute(): void
    {
        $a = $this->person('Asif');
        $b = $this->person('Bina');

        $this->phone($b, 'GET', '/api/v1/messenger/threads')->assertOk();

        $people = $this->phone($a, 'GET', '/api/v1/messenger/people')->assertOk()->json('data');
        $this->assertTrue($this->byId($people, (string) $b->id)['online'] ?? null, '⛔ এইমাত্র এসেছেন, অথচ অনলাইন নন / just seen, not online');

        $this->travel(2)->minutes();

        $people = $this->phone($a, 'GET', '/api/v1/messenger/people')->assertOk()->json('data');
        $this->assertFalse($this->byId($people, (string) $b->id)['online'] ?? null, '⛔ দুই মিনিট পরেও অনলাইন / still online after 2 minutes');
    }

    // ── the group, and the wall that stays ─────────────────────────────────

    /**
     * ⭐ Owner, 23–24 Nov 2026: "যে কোনো কোম্পানি থেকে যে কোনো ব্রাঞ্চে গ্রুপ অফ কোম্পানিতে" — a colleague of a sister company is
     * reachable (found, written to, called). ⛔ The wall that stays is the person: someone outside a chat never sees it.
     * ⓘ This claim used to say the opposite (a person of another company is never reached) — the owner's decision replaced it.
     */
    public function test_a_person_of_a_sister_company_is_reached_but_never_sees_a_chat_they_are_not_in(): void
    {
        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        $a = $this->person('Asif');
        $b = $this->person('Bina');
        $x = $this->person('Xenia Elsewhere', company: $other);

        $thread = $this->direct($a, $b);
        $this->say($a, $thread, 'TDEPOT only');

        $people = array_column($this->phone($a, 'GET', '/api/v1/messenger/people')->assertOk()->json('data'), 'id');
        $this->assertContains((string) $x->id, $people, '⛔ বোন-কোম্পানির সহকর্মী তালিকায় নেই / the sister company colleague is not listed');

        $this->phone($a, 'POST', '/api/v1/messenger/direct', ['person' => (string) $x->id])->assertSuccessful();

        /* ⛔ Xenia is not in Asif ↔ Bina — she never sees it, whichever company is open */
        $this->phone($x, 'GET', "/api/v1/messenger/threads/{$thread}/messages")->assertNotFound();
        $this->assertNotContains($thread, array_column($this->phone($x, 'GET', '/api/v1/messenger/threads')->assertOk()->json('data'), 'id'),
            '⛔ a chat Xenia is not in shows in her list');
    }
}
