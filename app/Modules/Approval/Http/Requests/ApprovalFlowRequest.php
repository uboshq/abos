<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Requests;

use App\Models\ApprovalCondition;
use App\Models\ApprovalFlowStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * অনুমোদনের ছকের ইনপুট।
 *
 * মডিউল ও কাজের নাম এখানে কেবল আকারে যাচাই হয়; ওগুলো সত্যিই কোনো
 * মডিউলের ঘোষিত কাজ কি না সেটা সেবা স্তর দেখে — কারণ তালিকাটা
 * রেজিস্ট্রি থেকে আসে, আর সেটা ব্যবসার নিয়ম, ফর্মের নিয়ম নয়।
 */
class ApprovalFlowRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'module' => ['required', 'string', 'max:64'],
            'action' => ['required', 'string', 'max:64'],
            'document_type' => ['nullable', 'string', 'max:64'],

            /*
             * সীমা ঐচ্ছিক, কিন্তু ঋণাত্মক নয়।
             *
             * ঋণাত্মক সীমা মানে "সব ক্ষেত্রেই লাগবে", যেটা খালি রেখেই
             * বলা যায় — দুইভাবে একই কথা বলার সুযোগ থাকলে একদিন দুইটা
             * আলাদা আচরণ করে।
             */
            'threshold_amount' => ['nullable', 'numeric', 'min:0'],

            /*
             * নিয়মটা কেন বসানো — ঐচ্ছিক, কিন্তু সীমা বাঁধা।
             *
             * ⓘ ৫০০ অক্ষর কলামের সমান। ⚠️ যাচাই না বসালে লম্বা লেখা
             * ডেটাবেজে গিয়ে কাটা পড়ত, আর মানুষ সংরক্ষণের পরেই দেখতেন
             * তাঁর শেষ বাক্যটা নেই — কোনো ত্রুটি ছাড়াই।
             */
            'remarks' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],

            'steps' => ['required', 'array', 'min:1'],
            'steps.*.level' => ['required', 'integer', 'min:1', 'max:9'],
            // ⓘ "সুপারভাইজার", "সিইও" — নাম না দিলে পর্দা নম্বরই দেখায়
            'steps.*.step_name' => ['nullable', 'string', 'max:64'],
            'steps.*.approver_type' => ['required', Rule::in([ApprovalFlowStep::BY_ROLE, ApprovalFlowStep::BY_USER])],
            'steps.*.approver_id' => ['required', 'integer', 'min:1'],
            'steps.*.requires_all' => ['nullable', 'boolean'],

            /*
             * ⭐ ঘড়ি — ২৪ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ `nullable` আর `min:1` — দুইটাই লাগে। ⛔ `0` ঢুকতে
             * দিলে সেটা *"সাথে সাথে দেরি"* মানে দাঁড়াত, আর প্রতিটা
             * কাগজ জন্মেই লাল হত — খালি রাখাই *"ঘড়ি নেই"* বলার
             * একমাত্র পথ।
             */
            'steps.*.sla_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'steps.*.warn_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'steps.*.escalate_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'steps.*.escalate_to_type' => ['nullable', Rule::in([ApprovalFlowStep::BY_ROLE, ApprovalFlowStep::BY_USER])],
            'steps.*.escalate_to_id' => ['nullable', 'integer', 'min:1'],

            /*
             * ⭐ এক ধাপে কয়জনের সই — ডিফল্ট ১।
             *
             * ⚠️ `required` নয়: পুরনো সারিগুলোতে ঘরটা নেই, আর
             * বাধ্য করলে পুরনো প্রবাহ সংরক্ষণই করা যেত না।
             */
            'steps.*.min_approvals' => ['nullable', 'integer', 'min:1', 'max:9'],

            /*
             * ⭐ টাকার অঙ্ক ছাড়াও শর্ত — ধাপ ৩।
             *
             * ⓘ ঘরের নামটা হাতে লেখা হয়, কারণ প্রতিটা মডিউল
             * আলাদা ঘর পাঠায়। ⛔ তাই ভুল নাম লেখা সম্ভব, আর সেটা
             * **নীরব** — প্রবাহটা কখনো ধরত না। ⓘ সেই ফাঁকটা
             * [[ApprovalExceptions::flowsThatCanNeverCatch()]] ধরে।
             */
            'conditions' => ['nullable', 'array'],
            'conditions.*.field' => ['required', 'string', 'max:64'],
            'conditions.*.operator' => ['required', Rule::in(ApprovalCondition::OPERATORS)],
            'conditions.*.value' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * ফাঁকা সারিগুলো বাদ দিয়ে স্তরগুলো।
     *
     * @return list<array<string, mixed>>
     */
    public function steps(): array
    {
        return $this->validated()['steps'] ?? [];
    }

    /**
     * ⭐ শর্তগুলো — খালি সারি ছাড়া।
     *
     * @return list<array<string, mixed>>
     */
    public function conditions(): array
    {
        return $this->validated()['conditions'] ?? [];
    }

    /**
     * ⓘ খালি ঘর মানে `null` — শূন্য নয়।
     *
     * ⚠️ একটা আলাদা মেথড, কারণ চারটা ঘরে একই প্রশ্ন, আর
     * চার জায়গায় লিখলে একটায় ভুল হত — আর সেটা নীরব।
     */
    private static function positiveOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '' || (int) $value < 1) ? null : (int) $value;
    }

    /**
     * ফর্মের "role|3" ভেঙে দুইটা ঘরে, আর না-ছোঁয়া সারিগুলো বাদ।
     *
     * ── কেন ঘর দুইটা জোড়া লাগিয়ে পাঠানো হয় ─────────────────────────
     * ধরন ও ব্যক্তি আলাদা দুইটা ড্রপডাউন হলে "ধরন: রোল, অনুমোদনকারী:
     * রফিক" এমন অসম্ভব জোড়া বাছা যেত — আর সেটা সংরক্ষিতও হয়ে যেত,
     * কারণ দুইটা ঘরই আলাদাভাবে বৈধ। তখন ছকটা কাউকেই মেলাত না, আর
     * অনুরোধগুলো চিরকাল ঝুলে থাকত।
     *
     * ── কেন খালি সারি ছাঁকা হয় ──────────────────────────────────────
     * ফর্মে সবসময় কয়েকটা খালি সারি থাকে। না ছাঁকলে "অনুমোদনকারী
     * বাছুন" বলে ভ্যালিডেশন আটকাত, অথচ ব্যবহারকারী ওই সারিগুলো ছুঁয়েও
     * দেখেননি।
     */
    protected function prepareForValidation(): void
    {
        $steps = [];

        foreach ($this->input('steps', []) as $step) {
            $approver = (string) ($step['approver'] ?? '');

            if (! str_contains($approver, '|')) {
                continue;
            }

            [$type, $id] = explode('|', $approver, 2);

            if ($id === '') {
                continue;
            }

            /*
             * ⓘ গন্তব্যও একটাই ঘরে ("role|3"), সইকারীর মতোই।
             * ⛔ আলাদা রাখলে *"ধরন: রোল, ব্যক্তি: রফিক"* এমন অসম্ভব
             * জোড়া সংরক্ষিত হয়ে যেত, আর কাগজ কারো কাছে যেত না।
             */
            $target = (string) ($step['escalate_to'] ?? '');
            [$targetType, $targetId] = str_contains($target, '|')
                ? explode('|', $target, 2)
                : [null, null];

            $steps[] = [
                'level' => $step['level'] ?? 1,
                'step_name' => $step['step_name'] ?? null,
                'approver_type' => $type,
                'approver_id' => $id,
                'requires_all' => ! empty($step['requires_all']),

                /*
                 * ⚠️ খালি ঘর মানে `null`, `0` নয়।
                 *
                 * ⓘ HTML খালি ঘর ফাঁকা লেখা পাঠায়, আর সেটাকে
                 * সংখ্যা করলে `0` হয়। ⛔ তাহলে *"ঘড়ি নেই"* বলার কোনো পথ
                 * থাকত না — একটা পুরনো প্রবাহ সংরক্ষণ করলেই তার সব
                 * কাগজ সঙ্গে সঙ্গে দেরি হয়ে যেত।
                 */
                'sla_hours' => self::positiveOrNull($step['sla_hours'] ?? null),
                'warn_hours' => self::positiveOrNull($step['warn_hours'] ?? null),
                'escalate_hours' => self::positiveOrNull($step['escalate_hours'] ?? null),
                'escalate_to_type' => $targetType ?: null,
                'escalate_to_id' => ($targetId ?? '') !== '' ? $targetId : null,
                'min_approvals' => self::positiveOrNull($step['min_approvals'] ?? null),
            ];
        }

        /*
         * ⓘ শর্তের খালি সারিগুলোও ছাঁকা হয় — ধাপের মতোই।
         * ⛔ না ছাঁকলে *"ঘরের নাম দিন"* বলে ফর্ম আটকাত, অথচ
         * ব্যবহারকারী ওই সারিগুলো ছুঁয়েও দেখেননি।
         */
        $conditions = [];

        foreach ($this->input('conditions', []) as $condition) {
            $field = trim((string) ($condition['field'] ?? ''));
            $value = trim((string) ($condition['value'] ?? ''));

            if ($field === '' || $value === '') {
                continue;
            }

            $conditions[] = [
                'field' => $field,
                'operator' => (string) ($condition['operator'] ?? '>'),
                'value' => $value,
            ];
        }

        $this->merge(['steps' => $steps, 'conditions' => $conditions]);
    }
}
