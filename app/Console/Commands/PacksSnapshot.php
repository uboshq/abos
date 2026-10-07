<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Inventory\Services\PackSnapshot;
use Illuminate\Console\Command;

/**
 * মজুদ আর কাগজের আঙুলের ছাপ — প্যাকের ব্যাকফিলের আগে ও পরে।
 *
 * `--save` একটা ফাইলে রাখে, `--compare` সেই ফাইলের সাথে মেলায়, আর
 * একটা টেবিলেও অমিল পেলে **exit 1** — যাতে ডিপ্লয় স্ক্রিপ্ট বা মানুষ,
 * কেউ সবুজ ভেবে এগিয়ে না যায়। কেন — [[PackSnapshot]]।
 */
class PacksSnapshot extends Command
{
    protected $signature = 'abos:packs-snapshot
        {--save= : ছাপটা এই JSON ফাইলে রাখে}
        {--compare= : আগে রাখা ছাপের সাথে মেলায়; অমিল হলে exit 1}';

    protected $description = 'মজুদ, খরচ আর কাগজের লাইনের ছাপ — প্যাকের ব্যাকফিলে কিছু বদলায়নি তার প্রমাণ';

    public function handle(PackSnapshot $snapshot): int
    {
        $now = $snapshot->take();

        foreach ($now as $table => $print) {
            $this->line(sprintf('  %-24s %8d  %s', $table, $print['rows'], $print['md5']));
        }

        if ($path = $this->option('save')) {
            file_put_contents($path, json_encode($now, JSON_PRETTY_PRINT));
            $this->info("ছাপ রাখা হলো: {$path}");
        }

        if ($path = $this->option('compare')) {
            if (! is_file($path)) {
                $this->error("আগের ছাপ পাওয়া যায়নি: {$path}");

                return self::FAILURE;
            }

            $changed = $snapshot->differences((array) json_decode((string) file_get_contents($path), true), $now);

            if ($changed !== []) {
                $this->error('⛔ অমিল: '.implode(', ', $changed));

                return self::FAILURE;
            }

            $this->info('✓ হুবহু এক — কোনো মজুদ বা লাইন বদলায়নি।');
        }

        return self::SUCCESS;
    }
}
