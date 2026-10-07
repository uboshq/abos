<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\PermissionSyncer;
use Illuminate\Console\Command;

/**
 * ডিপ্লয়ের সময় চলে — নতুন মডিউলের অনুমতিগুলো ডাটাবেজে বসায়।
 *
 * নাহলে মডিউল যোগ করার পর তার মেনু আইটেমগুলো কারও চোখেই পড়ত না: মেনু
 * অনুমতি দেখে ফিল্টার হয়, আর যে অনুমতি ডাটাবেজে নেই কেউ সেটা পায় না।
 */
class SyncPermissions extends Command
{
    protected $signature = 'abos:sync-permissions {--drift : কী কী মিলছে না শুধু তা দেখাও, কিছু বদলিও না}';

    protected $description = 'প্রতিটা module.php-তে ঘোষিত অনুমতি ডাটাবেজে নিবন্ধন করে';

    public function handle(PermissionSyncer $syncer): int
    {
        if ($this->option('drift')) {
            $drift = $syncer->drift();

            if ($drift['undeclared'] === [] && $drift['unregistered'] === []) {
                $this->info('সব অনুমতি মিলে আছে।');

                return self::SUCCESS;
            }

            foreach ($drift['unregistered'] as $name) {
                $this->warn("ঘোষিত কিন্তু ডাটাবেজে নেই: {$name}");
            }

            foreach ($drift['undeclared'] as $name) {
                $this->warn("ডাটাবেজে আছে কিন্তু কোনো মডিউল আর চায় না: {$name}");
            }

            return self::FAILURE;
        }

        $result = $syncer->sync();

        foreach ($result['created'] as $name) {
            $this->line("  + {$name}");
        }

        if ($result['created'] === []) {
            $this->info("নতুন কিছু নেই — {$result['existing']}টা অনুমতি আগে থেকেই আছে।");
        } else {
            $this->info(count($result['created']).'টা নতুন অনুমতি যোগ হয়েছে।');
        }

        /*
         * মালিকের রোলে কয়টা বসল সেটা আলাদা করে বলা হয়, কারণ দুইটা
         * আলাদা ঘটনা। অনুমতি তৈরি হওয়া মানেই কারও কাছে পৌঁছানো নয় —
         * প্রথমবার এটা ধরা পড়েছিল ব্রাউজারে, যখন নতুন মডিউলের প্রতিটা
         * পর্দা মালিককেও ৪০৩ দিচ্ছিল।
         */
        if ($result['granted'] > 0) {
            $this->info($result['granted'].'টা অনুমতি মালিকের রোলে বসানো হয়েছে।');
        }

        return self::SUCCESS;
    }
}
