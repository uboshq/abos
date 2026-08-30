{{--
    কোম্পানির রূপগুলো — থিম ইঞ্জিনের ধাপ ৩।

    ── তালিকায় কী দেখতে হবে, আর কেন ─────────────────────────────────────
    তিনটা প্রশ্নের উত্তর এক নজরে থাকতে হয়:

      · কার উপর দাঁড়ানো — নাহলে "আমাদেরটা কেন হঠাৎ বদলাল" প্রশ্নের
        উত্তর খুঁজতে প্রতিটা রূপ খুলে দেখতে হত
      · প্রকাশিত না খসড়া — কারণ খসড়া কারো পর্দায় নেই
      · **অপ্রকাশিত বদল আছে কি না** — সবচেয়ে জরুরিটা, কারণ এটাই সেই
        অবস্থা যেখানে মানুষ ভাবেন কাজটা সবাই দেখছেন, অথচ দেখছেন না
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.look.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('core.look.title')"
                          :subtitle="__('core.look.subtitle')">
            <x-slot:actions>
                {{--
                    আমদানি — ফাইল বাছাই আর পাঠানো এক ধাপে।

                    আলাদা একটা "আমদানি" পাতা বানানো হয়নি: ওখানে
                    দেখানোর মতো কিছুই থাকত না, কেবল একটা ফাইলের ঘর।
                    একটা পাতা যেখানে একটাই ঘর, সেটা একটা ধাপ বাড়ানো।
                --}}
                <form method="POST" action="{{ route('system_admin.look.import') }}"
                      enctype="multipart/form-data">
                    @csrf

                    {{-- ঘরটা লুকানো, লেবেলটাই বোতাম।

                         ── কেন, ৩০ আগস্ট ২০২৬ ──────────────────────────
                         আগে `<input type="file">` খোলা ছিল, আর তার পাশে
                         আমাদের বোতাম। ব্রাউজার ওই ইনপুটের নিজের বোতামটাও
                         আঁকে — **"Choose File / No file chosen"**,
                         ইংরেজিতে, আর আমাদের স্টাইল ছাড়াই (`file:`
                         ইউটিলিটিগুলো ওখানে পৌঁছয়নি)।

                         ফলে পাশাপাশি দুইটা কন্ট্রোল একই কথা বলত, একটা
                         ইংরেজিতে। HP ২৫ আগস্ট এটাকে "বোতামটা দুইবার
                         রেন্ডার হচ্ছে" বলে রিপোর্ট করেছেন — আর পাঠকের
                         চোখে ঠিক তাই। তখন নামটা বদলে সারানোর চেষ্টা
                         হয়েছিল, কিন্তু ইংরেজি বোতামটা রয়েই গিয়েছিল।

                         এখন একটাই কন্ট্রোল, একটাই লেখা, আর সেটা বাংলায়।
                         ফাইল বাছার সাথে সাথেই পাঠানো হয় — বাছাইয়ের পর
                         দ্বিতীয় একটা ক্লিক চাওয়ার কারণ নেই। --}}
                    <label class="inline-flex min-h-(--spacing-touch) cursor-pointer items-center
                                  rounded-(--radius-field) border border-(--color-border)
                                  bg-(--color-surface-card) px-3 text-sm font-medium
                                  hover:bg-(--color-surface-hover)
                                  focus-within:outline focus-within:outline-2
                                  focus-within:outline-offset-2">
                        {{ __('core.look.import') }}

                        <input type="file" name="file" accept=".json,application/json" required
                               onchange="this.form.submit()" class="sr-only">
                    </label>
                </form>

                <x-ui.button :href="route('system_admin.look.create')" tone="primary">
                    {{ __('core.look.new') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @forelse ($skins as $skin)
        <section data-boxed
                 class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-(--radius-card)
                        border border-(--color-border) bg-(--color-surface-card) px-4 py-3">
            <div class="min-w-0">
                <a href="{{ route('system_admin.look.edit', $skin) }}"
                   class="font-semibold hover:underline">{{ $skin->name }}</a>

                <p class="mt-0.5 text-2xs text-(--color-ink-muted)">
                    {{ __('core.look.parent') }}:
                    {{ $parents[$skin->parent] ?? $skin->parent }}
                    @if ($skin->live())
                        · {{ __('core.look.version_n', ['n' => $skin->live()->version]) }}
                    @endif
                </p>
            </div>

            <div class="flex items-center gap-2">
                {{--
                    ব্যাজের ক্রমটা ইচ্ছাকৃত: "অপ্রকাশিত বদল আছে" আগে।

                    একটা প্রকাশিত রূপে অপ্রকাশিত বদলও থাকতে পারে, আর
                    তখন "প্রকাশিত" লেখাটা একা দাঁড়ালে মিথ্যা বলত।
                --}}
                @if ($skin->hasUnpublishedChanges())
                    <x-ui.badge tone="pending">
                        {{ $skin->live() ? __('core.look.unpublished') : __('core.look.draft') }}
                    </x-ui.badge>
                @else
                    <x-ui.badge tone="success">{{ __('core.look.published') }}</x-ui.badge>
                @endif

                <form method="POST" action="{{ route('system_admin.look.preview', $skin) }}">
                    @csrf
                    <x-ui.button type="submit" tone="secondary">{{ __('core.look.preview') }}</x-ui.button>
                </form>
            </div>
        </section>
    @empty
        <x-ui.empty-state :message="__('core.look.none')" />
    @endforelse
</x-layouts.app>
