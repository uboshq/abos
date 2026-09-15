<?php

declare(strict_types=1);

namespace App\Core\Services\Backup;

/**
 * এই সার্ভারে PHP বাইরের প্রোগ্রাম চালাতে পারে কি না।
 *
 * ── ⛔ কেন এই ক্লাসটা লাগল, ১৫ সেপ্টেম্বর ২০২৬ ───────────────────────
 * লাইভে মেপে দেখা গেছে `abos:backup` **কোনোদিন চলেনি**:
 *
 *     ব্যাকআপ ব্যর্থ: The Process class relies on proc_open,
 *     which is not available on your PHP installation.
 *
 * শেয়ার্ড হোস্টিংয়ে (cPanel) `proc_open` বন্ধ থাকে — চারটা PHP
 * বাইনারিতেই। ⚠️ আর `Symfony\Component\Process` ওটা ছাড়া চলে না, তাই
 * `mysqldump`-নির্ভর পুরো পথটাই ঐ সার্ভারে অচল।
 *
 * ── ⭐ কেন এটা "শুরুতেই" জিজ্ঞেস করা হয়, শেষে নয় ────────────────────
 * আগে ভুলটা ধরা পড়ত ডাম্প নেওয়ার চেষ্টার **পরে** — অর্থাৎ একটা অর্ধেক
 * ফাইল, একটা অস্থায়ী defaults ফাইল আর একটা অস্পষ্ট বার্তা রেখে।
 *
 * ⓘ এখন প্রশ্নটা আগে করা হয়, আর উত্তরটা পথ বেছে নেয় — ব্যর্থ হয় না।
 */
final class ShellAvailability
{
    /**
     * PHP এখানে বাইরের প্রোগ্রাম চালাতে পারে?
     *
     * ⚠️ `function_exists()` একাই যথেষ্ট নয়: `disable_functions`-এ নাম
     * থাকলে PHP ফাংশনটা **সংজ্ঞায়িত রাখে না**, কিন্তু কিছু সেটআপে
     * ওটা অন্যভাবে আটকানো থাকে। তাই দুইটাই দেখা হয়।
     */
    public static function canRunProcesses(): bool
    {
        return self::isEnabled('proc_open');
    }

    /**
     * একটা নির্দিষ্ট ফাংশন সত্যিই ডাকা যায় কি না।
     */
    public static function isEnabled(string $function): bool
    {
        if (! function_exists($function)) {
            return false;
        }

        $disabled = array_map(
            'trim',
            explode(',', (string) ini_get('disable_functions')),
        );

        return ! in_array($function, $disabled, true);
    }

    /**
     * কেন চলবে না — মানুষের ভাষায়, যাতে বার্তাটা কাজে লাগে।
     *
     * ⓘ `null` মানে সব ঠিক আছে।
     */
    public static function whyNot(): ?string
    {
        if (self::canRunProcesses()) {
            return null;
        }

        return __('backup::error.no_proc_open', [
            'disabled' => (string) ini_get('disable_functions') ?: '—',
        ]);
    }
}
