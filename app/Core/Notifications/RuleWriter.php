<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Services\NotificationAudit;
use App\Core\Support\Actor;
use App\Core\Support\NotificationKinds;
use App\Models\NotificationChannel;
use App\Models\NotificationRule;
use App\Models\NotificationRuleVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ নিয়ম লেখা — যাচাই, সংরক্ষণ, সংস্করণ, নিরীক্ষা; পর্দা আর API দুইটাই এটাই ডাকে (মালিকের স্পেক §৮, §১২; ধাপ ৩)।
 *
 * ⓘ প্রতিটা সংরক্ষণে কিছু বদলালে সংস্করণ বাড়ে আর পুরো ছবি [[NotificationRuleVersion]]-এ; কাজটা নিরীক্ষার খাতায়।
 */
final class RuleWriter
{
    /** পর্দায় আর API-তে শর্তের সারি সর্বোচ্চ কয়টা */
    public const CONDITION_ROWS = 4;

    /**
     * নিয়মের ইনপুট যাচাই আর পরিষ্কার — ফাঁকা শর্ত বাদ, সংখ্যার ঘরে সংখ্যা, প্রাপক কেবল এই কোম্পানির।
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $choices = RecipientChoices::ids();

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'event' => ['required', Rule::in(array_keys(NotificationKinds::all()))],
            'branch_id' => ['nullable', Rule::in($choices['branches'])],
            'conditions' => ['nullable', 'array', 'max:'.self::CONDITION_ROWS],
            'conditions.*.field' => ['nullable', Rule::in(NotificationVariables::CONDITION_FIELDS)],
            'conditions.*.op' => ['nullable', Rule::in(NotificationRule::OPERATORS)],
            'conditions.*.value' => ['nullable', 'string', 'max:191'],
            'recipients' => ['nullable', 'array'],
            'recipients.users' => ['nullable', 'array'], 'recipients.users.*' => [Rule::in($choices['users'])],
            'recipients.roles' => ['nullable', 'array'], 'recipients.roles.*' => [Rule::in($choices['roles'])],
            'recipients.branches' => ['nullable', 'array'], 'recipients.branches.*' => [Rule::in($choices['branches'])],
            'recipients.departments' => ['nullable', 'array'], 'recipients.departments.*' => [Rule::in($choices['departments'])],
            'recipients.groups' => ['nullable', 'array'], 'recipients.groups.*' => [Rule::in($choices['groups'])],
            'recipients.responsible' => ['nullable', 'boolean'],
            'channels' => ['nullable', 'array'], 'channels.*' => [Rule::in(NotificationChannel::ALL)],
            'priority' => ['nullable', Rule::in(NotificationKinds::PRIORITIES)],
            'template_id' => ['nullable', Rule::in($choices['templates'])],
            'delay_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'expires_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'cooldown_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'is_active' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ])->validate();

        // ⓘ ফাঁকা শর্তের সারি বাদ; সংখ্যার ঘরে সংখ্যা ছাড়া কিছু নয়
        $conditions = [];

        foreach ((array) ($data['conditions'] ?? []) as $i => $c) {
            if (blank($c['field'] ?? null) || blank($c['op'] ?? null)) {
                continue;
            }

            $value = trim((string) ($c['value'] ?? ''));

            if (in_array($c['field'], NotificationVariables::NUMERIC_FIELDS, true) && $c['op'] !== 'in'
                && preg_match('/^-?[\d০-৯,]+(\.[\d০-৯]+)?$/u', $value) !== 1) {
                throw ValidationException::withMessages(["conditions.$i.value" => __('core.notify.number_needed')]);
            }

            $conditions[] = ['field' => $c['field'], 'op' => $c['op'], 'value' => $value];
        }

        $recipients = [];

        foreach (['users', 'roles', 'branches', 'departments', 'groups'] as $kind) {
            if ($ids = array_values(array_unique(array_map('intval', (array) ($data['recipients'][$kind] ?? []))))) {
                $recipients[$kind] = $ids;
            }
        }

        if (! empty($data['recipients']['responsible'])) {
            $recipients['responsible'] = true;
        }

        return [
            'name' => $data['name'],
            'module' => NotificationKinds::classify($data['event'])['module'],
            'event' => $data['event'],
            'branch_id' => $data['branch_id'] ?? null,
            'conditions' => $conditions ?: null,
            'recipients' => $recipients ?: null,
            'channels' => array_values(array_unique((array) ($data['channels'] ?? []))) ?: null,
            'priority' => $data['priority'] ?? null,
            'template_id' => $data['template_id'] ?? null,
            'delay_minutes' => (int) ($data['delay_minutes'] ?? 0),
            'expires_minutes' => isset($data['expires_minutes']) ? (int) $data['expires_minutes'] : null,
            'cooldown_minutes' => (int) ($data['cooldown_minutes'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
        ];
    }

    /**
     * ⭐ সংরক্ষণ — নতুন নিয়ম, নয়তো আগেরটার নতুন সংস্করণ (কিছু বদলালে তবেই)।
     *
     * @param  array<string, mixed>  $data  [[validate()]]-এর ফল
     */
    public function save(array $data, ?NotificationRule $rule = null): NotificationRule
    {
        $rule = DB::transaction(function () use ($data, $rule): NotificationRule {
            if ($rule === null) {
                $rule = NotificationRule::query()->create($data + ['version' => 1, 'created_by' => Actor::userId(), 'updated_by' => Actor::userId()]);
                $this->remember($rule);

                return $rule;
            }

            $rule->fill($data + ['updated_by' => Actor::userId()]);

            if ($rule->isDirty()) {
                $rule->version = (int) $rule->version + 1;
                $rule->save();
                $this->remember($rule);
            }

            return $rule;
        });

        app(NotificationAudit::class)->record('rule_save', $rule, 'done', ['rule_id' => $rule->id, 'version' => (int) $rule->version]);

        return $rule;
    }

    private function remember(NotificationRule $rule): void
    {
        NotificationRuleVersion::query()->create([
            'company_id' => $rule->company_id,
            'rule_id' => $rule->id,
            'version' => (int) $rule->version,
            'snapshot' => $rule->snapshot(),
            'changed_by' => Actor::userId(),
        ]);
    }
}
