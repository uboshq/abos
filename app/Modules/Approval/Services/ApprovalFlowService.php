<?php

declare(strict_types=1);

namespace App\Modules\Approval\Services;

use App\Core\Module\ModuleRegistry;
use App\Models\Approval;
use App\Models\ApprovalCondition;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * অনুমোদনের ছক — কোন কাজে, কত টাকার উপরে, কে।
 */
class ApprovalFlowService
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * কোন মডিউলের কোন কাজে অনুমোদন বসানো যায় — রেজিস্ট্রি থেকে।
     *
     * @return array<string, array{label: string, actions: array<string, string>}>
     */
    public function choices(): array
    {
        $choices = [];

        foreach ($this->modules->all() as $module) {
            if ($module->approvals === []) {
                continue;
            }

            $choices[$module->code] = [
                'label' => $module->label(),
                'actions' => $module->approvals,
            ];
        }

        return $choices;
    }

    /**
     * "module.action" => ব্যবহারকারীর ভাষায় কাজটার নাম।
     *
     * পর্দাগুলো এটা দিয়েই "sales · discount"-কে "বিক্রয়ে ছাড়" বানায়।
     * অনুরোধের সারিতে মডিউল ও কাজ কাঁচা নামে বসে থাকে (কলামটা তাই),
     * আর নামটা কেবল মডিউলই জানে।
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        $labels = [];

        foreach ($this->choices() as $module => $entry) {
            foreach ($entry['actions'] as $action => $key) {
                $labels[$module.'.'.$action] = __($key);
            }
        }

        return $labels;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{level: mixed, approver_type: mixed, approver_id: mixed, requires_all?: mixed}>  $steps
     */
    public function create(array $data, array $steps, array $conditions = []): ApprovalFlow
    {
        $this->assertKnownAction((string) $data['module'], (string) $data['action']);
        $this->assertSteps($steps);
        $this->assertNotDuplicated($data);

        return DB::transaction(function () use ($data, $steps, $conditions) {
            $flow = ApprovalFlow::create([
                'module' => $data['module'],
                'action' => $data['action'],
                // "সব ধরনে" একটা আসল মান, অনুপস্থিতি নয় — নাহলে unique
                // index দুইটা একই ছক আটকাতে পারত না
                'document_type' => $data['document_type'] ?? '',
                'threshold_amount' => $data['threshold_amount'] ?? null,
                'remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->replaceSteps($flow, $steps);
            $this->replaceConditions($flow, $conditions);

            return $flow->fresh(['steps', 'conditions']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{level: mixed, approver_type: mixed, approver_id: mixed, requires_all?: mixed}>  $steps
     */
    public function update(ApprovalFlow $flow, array $data, array $steps, array $conditions = []): ApprovalFlow
    {
        $this->assertKnownAction((string) $data['module'], (string) $data['action']);
        $this->assertSteps($steps);
        $this->assertNotDuplicated($data, $flow);

        return DB::transaction(function () use ($flow, $data, $steps, $conditions) {
            $flow->update([
                'module' => $data['module'],
                'action' => $data['action'],

                /*
                 * ⛔ অনুপস্থিত চাবি মানে *"বদলানো হয়নি"*, *"মুশে দাও"* নয়।
                 *
                 * ── ⚠️ যা ভাঙা ছিল, ২৪ সেপ্টেম্বর ২০২৬ ────────────────
                 * লেখা ছিল `?? ''`, আর ফর্মে ঘরটা নেই। ⛔ তাই
                 * `document_type = 'SalesInvoice'` বসানো একটা ছক কেবল
                 * খুলে সংরক্ষণ করলেই *"সব ধরনে"* হয়ে যেত — অর্থাৎ
                 * ওই ছকটা হঠাৎ আরও অনেক কাগজ আটকাত।
                 *
                 * ⓘ ভুলটা সম্পূর্ণ নীরব: কোনো ত্রুটি নেই, পর্দায় কিছু
                 * বদলায় না। ⚠️ ফর্মে একটা লুকানো ঘরও বসেছে, কিন্তু
                 * পর্দা কখনো শেষ কথা নয় — তাই নিয়মটা এখানে।
                 */
                'document_type' => array_key_exists('document_type', $data)
                    ? ($data['document_type'] ?? '')
                    : $flow->document_type,
                'threshold_amount' => $data['threshold_amount'] ?? null,
                'remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
                // ⚠️ `code` ইচ্ছাকৃতভাবে বাদ — সংকেত একবার বসলে আর বদলায় না
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->replaceSteps($flow, $steps);
            $this->replaceConditions($flow, $conditions);

            return $flow->fresh(['steps', 'conditions']);
        });
    }

    /**
     * ছক মোছা।
     *
     * ── কেন অপেক্ষমাণ অনুরোধ থাকলে নয় ───────────────────────────────
     * ছকটা মুছে ফেললে যে অনুরোধগুলো এখনো ঝুলে আছে সেগুলোর আর কোনো
     * অনুমোদনকারী থাকত না — canDecide() সবসময় "না" বলত, আর অনুরোধগুলো
     * চিরকাল অপেক্ষমাণ থেকে যেত। কেউ বুঝতেও পারত না কেন।
     */
    public function delete(ApprovalFlow $flow): void
    {
        $pending = Approval::query()
            ->where('module', $flow->module)
            ->where('action', $flow->action)
            ->pending()
            ->count();

        if ($pending > 0) {
            throw ValidationException::withMessages([
                'flow' => __('approval::validation.flow_has_pending', ['count' => $pending]),
            ]);
        }

        $flow->delete();
    }

    /** @param list<array<string, mixed>> $steps */
    private function replaceSteps(ApprovalFlow $flow, array $steps): void
    {
        /*
         * পুরোটা বদলে বসানো, সারি ধরে ধরে মেলানো নয় — **এক জায়গা ছাড়া**।
         *
         * ছক সাজানোর পর্দায় মানুষ স্তর যোগ করেন, সরান, ক্রম বদলান।
         * সারি মেলাতে গেলে "কোন সারিটা কোনটা" ঠিক করতে হত, আর একটা
         * ভুল মিলে দুই স্তরের অনুমোদনকারী উল্টে যেত — যেটা কেউ খেয়াল
         * করত না, কারণ সংখ্যা দুইটাই ঠিক থাকত।
         *
         * ── ⛔ কী ভাঙা ছিল — অডিট §৩, ২৭ সেপ্টেম্বর ২০২৬ ─────────────
         * আগে এখানে `$flow->steps()->delete()` — একটা **কোয়েরি-মোছা**, যা
         * মডেলের ঘটনা ডাকে না। ⚠️ তাই [[ApprovalFlowStep]]-এ [[IsAudited]]
         * বসালেও পুরনো ধাপ নিঃশব্দে যেত, আর খাতায় উঠত কেবল নতুনটার
         * "তৈরি"। ⛔ *"এই ধাপে আগে কে সই দিতেন?"* — উত্তর কোথাও নেই।
         *
         * ── ⭐ এখন ─────────────────────────────────────────────────────
         * ⓘ যে স্তরে **আগেও ঠিক একজন, এখনও ঠিক একজন**, সেখানে "কোনটা
         * কোনটা" প্রশ্নের একটাই উত্তর — তাই সারিটা জায়গায় বদলায়, আর
         * খাতায় বসে *"approver_id ৫ → ৭"*। ⚠️ মেলানোর উপর কেবল খাতার
         * বর্ণনা নির্ভর করে, তথ্য নয়: বদলের পর সারির প্রতিটা ঘর হুবহু
         * যা পাঠানো হয়েছে তা-ই।
         *
         * ⓘ বাকি সব স্তরে আগের নিয়মই — মুছে নতুন করে। ⭐ তবে মোছা এখন
         * **একটা একটা করে**, তাই প্রতিটা পুরনো ধাপের "মোছা" খাতায় ওঠে।
         */
        $before = $flow->steps()->get()->groupBy(fn (ApprovalFlowStep $row) => (int) $row->level);
        $after = collect($steps)->groupBy(fn (array $step) => (int) $step['level']);

        $inPlace = [];
        $fresh = [];

        foreach ($after as $level => $atLevel) {
            $had = $before->get($level);

            if ($had !== null && $had->count() === 1 && $atLevel->count() === 1) {
                $inPlace[] = [$had->first(), $this->stepAttributes($flow, $atLevel->first())];

                continue;
            }

            foreach ($atLevel as $step) {
                $fresh[] = $this->stepAttributes($flow, $step);
            }
        }

        $kept = array_map(fn (array $pair) => (int) $pair[0]->id, $inPlace);

        /*
         * ⛔ ক্রমটা জরুরি: আগে মোছা, তারপর বদল, শেষে নতুন।
         *
         * ⓘ `approval_step_unique` = (ছক, স্তর, ধরন, আইডি)। পুরনো সারি
         * থাকতেই একই চাবির নতুন সারি বসালে গোটা সংরক্ষণ ভেঙে পড়ত।
         */
        $flow->steps()->whereKeyNot($kept)->get()
            ->each(fn (ApprovalFlowStep $gone) => $gone->delete());

        foreach ($inPlace as [$row, $attributes]) {
            $row->fill($attributes)->save();
        }

        foreach ($fresh as $attributes) {
            ApprovalFlowStep::create($attributes);
        }
    }

    /**
     * ⭐ একটা ধাপের ঘরগুলো — তৈরি আর জায়গায় বদল, দুই পথেই এই এক তালিকা।
     *
     * ⚠️ দুই জায়গায় আলাদা করে লিখলে একদিন একটায় নতুন ঘর বসত, অন্যটায়
     * নয় — আর জায়গায়-বদলের পথে ঐ ঘরটা নিঃশব্দে পুরনো মান রেখে দিত।
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function stepAttributes(ApprovalFlow $flow, array $step): array
    {
        return [
            'approval_flow_id' => $flow->id,
            'level' => (int) $step['level'],
            // ⓘ নাম ঐচ্ছিক — খালি দিলে পর্দা "ধাপ ২" দেখায়, আগের মতোই
            'step_name' => trim((string) ($step['step_name'] ?? '')) ?: null,
            'approver_type' => $step['approver_type'],
            'approver_id' => (int) $step['approver_id'],
            'requires_all' => (bool) ($step['requires_all'] ?? false),

            /*
             * ⚠️ `?? null` । `(int)` নয় — আর সেটাই সবটা।
             *
             * ⓘ এই ঘরগুলোতে `null` একটা **অর্থবহ মান**:
             * *"এই ধাপে ঘড়ি নেই"*। ⛔ শূন্য বসলে সেটা
             * *"সাথে সাথে দেরি"* হয়ে যেত, আর প্রতিটা পুরনো প্রবাহ
             * সংরক্ষণ করলেই তার কাগজগুলো জন্মেই লাল হত।
             */
            'sla_hours' => $step['sla_hours'] ?? null,
            'warn_hours' => $step['warn_hours'] ?? null,
            'escalate_hours' => $step['escalate_hours'] ?? null,
            'escalate_to_type' => $step['escalate_to_type'] ?? null,
            'escalate_to_id' => $step['escalate_to_id'] ?? null,

            /*
             * ⛔ এই একটায় `null` চলে না — উপরের পাঁচটার মতো নয়।
             *
             * ⓘ কলামটা `NOT NULL DEFAULT 1`। ⚠️ ডিফল্ট কেবল
             * তখনই খাটে যখন ঘরটা INSERT-এ **থাকেই না**; Eloquent
             * ঘরটা পাঠায়, তাই `null` পাঠানো মানে সরাসরি নিষেধাজ্ঞা
             * ভাঙা — আর কড়া sql_mode-এ সেটা সংরক্ষণই ফেলে দেয়।
             */
            'min_approvals' => $step['min_approvals'] ?? 1,
        ];
    }

    /**
     * ⭐ শর্তগুলো — পুরোটা বদলে বসানো, [[replaceSteps]]-এর মতোই।
     *
     * ⚠️ সারি ধরে মেলাতে গেলে *"কোন সারিটা কোনটা"* ঠিক করতে
     * হত, আর একটা ভুল মিলে দুই শর্তের মান উল্টে যেত — যেমন
     * *"ছাড় > ১০"* হয়ে যেত *"পরিমাণ > ১০"*। ⛔ দুইটাই বৈধ, তাই
     * কোথাও কিছু লাল হত না।
     *
     * @param  list<array<string, mixed>>  $conditions
     */
    private function replaceConditions(ApprovalFlow $flow, array $conditions): void
    {
        $flow->conditions()->delete();

        foreach ($conditions as $condition) {
            ApprovalCondition::create([
                'approval_flow_id' => $flow->id,
                'field' => $condition['field'],
                'operator' => $condition['operator'],
                'value' => $condition['value'],
            ]);
        }
    }

    /** @param list<array<string, mixed>> $steps */
    private function assertSteps(array $steps): void
    {
        if ($steps === []) {
            throw ValidationException::withMessages([
                'steps' => __('approval::validation.no_steps'),
            ]);
        }

        $seen = [];

        foreach ($steps as $step) {
            $key = $step['level'].'|'.$step['approver_type'].'|'.$step['approver_id'];

            if (in_array($key, $seen, true)) {
                throw ValidationException::withMessages([
                    'steps' => __('approval::validation.duplicate_step'),
                ]);
            }

            $seen[] = $key;
        }
    }

    /**
     * একই কাজে দুইটা ছক নয়।
     *
     * ── কেন এই পাহারাটা এখানেও, যদিও ডাটাবেজে unique index আছে ──────
     * index-টা শেষ কথা, কিন্তু তার বার্তাটা ব্যবহারকারীর জন্য নয় —
     * "Integrity constraint violation 1062" পড়ে কেউ বোঝেন না কী করতে
     * হবে। এখানে ধরলে বলা যায় "এই কাজের ছক আগে থেকেই আছে, সেটাই
     * সম্পাদনা করুন", আর সেটাই তিনি করতে চেয়েছিলেন।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNotDuplicated(array $data, ?ApprovalFlow $except = null): void
    {
        $exists = ApprovalFlow::query()
            ->where('module', $data['module'])
            ->where('action', $data['action'])
            ->where('document_type', $data['document_type'] ?? '')
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'module' => __('approval::validation.duplicate_flow'),
            ]);
        }
    }

    /**
     * কাজটা কোনো মডিউল সত্যিই ঘোষণা করেছে কি না।
     *
     * অঘোষিত কাজে ছক বসালে সেটা কখনো চলত না — কোনো মডিউল ওই নামে
     * অনুমোদন চাইত না, আর ছকটা পর্দায় থেকে যেত যেন কাজ করছে।
     */
    private function assertKnownAction(string $module, string $action): void
    {
        $choices = $this->choices();

        if (! isset($choices[$module]['actions'][$action])) {
            throw ValidationException::withMessages([
                'action' => __('approval::validation.unknown_action'),
            ]);
        }
    }
}
