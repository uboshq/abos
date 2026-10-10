{{--
    একজন কর্মীর পাতা — মালিকের অনুমোদিত নকশা (১ অক্টোবর ২০২৬): ১ নম্বরের কভার ও ট্যাব,
    ৩ নম্বরের হাজিরা ও ছুটি। চাকরির ইতিহাস আর রিপোর্টিং লাইন পরের ধাপে, নতুন ঘর এলে।

    বেতনের অঙ্কগুলো কেবল viewSalary নীতিতে (hr.salary.view + শাখার নাগাল) দেখা যায় — হিসাবরক্ষক
    ছাড়া কারও কারও বেতন জানার দরকার নেই, আর তালিকায় সেটা ফাঁস হওয়া
    উচিত নয়। হাজিরা hr.attendance.view, ছুটি hr.leave.view — প্রোফাইল খোলার
    চাবি দিয়ে এগুলো খোলে না।
--}}
@php
    $left = $employee->leaving_date !== null;

    /*
     * কর্মকাল — যোগদান থেকে আজ (বা চাকরি ছাড়ার দিন) পর্যন্ত।
     * ⓘ যোগদানের তারিখ না থাকলে ঘরটাই নেই; "০ বছর" মানে মিথ্যা উত্তর।
     */
    $tenure = null;

    if ($employee->joining_date) {
        $span = $employee->joining_date->diff($employee->leaving_date ?? now());
        $tenure = __('hr::profile.tenure_value', ['years' => $span->y, 'months' => $span->m]);
    }

    $initial = mb_substr(trim((string) $employee->name()), 0, 1);

    /*
     * ⭐ গ্রুপের মালিকের পদবি সব কোম্পানিতে একটাই — মালিকের সিদ্ধান্ত, ২ অক্টোবর ২০২৬।
     * ⓘ এই কোম্পানির সারি অন্য পদবি বললেও এখানে বদলায় না ([[Ownership::isGroupOwner()]], কেবল পরিচয়)।
     */
    $title = app(\App\Core\Services\Ownership::class)->isGroupOwner($employee->user)
        ? (app(\App\Core\Services\Ownership::class)->groupOwnerTitle() ?? $employee->designation?->name())
        : $employee->designation?->name();
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $employee->name() }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.errors />

    {{-- ── কভার ─────────────────────────────────────────────────────────
         ব্র্যান্ডের রঙে ব্যানার, তার উপর গোল ছবির ঘর, নাম আর এক লাইনে পরিচয়। --}}
    <section data-profile-cover data-boxed
             class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <div class="h-24 bg-(--color-brand-500)"></div>

        <div class="flex flex-wrap items-end gap-4 px-4 pb-4" style="margin-top: -3rem">
            {{-- ⭐ ছবি থাকলে ছবি, না থাকলে আদ্যক্ষর। ⛔ attachment.download — কর্মীর view নীতি পেরিয়েই ছবি আসে --}}
            @if ($employee->photo)
                <img data-profile-photo src="{{ route('attachment.download', $employee->photo) }}" alt="{{ $employee->name() }}"
                     class="size-24 shrink-0 rounded-full object-cover shadow"
                     style="border: 4px solid var(--color-surface-card)" />
            @else
                <div class="grid size-24 shrink-0 place-items-center rounded-full
                            bg-(--color-brand-50) text-4xl font-bold text-(--color-brand-700) shadow"
                     style="border: 4px solid var(--color-surface-card)"
                     aria-hidden="true">{{ $initial }}</div>
            @endif

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-2xl font-semibold text-(--color-ink)">{{ $employee->name() }}</h1>
                <p class="text-sm text-(--color-ink-body)">
                    {{ collect([$employee->code, $title, $employee->department?->name(), $employee->branch?->name()])->filter()->implode(' · ') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span @class([
                    'rounded-full px-3 py-1 text-xs font-semibold',
                    'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => ! $left,
                    'bg-(--color-badge-draft-bg) text-(--color-badge-draft-ink)' => $left,
                ])>{{ $left ? __('hr::profile.status_left') : __('hr::profile.status_active') }}</span>

                @can('viewSalary', $employee)
                    <x-ui.button :href="route('hr.employee.salary', $employee)">
                        {{ __('hr::action.salary') }}
                    </x-ui.button>
                @endcan
                @can('hr.employee.manage')
                    <x-ui.button tone="primary" :href="route('hr.employee.edit', $employee)">
                        {{ __('hr::action.edit') }}
                    </x-ui.button>
                @endcan
            </div>
        </div>

        {{-- ট্যাব — একই পাতার অংশে যায়, তাই লিংক পাঠালেও ঠিক জায়গা খোলে --}}
        <nav class="flex gap-1 overflow-x-auto border-t border-(--color-border) px-4" aria-label="{{ $employee->name() }}">
            <a href="#general" class="whitespace-nowrap border-b-2 border-(--color-brand-500) px-3 py-2.5 text-sm font-semibold text-(--color-brand-700)">{{ __('hr::profile.tab_general') }}</a>
            <a href="#line" class="whitespace-nowrap px-3 py-2.5 text-sm text-(--color-ink-body) hover:text-(--color-ink)">{{ __('hr::profile.tab_line') }}</a>
            <a href="#history" class="whitespace-nowrap px-3 py-2.5 text-sm text-(--color-ink-body) hover:text-(--color-ink)">{{ __('hr::profile.tab_history') }}</a>
            @if ($attendance !== null || $leave !== null)
                <a href="#attendance" class="whitespace-nowrap px-3 py-2.5 text-sm text-(--color-ink-body) hover:text-(--color-ink)">{{ __('hr::profile.tab_attendance') }}</a>
            @endif
            @can('viewSalary', $employee)
                <a href="#salary" class="whitespace-nowrap px-3 py-2.5 text-sm text-(--color-ink-body) hover:text-(--color-ink)">{{ __('hr::profile.tab_salary') }}</a>
            @endcan
        </nav>
    </section>

    {{-- ── সাধারণ তথ্য: ব্যক্তিগত · চাকরি ─────────────────────────────── --}}
    <div id="general" class="mb-4 grid gap-4 lg:grid-cols-2">
        @foreach ([
            'hr::profile.personal_info' => [
                'hr::field.father_name' => $employee->father_name,
                'hr::field.mother_name' => $employee->mother_name,
                'hr::field.date_of_birth' => $employee->date_of_birth?->format('d M Y'),
                'hr::field.blood_group' => $employee->blood_group,
                'hr::field.mobile' => $employee->mobile,
                'hr::field.email' => $employee->email,
                'hr::field.national_id' => \App\Core\Security\FieldSecurity::show(
                    $employee, 'national_id', $employee->national_id),
                'hr::field.present_address' => $employee->present_address,
                'hr::field.permanent_address' => $employee->permanent_address,
                'hr::field.emergency_name' => collect([$employee->emergency_name, $employee->emergency_relation ? '('.$employee->emergency_relation.')' : null])->filter()->implode(' '),
                'hr::field.emergency_mobile' => $employee->emergency_mobile,
            ],
            'hr::profile.job_info' => [
                'hr::field.department' => $employee->department?->name(),
                'hr::field.designation' => $title,
                'hr::field.employment_type' => $employee->employmentType?->name(),
                'hr::field.branch' => $employee->branch?->name(),
                'hr::field.joining_date' => $employee->joining_date?->format('d M Y'),
                'hr::field.leaving_date' => $employee->leaving_date?->format('d M Y'),
                'hr::profile.tenure' => $tenure,
                'hr::field.payment_method' => __('hr::kind.' . $employee->payment_method),
                'hr::field.bank_account_no' => \App\Core\Security\FieldSecurity::show(
                    $employee, 'bank_account_no', $employee->bank_account_no),
                'hr::field.mfs_number' => \App\Core\Security\FieldSecurity::show(
                    $employee, 'mfs_number', $employee->mfs_number),
            ],
        ] as $heading => $rows)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __($heading) }}</h2>
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    @foreach ($rows as $label => $value)
                        <div class="min-w-0">
                            <dt class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</dt>
                            <dd class="font-medium">{{ filled($value) ? $value : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>

    {{-- ── রিপোর্টিং লাইন (নকশা ১০) · চাকরির ইতিহাস (নকশা ৪) ─────────────────
         ⓘ উপরে যাঁর অধীনে, মাঝে তিনি নিজে, নিচে যাঁরা তাঁর অধীনে — একটা ছোট গাছ।
         ⓘ ইতিহাস নিরীক্ষার খাতা থেকে ([[JobHistory]]) — আলাদা খাতা নেই, তাই দুই কথা বলার সুযোগও নেই। --}}
    @php
        $person = function (\App\Modules\Hr\Models\Employee $e, bool $self = false) {
            return ['name' => $e->name(), 'title' => $e->designation?->name(), 'initial' => mb_substr(trim((string) $e->name()), 0, 1),
                'href' => $self ? null : route('hr.employee.show', $e), 'self' => $self];
        };
    @endphp
    <div class="mb-4 grid gap-4 lg:grid-cols-2">
        <section id="line" data-reporting-line data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('hr::profile.line_title') }}</h2>

            <div class="flex flex-col items-center gap-2">
                @php
                    $chain = [];
                    if ($employee->reportsTo) { $chain[] = $person($employee->reportsTo); }
                    $chain[] = ['name' => $employee->name(), 'title' => $title, 'initial' => $initial, 'href' => null, 'self' => true];
                @endphp

                @if (! $employee->reportsTo)
                    <p class="text-2xs text-(--color-ink-muted)">{{ __('hr::profile.nobody_above') }}</p>
                @endif

                @foreach ($chain as $i => $node)
                    @if ($i > 0)
                        <div class="h-4 w-px bg-(--color-border)" aria-hidden="true"></div>
                    @endif
                    @if ($node['href'])
                        <a href="{{ $node['href'] }}" class="flex w-full max-w-xs items-center gap-3 rounded-(--radius-field) border border-(--color-border) px-3 py-2 hover:bg-(--color-surface-hover)">
                    @else
                        <div @class(['flex w-full max-w-xs items-center gap-3 rounded-(--radius-field) border px-3 py-2',
                                     'border-(--color-brand-500) bg-(--color-brand-50)' => $node['self']])>
                    @endif
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-(--color-brand-50) font-bold text-(--color-brand-700)" aria-hidden="true">{{ $node['initial'] }}</span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold">{{ $node['name'] }}</span>
                            <span class="block truncate text-2xs text-(--color-ink-muted)">{{ $node['title'] ?? '—' }}</span>
                        </span>
                    @if ($node['href']) </a> @else </div> @endif
                @endforeach

                <div class="h-4 w-px bg-(--color-border)" aria-hidden="true"></div>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('hr::profile.team', ['count' => $employee->reports->count()]) }}</p>

                @if ($employee->reports->isEmpty())
                    <p class="text-sm text-(--color-ink-muted)">{{ __('hr::profile.nobody_below') }}</p>
                @else
                    <div class="grid w-full gap-2 sm:grid-cols-2">
                        @foreach ($employee->reports as $member)
                            <a href="{{ route('hr.employee.show', $member) }}" class="flex min-w-0 items-center gap-2 rounded-(--radius-field) border border-(--color-border) px-3 py-2 hover:bg-(--color-surface-hover)">
                                <span class="grid size-7 shrink-0 place-items-center rounded-full bg-(--color-brand-50) text-xs font-bold text-(--color-brand-700)" aria-hidden="true">{{ mb_substr(trim((string) $member->name()), 0, 1) }}</span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm">{{ $member->name() }}</span>
                                    <span class="block truncate text-2xs text-(--color-ink-muted)">{{ $member->designation?->name() ?? '—' }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <section id="history" data-job-history data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('hr::profile.history_title') }}</h2>

            <ol class="relative space-y-4 border-l-2 border-(--color-border)" style="padding-left: 1.25rem">
                @foreach ($history as $event)
                    @php
                        /* ⓘ যোগদানের পাশে আজকের পদবি — মাথার লাইনের একই `$title`, তাই মালিকের গ্রুপ-পদবি এখানেও একটাই */
                        if ($event['field'] === 'joined') { $event['to'] = $title; }
                    @endphp
                    <li class="relative">
                        <span @class(['absolute top-1 size-3 rounded-full', 'bg-(--color-brand-500)' => $loop->first, 'bg-(--color-border)' => ! $loop->first])
                              style="left: -1.6rem" aria-hidden="true"></span>
                        <p class="text-2xs text-(--color-ink-muted)">{{ $event['date']->format('d M Y') }}</p>
                        <p class="text-sm font-semibold">{{ __('hr::profile.event_'.$event['field']) }}</p>
                        @if ($event['from'] !== null || $event['to'] !== null)
                            <p class="text-sm text-(--color-ink-body)">
                                @if ($event['from'] !== null) {{ $event['from'] }} → @endif{{ $event['to'] ?? '—' }}
                            </p>
                        @endif
                        @if ($event['by'])
                            <p class="text-2xs text-(--color-ink-muted)">{{ __('hr::profile.by', ['name' => $event['by']]) }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>

            @if (count(array_filter($history, fn ($e) => ! in_array($e['field'], ['joined', 'left'], true))) === 0)
                <p class="mt-3 text-sm text-(--color-ink-muted)">{{ __('hr::profile.history_none') }}</p>
            @endif
        </section>
    </div>

    {{-- ── হাজিরা ও ছুটি ──────────────────────────────────────────────────
         ⓘ গোনা আর ক্যালেন্ডার একই সারি থেকে ([[AttendanceService::days()]]) — দুইটা কখনো দুই কথা বলে না।
         ⚠️ যে দিন লেখা হয়নি সেটা ধূসর "লেখা হয়নি", অনুপস্থিত নয়। --}}
    @if ($attendance !== null || $leave !== null)
        <section id="attendance" data-profile-attendance class="mb-4">
            <div class="mb-2 flex flex-wrap items-end justify-between gap-3">
                <h2 class="text-lg font-semibold">{{ __('hr::profile.attendance_title') }}</h2>

                <form method="GET" action="{{ route('hr.employee.show', $employee) }}#attendance" class="flex items-end gap-2">
                    <label class="flex flex-col gap-1 text-2xs text-(--color-ink-muted)">
                        {{ __('hr::profile.month') }}
                        <x-ui.month name="month" :value="$month->format('Y-m')"
                                    class="h-10 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm text-(--color-ink)" />
                    </label>
                    <x-ui.button type="submit">{{ __('hr::profile.show') }}</x-ui.button>
                </form>
            </div>

            @if ($attendance !== null)
                @php $sum = $attendance['summary']; @endphp

                <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    @foreach ([
                        ['hr::profile.present_days', $sum['present'], __('hr::profile.of_marked', ['count' => $sum['marked']])],
                        ['hr::profile.late', $sum['late'], null],
                        ['hr::profile.absent', $sum['absent'], null],
                        ['hr::profile.on_leave', $sum['leave'], null],
                    ] as [$label, $value, $note])
                        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                            <p class="text-sm text-(--color-ink-muted)">{{ __($label) }}</p>
                            <p class="tabular text-3xl font-bold text-(--color-brand-700)">{{ $value }}</p>
                            @if ($note)
                                <p class="text-2xs text-(--color-ink-muted)">{{ $note }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="grid gap-4 lg:grid-cols-3">
                @if ($attendance !== null)
                    @php
                        /*
                         * সপ্তাহ শনিবারে শুরু — দেশের অফিসের সপ্তাহ। প্রথম দিনের আগের ঘরগুলো ফাঁকা।
                         * Carbon-এ শনিবার = ৬, তাই (dayOfWeek + 1) % 7 শনিবারকে ০ বানায়।
                         */
                        $lead = ($month->dayOfWeek + 1) % 7;
                        $styles = [
                            'present' => 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)',
                            'late' => 'bg-(--color-badge-pending-bg) text-(--color-badge-pending-ink)',
                            'absent' => 'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)',
                            'leave' => 'bg-(--color-badge-info-bg) text-(--color-badge-info-ink)',
                            'holiday' => 'bg-(--color-badge-draft-bg) text-(--color-badge-draft-ink)',
                            'none' => 'border border-dashed border-(--color-border) text-(--color-ink-disabled)',
                        ];
                        $names = [
                            'present' => __('hr::kind.present'),
                            'late' => __('hr::profile.late'),
                            'absent' => __('hr::kind.absent'),
                            'leave' => __('hr::kind.leave'),
                            'holiday' => __('hr::kind.holiday'),
                            'none' => __('hr::profile.not_written'),
                        ];
                    @endphp

                    <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 lg:col-span-2">
                        <h3 class="mb-3 text-sm font-semibold">{{ __('hr::profile.calendar_title', ['month' => $month->translatedFormat('F Y')]) }}</h3>

                        <div class="grid gap-1.5 text-center text-xs" style="grid-template-columns: repeat(7, minmax(0, 1fr))">
                            @foreach (__('hr::profile.weekdays') as $weekday)
                                <div class="pb-1 text-2xs text-(--color-ink-muted)">{{ $weekday }}</div>
                            @endforeach

                            @for ($i = 0; $i < $lead; $i++)
                                <div></div>
                            @endfor

                            @for ($day = $month->copy(); $day->month === $month->month; $day->addDay())
                                @php
                                    $row = $attendance['days'][$day->toDateString()] ?? null;
                                    $kind = $row === null ? 'none'
                                        : ($row['status'] === 'present' && $row['late'] ? 'late' : $row['status']);
                                @endphp
                                <div class="rounded-(--radius-field) py-2 tabular {{ $styles[$kind] ?? $styles['none'] }}"
                                     title="{{ $day->format('d M') }} · {{ $names[$kind] ?? $kind }}">{{ $day->day }}</div>
                            @endfor
                        </div>

                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-2xs text-(--color-ink-body)">
                            @foreach ($names as $kind => $name)
                                <span class="flex items-center gap-1.5">
                                    <span class="inline-block size-3 rounded-sm {{ $styles[$kind] }}"></span>{{ $name }}
                                </span>
                            @endforeach
                        </div>

                        @if ($attendance['summary']['marked'] === 0)
                            <p class="mt-3 text-sm text-(--color-ink-muted)">{{ __('hr::profile.nothing_written') }}</p>
                        @endif
                    </div>
                @endif

                @if ($leave !== null)
                    <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                        <h3 class="mb-3 text-sm font-semibold">{{ __('hr::profile.leave_balance', ['year' => $month->year]) }}</h3>

                        @forelse ($leave as $row)
                            @php
                                /* ⓘ bcmath — টাকা-দিনের মতো ভগ্নাংশে float নয় (MoneyIsNeverAFloatTest) */
                                $share = bccomp((string) $row['allowed'], '0', 1) > 0
                                    ? min(100, (int) bcdiv(bcmul((string) $row['taken'], '100', 1), (string) $row['allowed'], 0))
                                    : 0;
                            @endphp
                            <div class="mb-3">
                                <div class="flex items-baseline justify-between gap-2 text-sm">
                                    <span class="truncate">{{ $row['type']->name() }}</span>
                                    <span class="shrink-0 text-xs text-(--color-ink-muted)">
                                        {{ $row['left'] === null ? __('hr::profile.unlimited') : __('hr::profile.left', ['left' => $row['left']]) }}
                                    </span>
                                </div>
                                @if ($row['left'] !== null)
                                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-(--color-brand-50)">
                                        <div class="h-full bg-(--color-brand-500)" style="width: {{ $share }}%"></div>
                                    </div>
                                    <p class="mt-0.5 text-2xs text-(--color-ink-muted)">{{ __('hr::profile.taken_of', ['taken' => $row['taken'], 'allowed' => $row['allowed']]) }}</p>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-(--color-ink-muted)">{{ __('hr::profile.no_leave_types') }}</p>
                        @endforelse

                        <a href="{{ route('hr.leave.index', ['employee' => $employee->id]) }}"
                           class="mt-1 flex h-11 items-center justify-center rounded-(--radius-field) bg-(--color-brand-50)
                                  text-sm font-semibold text-(--color-brand-700) hover:bg-(--color-brand-100)">
                            {{ __('hr::profile.see_leave') }}
                        </a>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- ── বেতন ──────────────────────────────────────────────────────────── --}}
    @can('viewSalary', $employee)
        <section id="salary" data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4" style="max-width: 36rem">
            <h2 class="mb-3 font-semibold">{{ __('hr::action.salary') }}</h2>

            @if ($components === [])
                <p class="text-sm text-(--color-ink-muted)">{{ __('hr::message.no_salary_yet') }}</p>
            @else
                <dl class="space-y-1 text-sm">
                    @foreach ($components as $component)
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-(--color-ink-muted)">
                                {{ $component['head']->name() }}
                                @unless ($component['head']->isEarning())
                                    <span class="text-2xs">({{ __('hr::kind.deduction') }})</span>
                                @endunless
                            </dt>
                            <dd class="num">{{ \App\Core\Support\Money::format($component['amount']) }}</dd>
                        </div>
                    @endforeach

                    <div class="mt-2 flex items-center justify-between gap-2 border-t border-(--color-border) pt-2
                                font-semibold">
                        <dt>{{ __('hr::field.net') }}</dt>
                        <dd class="num">{{ \App\Core\Support\Money::format($totals['net']) }}</dd>
                    </div>
                </dl>
            @endif
        </section>
    @endcan

    {{-- ⭐ জোড়া ডকুমেন্ট (ডকুমেন্ট পরিকল্পনা §১৫, ৯ অক্টোবর ২০২৬) — কোরের উপাদান; কিছু না থাকলে কিছুই বসে না --}}
    <x-ui.linked-documents :record="$employee" />
</x-layouts.app>
