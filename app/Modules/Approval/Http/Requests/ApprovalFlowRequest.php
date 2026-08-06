<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Requests;

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
            'is_active' => ['nullable', 'boolean'],

            'steps' => ['required', 'array', 'min:1'],
            'steps.*.level' => ['required', 'integer', 'min:1', 'max:9'],
            'steps.*.approver_type' => ['required', Rule::in([ApprovalFlowStep::BY_ROLE, ApprovalFlowStep::BY_USER])],
            'steps.*.approver_id' => ['required', 'integer', 'min:1'],
            'steps.*.requires_all' => ['nullable', 'boolean'],
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

            $steps[] = [
                'level' => $step['level'] ?? 1,
                'approver_type' => $type,
                'approver_id' => $id,
                'requires_all' => ! empty($step['requires_all']),
            ];
        }

        $this->merge(['steps' => $steps]);
    }
}
