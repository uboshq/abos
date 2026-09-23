{{--
    একটা নোটিশ, পুরোটা।

    ⓘ খোলা মানেই পড়া — দাগটা কন্ট্রোলারে পড়ে
    ([[NoticeController::show()]])। ⚠️ পড়ার হিসাবটা কেবল প্রশাসক দেখেন;
    সহকর্মী কে পড়েনি সেটা জানার কোনো কারণ নেই, আর দেখালে এটা নোটিশের
    পর্দা না হয়ে নজরদারির পর্দা হত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $notice->title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$notice->title"
                          :subtitle="$notice->starts_on?->format('d M Y')">
            @if ($canManage)
                <x-slot:actions>
                    <x-ui.button :href="route('system_admin.notice.edit', $notice->id)" tone="secondary">
                        {{ __('core.action.edit') }}
                    </x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
                {{-- ⚠️ `nl2br` + `e()` — লেখাটা ব্যবহারকারীর, তাই আগে পালানো,
                     তারপর লাইন ভাঙা। ⛔ উল্টো ক্রমে `<br>`-ও পালাত। --}}
                <div class="whitespace-pre-line text-sm">{{ $notice->body }}</div>
            </div>

            {{--
                সইয়ের বোতাম — কেবল যে নোটিশ সই চায়।

                ⓘ পড়া আর সই এক নয়। পাতাটা খোলামাত্র *পড়া* দাগ পড়ে
                যায়; সই দিতে মানুষকে এই বোতামে চাপতে হয়।

                ⚠️ এই ফর্কটাই পুরো ব্যবস্থাটার মূল্য: ⛔ এক ঘরে রাখলে
                *"নতুন নীতিমালা কে মেনেছেন"* প্রশ্নের উত্তর হত *"পাতাটা কে
                খুলেছেন"*।
            --}}
            {{--
                প্রত্যাহার · সংরক্ষণাগার · ফেরত — মোছার বদলে তিনটা পথ।

                ⛔ এখানে কোনো "মুছুন" বোতাম নেই, আর সেটা ইচ্ছাকৃত: প্রকাশিত
                নোটিশ মানুষ পড়ে ফেলেছে। ⓘ মুছলে খাতা বলত কথাটা কেউ
                জানে না, অথচ গোটা অফিস জানে।

                ⚠️ প্রত্যাহারে কারণ বাধ্যতামূলক — ছয় মাস পরে *"ওটা কেন তুলে
                নেওয়া হলো"* প্রশ্নের এই লেখাটাই একমাত্র উত্তর।
            --}}
            @if ($canManage && $notice->status)
                <div data-boxed
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">

                    @if ($notice->status->isLive())
                        <form method="POST" action="{{ route('system_admin.notice.recall', $notice->id) }}"
                              class="space-y-2">
                            @csrf
                            <x-ui.field name="reason" :label="__('core.notice.why_label')" required maxlength="300" />
                            <x-ui.button type="submit">{{ __('core.notice.recall_action') }}</x-ui.button>
                        </form>
                    @endif

                    @if ($notice->status->canBecome(\App\Core\Support\NoticeStatus::ARCHIVED))
                        <form method="POST" action="{{ route('system_admin.notice.archive', $notice->id) }}"
                              class="mt-2">
                            @csrf
                            <x-ui.button type="submit">{{ __('core.notice.archive_action') }}</x-ui.button>
                        </form>
                    @endif

                    @if ($notice->status === \App\Core\Support\NoticeStatus::ARCHIVED)
                        <form method="POST" action="{{ route('system_admin.notice.restore', $notice->id) }}">
                            @csrf
                            <x-ui.button type="submit">{{ __('core.notice.restore_action') }}</x-ui.button>
                        </form>
                    @endif
                </div>
            @endif

            @if ($notice->ack_required)
                <div data-boxed
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                    <div class="mb-2 flex items-center gap-2 text-sm">
                        <span class="text-(--color-ink-muted)">{{ __('core.notice.your_standing') }}</span>
                        <span class="font-medium">{{ $standing->label() }}</span>
                    </div>

                    @if ($standing->stillOwes())
                        <form method="POST" action="{{ route('system_admin.notice.sign', $notice->id) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">
                                {{ __('core.notice.sign_it') }}
                            </x-ui.button>
                        </form>
                    @endif
                </div>
            @endif
        </div>

        <div class="space-y-4">
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
                <dl class="space-y-3">
                    <div>
                        <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('system_admin::notice.roles') }}
                        </dt>
                        <dd class="text-sm">
                            @php $who = $notice->audience->pluck('role')->all(); @endphp
                            {{ $who === [] ? __('system_admin::notice.everyone') : implode(' · ', $who) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('system_admin::notice.period') }}
                        </dt>
                        <dd class="text-sm">
                            @if ($notice->starts_on === null && $notice->ends_on === null)
                                {{ __('system_admin::notice.always') }}
                            @else
                                {{ $notice->starts_on?->format('d M Y') ?? '—' }}
                                → {{ $notice->ends_on?->format('d M Y') ?? '—' }}
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('system_admin::notice.author') }}
                        </dt>
                        <dd class="text-sm">{{ $notice->author?->name ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- ⭐ কে পড়েছেন, কে পড়েননি — আর দ্বিতীয়টাই আসল।

                 ⚠️ "কয়জন পড়েছেন" জেনে কিছু করার নেই; "কে পড়েননি" জেনে
                 তাঁকে বলা যায়। ⓘ তাই দুইটা তালিকাই, আর না-পড়াটা আগে। --}}
            @if ($readers !== null)
                <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                    <h2 class="mb-2 text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">
                        {{ __('system_admin::notice.unread') }}
                    </h2>

                    @if ($readers['unread'] === [])
                        <p class="text-sm text-(--color-ink-muted)">
                            {{ __('system_admin::notice.all_read') }}
                        </p>
                    @else
                        <ul class="space-y-1 text-sm">
                            @foreach ($readers['unread'] as $name)
                                <li>{{ $name }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <h2 class="mb-2 mt-4 text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">
                        {{ __('system_admin::notice.read') }}
                    </h2>

                    @if ($readers['read'] === [])
                        <p class="text-sm text-(--color-ink-muted)">
                            {{ __('system_admin::notice.nobody_yet') }}
                        </p>
                    @else
                        <ul class="space-y-1 text-sm">
                            @foreach ($readers['read'] as $one)
                                <li>{{ $one['name'] }}
                                    <span class="text-(--color-ink-muted)">· {{ $one['at'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
