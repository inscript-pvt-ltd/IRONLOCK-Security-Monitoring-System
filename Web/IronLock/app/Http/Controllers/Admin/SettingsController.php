<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Sites\Models\Site;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * SettingsController — per-site verification settings.
 *
 * Each non-archived site gets a row with two toggles (photo verification /
 * wakefulness) and a min/max minute gap for each. The gaps are optional: left
 * blank they inherit the global config default (see config/ironlock.php), which
 * is what the placeholder in the form shows.
 *
 * These settings are read when a shift is STARTED (Shift::buildPhotoSchedule /
 * buildWakefulnessSchedule provision the frozen schedule from them), so a change
 * here applies to shifts that start afterwards; a shift already in progress keeps
 * the schedule it was provisioned with.
 */
class SettingsController extends Controller
{
    /**
     * Smallest gap an admin may configure between two checks of the same type.
     *
     * This is an OFFLINE-delivery limit, not an arbitrary one. Per the Flutter
     * dev's 2026-07-27 reply: an offline check cannot fire in the background —
     * it needs the guard to foreground the app or tap the local notification, so
     * the capture lands 7–10 min after the mark in practice and the app skips
     * any mark older than its 15-minute fire window. Below ~20 min a late
     * capture spills into the next slot (PhotoVerificationService matches the
     * latest mark <= capture + 90s), so checks get attributed to the wrong slot
     * and the rest are recorded as missed — the compliance record would be
     * fiction. See FLUTTER_HANDOFF_PER_SITE_VERIFICATION_SETTINGS.md §5.
     */
    private const MIN_GAP_MINUTES = 20;

    /**
     * Show the settings table (one row per active/inactive, non-archived site).
     */
    public function index()
    {
        // Count shifts currently in progress per site. These settings are read
        // at shift START, so a site with a live shift keeps that shift's already
        // provisioned schedule — the row is badged to make that explicit rather
        // than blocked (the edit itself is safe). Mirrors the same withCount the
        // Sites page uses to lock geofence editing.
        $sites = Site::notArchived()
            ->withCount(['shifts as active_shifts_count' => function ($query) {
                $query->where('status', \App\Domains\Shifts\Models\Shift::STATUS_ACTIVE);
            }])
            ->orderBy('name')
            ->get();

        // Global fallbacks surfaced to the view as input placeholders, so an
        // admin can see the default that a blank field will use.
        $defaults = [
            'photo_min' => (int) config('ironlock.photo_min_gap_minutes', 50),
            'photo_max' => (int) config('ironlock.photo_max_gap_minutes', 70),
            'wake_min' => (int) config('ironlock.wakefulness_min_gap_minutes', 30),
            'wake_max' => (int) config('ironlock.wakefulness_max_gap_minutes', 45),
        ];

        return view('admin.settings.index', compact('sites', 'defaults'));
    }

    /**
     * Persist the settings for every submitted site row.
     *
     * The form posts parallel arrays keyed by site id. Toggles are checkboxes,
     * so an unchecked box is simply absent from the payload (= off). Gap fields
     * are optional; a blank field is stored as NULL (inherit the config default).
     */
    public function update(Request $request)
    {
        // Normalise FIRST. `site` arrives from a hand-built request as easily as
        // from our form, and a scalar here would blow up array_keys() below
        // before validation ever ran. Everything downstream uses $rows, never a
        // second read of the raw request.
        $rows = $request->input('site');
        $rows = is_array($rows) ? $rows : [];

        // Only sites that actually exist and are not archived may be written.
        $sites = Site::notArchived()->whereIn('id', array_keys($rows))->get()->keyBy('id');

        // Readable field names, so a failed rule reads "The photo min gap
        // (Big Ben) field must not be greater than 1440" rather than exposing
        // the raw "site.019ecba4-….photo_min_gap_minutes" path to the admin.
        $attributes = [];
        foreach ($sites as $id => $site) {
            $attributes["site.$id.photo_min_gap_minutes"] = "photo min gap ({$site->name})";
            $attributes["site.$id.photo_max_gap_minutes"] = "photo max gap ({$site->name})";
            $attributes["site.$id.wakefulness_min_gap_minutes"] = "wakefulness min gap ({$site->name})";
            $attributes["site.$id.wakefulness_max_gap_minutes"] = "wakefulness max gap ({$site->name})";
        }

        $min = self::MIN_GAP_MINUTES;
        $gapRule = "nullable|integer|min:$min|max:1440";

        $validator = Validator::make($request->all(), [
            'site' => 'array',
            'site.*' => 'array',
            'site.*.photo_min_gap_minutes' => $gapRule,
            'site.*.photo_max_gap_minutes' => $gapRule,
            'site.*.wakefulness_min_gap_minutes' => $gapRule,
            'site.*.wakefulness_max_gap_minutes' => $gapRule,
        ], [
            // Explain the floor rather than just stating it — an admin who wants
            // 5-minute checks needs to know why they cannot have them.
            'site.*.photo_min_gap_minutes.min' => "The :attribute must be at least {$min} minutes — shorter gaps cannot be delivered reliably while a guard is offline.",
            'site.*.photo_max_gap_minutes.min' => "The :attribute must be at least {$min} minutes — shorter gaps cannot be delivered reliably while a guard is offline.",
            'site.*.wakefulness_min_gap_minutes.min' => "The :attribute must be at least {$min} minutes — shorter gaps cannot be delivered reliably while a guard is offline.",
            'site.*.wakefulness_max_gap_minutes.min' => "The :attribute must be at least {$min} minutes — shorter gaps cannot be delivered reliably while a guard is offline.",
        ], $attributes);

        // min must not exceed max for either pair. Compared on the EFFECTIVE
        // values, not just when both fields are filled in: a blank field inherits
        // the global config default, so "max = 25, min blank" really means
        // 50..25 — which the schedule builder would silently flatten to 50..50
        // rather than reject. Catch it here instead.
        $validator->after(function ($validator) use ($rows, $sites) {
            $pairs = [
                ['photo', 'photo_min_gap_minutes', 'photo_max_gap_minutes', 'Photo'],
                ['wakefulness', 'wakefulness_min_gap_minutes', 'wakefulness_max_gap_minutes', 'Wakefulness'],
            ];

            foreach ($rows as $id => $row) {
                if (! is_array($row) || ! isset($sites[$id])) {
                    continue;
                }
                $name = $sites[$id]->name;

                foreach ($pairs as [$prefix, $minKey, $maxKey, $label]) {
                    $rawMin = $row[$minKey] ?? null;
                    $rawMax = $row[$maxKey] ?? null;

                    $effMin = $this->effectiveGap($rawMin, "ironlock.{$prefix}_min_gap_minutes");
                    $effMax = $this->effectiveGap($rawMax, "ironlock.{$prefix}_max_gap_minutes");

                    if ($effMin <= $effMax) {
                        continue;
                    }

                    // Point at whichever field the admin actually typed, and spell
                    // out the inherited number so a half-filled pair makes sense.
                    if ($rawMin !== null && $rawMin !== '' && $rawMax !== null && $rawMax !== '') {
                        $validator->errors()->add("site.$id.$minKey", "{$label} min gap cannot exceed max for {$name}.");
                    } elseif ($rawMax !== null && $rawMax !== '') {
                        $validator->errors()->add("site.$id.$maxKey", "{$label} max gap ({$effMax}) is below the inherited min gap ({$effMin}) for {$name} — set the min gap too, or raise the max.");
                    } else {
                        $validator->errors()->add("site.$id.$minKey", "{$label} min gap ({$effMin}) is above the inherited max gap ({$effMax}) for {$name} — set the max gap too, or lower the min.");
                    }
                }
            }
        });

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // Nothing writable matched (empty post, or every id was unknown/archived).
        // Say so plainly rather than flashing a success for a no-op.
        if ($sites->isEmpty()) {
            return back()->with('error', 'No settings were saved — those sites are no longer available. Reload the page and try again.');
        }

        try {
            DB::transaction(function () use ($rows, $sites) {
                foreach ($sites as $id => $site) {
                    $row = $rows[$id] ?? [];
                    if (! is_array($row)) {
                        $row = [];
                    }

                    $site->update([
                        // Checkbox absent => off. A hidden "0" companion field also
                        // guarantees the key is present, but array_key_exists on the
                        // checkbox value is the authority.
                        'photo_verification_enabled' => ! empty($row['photo_verification_enabled']),
                        'wakefulness_enabled' => ! empty($row['wakefulness_enabled']),
                        'photo_min_gap_minutes' => $this->nullableInt($row['photo_min_gap_minutes'] ?? null),
                        'photo_max_gap_minutes' => $this->nullableInt($row['photo_max_gap_minutes'] ?? null),
                        'wakefulness_min_gap_minutes' => $this->nullableInt($row['wakefulness_min_gap_minutes'] ?? null),
                        'wakefulness_max_gap_minutes' => $this->nullableInt($row['wakefulness_max_gap_minutes'] ?? null),
                    ]);
                }
            });
        } catch (\Exception $e) {
            // The transaction rolled back, so no site was half-updated. Keep the
            // admin's input so nothing they typed is lost on the repaint.
            Log::error('Error saving per-site verification settings', [
                'admin_id' => session('admin_id'),
                'site_ids' => $sites->keys()->all(),
                'exception' => $e,
            ]);

            return back()
                ->withInput()
                ->with('error', 'Could not save the settings. No changes were made — please try again.');
        }

        Log::info('Admin updated per-site verification settings', [
            'admin_id' => session('admin_id'),
            'site_count' => $sites->count(),
        ]);

        return back()->with('success', 'Verification settings saved.');
    }

    /**
     * The gap that will actually be used: the submitted value, or the global
     * config default when the field was left blank (which is what a NULL column
     * resolves to in Site::photoMinGapMinutes() and friends).
     */
    private function effectiveGap(mixed $value, string $configKey): int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return (int) config($configKey);
        }

        return (int) $value;
    }

    /**
     * Blank string / null => NULL (inherit default); otherwise a plain int.
     */
    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
