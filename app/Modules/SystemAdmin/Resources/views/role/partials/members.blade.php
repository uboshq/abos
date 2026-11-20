{{--
    ⭐ সদস্য — সময়-বাঁধা সদস্যপদসহ (মালিকের অনুমোদিত নমুনা, ২০ নভেম্বর ২০২৬, দফা ১০)।

    নাম · রোল এসেছে (সরাসরি / যৌথ রোলের ভেতর দিয়ে) · থেকে · পর্যন্ত · অবস্থা (চালু / n দিন পরে শেষ / শুরুর অপেক্ষায় / শেষ) · সময় বদলান।
    ⓘ সময় শেষ হলে রোল নিজে ওঠে ([[RoleTerms]]) — কারও মনে রাখতে হয় না; শেষের আগের দিন মালিক আর ঐ মানুষ খবর পান।
    ⓘ ইমেইল পুরো কেবল মানুষ সামলানোর চাবিতে ([[ContactMask]]) — দফা ১২।
    $rows · $role
--}}
@if ($rows === [])
    <p class="role-note">{{ __('system_admin::permission.no_users') }}</p>
@else
    <div class="role-table-wrap">
        <table class="role-list-table" data-role-members>
            <thead>
                <tr>
                    <th>{{ __('system_admin::permission.member_name') }}</th>
                    <th>{{ __('system_admin::permission.member_via') }}</th>
                    <th>{{ __('system_admin::permission.member_from') }}</th>
                    <th>{{ __('system_admin::permission.member_until') }}</th>
                    <th>{{ __('system_admin::permission.member_state') }}</th>
                    <th><span class="sr-only">{{ __('system_admin::permission.term_change') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php
                        $user = $row['user'];
                        $term = $row['term'];
                        [$tone, $words] = $row['state'];
                    @endphp
                    <tr data-role-member="{{ $user->id }}">
                        <td>
                            @if (\Illuminate\Support\Facades\Route::has('system_admin.user.edit'))
                                <a href="{{ route('system_admin.user.edit', $user->id) }}" class="role-member-name">{{ $user->name }}</a>
                            @else
                                {{ $user->name }}
                            @endif
                            <small class="role-member-mail">{{ \App\Core\Support\ContactMask::email($user->email) }}</small>
                        </td>
                        <td>
                            @if ($row['via'])
                                {{ __('system_admin::permission.member_via_composite', ['role' => $row['via']]) }}
                            @else
                                {{ __('system_admin::permission.member_direct') }}@if ($term?->note) · {{ $term->note }}@endif
                            @endif
                        </td>
                        <td class="num">{{ $term?->valid_from ? \App\Core\Support\DateFormat::spokenDate($term->valid_from) : '—' }}</td>
                        <td class="num">{{ $term?->valid_until ? \App\Core\Support\DateFormat::spokenDate($term->valid_until) : '— '.__('system_admin::permission.term_permanent') }}</td>
                        <td><span class="role-state is-{{ $tone }}" data-role-term-state="{{ $tone }}">{{ $words }}</span></td>
                        <td>
                            @if ($row['editable'])
                                <details class="role-term" data-role-term>
                                    <summary class="role-btn">{{ __('system_admin::permission.term_change') }}</summary>
                                    <form method="POST" action="{{ route('system_admin.role.term', ['role' => $role, 'user' => $user->id]) }}" class="role-pop role-term-form">
                                        @csrf
                                        @method('PUT')
                                        <label><span>{{ __('system_admin::permission.member_from') }}</span>
                                            <input type="date" name="valid_from" value="{{ $term?->valid_from }}"></label>
                                        <label><span>{{ __('system_admin::permission.member_until') }}</span>
                                            <input type="date" name="valid_until" value="{{ $term?->valid_until }}"></label>
                                        <label><span>{{ __('system_admin::permission.term_note') }}</span>
                                            <input type="text" name="note" maxlength="200" value="{{ $term?->note }}"></label>
                                        <p class="role-note">{{ __('system_admin::permission.term_hint') }}</p>
                                        <button type="submit" class="role-btn is-primary">{{ __('core.action.save') }}</button>
                                    </form>
                                </details>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="role-note">{{ __('system_admin::permission.term_footnote') }}</p>
@endif
