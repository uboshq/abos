{{--
    আমার লগইনগুলো — আর দূর থেকে বন্ধ করা।

    ── কেন এটা লাগে ───────────────────────────────────────────────────
    কাউন্টারের কম্পিউটারে লগইন রেখে কেউ বাড়ি চলে গেলে আজ কিছু করার নেই।
    পাসওয়ার্ড বদলালেও পুরনো লগইনটা চলতেই থাকে — সেশন পাসওয়ার্ডের সাথে
    বাঁধা নয়।

    ── কেন কার্ড, টেবিল নয় ────────────────────────────────────────────
    সাধারণত দুই-তিনটা সারি, আর প্রতিটাতেই একটা সিদ্ধান্ত নিতে হয়
    ("এটা কি আমার?")। টেবিলে বসালে বোতামগুলো ঠাসাঠাসি হত, আর ফোনে
    ভুল সারির বোতাম চাপার ঝুঁকি থাকত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('governance::menu.my_sessions') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('governance::menu.my_sessions')" />
    </x-slot:header>

    <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs
              text-(--color-ink-muted)">
        {{ __('governance::message.sessions_why') }}
    </p>

    <div class="space-y-2">
        @foreach ($sessions as $session)
            <div @class([
                'flex items-center gap-3 rounded-(--radius-card) border bg-(--color-surface-card) px-4 py-3',
                'border-(--color-brand-600)' => $session->mine,
                'border-(--color-border)' => ! $session->mine,
            ])>
                <span class="text-(--color-ink-muted)">
                    <x-ui.icon name="lock" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium">
                        {{ $session->agent }}

                        {{-- নিজেরটা চিহ্নিত: নাহলে কেউ নিজেকেই বের করে
                             দিয়ে ভাবতেন কিছু ভেঙেছে --}}
                        @if ($session->mine)
                            <span class="ms-1 rounded-(--radius-pill) bg-(--color-badge-success-bg)
                                         px-2 py-0.5 text-2xs text-(--color-badge-success-ink)">
                                {{ __('governance::message.this_device') }}
                            </span>
                        @endif
                    </p>

                    <p class="mt-0.5 text-2xs text-(--color-ink-muted)">
                        <span class="num">{{ $session->ip }}</span>
                        ·
                        {{ __('governance::field.last_seen') }}:
                        <span class="num">{{ $session->seen->diffForHumans() }}</span>
                    </p>
                </div>

                @unless ($session->mine)
                    <form method="POST"
                          action="{{ route('governance.session.destroy', ['id' => $session->id]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="min-h-(--spacing-touch) rounded-(--radius-field) border
                                       border-(--color-border) px-3 text-sm
                                       transition-colors hover:bg-(--color-surface-hover)">
                            {{ __('governance::action.end_session') }}
                        </button>
                    </form>
                @endunless
            </div>
        @endforeach
    </div>

    {{--
        "বাকি সব জায়গা থেকে বেরোন" — কেবল যখন সত্যিই বাকি কিছু আছে।

        একটাই সেশন থাকলে বোতামটা কিছুই করত না, আর যে বোতাম কিছু করে না
        সেটা বাকিগুলোর বিশ্বাসও নষ্ট করে।
    --}}
    @if ($sessions->count() > 1)
        <form method="POST" action="{{ route('governance.session.others') }}" class="mt-4">
            @csrf
            @method('DELETE')
            <button type="submit"
                    class="min-h-(--spacing-touch) rounded-(--radius-field) border
                           border-(--color-danger) px-4 text-sm text-(--color-danger)
                           transition-colors hover:bg-(--color-badge-danger-bg)">
                {{ __('governance::action.end_other_sessions') }}
            </button>
        </form>
    @else
        <p class="mt-4 text-2xs text-(--color-ink-muted)">
            {{ __('governance::message.only_here') }}
        </p>
    @endif
</x-layouts.app>
