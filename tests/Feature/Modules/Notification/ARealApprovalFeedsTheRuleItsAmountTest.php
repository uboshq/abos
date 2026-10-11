<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationEvent;
use App\Models\NotificationRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ আসল অনুমোদন নিয়মকে নিজের টাকা জানায় — তাই "এক লাখের বেশি ফেরত গেলে মালিককেও জানাও" সত্যিই খাটে
 * (মালিকের স্পেক §৮ "Conditions: Amount"; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩-এর অনুসরণ)।
 *
 * ⭐ দাবি:
 *   · অনুমোদন ইঞ্জিনের ফেরত-খবর টাকা, স্তর আর ফল নিয়ে যায় — ঘটনায় লেখা থাকে
 *   · এক লাখের বেশি হলে নিয়মের মানুষ পান, কম হলে পান না; অনুরোধকারী দুইবারই পান
 */
final class ARealApprovalFeedsTheRuleItsAmountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_rejection_carries_its_amount_into_the_rule(): void
    {
        $company = Company::create(['code' => 'NR', 'name_en' => 'Rule Co']);
        CompanyContext::set($company->id);

        [$salesman, $manager, $owner] = array_map(function (string $name) use ($company) {
            $user = User::create(['name' => $name, 'email' => strtolower($name).'@t.test', 'password' => 'x', 'is_active' => true]);
            $user->companies()->attach($company->id, ['is_active' => true]);

            return $user;
        }, ['Salesman', 'Manager', 'Owner']);

        $flow = ApprovalFlow::create(['module' => 'sales', 'action' => 'discount']);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $manager->id]);

        NotificationRule::query()->create([
            'name' => 'বড় ফেরত মালিককে', 'module' => 'approval', 'event' => 'approval.rejected', 'is_active' => true, 'version' => 1,
            'conditions' => [['field' => 'amount', 'op' => 'gt', 'value' => '100000']],
            'recipients' => ['users' => [$owner->id]],
        ]);

        $engine = app(ApprovalEngine::class);
        $big = $engine->request(Branch::create(['code' => 'B1', 'name_en' => 'Doc']), 'sales', 'discount', '150000', userId: $salesman->id);
        $small = $engine->request(Branch::create(['code' => 'B2', 'name_en' => 'Doc']), 'sales', 'discount', '500', userId: $salesman->id);

        $this->actingAs($manager);
        $engine->reject($big, $manager, 'বেশি');
        $engine->reject($small, $manager, 'বেশি');

        $events = NotificationEvent::query()->where('type', 'approval.rejected')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame('1,50,000.00', $events[0]->data['amount'] ?? null, '⛔ অনুমোদনের খবর টাকা নিয়ে গেল না');
        $this->assertSame('rejected', $events[0]->data['status'] ?? null);
        $this->assertSame([NotificationRule::query()->value('id')], $events[0]->rule_ids, 'বড় অঙ্কে নিয়ম খাটল বলে লেখা থাকে');
        $this->assertNull($events[1]->rule_ids);

        $this->assertSame(2, Notification::query()->where('user_id', $salesman->id)->count(), 'অনুরোধকারী দুইবারই পান');
        $this->assertSame(1, Notification::query()->where('user_id', $owner->id)->count(), '⛔ টাকার শর্তে নিয়ম ঠিকমতো খাটল না');
        $this->assertSame($events[0]->id, (int) Notification::query()->where('user_id', $owner->id)->value('event_id'));
    }
}
