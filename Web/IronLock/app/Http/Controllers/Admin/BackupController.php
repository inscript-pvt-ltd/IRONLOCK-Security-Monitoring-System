<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Backups\Services\BackupService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin Backup Management (admin-auth only, via the admin route group).
 *
 * Lists monthly evidence archives for the Backup modal and streams a month's
 * ZIP on demand. Read-only over the existing evidence store — generating or
 * downloading a backup never alters the original photos. No public access.
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    /**
     * GET /admin/backups — JSON list of monthly backups for the modal.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'backups' => $this->backups->list(),
        ]);
    }

    /**
     * GET /admin/backups/{month}/download — stream the month's evidence ZIP.
     * {month} is "YYYY-MM". 404 when the month has no evidence or its retention
     * window has elapsed.
     */
    public function download(string $month): BinaryFileResponse
    {
        try {
            $archive = $this->backups->resolveDownload($month);
        } catch (\Throwable $e) {
            Log::error('Backup download failed', ['month' => $month, 'exception' => $e]);
            abort(500, 'Unable to generate the backup archive.');
        }

        if ($archive === null) {
            abort(404, 'No backup is available for this month.');
        }

        $response = response()->download($archive['path'], $archive['filename'], [
            'Content-Type' => 'application/zip',
        ]);

        // A freshly-generated current-month archive is temporary — delete it
        // once it has been streamed. Cached completed-month archives persist.
        if ($archive['ephemeral']) {
            $response->deleteFileAfterSend(true);
        }

        return $response;
    }
}
