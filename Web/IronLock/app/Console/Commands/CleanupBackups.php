<?php

namespace App\Console\Commands;

use App\Domains\Backups\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Remove monthly evidence backups whose retention window has elapsed.
 *
 * Deletes the backup record and any cached ZIP archive once a completed month is
 * older than config('ironlock.backup_retention_days'). It NEVER deletes the
 * original photo_evidences (they are immutable) — only the generated archives.
 * Scheduled daily; safe to run by hand.
 */
class CleanupBackups extends Command
{
    protected $signature = 'ironlock:backups-cleanup';

    protected $description = 'Delete expired monthly evidence backup archives (retention cleanup)';

    public function handle(BackupService $backups): int
    {
        $removed = $backups->cleanupExpired();
        $this->info("Backup cleanup complete — {$removed} expired backup(s) removed.");

        return self::SUCCESS;
    }
}
