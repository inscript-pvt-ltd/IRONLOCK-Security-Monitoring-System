<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Admins\Models\Admin;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Shifts\Models\ShiftEvent;
use App\Domains\Guards\Models\Guard;
use App\Domains\Sites\Models\Site;
use App\Domains\Geofences\Models\Geofence;
use App\Domains\Shifts\Models\WorkingTimeOverride;
use App\Domains\Photos\Models\PhotoEvidence;
use App\Domains\Photos\Models\PhotoRequest;
use App\Domains\Photos\Models\PhotoReview;
use App\Domains\Photos\Services\PhotoVerificationService;
use App\Domains\Notifications\Services\PhotoPushNotifier;
use App\Domains\Wakefulness\Models\WakefulnessCheck;
use App\Domains\Wakefulness\Services\WakefulnessService;
use App\Domains\Authentication\Services\ShiftAccessLinkService;
use App\Domains\Compliance\Services\ComplianceCalculator;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Carbon\Carbon;

/**
 * ShiftController - Shift Management with UK Working Time Regulations Compliance
 *
 * Handles shift scheduling, assignment, and lifecycle management with:
 * - Working Time Regulations 1998 compliance validation
 * - 12-hour warning, 16-hour hard limit enforcement
 * - 11-hour rest period validation
 * - Override management with documented justification
 * - Guard assignment to sites with geofence integration
 * - Real-time shift status tracking
 */
class ShiftController extends Controller
{
    /**
     * Display shifts with filtering and search.
     */
    public function index(Request $request)
    {
        // Validate filter inputs up front so malformed values (e.g. a bad
        // date string passed to Carbon::parse) cannot trigger an unhandled 500.
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:scheduled,checked_in,active,completed,cancelled,missed',
            'guard_id' => 'nullable|uuid',
            'site_id' => 'nullable|uuid',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }
            return redirect()
                ->route('admin.shifts.index')
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $query = Shift::with(['assignedGuard', 'site', 'geofence', 'creator', 'workingTimeOverrides.approvedBy'])
                ->when($request->get('status'), function($q, $v) {
                    $q->where('status', $v);
                })
                ->when($request->get('guard_id'), function($q, $v) {
                    $q->where('guard_id', $v);
                })
                ->when($request->get('site_id'), function($q, $v) {
                    $q->where('site_id', $v);
                })
                ->when($request->get('date_from'), function($q, $v) {
                    $q->where('scheduled_start', '>=', Carbon::parse($v)->startOfDay());
                })
                ->when($request->get('date_to'), function($q, $v) {
                    $q->where('scheduled_start', '<=', Carbon::parse($v)->endOfDay());
                })
                ->orderBy('scheduled_start', 'asc');

            // AJAX calls from the calendar JS (Accept: application/json) get a
            // flat array of all matching shifts so the calendar can render them.
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'shifts' => $query->get(),
                ]);
            }

            $shifts = $query->paginate(20)->withQueryString();

            return view('admin.shifts.index', compact('shifts'));
        } catch (\Exception $e) {
            Log::error('Error loading shifts list', ['exception' => $e]);

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'error' => 'Unable to load shifts.'], 500);
            }

            return redirect()
                ->route('admin.dashboard')
                ->with('error', 'Unable to load shifts. Please try again.');
        }
    }

    /**
     * Store a new shift with WTR validation.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'guard_id' => 'required|exists:guards,id',
            'site_id' => 'required|exists:sites,id',
            'scheduled_start' => 'required|date|after:now',
            'scheduled_end' => 'required|date|after:scheduled_start',
            'override_12hr_warning' => 'nullable|boolean',
            'override_11hr_rest' => 'nullable|boolean',
            'override_justification' => 'required_if:override_12hr_warning,true|required_if:override_11hr_rest,true|nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $guard = Guard::findOrFail($request->guard_id);
            $site = Site::findOrFail($request->site_id);

            // Get active geofence for the site
            $geofence = Geofence::where('site_id', $site->id)
                ->where('is_active', true)
                ->first();

            if (!$geofence) {
                return response()->json([
                    'success' => false,
                    'error' => 'No active geofence found for the selected site. Please set up a geofence first.',
                    'redirect' => '/admin/sites'
                ], 422);
            }

            $scheduledStart = Carbon::parse($request->scheduled_start);
            $scheduledEnd = Carbon::parse($request->scheduled_end);

            // Working Time Regulations Validation
            $wtrValidation = $this->validateWorkingTimeRegulations(
                $guard->id,
                $scheduledStart,
                $scheduledEnd
            );

            // Handle WTR violations
            if (!$wtrValidation['compliant']) {
                $assessment = $this->assessWtrViolations(
                    $wtrValidation,
                    $request->boolean('override_12hr_warning'),
                    $request->boolean('override_11hr_rest')
                );

                // 16-hour maximum is a hard block — never overridable (spec rule #22).
                if ($assessment['blocked']) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Shift duration exceeds 16 hours maximum. This cannot be overridden.',
                        'wtr_violation' => $assessment['block_violation']
                    ], 422);
                }

                if (!empty($assessment['override_required'])) {
                    return response()->json([
                        'success' => false,
                        'wtr_warning' => true,
                        'violations' => $wtrValidation['violations'],
                        'override_required' => $assessment['override_required'],
                        'message' => 'Working Time Regulations compliance issues detected. Override required with justification.'
                    ], 422);
                }
            }

            // Check for shift conflicts
            $conflicts = $this->checkShiftConflicts($guard->id, $scheduledStart, $scheduledEnd);
            if (!empty($conflicts)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Shift conflicts detected with existing shifts',
                    'conflicts' => $conflicts
                ], 422);
            }

            // Create the shift
            $shift = Shift::create([
                'guard_id' => $guard->id,
                'site_id' => $site->id,
                'geofence_id' => $geofence->id,
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                'status' => 'scheduled',
                'created_by' => $this->currentAdminId(),
            ]);

            // Create WTR overrides if needed
            if (($request->boolean('override_12hr_warning') || $request->boolean('override_11hr_rest')) && $request->override_justification) {
                $this->createWorkingTimeOverrides($shift, $wtrValidation['violations'], $request->override_justification);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Shift scheduled successfully',
                'shift' => $shift->load('assignedGuard', 'site', 'geofence'),
                'wtr_compliance' => $wtrValidation
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error creating shift', ['exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to create shift. Please try again.'
            ], 500);
        }
    }

    /**
     * Shift Detail & Timeline page (D-04 / ADM-009).
     *
     * Renders the append-only audit timeline for one shift from its
     * shift_events, plus the compliance summary. Only the shift-lifecycle events
     * exist today (check-in, start, end, missed, resolution actions); GPS,
     * wakefulness, photo and alert events arrive in later roadmap phases, so the
     * view shows placeholders for those sections.
     */
    public function timeline(string $id, ComplianceCalculator $compliance)
    {
        try {
            $shift = Shift::with(['assignedGuard', 'site', 'geofence', 'creator', 'lateAuthorizer'])
                ->findOrFail($id);

            // Append-only audit trail, newest first, ordered by the authoritative
            // server timestamp so the most recent activity reads at the top.
            $events = ShiftEvent::with('assignedGuard')
                ->where('shift_id', $shift->id)
                ->orderByDesc('server_received_at')
                ->orderByDesc('created_at')
                ->get();

            // Photo verification (Phase 4): all requests for this shift, newest
            // first, each with ALL its stored evidence images (a request may now
            // carry up to 5). The images are served only through an admin-
            // authenticated route (viewPhoto) — the file is never publicly
            // accessible and no raw path leaks into the page.
            // Oldest request first so the attempt ordinals read chronologically:
            // the first photo asked of the guard is "1st Attempt", and the list
            // renders top-to-bottom in the order the checks actually happened.
            $photoRequests = PhotoRequest::with('evidences.review')
                ->where('shift_id', $shift->id)
                ->orderBy('requested_at')
                ->get()
                ->map(function (PhotoRequest $r) {
                    $evidences = $r->evidences
                        ->sortBy('created_at')
                        ->values()
                        ->map(function (PhotoEvidence $e) {
                            $review = $e->review;
                            return [
                                'evidence_id' => $e->id,
                                'evidence_status' => $e->status(),
                                'flags' => $e->flags ?? [],
                                'gps_latitude' => $e->gps_latitude,
                                'gps_longitude' => $e->gps_longitude,
                                'captured_at' => $e->captured_at,
                                'view_url' => route('admin.photos.view', $e->id),
                                // Review state — drives the Review button / decision badge.
                                'review_url' => route('admin.photos.review', $e->id),
                                'can_review' => $review === null,
                                'review_decision' => $review?->decision,    // APPROVED | REJECTED | null
                                'review_note' => $review?->note,
                                'reviewed_at' => $review?->reviewed_at,
                            ];
                        })->all();

                    return [
                        'status' => $r->status,
                        'request_type' => $r->request_type,
                        'requested_at' => $r->requested_at,
                        'submitted_at' => $r->submitted_at,
                        'image_count' => count($evidences),
                        'evidences' => $evidences,
                    ];
                });

            // Photo-review tally for the Compliance Summary panel — computed live
            // (reviews can be recorded after the shift ends, so the stored
            // compliance snapshot may lag; the panel always shows current state).
            // Counts are image-level now that a request can hold multiple photos.
            // Shared with the generated reports via ComplianceCalculator so the
            // panel and REP-001/REP-002 can never diverge (Phase 8).
            $photoSummary = $compliance->photoSummary($shift);

            // Wakefulness verification (Phase 5): every code-challenge for this
            // shift, newest first. A null result is the still-pending state; the
            // view shows CONFIRMED / FAILED / PENDING with the response time.
            // Why each FAILED check failed lives in the WAKEFULNESS_FAILED audit
            // event's metadata (reason + alerted), not on the check row. Index it
            // by check_id so the table can show the real cause — offline TOTP
            // mismatch, no-response, or a suppressed comms/delivery gap (not paged).
            $wakeFailMeta = $events->where('event_type', 'WAKEFULNESS_FAILED')
                ->mapWithKeys(fn (ShiftEvent $e) => [
                    (string) (is_array($e->metadata) ? ($e->metadata['check_id'] ?? '') : '') => (is_array($e->metadata) ? $e->metadata : []),
                ])->all();

            $wakefulnessChecks = WakefulnessCheck::where('shift_id', $shift->id)
                ->orderByDesc('scheduled_at')
                ->get()
                ->map(function (WakefulnessCheck $c) use ($wakeFailMeta) {
                    $reason = null;
                    $alerted = null;
                    if ($c->result === WakefulnessCheck::RESULT_FAILED) {
                        $meta = $wakeFailMeta[$c->id] ?? [];
                        $reason = WakefulnessCheck::describeFailure($meta['reason'] ?? null);
                        $alerted = array_key_exists('alerted', $meta) ? (bool) $meta['alerted'] : null;
                    }

                    return [
                        'mode' => $c->online_or_offline,
                        'is_offline' => $c->online_or_offline === WakefulnessCheck::MODE_OFFLINE,
                        'request_type' => $c->request_type, // manual | scheduled
                        'result' => $c->result,            // CONFIRMED | FAILED | null
                        'scheduled_at' => $c->scheduled_at,
                        'responded_at' => $c->responded_at,
                        'response_time_seconds' => $c->response_time_seconds,
                        // Plain-language cause for a FAILED check + whether it paged
                        // a supervisor (false = suppressed offline/undelivered miss).
                        'failure_reason' => $reason,
                        'failure_alerted' => $alerted,
                    ];
                });

            // Wakefulness tally for the Compliance Summary panel — computed live
            // (a pending check can resolve via the timeout sweep after this load).
            // No review step here: the result is the server's own CONFIRMED/FAILED.
            // Shared with the generated reports via ComplianceCalculator (Phase 8).
            $wakefulnessSummary = $compliance->wakefulnessSummary($shift);

            return view('admin.shifts.timeline', compact('shift', 'events', 'photoRequests', 'photoSummary', 'wakefulnessChecks', 'wakefulnessSummary'));
        } catch (ModelNotFoundException $e) {
            return redirect()
                ->route('admin.shifts.index')
                ->with('error', 'Shift not found.');
        } catch (\Exception $e) {
            Log::error('Error loading shift timeline', ['shift_id' => $id, 'exception' => $e]);
            return redirect()
                ->route('admin.shifts.index')
                ->with('error', 'Unable to load the shift timeline. Please try again.');
        }
    }

    /**
     * Manually request a live verification photo from the guard on an active
     * shift (spec §8). Issues a fresh ONLINE nonce, records the request and
     * fires the best-effort push. Only meaningful while the guard is on duty.
     */
    public function requestPhoto(string $id): JsonResponse
    {
        \Log::info('Admin Photo Request', ['shift_id' => $id]);

        try {
            $shift = Shift::with('assignedGuard')->findOrFail($id);

            if ($shift->status !== Shift::STATUS_ACTIVE) {
                return response()->json([
                    'success' => false,
                    'error' => 'A photo can only be requested while the shift is active.',
                ], 422);
            }

            if (!$shift->assignedGuard) {
                return response()->json([
                    'success' => false,
                    'error' => 'This shift has no assigned guard.',
                ], 422);
            }

            $admin = Admin::find($this->currentAdminId());

            $request = app(PhotoVerificationService::class)
                ->createRequest($shift, $admin, PhotoRequest::TYPE_MANUAL);

            \Log::info('Admin Photo Request Created', [
                'shift_id'   => $id,
                'request_id' => $request->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Photo request sent. The guard has 90 seconds to respond.',
                'request_id' => $request->id,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'error' => 'Shift not found.'], 404);
        } catch (\Throwable $e) {
            Log::error('Error requesting photo', ['shift_id' => $id, 'exception' => $e]);
            return response()->json(['success' => false, 'error' => 'Unable to request a photo. Please try again.'], 500);
        }
    }

    /**
     * Manually issue a live wakefulness code-challenge to the guard on an active
     * shift (spec §9). Dispatches an ONLINE check (random code + best-effort
     * push) — identical to a scheduled challenge, so the app handles it via the
     * existing WAKEFULNESS_CHALLENGE flow and the timeout sweep is the backstop.
     * There is no admin review: the result is the server's own CONFIRMED/FAILED.
     */
    public function requestWakefulness(string $id): JsonResponse
    {
        try {
            $shift = Shift::with('assignedGuard')->findOrFail($id);

            if ($shift->status !== Shift::STATUS_ACTIVE) {
                return response()->json([
                    'success' => false,
                    'error' => 'A wakefulness check can only be requested while the shift is active.',
                ], 422);
            }

            if (!$shift->assignedGuard) {
                return response()->json([
                    'success' => false,
                    'error' => 'This shift has no assigned guard.',
                ], 422);
            }

            $check = app(WakefulnessService::class)
                ->dispatchOnlineCheck($shift, WakefulnessCheck::TYPE_MANUAL);

            if (!$check) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unable to issue a wakefulness check for this shift.',
                ], 422);
            }

            $seconds = (int) config('ironlock.wakefulness_response_seconds', 60);

            return response()->json([
                'success' => true,
                'message' => "Wakefulness check sent. The guard has {$seconds} seconds to respond.",
                'check_id' => $check->id,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'error' => 'Shift not found.'], 404);
        } catch (\Throwable $e) {
            Log::error('Error requesting wakefulness check', ['shift_id' => $id, 'exception' => $e]);
            return response()->json(['success' => false, 'error' => 'Unable to request a wakefulness check. Please try again.'], 500);
        }
    }

    /**
     * Generate a one-time SSO access link for this shift and return it to the
     * admin (the drawer copies it to the clipboard). The link signs the assigned
     * guard in without a password but still passes every normal login gate at
     * redemption; generating a new one supersedes any prior unused link. Valid
     * for config('ironlock.shift_access_link_ttl_minutes') (default 60).
     */
    public function generateAccessLink(string $id): JsonResponse
    {
        try {
            $shift = Shift::with('assignedGuard')->findOrFail($id);

            if (in_array($shift->status, [Shift::STATUS_COMPLETED, Shift::STATUS_CANCELLED], true)) {
                return response()->json([
                    'success' => false,
                    'error' => 'An access link cannot be generated for a completed or cancelled shift.',
                ], 422);
            }

            if (!$shift->assignedGuard) {
                return response()->json([
                    'success' => false,
                    'error' => 'This shift has no assigned guard.',
                ], 422);
            }

            $link = app(ShiftAccessLinkService::class)->generate($shift, $this->currentAdminId());

            return response()->json([
                'success' => true,
                'message' => 'Access link generated.',
                'url' => $link['url'],
                'expires_at' => $link['expires_at']->toISOString(),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'error' => 'Shift not found.'], 404);
        } catch (\Throwable $e) {
            Log::error('Error generating access link', ['shift_id' => $id, 'exception' => $e]);
            return response()->json(['success' => false, 'error' => 'Unable to generate an access link. Please try again.'], 500);
        }
    }

    /**
     * Stream a stored evidence photo to an authenticated admin. The file lives
     * on the private `photos` disk and is never publicly served — this route is
     * the only way to view it, and it sits behind the admin auth middleware.
     */
    public function viewPhoto(string $evidence)
    {
        $photo = PhotoEvidence::find($evidence);

        if (!$photo || empty($photo->file_path) || !\Illuminate\Support\Facades\Storage::disk('photos')->exists($photo->file_path)) {
            abort(404);
        }

        return \Illuminate\Support\Facades\Storage::disk('photos')->response($photo->file_path);
    }

    /**
     * Record an admin's manual review of a stored verification photo.
     *
     * The supervisor approves or rejects the photo (with an optional note) after
     * viewing it. The decision is written once to an immutable photo_reviews row
     * (idempotent — a photo can't be re-reviewed), audited on the shift
     * timeline, folded into the compliance summary, and pushed best-effort to
     * the guard's app (the guard also polls GET /shifts/{id}/photos/reviews).
     */
    public function reviewPhoto(Request $request, string $evidence): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $photo = PhotoEvidence::with(['photoRequest.shift', 'photoRequest.assignedGuard', 'review'])
                ->find($evidence);

            if (!$photo) {
                return response()->json(['success' => false, 'error' => 'Photo evidence not found.'], 404);
            }

            // Idempotent like alert acknowledgement: a photo is reviewed once.
            if ($photo->review) {
                return response()->json([
                    'success' => false,
                    'error' => 'This photo has already been reviewed.',
                ], 422);
            }

            $pr = $photo->photoRequest;
            if (!$pr) {
                return response()->json(['success' => false, 'error' => 'This photo is not linked to a request.'], 422);
            }

            $decision = $request->input('decision') === 'approve'
                ? PhotoReview::DECISION_APPROVED
                : PhotoReview::DECISION_REJECTED;
            $note = $request->input('note');
            $now = Carbon::now();

            DB::beginTransaction();
            try {
                $review = PhotoReview::create([
                    'photo_evidence_id' => $photo->id,
                    'photo_request_id' => $pr->id,
                    'shift_id' => $pr->shift_id,
                    'guard_id' => $pr->guard_id,
                    'reviewed_by' => $this->currentAdminId(),
                    'decision' => $decision,
                    'note' => $note,
                    'reviewed_at' => $now,
                    'server_received_at' => $now,
                ]);

                if ($pr->shift) {
                    $this->logShiftEvent($pr->shift, 'PHOTO_REVIEWED', [
                        'request_id' => $pr->id,
                        'evidence_id' => $photo->id,
                        'decision' => $decision,
                        'note' => $note,
                        'reviewed_by' => $this->currentAdminId(),
                    ]);
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            // Best-effort: tell the guard's app the outcome immediately. The
            // mobile reviews poll is the reliable fallback if the push is lost.
            if ($pr->assignedGuard) {
                app(PhotoPushNotifier::class)->notifyReviewed($pr->assignedGuard, $review);
            }

            return response()->json([
                'success' => true,
                'message' => 'Photo review recorded.',
                'decision' => $decision,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error reviewing photo', ['evidence_id' => $evidence, 'exception' => $e]);
            return response()->json(['success' => false, 'error' => 'Unable to record the review. Please try again.'], 500);
        }
    }

    /**
     * Display shift details.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $shift = Shift::with([
                'assignedGuard',
                'site',
                'geofence',
                'creator',
                'workingTimeOverrides.approvedBy'
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'shift' => $shift
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Shift not found'
            ], 404);
        }
    }

    /**
     * Update shift (with WTR re-validation).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $shift = Shift::findOrFail($id);

            // A resolved shift is locked. Once a supervisor has recorded an
            // outcome (late authorisation, excuse, reassignment, no-show) the
            // schedule must not be quietly rewritten — editing is closed off
            // entirely. The form opens read-only, but enforce it here too so the
            // API itself can't be used to bypass the lock.
            if ($shift->resolved_at) {
                return response()->json([
                    'success' => false,
                    'error' => 'This shift has already been resolved and is locked. Create a new shift if changes are needed.'
                ], 422);
            }

            // Only a scheduled shift can be edited. Once a guard has checked in,
            // the shift is active, or it has finished (completed/missed), the
            // schedule is fixed: a missed shift is handled via resolve(), and an
            // early stop on an active shift goes through End → early-finish.
            if ($shift->status !== Shift::STATUS_SCHEDULED) {
                return response()->json([
                    'success' => false,
                    'error' => $shift->status === Shift::STATUS_MISSED
                        ? 'Use Resolve to handle a missed shift.'
                        : 'Only a scheduled shift can be edited. This shift has already begun or finished.'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'guard_id' => 'required|exists:guards,id',
                'site_id' => 'required|exists:sites,id',
                'scheduled_start' => 'required|date|after:now',
                'scheduled_end' => 'required|date|after:scheduled_start',
                'override_12hr_warning' => 'nullable|boolean',
                'override_11hr_rest' => 'nullable|boolean',
                'override_justification' => 'required_if:override_12hr_warning,true|required_if:override_11hr_rest,true|nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // The guard and/or site may have been reassigned in the edit form, so
            // resolve them from the request rather than the existing record.
            $guard = Guard::findOrFail($request->guard_id);
            $site = Site::findOrFail($request->site_id);

            // A reassigned site needs its own active geofence; without one the
            // shift could not be monitored, so block the change just like store().
            $geofence = Geofence::where('site_id', $site->id)
                ->where('is_active', true)
                ->first();

            if (!$geofence) {
                return response()->json([
                    'success' => false,
                    'error' => 'No active geofence found for the selected site. Please set up a geofence first.',
                    'redirect' => '/admin/sites'
                ], 422);
            }

            $scheduledStart = Carbon::parse($request->scheduled_start);
            $scheduledEnd = Carbon::parse($request->scheduled_end);

            // Re-validate WTR for the (possibly reassigned) guard, excluding this
            // shift from the rest-period lookup so it never clashes with itself.
            $wtrValidation = $this->validateWorkingTimeRegulations(
                $guard->id,
                $scheduledStart,
                $scheduledEnd,
                $shift->id
            );

            // Apply the same WTR rules as store(): the 16-hour maximum is a hard
            // block that no justification can override; the 12-hour and 11-hour
            // rest warnings require the matching override flag + justification.
            if (!$wtrValidation['compliant']) {
                $assessment = $this->assessWtrViolations(
                    $wtrValidation,
                    $request->boolean('override_12hr_warning'),
                    $request->boolean('override_11hr_rest')
                );

                if ($assessment['blocked']) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Shift duration exceeds 16 hours maximum. This cannot be overridden.',
                        'wtr_violation' => $assessment['block_violation']
                    ], 422);
                }

                if (!empty($assessment['override_required'])) {
                    return response()->json([
                        'success' => false,
                        'wtr_warning' => true,
                        'violations' => $wtrValidation['violations'],
                        'override_required' => $assessment['override_required'],
                        'message' => 'Working Time Regulations compliance issues detected. Override required with justification.'
                    ], 422);
                }
            }

            // Check for conflicts against the (possibly reassigned) guard's other
            // shifts, excluding this shift itself.
            $conflicts = $this->checkShiftConflicts($guard->id, $scheduledStart, $scheduledEnd, $shift->id);
            if (!empty($conflicts)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Shift conflicts detected with existing shifts',
                    'conflicts' => $conflicts
                ], 422);
            }

            DB::beginTransaction();
            try {
                $shift->update([
                    'guard_id' => $guard->id,
                    'site_id' => $site->id,
                    'geofence_id' => $geofence->id,
                    'scheduled_start' => $scheduledStart,
                    'scheduled_end' => $scheduledEnd,
                ]);

                // Reconcile override records to match the shift's current
                // violations: acknowledged warnings get an up-to-date row, and
                // any override that no longer applies is pruned. Called
                // unconditionally so an edit back to a compliant schedule clears
                // previously-logged overrides.
                $this->createWorkingTimeOverrides(
                    $shift,
                    $wtrValidation['violations'],
                    (string) ($request->override_justification ?? '')
                );

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            return response()->json([
                'success' => true,
                'message' => 'Shift updated successfully',
                'shift' => $shift->load('assignedGuard', 'site', 'geofence')
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Shift not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating shift', ['shift_id' => $id, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to update shift. Please try again.'
            ], 500);
        }
    }

    /**
     * Cancel a shift before the guard goes on duty.
     *
     * For a shift called off ahead of time — a scheduling mistake (wrong
     * day/guard/site) or a short-notice emergency. Only a scheduled shift can
     * be cancelled; once a guard has checked in or the shift is active it has
     * already begun, so an early stop goes through End → early-finish
     * resolution instead, and a missed shift is excused via resolve(). Because
     * a cancelled shift
     * will never run, it is deleted outright — removed from the table along with
     * its dependent rows — rather than kept as a Cancelled record.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|in:scheduling_error,emergency,client_request,other',
            'note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $shift = Shift::findOrFail($id);

            if ($shift->status !== Shift::STATUS_SCHEDULED) {
                return response()->json([
                    'success' => false,
                    'error' => 'Only a scheduled shift can be cancelled. Once a guard has checked in or started, cancellation is no longer available.'
                ], 422);
            }

            // A cancelled shift will never run, so it is removed from the system
            // entirely. Clear every child row that references it first — most of
            // these only exist once a guard is on duty, but a checked-in shift
            // can already have some (a nonce, a check-in event), and only
            // working_time_overrides has a cascading FK, so the rest must be
            // deleted explicitly or the delete would hit a foreign-key constraint.
            DB::beginTransaction();
            try {
                foreach ([
                    'shift_events', 'working_time_overrides', 'nonces', 'photo_reviews',
                    'photo_requests', 'wakefulness_checks', 'alerts', 'reports',
                    'guard_locations', 'offline_sync_queue', 'shift_access_links',
                ] as $childTable) {
                    DB::table($childTable)->where('shift_id', $shift->id)->delete();
                }
                $shift->delete();
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            return response()->json([
                'success' => true,
                'message' => 'Shift cancelled and removed.',
                'deleted' => true,
                'shift_id' => $id,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Shift not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error cancelling shift', ['shift_id' => $id, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to cancel shift. Please try again.'
            ], 500);
        }
    }

    /**
     * Resolve a missed shift (supervisor recovery flow).
     *
     * A guard who fell outside the check-in/start window is marked Missed and
     * told to contact their supervisor. The supervisor records a reason
     * category + note and chooses an outcome:
     *
     *  - authorize_late : open a per-shift late-check-in override for
     *    config('ironlock.late_authorization_minutes') and re-open the shift as
     *    Scheduled so the guard can sign in and start late.
     *  - excuse         : close the shift as Cancelled with the reason; no penalty.
     *  - reassign       : hand the shift to another guard (re-opens as Scheduled).
     *  - confirm_no_show: leave it Missed, recording the unexcused outcome.
     *
     * An early-finished shift (status completed, ended_early) is resolved the
     * same way with its own two outcomes:
     *  - accept_early_end: authorise the early finish — no penalty.
     *  - flag_early_end  : record an unexcused early departure (incident).
     *
     * Every outcome stamps resolved_at so the red ⚠ clears on the dashboard.
     */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'outcome' => 'required|in:authorize_late,excuse,reassign,confirm_no_show,accept_early_end,flag_early_end',
            'reason' => 'required|in:emergency,transport,illness,personal,operational,other',
            'note' => 'nullable|string|max:500',
            'guard_id' => 'required_if:outcome,reassign|nullable|exists:guards,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $shift = Shift::findOrFail($id);

            if (!$shift->needsResolution()) {
                return response()->json([
                    'success' => false,
                    'error' => 'This shift does not need resolution.'
                ], 422);
            }

            $outcome = $request->input('outcome');
            $reason = $request->input('reason');
            $note = $request->input('note');
            $adminId = $this->currentAdminId();

            // Guard against applying the wrong outcome group to the wrong flag:
            // early-end outcomes only apply to an early-finished shift, and the
            // missed outcomes only apply to a missed shift.
            $earlyOutcomes = ['accept_early_end', 'flag_early_end'];
            $isEarlyEnd = $shift->resolutionKind() === 'ended_early';

            if (in_array($outcome, $earlyOutcomes, true) !== $isEarlyEnd) {
                return response()->json([
                    'success' => false,
                    'error' => $isEarlyEnd
                        ? 'Choose how to resolve the early finish.'
                        : 'That outcome does not apply to this shift.',
                ], 422);
            }

            // Late check-in and reassignment bring a guard in now, so they only
            // make sense while the shift still has time left to work. Once the
            // scheduled end has passed there is nothing to recover — refuse and
            // leave Excuse / Confirm no-show as the only valid outcomes.
            $lateRecoveryOutcomes = ['authorize_late', 'reassign'];
            if (in_array($outcome, $lateRecoveryOutcomes, true) && !$shift->canRecoverLate()) {
                return response()->json([
                    'success' => false,
                    'error' => 'This shift has already ended, so it can no longer be recovered with a late check-in or reassignment. Excuse it or confirm the no-show instead.',
                ], 422);
            }

            DB::beginTransaction();
            try {
                switch ($outcome) {
                    case 'authorize_late':
                        $minutes = (int) config('ironlock.late_authorization_minutes', 30);
                        $shift->update([
                            'status' => Shift::STATUS_SCHEDULED,
                            'checkin_override_until' => Carbon::now()->addMinutes($minutes),
                            'late_authorized_by' => $adminId,
                            'attendance_reason' => $reason,
                            'attendance_note' => $note,
                            'resolved_at' => Carbon::now(),
                        ]);
                        $this->logShiftEvent($shift, 'LATE_CHECKIN_AUTHORIZED', [
                            'reason' => $reason,
                            'note' => $note,
                            'override_until' => $shift->checkin_override_until->toISOString(),
                            'authorized_by' => $adminId,
                        ]);
                        break;

                    case 'excuse':
                        $shift->update([
                            'status' => Shift::STATUS_CANCELLED,
                            'attendance_reason' => $reason,
                            'attendance_note' => $note,
                            'resolved_at' => Carbon::now(),
                        ]);
                        $this->logShiftEvent($shift, 'SHIFT_EXCUSED', [
                            'reason' => $reason,
                            'note' => $note,
                            'resolved_by' => $adminId,
                        ]);
                        break;

                    case 'reassign':
                        $newGuard = Guard::findOrFail($request->input('guard_id'));

                        // The reassigned guard must be free for the slot.
                        $conflicts = $this->checkShiftConflicts(
                            $newGuard->id,
                            Carbon::parse($shift->scheduled_start),
                            Carbon::parse($shift->scheduled_end),
                            $shift->id
                        );
                        if (!empty($conflicts)) {
                            DB::rollBack();
                            return response()->json([
                                'success' => false,
                                'error' => 'The selected guard already has a shift in that time window.',
                                'conflicts' => $conflicts,
                            ], 422);
                        }

                        // The original scheduled_start is already in the past, so
                        // re-opening as Scheduled would let the every-minute
                        // mark-missed sweep flip this straight back to Missed.
                        // Grant a fresh check-in window from now so the new guard
                        // has the normal grace period to reach the site and start;
                        // if they also no-show, it correctly goes Missed again.
                        $shift->update([
                            'guard_id' => $newGuard->id,
                            'status' => Shift::STATUS_SCHEDULED,
                            'checked_in_at' => null,
                            'checkin_override_until' => Carbon::now()->addMinutes(Shift::windowMinutes()),
                            'late_authorized_by' => null,
                            'attendance_reason' => $reason,
                            'attendance_note' => $note,
                            'resolved_at' => Carbon::now(),
                        ]);
                        $this->logShiftEvent($shift, 'SHIFT_REASSIGNED', [
                            'reason' => $reason,
                            'note' => $note,
                            'reassigned_to' => $newGuard->id,
                            'resolved_by' => $adminId,
                        ]);
                        break;

                    case 'confirm_no_show':
                        $shift->update([
                            'attendance_reason' => $reason,
                            'attendance_note' => $note,
                            'resolved_at' => Carbon::now(),
                        ]);
                        $this->logShiftEvent($shift, 'SHIFT_NO_SHOW_CONFIRMED', [
                            'reason' => $reason,
                            'note' => $note,
                            'resolved_by' => $adminId,
                        ]);
                        break;

                    case 'accept_early_end':
                        // Authorised early finish — keep the completed shift as-is,
                        // just record the reason and clear the flag.
                        $shift->update([
                            'attendance_reason' => $reason,
                            'attendance_note' => $note,
                            'resolved_at' => Carbon::now(),
                        ]);
                        $this->logShiftEvent($shift, 'EARLY_END_APPROVED', [
                            'reason' => $reason,
                            'note' => $note,
                            'actual_end' => optional($shift->actual_end)->toISOString(),
                            'scheduled_end' => optional($shift->scheduled_end)->toISOString(),
                            'resolved_by' => $adminId,
                        ]);
                        break;

                    case 'flag_early_end':
                        // Unexcused early departure — recorded as an incident.
                        $shift->update([
                            'attendance_reason' => $reason,
                            'attendance_note' => $note,
                            'resolved_at' => Carbon::now(),
                        ]);
                        $this->logShiftEvent($shift, 'EARLY_END_FLAGGED', [
                            'reason' => $reason,
                            'note' => $note,
                            'actual_end' => optional($shift->actual_end)->toISOString(),
                            'scheduled_end' => optional($shift->scheduled_end)->toISOString(),
                            'resolved_by' => $adminId,
                        ]);
                        break;
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            return response()->json([
                'success' => true,
                'message' => 'Shift resolved successfully',
                'shift' => $shift->fresh()->load('assignedGuard', 'site', 'geofence'),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'error' => 'Shift not found'], 404);
        } catch (\Exception $e) {
            Log::error('Error resolving shift', ['shift_id' => $id, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to resolve shift. Please try again.'
            ], 500);
        }
    }

    /**
     * Approve a guard's pending early-end request (BACKEND_SHIFT_END_SPEC §0.2).
     * The guard's phone learns of this by polling GET /shifts/current; once
     * approved it unlocks END and the guard finishes the shift early.
     */
    public function approveEarlyEnd(Request $request, string $id): JsonResponse
    {
        return $this->decideEarlyEnd($id, Shift::EARLY_END_APPROVED);
    }

    /**
     * Reject a guard's pending early-end request. The guard may submit a fresh
     * request or keep working through to the scheduled end.
     */
    public function rejectEarlyEnd(Request $request, string $id): JsonResponse
    {
        return $this->decideEarlyEnd($id, Shift::EARLY_END_REJECTED);
    }

    /**
     * Shared approve/reject handler — records the supervisor's decision on the
     * pending early-end request and audits it. The server is the authority on
     * early ends; only an approved request lets the guard's POST /end succeed.
     */
    private function decideEarlyEnd(string $id, string $decision): JsonResponse
    {
        try {
            $shift = Shift::findOrFail($id);

            if (!$shift->hasPendingEarlyEnd()) {
                return response()->json([
                    'success' => false,
                    'error' => 'There is no pending early-end request for this shift.',
                ], 422);
            }

            $adminId = $this->currentAdminId();
            $shift->decideEarlyEnd($decision, $adminId);

            $this->logShiftEvent(
                $shift,
                $decision === Shift::EARLY_END_APPROVED ? 'EARLY_END_REQUEST_APPROVED' : 'EARLY_END_REQUEST_REJECTED',
                [
                    'reason' => $shift->early_end_reason,
                    'note' => $shift->early_end_note,
                    'decided_by' => $adminId,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => $decision === Shift::EARLY_END_APPROVED
                    ? 'Early-end request approved.'
                    : 'Early-end request rejected.',
                'shift' => $shift->fresh()->load('assignedGuard', 'site', 'geofence'),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'error' => 'Shift not found'], 404);
        } catch (\Exception $e) {
            Log::error('Error deciding early-end request', ['shift_id' => $id, 'decision' => $decision, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to update the early-end request. Please try again.',
            ], 500);
        }
    }

    /**
     * Get WTR compliance check for shift planning.
     */
    public function checkWTRCompliance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'guard_id' => 'required|exists:guards,id',
            'scheduled_start' => 'required|date',
            'scheduled_end' => 'required|date|after:scheduled_start',
            'shift_id' => 'nullable|uuid|exists:shifts,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // When editing, exclude the shift itself from the rest-period lookup so
        // it is never compared against its own (pre-edit) database record. This
        // keeps the live preview consistent with what update() will actually do.
        $wtrValidation = $this->validateWorkingTimeRegulations(
            $request->guard_id,
            Carbon::parse($request->scheduled_start),
            Carbon::parse($request->scheduled_end),
            $request->input('shift_id')
        );

        return response()->json([
            'success' => true,
            'wtr_compliance' => $wtrValidation
        ]);
    }

    /**
     * Validate Working Time Regulations for a proposed shift.
     *
     * @param string $guardId
     * @param Carbon $scheduledStart
     * @param Carbon $scheduledEnd
     * @param string|null $excludeShiftId
     * @return array
     */
    private function validateWorkingTimeRegulations(string $guardId, Carbon $scheduledStart, Carbon $scheduledEnd, ?string $excludeShiftId = null): array
    {
        $violations = [];
        $duration = $scheduledStart->diffInHours($scheduledEnd, true); // float, used for comparisons

        // Human-readable duration label: "15 hours and 1 min" instead of "15.016667 hours".
        $totalMins = (int) round($scheduledStart->diffInMinutes($scheduledEnd));
        $dHours = intdiv($totalMins, 60);
        $dMins  = $totalMins % 60;
        $durationLabel = $dMins > 0 ? "{$dHours}h {$dMins}min" : "{$dHours}h";

        // Rule 1: 16-hour absolute maximum (cannot be overridden)
        if ($duration > 16) {
            $violations[] = [
                'type' => 'DURATION_16HR_BLOCK',
                'severity' => 'ERROR',
                'message' => "Shift duration of {$durationLabel} exceeds the 16-hour maximum and cannot be saved.",
                'override_allowed' => false
            ];
        }

        // Rule 2: 12-hour warning (can be overridden with justification)
        if ($duration > 12) {
            $violations[] = [
                'type' => 'DURATION_12HR_WARNING',
                'severity' => 'WARNING',
                'message' => "Shift duration of {$durationLabel} exceeds the 12-hour recommendation.",
                'override_allowed' => true
            ];
        }

        // Rule 3: 11-hour rest period validation
        $lastShift = Shift::where('guard_id', $guardId)
            ->where('status', '!=', 'cancelled')
            ->when($excludeShiftId, function($query) use ($excludeShiftId) {
                $query->where('id', '!=', $excludeShiftId);
            })
            ->where('scheduled_end', '<=', $scheduledStart)
            ->orderBy('scheduled_end', 'desc')
            ->first();

        if ($lastShift) {
            $restPeriodHours = Carbon::parse($lastShift->scheduled_end)->diffInHours($scheduledStart, true);
            if ($restPeriodHours < 11) {
                $restMins  = (int) round(Carbon::parse($lastShift->scheduled_end)->diffInMinutes($scheduledStart));
                $rHours    = intdiv($restMins, 60);
                $rMinLabel = ($restMins % 60) > 0 ? "{$rHours}h " . ($restMins % 60) . "min" : "{$rHours}h";
                $violations[] = [
                    'type' => 'REST_PERIOD_11HR',
                    'severity' => 'WARNING',
                    'message' => "Only {$rMinLabel} rest since last shift — 11 hours required.",
                    'override_allowed' => true,
                    'last_shift_end' => $lastShift->scheduled_end
                ];
            }
        }

        // Rule 3b: 11-hour rest before the NEXT shift. Scheduling a shift that
        // ends too close to an already-scheduled later shift is just as much a
        // rest-period breach as the backward case, so check both directions.
        $nextShift = Shift::where('guard_id', $guardId)
            ->where('status', '!=', 'cancelled')
            ->when($excludeShiftId, function($query) use ($excludeShiftId) {
                $query->where('id', '!=', $excludeShiftId);
            })
            ->where('scheduled_start', '>=', $scheduledEnd)
            ->orderBy('scheduled_start', 'asc')
            ->first();

        if ($nextShift) {
            $restBeforeNext = $scheduledEnd->diffInHours(Carbon::parse($nextShift->scheduled_start), true);
            if ($restBeforeNext < 11) {
                $restMins  = (int) round($scheduledEnd->diffInMinutes(Carbon::parse($nextShift->scheduled_start)));
                $rHours    = intdiv($restMins, 60);
                $rMinLabel = ($restMins % 60) > 0 ? "{$rHours}h " . ($restMins % 60) . "min" : "{$rHours}h";
                $violations[] = [
                    'type' => 'REST_PERIOD_11HR',
                    'severity' => 'WARNING',
                    'message' => "Only {$rMinLabel} rest before the next shift — 11 hours required.",
                    'override_allowed' => true,
                    'next_shift_start' => $nextShift->scheduled_start
                ];
            }
        }

        return [
            'compliant' => empty($violations),
            'violations' => $violations,
            'duration_hours' => $duration,
            'validated_at' => Carbon::now()->toISOString()
        ];
    }

    /**
     * Assess WTR violations against the override flags supplied by the admin.
     *
     * Centralises the policy shared by store() and update():
     *  - DURATION_16HR_BLOCK is a hard block — never overridable (spec rule #22).
     *  - DURATION_12HR_WARNING / REST_PERIOD_11HR require the matching override
     *    flag (plus justification, enforced by validation) to proceed.
     *
     * @return array{blocked: bool, block_violation: ?array, override_required: array}
     */
    private function assessWtrViolations(array $wtrValidation, bool $override12hr, bool $override11hr): array
    {
        $blockViolation = null;
        $overrideRequired = [];

        foreach ($wtrValidation['violations'] as $violation) {
            switch ($violation['type']) {
                case 'DURATION_16HR_BLOCK':
                    $blockViolation = $violation;
                    break;

                case 'DURATION_12HR_WARNING':
                    if (!$override12hr) {
                        $overrideRequired[] = '12-hour duration warning';
                    }
                    break;

                case 'REST_PERIOD_11HR':
                    if (!$override11hr) {
                        $overrideRequired[] = '11-hour rest period';
                    }
                    break;
            }
        }

        return [
            'blocked' => $blockViolation !== null,
            'block_violation' => $blockViolation,
            // A backward- and forward-rest breach both map to the single
            // 11-hour-rest override, so collapse duplicates for a clean message.
            'override_required' => array_values(array_unique($overrideRequired)),
        ];
    }

    /**
     * Check for shift conflicts.
     */
    private function checkShiftConflicts(string $guardId, Carbon $start, Carbon $end, ?string $excludeShiftId = null): array
    {
        // Two half-open intervals [start, end) and [existingStart, existingEnd)
        // overlap iff existingStart < end AND existingEnd > start. Using strict
        // comparisons means shifts that merely touch at a boundary (one ends
        // exactly when the next begins) are NOT treated as a conflict, so
        // legitimate back-to-back scheduling is allowed.
        $conflicts = Shift::where('guard_id', $guardId)
            ->where('status', '!=', 'cancelled')
            ->when($excludeShiftId, function($query) use ($excludeShiftId) {
                $query->where('id', '!=', $excludeShiftId);
            })
            ->where('scheduled_start', '<', $end)
            ->where('scheduled_end', '>', $start)
            ->with('site')
            ->get();

        return $conflicts->map(function($shift) {
            return [
                'shift_id' => $shift->id,
                'site_name' => $shift->site->name,
                'start' => $shift->scheduled_start,
                'end' => $shift->scheduled_end,
                'status' => $shift->status
            ];
        })->toArray();
    }

    /**
     * Enforce one shift per guard per day.
     *
     * A guard can be rostered across as many different days as needed, but may
     * only hold one shift on any given day. A second shift starting on a day
     * the guard already works is refused. A cancelled (e.g. excused) shift has
     * been called off and frees that day, so it does not block a replacement;
     * every other status — scheduled, checked_in, active, completed or missed —
     * counts as the day being taken.
     *
     * The day is bounded by the start/end of $date in the same (stored) time
     * frame the rest of the scheduling logic uses, so the comparison lines up
     * with scheduled_start values already in the table.
     *
     * Returns the blocking shifts (empty when the guard's day is free).
     */
    private function findSameDayShifts(string $guardId, Carbon $date, ?string $excludeShiftId = null): array
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd   = $date->copy()->endOfDay();

        $blocking = Shift::where('guard_id', $guardId)
            ->where('status', '!=', Shift::STATUS_CANCELLED)
            ->whereBetween('scheduled_start', [$dayStart, $dayEnd])
            ->when($excludeShiftId, function ($query) use ($excludeShiftId) {
                $query->where('id', '!=', $excludeShiftId);
            })
            ->with('site')
            ->get();

        return $blocking->map(function ($shift) {
            return [
                'shift_id' => $shift->id,
                'site_name' => $shift->site->name ?? '—',
                'start' => $shift->scheduled_start,
                'end' => $shift->scheduled_end,
                'status' => $shift->status,
            ];
        })->toArray();
    }

    /**
     * Create working time overrides.
     */
    private function createWorkingTimeOverrides(Shift $shift, array $violations, string $justification): void
    {
        $adminId = $this->currentAdminId();
        $activeTypes = [];

        foreach ($violations as $violation) {
            if (!($violation['override_allowed'] ?? false)) {
                continue;
            }

            $overrideType = $violation['type'] === 'DURATION_12HR_WARNING'
                ? 'duration_12hr'
                : 'rest_period_11hr';
            $activeTypes[$overrideType] = true;

            // Keep a single override record per (shift, type). Re-saving an
            // edited shift refreshes the justification/admin/timestamp instead
            // of stacking duplicate rows for the same acknowledged warning.
            WorkingTimeOverride::updateOrCreate(
                [
                    'shift_id' => $shift->id,
                    'override_type' => $overrideType,
                ],
                [
                    'justification' => $justification,
                    'approved_by' => $adminId,
                    'approved_at' => Carbon::now(),
                ]
            );
        }

        // Drop any override rows whose warning no longer applies after an edit
        // (e.g. a rest-period override left behind once the shift was moved).
        WorkingTimeOverride::where('shift_id', $shift->id)
            ->when(!empty($activeTypes), function ($query) use ($activeTypes) {
                $query->whereNotIn('override_type', array_keys($activeTypes));
            })
            ->delete();
    }

    /**
     * Append an immutable shift_events audit row for a supervisor action.
     * Best-effort — a logging failure must not roll back the resolution.
     */
    private function logShiftEvent(Shift $shift, string $eventType, array $metadata = []): void
    {
        try {
            ShiftEvent::create([
                'id' => (string) Str::uuid(),
                'shift_id' => $shift->id,
                'guard_id' => $shift->guard_id,
                'event_type' => $eventType,
                'metadata' => $metadata,
                'recorded_at' => Carbon::now(),
                // Authoritative server timestamp, assigned in PHP (UTC) at receipt.
                // Set explicitly so it never falls back to the DB CURRENT_TIMESTAMP
                // default, which uses the DB session timezone (not UTC).
                'server_received_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write shift event', ['shift_id' => $shift->id, 'type' => $eventType]);
        }
    }
}
