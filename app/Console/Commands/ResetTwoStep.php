<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Security\MfaService;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * শেষ উপায় — একজনই সুপার অ্যাডমিন, আর তাঁর ফোনটা হারিয়ে গেছে।
 *
 * ── ⛔ কেন এই কমান্ডটা থাকতেই হবে ─────────────────────────────────────
 * সুপার অ্যাডমিনে দুই ধাপ বাধ্যতামূলক করার সাথে সাথেই একটা নতুন
 * ব্যর্থতার পথ তৈরি হয়: ⚠️ একজনই সুপার অ্যাডমিন, উদ্ধার-কোড শেষ বা
 * হারানো, আর ফোনটা নেই — তখন **কেউই** তালাটা খুলতে পারেন না, আর
 * ব্যবসাটা নিজের হিসাব থেকে চিরতরে বাইরে থাকে।
 *
 * ⓘ পর্দার রিসেট ([[UserController::resetTwoStep()]]) ঐ ঘরটায় কাজে
 * আসে না — ওটা চালাতে **অন্য একজন** সুপার অ্যাডমিন লাগে।
 *
 * ── ⭐ কেন এটা দুর্বলতা নয় ────────────────────────────────────────────
 * এটা চালাতে সার্ভারে শেল লাগে। ⓘ যাঁর সার্ভারে শেল আছে, তিনি
 * এমনিতেই ডাটাবেস খুলে যেকোনো সারি বদলাতে পারেন — অর্থাৎ এই কমান্ডটা
 * নতুন কোনো ক্ষমতা দেয় না, কেবল ঐ ক্ষমতাটাকে **একটা নিরাপদ, দাগ রেখে
 * যাওয়া পথে** নিয়ে আসে।
 *
 * ⛔ হাতে SQL চালালে নিরীক্ষার খাতায় কিছুই বসত না, আর খতিয়ানের সিলও
 * ভাঙত। ⭐ এখানে সারিটা আগে লেখা হয়, তারপর তালা খোলে।
 */
final class ResetTwoStep extends Command
{
    protected $signature = 'abos:two-step-reset
                            {email : যাঁর দুই ধাপ রিসেট হবে}
                            {--reason= : কেন — নিরীক্ষার খাতায় এটাই থাকবে}';

    protected $description = "Reset a user's two-step sign-in when the phone and the recovery codes are both gone";

    public function handle(MfaService $mfa, AuditEngine $audit): int
    {
        $email = (string) $this->argument('email');
        $reason = trim((string) ($this->option('reason') ?? ''));

        /*
         * ⛔ কারণ ছাড়া চলে না, ঠিক পর্দার মতোই। ⚠️ কমান্ড বলে ছাড় দিলে
         * সবচেয়ে গোপন পথটাই হত সবচেয়ে কম দাগ রেখে যাওয়া পথ।
         */
        if ($reason === '') {
            $this->error('--reason লাগবে। ছয় মাস পরে "কেন খোলা হয়েছিল" প্রশ্নের উত্তর এটাই।');

            return self::FAILURE;
        }

        /*
         * ⓘ কোম্পানির সীমা ছাড়াই খোঁজা — আটকে থাকা মানুষটা কোন
         * কোম্পানিতে আছেন তা এই মুহূর্তে জানা নেই, আর জানার দরকারও নেই।
         */
        $user = User::query()->withoutGlobalScopes()->where('email', $email)->first();

        if ($user === null) {
            $this->error("এই ইমেইলে কোনো ব্যবহারকারী নেই: {$email}");

            return self::FAILURE;
        }

        if (! $mfa->isOn($user)) {
            $this->warn('এই অ্যাকাউন্টে দুই ধাপ চালুই নেই — কিছু করার নেই।');

            return self::SUCCESS;
        }

        // ⭐ দাগটা আগে, তালা পরে — মাঝপথে ভাঙলেও খাতায় প্রশ্নটা থেকে যায়
        $audit->recordAction($user, 'two_step_reset', 'console: '.$reason);

        $mfa->turnOff($user);

        $this->info("{$email} — দুই ধাপ রিসেট হলো। পরের লগইনে তিনি নিজে আবার বসাবেন।");

        return self::SUCCESS;
    }
}
