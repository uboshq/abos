{{--
    অনুমোদনের ছকগুলো।

    খালি থাকা মানে কোথাও অনুমোদন লাগছে না — আর সেটা একটা সিদ্ধান্তও
    হতে পারে। তাই খালি পর্দায় শুধু "কিছু নেই" নয়, কী হচ্ছে সেটাও লেখা।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.flows') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @error('flow')
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            {{ $message }}
        </div>
    @enderror

    {{-- ⭐ শিরোনাম, গোনা আর "নতুন ছক" বোতাম এখন টুলবারে — আগে ছিল
         page-header-এ। মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬: *"সব মডিউলেই
         একই অবস্থা, সব ঠিক করো"*।

         ⓘ এটা ছক নয়, কার্ডের তালিকা — তাই খোঁজা, ঘনত্ব আর রপ্তানি বন্ধ:
         কন্ট্রোলার কোনো `q` পড়ে না, ঘনত্ব কেবল `x-ui.table` মানে, আর
         রপ্তানির ফাইল টেবিলের কলাম থেকেই বানানো হয়। ⛔ ওগুলো রাখলে তিনটা
         মৃত বোতাম হত। কার্ডগুলো যেমন ছিল তেমনই, টুলবারের বাক্সের নিচে। --}}
    <div data-boxed class="mb-3 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('approval::menu.flows')"
                          :count="trans_choice('core.count.records', $flows->count(), ['count' => $flows->count()])"
                          :search="false" :density="false" :export="false">
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('approval.flow.create')">
                        {{ __('approval::action.new_flow') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>
    </div>

    @if ($flows->isEmpty())
        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-6">
            <p class="text-sm font-medium">{{ __('approval::message.no_flows') }}</p>
            <p class="mt-1 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('approval::message.no_flows_hint') }}
            </p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($flows as $flow)
                <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-medium">
                                {{ __($choices[$flow->module]['actions'][$flow->action] ?? $flow->action) }}
                                <span class="text-(--color-ink-muted)">
                                    · {{ $choices[$flow->module]['label'] ?? $flow->module }}
                                </span>
                            </p>

                            <p class="mt-0.5 text-2xs text-(--color-ink-muted)">
                                {{ $flow->threshold_amount === null
                                    ? __('approval::action.always')
                                    : __('approval::field.threshold').': '.\App\Core\Support\Money::format($flow->threshold_amount) }}
                                @unless ($flow->is_active)
                                    · {{ __('approval::status.cancelled') }}
                                @endunless
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <x-ui.button tone="secondary" :href="route('approval.flow.edit', $flow->id)">
                                {{ __('core.action.edit') }}
                            </x-ui.button>

                            <form method="POST" action="{{ route('approval.flow.destroy', $flow->id) }}">
                                @csrf
                                @method('DELETE')
                                {{-- "বাতিল" নয় — ওটা ফর্ম ছেড়ে বেরোনোর
                                     শব্দ, আর এখানে চাপলে ছকটা মুছে যায় --}}
                                <x-ui.button type="submit" tone="ghost">{{ __('approval::action.delete') }}</x-ui.button>
                            </form>
                        </div>
                    </div>

                    {{-- স্তরগুলো এক লাইনে — কে, তারপর কে --}}
                    <ol class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                        @foreach ($flow->steps as $step)
                            <li class="rounded-(--radius-field) bg-(--color-surface-hover) px-2 py-1">
                                <span class="text-(--color-ink-muted)">{{ $step->level }}.</span>

                                {{-- রোল নাকি ব্যক্তি — দুইটার আচরণ আলাদা, তাই
                                     নামের পাশে ধরনটাও লেখা থাকে --}}
                                <span class="text-2xs text-(--color-ink-muted)">
                                    {{ $step->approver_type === 'role'
                                        ? __('approval::action.by_role')
                                        : __('approval::action.by_user') }}
                                </span>

                                {{ $names[$step->approver_type][$step->approver_id] ?? '#'.$step->approver_id }}
                                @if ($step->requires_all)
                                    <span class="text-2xs text-(--color-ink-muted)">
                                        ({{ __('approval::field.requires_all') }})
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endforeach
        </div>
    @endif
</x-layouts.app>
