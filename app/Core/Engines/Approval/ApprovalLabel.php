<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Core\Module\ModuleRegistry;

/**
 * ⭐ সইয়ের কাজের নাম, সবসময় মানুষের ভাষায় — দফা ৪ (অনুমোদন মডিউলের নকশা, মালিক ২১ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে ঘোষণা না থাকলে কাঁচা কোড পর্দায় যেত ("sales · invoice", "accounts · journal") — ভাউচারের ধরন আর পুরনো সারির কাজ
 * কোনো মডিউলের `approvals`-এ ঘোষিত নয়, অথচ ইনবক্স, বিজ্ঞপ্তি আর "ছক নেই" বার্তায় আসে।
 *
 * ক্রম:
 *   ১. মডিউলের নিজের ঘোষণা (`module.php` → `approvals`);
 *   ২. ঘোষণার বাইরের নাম — `approval::paper.<module>.<action>` (মান অন্য ভাষা-চাবিও হতে পারে, যেমন ভাউচারের নাম);
 *   ৩. "<মডিউল>-এর কাগজ"।
 * ⛔ কোড কখনো নয়। ⓘ কোরে, কারণ ইঞ্জিনের বিজ্ঞপ্তি আর "ছক নেই" বার্তাও এটাই পড়ে, আর কোর কোনো মডিউলের নাম জানে না।
 */
final class ApprovalLabel
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function of(string $module, string $action): string
    {
        $definition = $this->modules->get($module);

        $declared = $definition?->approvals[$action] ?? null;
        if (is_string($declared) && __($declared) !== $declared) {
            return (string) __($declared);
        }

        $known = 'approval::paper.'.$module.'.'.$action;
        $value = __($known);
        if (is_string($value) && $value !== $known) {
            return (string) __($value);
        }

        $name = $definition?->label();
        $unknown = __('approval::paper.unknown', ['module' => $name !== null && $name !== '' ? $name : (string) __('approval::paper.some_module')]);

        return is_string($unknown) && ! str_starts_with($unknown, 'approval::') ? $unknown : (string) ($name ?? '—');
    }
}
