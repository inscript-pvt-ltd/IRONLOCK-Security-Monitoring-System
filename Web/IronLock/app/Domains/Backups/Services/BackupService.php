<?php

namespace App\Domains\Backups\Services;

use App\Domains\Backups\Models\Backup;
use App\Domains\Photos\Models\PhotoEvidence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * BackupService — monthly evidence archive listing, ZIP generation and cleanup.
 *
 * A "backup" is a generated ZIP of the photo evidence captured during one
 * calendar month, mirroring the live storage tree (day → shift reference →
 * images). It is a READ-ONLY view over the existing private `photos` disk: it
 * never moves, mutates or deletes the original immutable evidence. A completed
 * month is downloadable for config('ironlock.backup_retention_days') days after
 * it ends; the scheduled cleanup then removes the backup record + any cached ZIP
 * — and only those, never the source evidence.
 *
 * The month a photo belongs to is taken straight from its stored file_path
 * (YYYY/MM/DD/REF/file), which is anchored to the authoritative server-receipt
 * date — so a backup's contents always match exactly where the files live.
 */
class BackupService
{
    /**
     * Build the list of monthly backups for the admin modal: the current month
     * (always, "permanent until month ends") plus every completed month that
     * still has evidence and is within its retention window. Newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $retention = $this->retentionDays();
        $currentKey = Carbon::now()->utc()->format('Y-m');

        // Image counts per month, from the authoritative storage path prefix.
        $counts = $this->monthCounts();

        // Months to show: every month that has evidence, plus the current month.
        $keys = array_keys($counts);
        if (!in_array($currentKey, $keys, true)) {
            $keys[] = $currentKey;
        }
        rsort($keys); // newest first

        $out = [];
        foreach ($keys as $key) {
            $start = Carbon::createFromFormat('Y-m-d', $key . '-01')->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $isCurrent = $key === $currentKey;
            $imageCount = $counts[$key] ?? 0;

            if ($isCurrent) {
                $out[] = [
                    'month_key' => $key,
                    'label' => $start->format('F Y'),
                    'is_current' => true,
                    'status' => 'Current Month',
                    'sublabel' => 'Permanent until month ends',
                    'days_remaining' => null,
                    'retention_days' => $retention,
                    'progress_percent' => 100,
                    'image_count' => $imageCount,
                    'downloadable' => $imageCount > 0,
                ];
                continue;
            }

            // Completed month — only listed while within the retention window
            // and while it actually holds evidence.
            if ($imageCount === 0) {
                continue;
            }

            $expiresAt = $end->copy()->endOfDay()->addDays($retention);
            $daysRemaining = $this->daysRemaining($expiresAt);
            if ($daysRemaining <= 0) {
                continue; // expired — cleanup will remove any record/cache
            }

            // Ensure a record exists so cleanup has something to act on later.
            $this->ensureRecord($key, $start, $end, $expiresAt);

            $out[] = [
                'month_key' => $key,
                'label' => $start->format('F Y'),
                'is_current' => false,
                'status' => $daysRemaining . ' ' . ($daysRemaining === 1 ? 'Day' : 'Days') . ' Remaining',
                'sublabel' => $daysRemaining . ' ' . ($daysRemaining === 1 ? 'day' : 'days') . ' remaining',
                'days_remaining' => $daysRemaining,
                'retention_days' => $retention,
                'progress_percent' => (int) max(0, min(100, round($daysRemaining / max(1, $retention) * 100))),
                'image_count' => $imageCount,
                'downloadable' => true,
            ];
        }

        return $out;
    }

    /**
     * Resolve a downloadable ZIP for a month: returns the absolute path to the
     * archive and the download filename, or null if the month has no evidence
     * or its retention has elapsed. A completed month is cached on the `backups`
     * disk (and recorded) so repeat downloads are cheap; the current month is
     * always generated fresh to a temp file the caller must delete after send.
     *
     * @return array{path: string, filename: string, ephemeral: bool}|null
     */
    public function resolveDownload(string $monthKey): ?array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
            return null;
        }

        $start = Carbon::createFromFormat('Y-m-d', $monthKey . '-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $isCurrent = $monthKey === Carbon::now()->utc()->format('Y-m');
        $filename = $start->format('F') . '_IronLock.zip';

        if (($this->monthCounts()[$monthKey] ?? 0) === 0) {
            return null; // nothing to archive
        }

        if ($isCurrent) {
            // Still accruing — generate fresh each time to a temp file.
            $tmpRel = 'tmp/' . $monthKey . '_' . bin2hex(random_bytes(4)) . '.zip';
            Storage::disk('backups')->makeDirectory('tmp');
            $abs = Storage::disk('backups')->path($tmpRel);
            if ($this->buildZip($monthKey, $abs) === 0) {
                @unlink($abs);
                return null;
            }
            return ['path' => $abs, 'filename' => $filename, 'ephemeral' => true];
        }

        // Completed month — respect retention, then serve (and cache) the archive.
        $expiresAt = $end->copy()->endOfDay()->addDays($this->retentionDays());
        if ($this->daysRemaining($expiresAt) <= 0) {
            return null; // retention elapsed
        }

        $record = $this->ensureRecord($monthKey, $start, $end, $expiresAt);

        if (!empty($record->zip_path) && Storage::disk('backups')->exists($record->zip_path)) {
            return ['path' => Storage::disk('backups')->path($record->zip_path), 'filename' => $filename, 'ephemeral' => false];
        }

        $rel = 'archives/' . $monthKey . '_IronLock.zip';
        Storage::disk('backups')->makeDirectory('archives');
        $abs = Storage::disk('backups')->path($rel);

        if ($this->buildZip($monthKey, $abs) === 0) {
            @unlink($abs);
            return null;
        }

        $record->update(['zip_path' => $rel, 'zip_generated_at' => Carbon::now()]);

        return ['path' => $abs, 'filename' => $filename, 'ephemeral' => false];
    }

    /**
     * Remove backups whose retention window has elapsed: delete the cached ZIP
     * (if any) and the record. NEVER touches the original photo_evidences.
     *
     * @return int how many backup records were removed
     */
    public function cleanupExpired(): int
    {
        $removed = 0;

        Backup::whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->get()
            ->each(function (Backup $backup) use (&$removed) {
                if (!empty($backup->zip_path)) {
                    try {
                        Storage::disk('backups')->delete($backup->zip_path);
                    } catch (\Throwable $e) {
                        Log::warning('Backup ZIP delete failed', ['month' => $backup->month_key, 'error' => $e->getMessage()]);
                    }
                }
                $backup->delete();
                $removed++;
            });

        // Sweep any stray temp archives left behind by an interrupted download.
        try {
            foreach (Storage::disk('backups')->files('tmp') as $tmp) {
                if (Carbon::createFromTimestamp(Storage::disk('backups')->lastModified($tmp))->lt(Carbon::now()->subHour())) {
                    Storage::disk('backups')->delete($tmp);
                }
            }
        } catch (\Throwable $e) {
            // best-effort
        }

        return $removed;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Build the ZIP for a month at $targetAbsolutePath, mirroring the storage
     * tree as {Month}_IronLock/{DD}/{SHIFT-REF}/{stored-filename}. The image keeps
     * the exact filename it was stored under (e.g. GRD6895-fullName_072759.jpg)
     * so an archived file maps straight back to its evidence record. Returns the
     * number of files written (0 if none/failure).
     */
    private function buildZip(string $monthKey, string $targetAbsolutePath): int
    {
        $prefix = str_replace('-', '/', $monthKey) . '/'; // YYYY/MM/
        $monthName = Carbon::createFromFormat('Y-m-d', $monthKey . '-01')->format('F');
        $root = $monthName . '_IronLock';

        $rows = PhotoEvidence::whereNotNull('file_path')
            ->where('file_path', 'like', $prefix . '%')
            ->orderBy('file_path')
            ->pluck('file_path');

        if ($rows->isEmpty()) {
            return 0;
        }

        $zip = new ZipArchive();
        if ($zip->open($targetAbsolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Log::error('Backup ZIP open failed', ['month' => $monthKey, 'target' => $targetAbsolutePath]);
            return 0;
        }

        $used = [];  // per "{DD}/{REF}" set of taken filenames → avoid zip collisions
        $added = 0;

        foreach ($rows as $path) {
            // path: YYYY/MM/DD/REF/storedfile
            $parts = explode('/', $path);
            if (count($parts) < 5) {
                continue;
            }
            [$y, $m, $day, $ref] = [$parts[0], $parts[1], $parts[2], $parts[3]];
            $orig = $parts[count($parts) - 1];

            $abs = Storage::disk('photos')->path($path);
            if (!is_file($abs)) {
                continue; // source missing — skip, never fail the whole archive
            }

            // Keep the stored filename. The on-disk names are already unique, but
            // de-collide defensively so two identical names in one day/shift folder
            // never overwrite each other inside the archive.
            $groupKey = $day . '/' . $ref;
            $name = $orig;
            if (isset($used[$groupKey][$name])) {
                $ext = pathinfo($orig, PATHINFO_EXTENSION);
                $base = $ext !== '' ? substr($orig, 0, -(strlen($ext) + 1)) : $orig;
                $i = 2;
                do {
                    $name = $ext !== '' ? "{$base}_{$i}.{$ext}" : "{$base}_{$i}";
                    $i++;
                } while (isset($used[$groupKey][$name]));
            }
            $used[$groupKey][$name] = true;

            if ($zip->addFile($abs, "{$root}/{$day}/{$ref}/{$name}")) {
                $added++;
            }
        }

        $zip->close();

        return $added;
    }

    /**
     * Distinct month → image-count map, keyed "YYYY-MM", from the evidence
     * storage path prefix (YYYY/MM). Mirrors exactly where files physically live.
     *
     * @return array<string, int>
     */
    private function monthCounts(): array
    {
        $rows = PhotoEvidence::whereNotNull('file_path')
            ->selectRaw('LEFT(file_path, 7) as ym, COUNT(*) as c')
            ->groupBy('ym')
            ->pluck('c', 'ym');

        $out = [];
        foreach ($rows as $ym => $count) {
            // ym is "YYYY/MM" from the path; normalise to "YYYY-MM".
            $out[str_replace('/', '-', (string) $ym)] = (int) $count;
        }

        return $out;
    }

    /**
     * Find or create the backup record for a completed month.
     */
    private function ensureRecord(string $monthKey, Carbon $start, Carbon $end, Carbon $expiresAt): Backup
    {
        return Backup::firstOrCreate(
            ['month_key' => $monthKey],
            [
                'label' => $start->format('F Y'),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'expires_at' => $expiresAt,
            ]
        );
    }

    private function daysRemaining(Carbon $expiresAt): int
    {
        $seconds = Carbon::now()->diffInSeconds($expiresAt, false);
        return $seconds <= 0 ? 0 : (int) ceil($seconds / 86400);
    }

    private function retentionDays(): int
    {
        return max(1, (int) config('ironlock.backup_retention_days', 30));
    }
}
