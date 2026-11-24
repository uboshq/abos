<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Support;

/**
 * ⭐ The control panel by subject (owner "All ok", 24 Nov 2026, sample SkSwHwaM) — where a setting stands on the screen. One setting, one
 * subject; its key, value, permission and history are untouched, and the module's own tab still shows it.
 *
 * ⓘ A new module's setting always finds a subject by its module or group; nothing can be left without a place ("সাধারণ" is the last door).
 * ⛔ Screen switches (`screens`) are a module question, not a rule: they stand with the module's screens.
 */
final class ControlSubjects
{
    /** The sample's order. */
    public const ALL = ['general', 'sales', 'buying', 'stock', 'money', 'people', 'limits', 'closing', 'paper', 'messages', 'assistant', 'files', 'approval'];

    public const PREFIX = 'subject-';

    /** @param array<string, mixed> $definition */
    public static function of(string $key, array $definition): ?string
    {
        $module = (string) ($definition['module'] ?? '');
        $group = (string) ($definition['group'] ?? 'general');

        return match (true) {
            (bool) ($definition['menu'] ?? false), isset($definition['owned_by']), isset($definition['tab']), $group === 'screens' => null,
            in_array($group, ['print', 'print_paper', 'invoice_info'], true) => 'paper',
            preg_match('/image_kb|slip_max|^documents\.(max_upload|allow)/', $key) === 1 => 'files',
            $module === 'approval' => 'approval',
            $module === 'assistant' => 'assistant',
            $module === 'notification', str_starts_with($key, 'notice.') => 'messages',
            $group === 'day_close' => 'closing',
            in_array($group, ['limits', 'risk', 'loss'], true) => 'limits',
            in_array($module, ['sales', 'customer', 'promotion', 'restaurant'], true) => 'sales',
            in_array($module, ['purchase', 'supplier'], true) => 'buying',
            $module === 'inventory' => 'stock',
            in_array($module, ['accounts', 'finance', 'fixed_assets', 'credit_control'], true) => 'money',
            $module === 'hr' => 'people',
            default => 'general',
        };
    }

    /** "subject-sales" → "sales"; anything else → null. */
    public static function fromTab(string $tab): ?string
    {
        $subject = str_starts_with($tab, self::PREFIX) ? substr($tab, strlen(self::PREFIX)) : '';

        return in_array($subject, self::ALL, true) ? $subject : null;
    }
}
