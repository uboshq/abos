<?php

declare(strict_types=1);

namespace App\Modules\Approval\Services;

use App\Core\Module\ModuleRegistry;
use App\Models\Approval;
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
    public function create(array $data, array $steps): ApprovalFlow
    {
        $this->assertKnownAction((string) $data['module'], (string) $data['action']);
        $this->assertSteps($steps);
        $this->assertNotDuplicated($data);

        return DB::transaction(function () use ($data, $steps) {
            $flow = ApprovalFlow::create([
                'module' => $data['module'],
                'action' => $data['action'],
                // "সব ধরনে" একটা আসল মান, অনুপস্থিতি নয় — নাহলে unique
                // index দুইটা একই ছক আটকাতে পারত না
                'document_type' => $data['document_type'] ?? '',
                'threshold_amount' => $data['threshold_amount'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->replaceSteps($flow, $steps);

            return $flow->fresh('steps');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{level: mixed, approver_type: mixed, approver_id: mixed, requires_all?: mixed}>  $steps
     */
    public function update(ApprovalFlow $flow, array $data, array $steps): ApprovalFlow
    {
        $this->assertKnownAction((string) $data['module'], (string) $data['action']);
        $this->assertSteps($steps);
        $this->assertNotDuplicated($data, $flow);

        return DB::transaction(function () use ($flow, $data, $steps) {
            $flow->update([
                'module' => $data['module'],
                'action' => $data['action'],
                // "সব ধরনে" একটা আসল মান, অনুপস্থিতি নয় — নাহলে unique
                // index দুইটা একই ছক আটকাতে পারত না
                'document_type' => $data['document_type'] ?? '',
                'threshold_amount' => $data['threshold_amount'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->replaceSteps($flow, $steps);

            return $flow->fresh('steps');
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
         * পুরোটা বদলে বসানো, সারি ধরে ধরে মেলানো নয়।
         *
         * ছক সাজানোর পর্দায় মানুষ স্তর যোগ করেন, সরান, ক্রম বদলান।
         * সারি মেলাতে গেলে "কোন সারিটা কোনটা" ঠিক করতে হত, আর একটা
         * ভুল মিলে দুই স্তরের অনুমোদনকারী উল্টে যেত — যেটা কেউ খেয়াল
         * করত না, কারণ সংখ্যা দুইটাই ঠিক থাকত।
         */
        $flow->steps()->delete();

        foreach ($steps as $step) {
            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'level' => (int) $step['level'],
                'approver_type' => $step['approver_type'],
                'approver_id' => (int) $step['approver_id'],
                'requires_all' => (bool) ($step['requires_all'] ?? false),
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
