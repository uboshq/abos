{{--
    ব্যবহারকারীর ঘর — ছবি, নাম, আর নিচে পরিচয়।

    ── ⭐ মালিকের নমুনা, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────
    *"nexus er user management-y zevabe eivabe koro"* — ছবি, নাম, আর
    নিচের সারিতে `@লগইন · ভূমিকা`।

    ⓘ তিনটা তথ্য একটা ঘরে, তিনটা কলামে নয়: চোখ একবারেই পড়ে "ইনি কে"।
    ⚠️ আলাদা কলাম নিলে ছকটা এত চওড়া হত যে ভূমিকার চিপগুলোর জায়গাই
    থাকত না।

    ── ⚠️ ছবিটা হাতে আঁকা হয় না ────────────────────────────────────────
    প্রথম চালে এখানে `avatar_path` আর `mb_substr` হাতে লেখা ছিল। ⛔ কিন্তু
    [[avatar]] কম্পোনেন্টটা রিপোতে আগে থেকেই আছে, আর সে বৃত্তের রং
    ব্যবহারকারীর accent থেকে নেয়। ⓘ হাতে লিখলে একদিন টপবারের ছবি বদলাত
    আর এই তালিকারটা পুরনো চেহারাতেই থেকে যেত।
--}}
<div class="flex items-center gap-2">
    <x-ui.avatar :user="$user" size="sm" />

    <span class="min-w-0">
        <span class="block truncate font-medium">{{ $user->name }}</span>

        {{--
            ⓘ `@লগইন · প্রথম ভূমিকা` — মালিকের নমুনার দ্বিতীয় সারি।
            ⚠️ ভূমিকাটা এখানে **একটাই**, পুরো তালিকা নয়: পুরোটা পাশের
            কলামে চিপ হয়ে আছে, আর দুই জায়গায় একই জিনিস বসলে সারিটা
            ভরে যেত।
        --}}
        <span class="block truncate text-2xs text-(--color-ink-muted)">
            @if ($user->login_id)
                {{ '@'.$user->login_id }}
            @endif

            @if ($user->login_id && $user->roles->isNotEmpty())
                ·
            @endif

            @if ($user->roles->isNotEmpty())
                {{ \App\Core\Support\RoleLabel::for($user->roles->first()->name) }}
            @endif
        </span>
    </span>
</div>
