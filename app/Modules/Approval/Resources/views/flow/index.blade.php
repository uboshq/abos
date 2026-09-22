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

         ⭐ খোঁজা এখন চালু — মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬। ⓘ ঐদিন
         সকালে লাইভে ৭২টা ছক বসেছে, আর এক পর্দায় সব ঢালা মানে মানুষ স্ক্রল
         করে খোঁজেন, আর খুঁজে না পেয়ে ধরে নেন নিয়মটা নেই।

         ⛔ ঘনত্ব আর রপ্তানি এখনো বন্ধ, আর কারণটা বদলায়নি: এটা ছক নয়,
         কার্ডের তালিকা। ঘনত্ব কেবল `x-ui.table` মানে, আর রপ্তানির ফাইল
         টেবিলের কলাম থেকেই বানানো হয়।

         ⚠️ রপ্তানি চালু করতে হলে কার্ডগুলোকে `x-ui.table`-এ নিতে হত —
         কিন্তু একটা ছকের নিচে তার ধাপগুলো বসে, আর সেটা এক সারিতে ধরে না।
         ⓘ মৃত বোতাম বসানোর চেয়ে না বসানো ভালো, আর ওটা এই ফাইলের নিজেরই
         নিয়ম — তাই রপ্তানিটা আলাদা কাজ হিসেবে রইল। --}}
    <div data-boxed class="mb-3 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('approval::menu.flows')"
                          :count="trans_choice('core.count.records', $flows->total(), ['count' => $flows->total()])"
                          :density="false" :export="false">
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('approval.flow.create')">
                        {{ __('approval::action.new_flow') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>
    </div>

    {{-- ⚠️ খোঁজার পর খালি পাওয়া আর সত্যিই কিছু না থাকা — দুইটা আলাদা কথা।

         ⓘ "এখনো কোনো ছক বসানো হয়নি" পড়ে মানুষ নতুন একটা বানাতে যেতেন,
         অথচ নিয়মটা হয়তো আছে, কেবল তাঁর লেখা শব্দটার সাথে মেলেনি। --}}
    @if ($flows->isEmpty() && $q !== '')
        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-6">
            <p class="text-sm font-medium">{{ __('approval::message.no_match') }}</p>
            {{-- ⓘ যা লেখা হয়েছিল সেটা ফিরিয়ে দেখানো — নাহলে মানুষ
                 বুঝতে পারেন না ছাঁকনিটা এখনো চালু আছে কি না। --}}
            <p class="mt-1 text-sm text-(--color-ink-muted)">&ldquo;{{ $q }}&rdquo;</p>
        </div>
    @elseif ($flows->isEmpty())
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
                            {{-- ⭐ সংকেত — মালিকের চাওয়া, ২২ সেপ্টেম্বর ২০২৬।

                                 একটা নিয়মকে কথায় বা রিপোর্টে নাম ধরে ডাকার
                                 জন্য। ⓘ `public_id` আছে, কিন্তু ওটা UUID —
                                 মুখে বলার মতো নয়। --}}
                            <p class="text-2xs font-mono text-(--color-ink-muted)">{{ $flow->code }}</p>
                            <p class="font-medium">
                                {{ __($choices[$flow->module]['actions'][$flow->action] ?? $flow->action) }}
                                <span class="text-(--color-ink-muted)">
                                    · {{ $choices[$flow->module]['label'] ?? $flow->module }}
                                </span>
                            </p>
                            @if (filled($flow->remarks))
                                {{-- ⓘ কারণটা তালিকাতেই — সম্পাদনায় ঢুকে দেখতে
                                     হলে কেউ দেখত না, আর সেটাই ঘরটা বসানোর
                                     পুরো কারণ। --}}
                                <p class="mt-1 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                                    {{ $flow->remarks }}
                                </p>
                            @endif

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

    {{ $flows->links() }}
</x-layouts.app>
