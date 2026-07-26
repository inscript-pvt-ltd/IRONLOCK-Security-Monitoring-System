@extends('admin.layouts.app')

@section('title', 'Settings - IronLock')
@section('page-title', 'Settings')

@section('styles')
<style>
    .settings-intro {
        font-size: 12px;
        color: var(--text-secondary);
        line-height: 1.6;
        margin-bottom: 18px;
        max-width: 760px;
    }

    .settings-table-wrap { overflow-x: auto; }

    .settings-table {
        width: 100%;
        min-width: 720px;
        border-collapse: collapse;
        font-size: 12px;
    }
    .settings-table th,
    .settings-table td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--border-dark);
        text-align: left;
        vertical-align: middle;
    }
    .settings-table thead th {
        background: var(--border-dark);
        font-size: 9px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-secondary);
        white-space: nowrap;
    }
    /* Column-group header row (Photo Verification / Wakefulness spanners). */
    .settings-table thead .group-row th {
        text-align: center;
        border-bottom: 1px solid var(--border-dark);
    }
    .settings-table .col-group-photo { border-left: 2px solid var(--border-dark); }
    .settings-table .col-group-wake { border-left: 2px solid var(--border-dark); }
    .settings-table tbody tr:last-child td { border-bottom: none; }
    .settings-table tbody tr:hover { background: rgba(255, 255, 255, 0.02); }

    .settings-site-name { font-weight: bold; color: var(--text-primary); }
    .settings-site-sub { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

    /* "On duty now" badge — this site has a shift in progress, so a change here
       won't affect it (that shift's schedule was provisioned at start). */
    .settings-live-badge {
        display: inline-flex; align-items: center; gap: 5px;
        margin-left: 8px; padding: 2px 8px;
        font-size: 9px; font-weight: bold; text-transform: uppercase;
        letter-spacing: 0.05em; white-space: nowrap;
        color: var(--success-green);
        border: 1px solid var(--success-green);
        background: rgba(34, 197, 94, 0.12);
        border-radius: 9px;
        vertical-align: middle;
        cursor: help;
    }
    .settings-live-dot {
        width: 6px; height: 6px; border-radius: 50%;
        background: var(--success-green);
        animation: settings-live-pulse 1.8s ease-in-out infinite;
    }
    @keyframes settings-live-pulse { 50% { opacity: 0.3; } }

    .settings-live-note {
        display: flex; align-items: flex-start; gap: 8px;
        margin-bottom: 16px; padding: 10px 12px;
        font-size: 11px; line-height: 1.5;
        color: var(--text-secondary);
        border: 1px solid var(--border-dark);
        border-left: 3px solid var(--success-green);
        border-radius: 4px;
        background: rgba(34, 197, 94, 0.06);
    }

    .gap-input {
        width: 64px;
        background: var(--bg-dark);
        border: 1.5px solid var(--border-dark);
        color: var(--text-primary);
        padding: 6px 8px;
        border-radius: 4px;
        font-size: 12px;
        text-align: center;
    }
    .gap-input:focus { outline: none; border-color: var(--premium-gold); }
    .gap-input:disabled { opacity: 0.4; cursor: not-allowed; }
    .gap-unit { font-size: 10px; color: var(--text-muted); margin-left: 4px; }
    .gap-cell-error .gap-input { border-color: var(--error-red); }

    /* Toggle switch */
    .switch { position: relative; display: inline-block; width: 40px; height: 22px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .switch .slider {
        position: absolute; cursor: pointer; inset: 0;
        background: var(--border-dark);
        border-radius: 22px; transition: background 0.2s ease;
    }
    .switch .slider::before {
        content: ""; position: absolute; height: 16px; width: 16px;
        left: 3px; bottom: 3px; background: var(--text-secondary);
        border-radius: 50%; transition: transform 0.2s ease, background 0.2s ease;
    }
    .switch input:checked + .slider { background: var(--success-green); }
    .switch input:checked + .slider::before { transform: translateX(18px); background: #fff; }

    .settings-actions {
        display: flex; align-items: center; gap: 14px;
        margin-top: 20px;
    }
    .settings-save-btn {
        display: inline-flex; align-items: center; gap: 8px;
        background: var(--premium-gold); color: #1a1407; border: none;
        font-size: 12px; font-weight: bold; padding: 10px 22px;
        border-radius: 5px; cursor: pointer;
    }
    .settings-save-btn:hover { opacity: 0.9; }
    .settings-save-btn:disabled { opacity: 0.6; cursor: default; }

    /* Spinner is hidden until the button enters its saving state. */
    .settings-save-spinner { display: none; }
    .settings-save-btn.is-saving .settings-save-spinner {
        display: inline-block;
        width: 13px; height: 13px;
        border: 2px solid rgba(26, 20, 7, 0.35);
        border-top-color: #1a1407;
        border-radius: 50%;
        animation: settings-spin 0.6s linear infinite;
    }
    @keyframes settings-spin { to { transform: rotate(360deg); } }

    .settings-empty {
        padding: 40px; text-align: center; color: var(--text-muted); font-size: 13px;
        border: 1px solid var(--border-dark); border-radius: 6px;
    }
    .field-error-text { color: var(--error-red); font-size: 10px; margin-top: 4px; display: block; }

    .settings-error-list {
        margin: 14px 0 0; padding: 10px 12px 10px 30px;
        list-style: disc;
        font-size: 11px; line-height: 1.6;
        color: var(--error-red);
        border: 1px solid var(--error-red);
        border-radius: 4px;
        background: rgba(239, 68, 68, 0.08);
    }
</style>
@endsection

@section('content')
<p class="settings-intro">
    Turn photo verification and wakefulness checks on or off per site, and set how
    often each one runs. The <strong>min/max</strong> minutes bound the random gap
    between consecutive checks — leave a field blank to use the system default
    (shown as the placeholder). A change takes effect on shifts that start
    afterwards; a shift already in progress keeps its provisioned schedule.
</p>

@php
    // Any site with a shift in progress right now? Drives the banner below.
    $hasLiveShifts = $sites->contains(fn ($s) => (int) ($s->active_shifts_count ?? 0) > 0);
@endphp

@if ($sites->isEmpty())
    <div class="settings-empty">No sites yet. Add a site first, then its verification settings appear here.</div>
@else
    @if ($hasLiveShifts)
        <div class="settings-live-note">
            <span class="settings-live-dot" style="margin-top:5px;"></span>
            <span>
                Some sites have a <strong>shift in progress</strong>. Saving is safe — a running
                shift keeps the schedule it was given when it started, so these changes will not
                interrupt or alter it. They apply to shifts that start from now on.
            </span>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.settings.update') }}" id="settingsForm">
        @csrf
        @method('PUT')

        <div class="table-container settings-table-wrap">
            <table class="settings-table">
                <thead>
                    <tr class="group-row">
                        <th rowspan="2">Site</th>
                        <th colspan="3" class="col-group-photo">Photo Verification</th>
                        <th colspan="3" class="col-group-wake">Wakefulness</th>
                    </tr>
                    <tr>
                        <th class="col-group-photo">On</th>
                        <th>Min</th>
                        <th>Max</th>
                        <th class="col-group-wake">On</th>
                        <th>Min</th>
                        <th>Max</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sites as $site)
                        @php
                            $base = "site.{$site->id}";
                            $photoOn = old("$base.photo_verification_enabled", $site->photo_verification_enabled) ? true : false;
                            $wakeOn = old("$base.wakefulness_enabled", $site->wakefulness_enabled) ? true : false;
                            $liveCount = (int) ($site->active_shifts_count ?? 0);
                            $liveLabel = $liveCount === 1 ? '1 shift is' : $liveCount . ' shifts are';
                        @endphp
                        <tr>
                            <td>
                                <div class="settings-site-name">
                                    {{ $site->name }}
                                    @if ($liveCount > 0)
                                        <span class="settings-live-badge"
                                              title="{{ $liveLabel }} in progress at this site. Changes here apply to shifts that start later — the running shift keeps the schedule it was given at start.">
                                            <span class="settings-live-dot"></span>{{ $liveCount }} ON DUTY
                                        </span>
                                    @endif
                                </div>
                                @if ($site->address)
                                    <div class="settings-site-sub">{{ \Illuminate\Support\Str::limit($site->address, 44) }}</div>
                                @endif
                            </td>

                            {{-- Photo verification --}}
                            <td class="col-group-photo">
                                {{-- Hidden companion: guarantees an "off" toggle posts a 0, so
                                     the intent is explicit and repaints stay accurate. --}}
                                <input type="hidden" name="site[{{ $site->id }}][photo_verification_enabled]" value="0">
                                <label class="switch">
                                    <input type="checkbox" name="site[{{ $site->id }}][photo_verification_enabled]" value="1"
                                           data-toggle="photo-{{ $site->id }}" {{ $photoOn ? 'checked' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td @class(['gap-cell-error' => $errors->has("$base.photo_min_gap_minutes")])>
                                <input type="number" min="1" max="1440" class="gap-input" data-gap="photo-{{ $site->id }}"
                                       name="site[{{ $site->id }}][photo_min_gap_minutes]"
                                       value="{{ old("$base.photo_min_gap_minutes", $site->photo_min_gap_minutes) }}"
                                       placeholder="{{ $defaults['photo_min'] }}" {{ $photoOn ? '' : 'disabled' }}>
                            </td>
                            <td @class(['gap-cell-error' => $errors->has("$base.photo_max_gap_minutes")])>
                                <input type="number" min="1" max="1440" class="gap-input" data-gap="photo-{{ $site->id }}"
                                       name="site[{{ $site->id }}][photo_max_gap_minutes]"
                                       value="{{ old("$base.photo_max_gap_minutes", $site->photo_max_gap_minutes) }}"
                                       placeholder="{{ $defaults['photo_max'] }}" {{ $photoOn ? '' : 'disabled' }}>
                                <span class="gap-unit">min</span>
                            </td>

                            {{-- Wakefulness --}}
                            <td class="col-group-wake">
                                <input type="hidden" name="site[{{ $site->id }}][wakefulness_enabled]" value="0">
                                <label class="switch">
                                    <input type="checkbox" name="site[{{ $site->id }}][wakefulness_enabled]" value="1"
                                           data-toggle="wake-{{ $site->id }}" {{ $wakeOn ? 'checked' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td @class(['gap-cell-error' => $errors->has("$base.wakefulness_min_gap_minutes")])>
                                <input type="number" min="1" max="1440" class="gap-input" data-gap="wake-{{ $site->id }}"
                                       name="site[{{ $site->id }}][wakefulness_min_gap_minutes]"
                                       value="{{ old("$base.wakefulness_min_gap_minutes", $site->wakefulness_min_gap_minutes) }}"
                                       placeholder="{{ $defaults['wake_min'] }}" {{ $wakeOn ? '' : 'disabled' }}>
                            </td>
                            <td @class(['gap-cell-error' => $errors->has("$base.wakefulness_max_gap_minutes")])>
                                <input type="number" min="1" max="1440" class="gap-input" data-gap="wake-{{ $site->id }}"
                                       name="site[{{ $site->id }}][wakefulness_max_gap_minutes]"
                                       value="{{ old("$base.wakefulness_max_gap_minutes", $site->wakefulness_max_gap_minutes) }}"
                                       placeholder="{{ $defaults['wake_max'] }}" {{ $wakeOn ? '' : 'disabled' }}>
                                <span class="gap-unit">min</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Every failing row is listed, not just the first — with several sites
             on one form, a single message would hide the other problems. --}}
        @if ($errors->any())
            <ul class="settings-error-list">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        <div class="settings-actions">
            <button type="submit" class="settings-save-btn" id="settingsSaveBtn">
                <span class="settings-save-spinner" aria-hidden="true"></span>
                <span class="settings-save-label">SAVE SETTINGS</span>
            </button>
        </div>
    </form>
@endif
@endsection

@section('scripts')
<script>
    // A verification's min/max inputs are only editable while its toggle is on.
    // (When off the shift schedule is empty, so the gap values are irrelevant.)
    (function () {
        function syncRow(toggle) {
            var group = toggle.getAttribute('data-toggle');
            var inputs = document.querySelectorAll('input.gap-input[data-gap="' + group + '"]');
            inputs.forEach(function (inp) { inp.disabled = !toggle.checked; });
        }
        document.querySelectorAll('input[type="checkbox"][data-toggle]').forEach(function (toggle) {
            toggle.addEventListener('change', function () { syncRow(toggle); });
        });
    })();

    // Show a loading state on Save and lock the button so the form can't be
    // double-submitted. Disabled number inputs already stay out of the payload;
    // the button is disabled AFTER submit fires so its value still posts.
    (function () {
        var form = document.getElementById('settingsForm');
        var btn = document.getElementById('settingsSaveBtn');
        if (!form || !btn) return;

        form.addEventListener('submit', function () {
            btn.classList.add('is-saving');
            btn.querySelector('.settings-save-label').textContent = 'SAVING…';
            // Defer disabling until the current submit is dispatched, so the
            // native form POST isn't cancelled.
            setTimeout(function () { btn.disabled = true; }, 0);
        });
    })();
</script>
@endsection
