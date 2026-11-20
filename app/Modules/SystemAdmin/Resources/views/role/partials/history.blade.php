{{--
    ⭐ বদলের ইতিহাস — রোলের নিজের খাতা, কারণসহ, নতুন আগে (মালিকের অনুমোদিত নমুনা, ২০ নভেম্বর ২০২৬, দফা ৭)।
    ⓘ শেষ পঞ্চাশটা ([[RoleController::history()]]); পুরো খাতা আর আগের-পরের মান নিরীক্ষার পাতায় (চাবি থাকলে)।
    $trails
--}}
@if ($trails->isEmpty())
    <p class="role-note">{{ __('system_admin::field.no_history') }}</p>
@else
    <ol class="role-history" data-role-history>
        @foreach ($trails as $trail)
            <li data-role-history-row>
                <div class="role-history-top">
                    <span class="num">{{ \App\Core\Support\DateFormat::spoken($trail->created_at) }}</span>
                    <b>{{ \App\Models\AuditTrail::actionInWords($trail->action) }}</b>
                    <span class="role-grow"></span>
                    <span>{{ $trail->user?->name ?? __('system_admin::field.by_system') }}</span>
                    @if (\Illuminate\Support\Facades\Route::has('governance.audit.record') && auth()->user()?->can('governance.audit.view'))
                        <a href="{{ route('governance.audit.record', $trail->id) }}" data-change-history>{{ __('core.audit.history') }} →</a>
                    @endif
                </div>
                @if ($trail->reason)
                    <p class="role-history-reason">{{ __('system_admin::permission.history_reason', ['reason' => $trail->reason]) }}</p>
                @endif
                @foreach ($trail->getRelation('changes') as $change)
                    @if (($change->new_value ?? '') !== '' || ($change->old_value ?? '') !== '')
                        <p class="role-history-change">
                            <span>{{ \Illuminate\Support\Facades\Lang::has('system_admin::permission.history_field.'.$change->field) ? __('system_admin::permission.history_field.'.$change->field) : str_replace('_', ' ', $change->field) }}:</span>
                            {{ \Illuminate\Support\Str::limit(trim(($change->old_value ?? '').' → '.($change->new_value ?? ''), ' →'), 400) }}
                        </p>
                    @endif
                @endforeach
            </li>
        @endforeach
    </ol>
@endif
