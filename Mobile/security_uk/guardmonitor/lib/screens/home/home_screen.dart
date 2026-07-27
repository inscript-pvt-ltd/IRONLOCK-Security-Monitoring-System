import 'dart:async';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:permission_handler/permission_handler.dart';
import '../../config/api_config.dart';
import '../../models/api_response.dart';
import '../../models/current_shift_model.dart';
import '../../providers/alerts_provider.dart';
import '../../data/offline_queue_db.dart';
import '../../providers/app_providers.dart';
import '../../providers/wakefulness_provider.dart';
import '../../services/api_client.dart';
import '../../services/challenge_queue.dart';
import '../../services/connectivity_service.dart';
import '../../services/gps_service.dart';
import '../../services/nonce_pool_service.dart';
import '../../services/notification_service.dart';
import '../../services/push_messaging_service.dart';
import '../../services/secure_storage_service.dart';
import '../../services/time_anchor_service.dart';
import '../../services/wakefulness_service.dart';
import '../../theme/app_colors.dart';
import '../../theme/app_gradients.dart';
import '../../theme/app_shadows.dart';
import '../../theme/app_typography.dart';
import '../../theme/responsive.dart';
import '../../overlays/end_shift_sheet.dart';
import '../../overlays/location_required_overlay.dart';
import '../../overlays/permission_gate_overlay.dart';
import '../../overlays/privacy_notice_overlay.dart';
import '../../overlays/wakefulness_overlay.dart' hide AppGradients;
import '../../widgets/app_card.dart';
import '../../widgets/app_chip.dart';
import '../photo/photo_screen.dart';

/// Extracts a pending photo request from the (loosely-documented) shapes the
/// backend may use for `GET /shifts/{id}/photos/pending`. The guide promises a
/// `request_id` + `nonce_value` but never pins the envelope, so we tolerate:
/// `{pending:true, request_id, nonce_value}`, a bare `{request_id, nonce_value}`,
/// a nested `{request|photo_request|photo|pending_request:{...}}`, a
/// `{requests:[{...}]}` / `{photo_requests:[{...}]}` array (the real backend's
/// shape — an empty list means nothing pending), or the first element of a bare
/// list. Returns null when nothing actionable is pending.
///
/// Both the request id and its nonce are required — the nonce signs the upload,
/// so a payload missing either is treated as "nothing to do" rather than
/// opening a capture that can't be submitted.
///
/// `issuedAt` / `responseSeconds` are optional: if the backend ever stamps the
/// pending request with `issued_at` (or `expires_at`) and `response_seconds`,
/// they flow through so the capture screen anchors its countdown exactly. Absent
/// today, in which case the screen falls back to arrival time + 90s default.
({String requestId, String nonceValue, DateTime? issuedAt, int? responseSeconds})?
    extractPendingPhoto(dynamic data) {
  Map<String, dynamic>? m;
  if (data is Map<String, dynamic>) {
    if (data['pending'] == false) return null;
    // The real backend wraps pending requests in a list under `requests`
    // (or `photo_requests`). An explicit list is authoritative: empty → nothing
    // pending; otherwise take the first actionable element.
    final list = data['requests'] ?? data['photo_requests'];
    if (list is List) {
      if (list.isEmpty || list.first is! Map) return null;
      m = (list.first as Map).cast<String, dynamic>();
    } else {
      final nested = data['request'] ??
          data['photo_request'] ??
          data['photo'] ??
          data['pending_request'];
      m = nested is Map<String, dynamic> ? nested : data;
    }
  } else if (data is List && data.isNotEmpty && data.first is Map) {
    m = (data.first as Map).cast<String, dynamic>();
  }
  if (m == null) return null;
  final requestId = (m['request_id'] ?? m['id'])?.toString();
  final nonceValue = (m['nonce_value'] ?? m['nonce'])?.toString();
  if (requestId == null || requestId.isEmpty) return null;
  if (nonceValue == null || nonceValue.isEmpty) return null;
  // `expires_at` is an alternative anchor: back-compute the issue time from it.
  final responseSeconds =
      int.tryParse((m['response_seconds'] ?? '').toString());
  DateTime? issuedAt = DateTime.tryParse((m['issued_at'] ?? '').toString())?.toUtc();
  final expiresAt = DateTime.tryParse((m['expires_at'] ?? '').toString())?.toUtc();
  if (issuedAt == null && expiresAt != null) {
    issuedAt = expiresAt.subtract(
      Duration(seconds: responseSeconds ?? kPhotoWindowSeconds),
    );
  }
  return (
    requestId: requestId,
    nonceValue: nonceValue,
    issuedAt: issuedAt,
    responseSeconds: responseSeconds,
  );
}

/// Parses the outstanding ONLINE wakefulness challenge from
/// `GET /shifts/{id}/wakefulness/pending` (the twin of the photo pending poll).
/// The backend confirmed the envelope (BACKEND_REPLY_2026-07-21.md): challenges
/// come back under **`data.challenges[]`**, and an **empty array means nothing
/// pending** (there is no `pending:false` flag). We stay tolerant of the other
/// plausible shapes too (a nested object, a bare list) as defence, but
/// `data.challenges[]` is the real one. Both `check_id` and `code` are required —
/// without the code there's no challenge to raise. Returns null when nothing is
/// pending.
({String checkId, String code, DateTime? issuedAt, int? responseSeconds})?
    extractPendingWakefulness(dynamic data) {
  Map<String, dynamic>? m;
  if (data is Map<String, dynamic>) {
    if (data['pending'] == false) return null;
    final list = data['challenges'] ?? data['wakefulness_challenges'];
    if (list is List) {
      if (list.isEmpty || list.first is! Map) return null;
      m = (list.first as Map).cast<String, dynamic>();
    } else {
      final nested = data['challenge'] ??
          data['wakefulness'] ??
          data['pending_challenge'];
      m = nested is Map<String, dynamic> ? nested : data;
    }
  } else if (data is List && data.isNotEmpty && data.first is Map) {
    m = (data.first as Map).cast<String, dynamic>();
  }
  if (m == null) return null;
  final checkId = (m['check_id'] ?? m['id'])?.toString();
  final code = m['code']?.toString();
  if (checkId == null || checkId.isEmpty) return null;
  if (code == null || code.isEmpty) return null;
  final responseSeconds =
      int.tryParse((m['response_seconds'] ?? '').toString());
  DateTime? issuedAt = DateTime.tryParse((m['issued_at'] ?? '').toString())?.toUtc();
  final expiresAt = DateTime.tryParse((m['expires_at'] ?? '').toString())?.toUtc();
  if (issuedAt == null && expiresAt != null) {
    issuedAt = expiresAt.subtract(Duration(seconds: responseSeconds ?? 60));
  }
  return (
    checkId: checkId,
    code: code,
    issuedAt: issuedAt,
    responseSeconds: responseSeconds,
  );
}

class HomeScreen extends ConsumerStatefulWidget {
  const HomeScreen({super.key});

  @override
  ConsumerState<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends ConsumerState<HomeScreen> {
  Timer? _pollingTimer;
  // Fast tick (while online with a backlog) so the sync progress bar animates as
  // rows drain, instead of only stepping on the 20s poll. No-op when idle.
  Timer? _syncProgressTimer;
  StreamSubscription<String>? _zoneSub;
  // The photo request currently being handled (a PhotoScreen is open for it).
  // Guards against the 20s poll — which keeps reporting the same request as
  // pending until it's fulfilled — re-opening a second PhotoScreen on top (H1).
  String? _handlingPhotoRequestId;
  // Request ids we've already opened a PhotoScreen for. The server keeps
  // returning a request as "pending" until it's fulfilled — including one whose
  // window already EXPIRED (a missed check). Without this, every poll / pull-to-
  // refresh re-opens the same dead request: it opens already-expired, and any
  // capture then fails NONCE_EXPIRED. Once shown (completed OR missed), a request
  // id is done here and never re-opened; a genuinely new request has a new id.
  final _seenPhotoRequestIds = <String>{};
  // Guards against stacking two offline scheduled-capture screens.
  bool _handlingScheduledPhoto = false;
  // Serialises full-screen challenge presentation (wakefulness overlay + photo
  // screen) so a wake + photo landing in the same poll/push burst can't race and
  // stomp each other — they present one after the other instead.
  final _challengeQueue = ChallengeQueue();
  // True once the wakefulness overlay is queued/showing, cleared when it closes.
  // Stops the same challenge being enqueued twice (the state listener can fire
  // repeatedly while status stays `challenge`, and the post-frame cold-start
  // check may also see it).
  bool _wakefulnessPresenting = false;

  @override
  void initState() {
    super.initState();
    _pollingTimer = Timer.periodic(const Duration(seconds: 20), (_) {
      _pollBackend();
    });
    // Animate the sync progress bar: while online with a backlog, re-count the
    // queue ~1/sec so the bar fills as rows drain. Cheap COUNT() query; skips
    // entirely when there's nothing to sync.
    _syncProgressTimer =
        Timer.periodic(const Duration(milliseconds: 1200), (_) {
      if (!mounted) return;
      if (ref.read(isOnlineProvider) && ref.read(pendingSyncProvider).active) {
        ref.read(pendingSyncProvider.notifier).refresh();
      }
    });
    // Update zone state whenever the GPS service gets a server response.
    _zoneSub = ref.read(gpsServiceProvider).zoneStream.listen((zone) {
      if (!mounted) return;
      final zoneIndex = zone == 'INSIDE_ZONE' ? 0 : zone == 'OUTSIDE_ZONE' ? 1 : 2;
      ref.read(zoneProvider.notifier).set(zoneIndex);
      ref.read(zoneUpdatedAtProvider.notifier).markNow();
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _initOnFirstLaunch();
      // Present any challenge whose state was already set before our listeners
      // registered (cold-start via a push tap) — ref.listen won't replay it.
      _presentPendingChallenges();
    });
  }

  /// Sequentially show the permission gate (every launch until both camera +
  /// location are granted) then the privacy notice (once per install). Running
  /// them back-to-back via addPostFrameCallback avoids two routes stacking at
  /// the same time.
  Future<void> _initOnFirstLaunch() async {
    if (!mounted) return;

    // Step 1 — permission gate. Block until camera + location are granted.
    final locStatus = await Permission.locationWhenInUse.status;
    final camStatus = await Permission.camera.status;
    if (!locStatus.isGranted || !camStatus.isGranted) {
      if (!mounted) return;
      await Navigator.of(context).push(
        MaterialPageRoute(
          fullscreenDialog: true,
          builder: (_) => PermissionGateOverlay(onGranted: () {}),
        ),
      );
    }

    if (!mounted) return;

    // Step 2 — privacy notice (audit L15), shown once per install.
    if (await SecureStorageService.getPrivacyAccepted()) {
      ref.read(privacyAcceptedProvider.notifier).accept();
      return;
    }
    if (!mounted) return;
    Navigator.of(context).push(
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => PrivacyNoticeOverlay(
          onAccepted: SecureStorageService.setPrivacyAccepted,
        ),
      ),
    );
  }

  @override
  void dispose() {
    _pollingTimer?.cancel();
    _syncProgressTimer?.cancel();
    _zoneSub?.cancel();
    _challengeQueue.clear();
    super.dispose();
  }

  /// Re-checks whether a challenge is already pending right after first frame.
  /// On a cold-start opened by a push tap, the FCM handler sets the provider
  /// state BEFORE this screen's `ref.listen`s register — and ref.listen never
  /// replays the current value — so without this the challenge would sit in state
  /// unshown (stranding the screen). Idempotent: the enqueue guards below stop a
  /// double-present when the listener also fires.
  void _presentPendingChallenges() {
    if (!mounted) return;
    _onWakefulnessState(ref.read(wakefulnessProvider));
    _onPendingPhotoState(ref.read(pendingPhotoProvider));
  }

  /// Enqueues the wakefulness overlay when a challenge is live. Guarded by
  /// [_wakefulnessPresenting] so repeated state emissions (or the post-frame
  /// cold-start check) can't stack two overlays for one challenge.
  void _onWakefulnessState(WakefulnessState next) {
    if (next.status != WakefulnessStatus.challenge) return;
    if (_wakefulnessPresenting) return;
    _wakefulnessPresenting = true;
    _challengeQueue.enqueue(_presentWakefulness);
  }

  /// Enqueues a photo capture — offline scheduled or online/manual request —
  /// preserving the existing per-request dedup ([_handlingPhotoRequestId],
  /// [_seenPhotoRequestIds], [_handlingScheduledPhoto]).
  void _onPendingPhotoState(PendingPhotoState next) {
    if (!next.pending) return;
    // Phase 7: an OFFLINE schedule-triggered capture — no request id / nonce /
    // countdown. Open PhotoScreen in scheduled mode; it draws a pool nonce and
    // queues on submit.
    if (next.scheduled) {
      if (_handlingScheduledPhoto) return;
      _handlingScheduledPhoto = true;
      ref.read(pendingPhotoProvider.notifier).setPending(false);
      _challengeQueue.enqueue(_presentScheduledPhoto);
      return;
    }
    if (next.requestId == null || next.nonceValue == null) return;
    final requestId = next.requestId!;
    // Already showing a PhotoScreen for this request, or it was already shown
    // once (completed or missed) — don't re-open it.
    if (requestId == _handlingPhotoRequestId ||
        _seenPhotoRequestIds.contains(requestId)) {
      return;
    }
    _handlingPhotoRequestId = requestId;
    // Mark it shown so a later poll can't re-open the same (possibly expired)
    // request. Bounded so a long shift can't grow it without limit.
    _seenPhotoRequestIds.add(requestId);
    if (_seenPhotoRequestIds.length > 50) {
      _seenPhotoRequestIds.remove(_seenPhotoRequestIds.first);
    }
    // Alert the guard even if a data-only push drew no banner (iOS) or the
    // screen is queued behind another challenge — parity with wakefulness.
    unawaited(NotificationService.showPhotoRequest(requestId: requestId));
    final nonceValue = next.nonceValue!;
    final issuedAt = next.issuedAt;
    final receivedAt = next.receivedAt;
    final responseSeconds = next.responseSeconds;
    ref.read(pendingPhotoProvider.notifier).setPending(false);
    _challengeQueue.enqueue(() => _presentOnlinePhoto(
          requestId: requestId,
          nonceValue: nonceValue,
          issuedAt: issuedAt,
          receivedAt: receivedAt,
          responseSeconds: responseSeconds,
        ));
  }

  // ── Challenge presenters (serialised via [_challengeQueue]) ───────────────
  // Each returns a Future that completes when its route closes, so the queue
  // knows when to present the next one. This is the single place any challenge
  // reaches the screen, which is what keeps a wake + photo from racing.

  /// Presents the wakefulness code overlay. Skips if the challenge already
  /// resolved while it was queued (nothing live to show).
  Future<void> _presentWakefulness() async {
    try {
      if (!mounted) return;
      if (ref.read(wakefulnessProvider).status != WakefulnessStatus.challenge) {
        return;
      }
      await showDialog<void>(
        context: context,
        barrierDismissible: false,
        builder: (_) => const WakefulnessOverlay(),
      );
    } finally {
      _wakefulnessPresenting = false;
    }
  }

  /// Presents an OFFLINE schedule-triggered capture (no request id / nonce).
  Future<void> _presentScheduledPhoto() async {
    if (!mounted) {
      _handlingScheduledPhoto = false;
      return;
    }
    await Navigator.push(
      context,
      MaterialPageRoute<void>(builder: (_) => const PhotoScreen.scheduled()),
    );
    _handlingScheduledPhoto = false;
  }

  /// Presents an online/manual server-initiated photo request.
  Future<void> _presentOnlinePhoto({
    required String requestId,
    required String nonceValue,
    DateTime? issuedAt,
    DateTime? receivedAt,
    int? responseSeconds,
  }) async {
    if (!mounted) {
      if (_handlingPhotoRequestId == requestId) _handlingPhotoRequestId = null;
      return;
    }
    await Navigator.push(
      context,
      MaterialPageRoute<void>(
        builder: (_) => PhotoScreen(
          requestId: requestId,
          nonceValue: nonceValue,
          issuedAt: issuedAt,
          receivedAt: receivedAt,
          responseSeconds: responseSeconds,
        ),
      ),
    );
    // Free the lock once the capture flow closes, so a genuinely new request for
    // the same id (rare) can re-open later.
    if (_handlingPhotoRequestId == requestId) _handlingPhotoRequestId = null;
  }

  Future<void> _pollBackend() async {
    // Re-check the location master toggle each cycle. The OS status stream
    // handles a live foreground toggle instantly; this catches a change made
    // while the app was backgrounded (the stream may not replay it on resume).
    unawaited(ref.read(locationServiceEnabledProvider.notifier).refresh());

    // Refresh the pending-sync count and, when the backlog just drained to zero,
    // confirm it on-screen (so the offline→reconnect flush is visible with no
    // debugger attached).
    final pendingBefore = ref.read(pendingSyncProvider).pending;
    await ref.read(pendingSyncProvider.notifier).refresh();
    final pendingAfter = ref.read(pendingSyncProvider).pending;
    if (pendingBefore > 0 && pendingAfter == 0 && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Offline data synced ($pendingBefore item'
              '${pendingBefore == 1 ? '' : 's'})'),
          duration: const Duration(seconds: 3),
        ),
      );
    }

    // Always refresh the current shift so `can_start`/`can_end` stay live —
    // this is what flips the START button on once the server's 15-minute
    // pre-shift window opens, with no user action needed.
    await ref.read(currentShiftProvider.notifier).fetch();

    if (!ref.read(shiftProvider).active) return;
    try {
      final dio = ref.read(dioProvider);

      // Wakefulness: when the backend provisioned a TOTP schedule at shift start,
      // the local scheduler drives challenges from it — but ONLY WHEN OFFLINE.
      //
      // When online, a scheduled check exists as a real server row and is
      // delivered by push (Android/FCM) OR the `/wakefulness/pending` poll below
      // (iOS without APNs), both of which answer via `/respond` → recorded
      // ONLINE. Running the local scheduler while online would instead answer via
      // `/wakefulness/offline` → the dashboard mislabels an online check as
      // "Offline" (and risks double-firing the same window). So gate the local
      // scheduler on `!online`, not on push availability.
      final scheduler = ref.read(wakefulnessScheduleProvider.notifier);
      // "Effectively online" = the OS interface is up AND the backend actually
      // answered our recent requests. A phone on a captive portal / dead Wi-Fi
      // reports interface-online but no API call gets through; treating that as
      // online would suppress the offline scheduler AND fail every poll, so the
      // guard would see NO welfare/photo prompt at all. Falling back to the
      // offline path keeps prompting — and since the server truly can't be
      // reached, the answer is legitimately recorded offline (no mislabelling).
      final online =
          ref.read(isOnlineProvider) && ref.read(serverReachableProvider);
      if (scheduler.isArmed) {
        if (!online) {
          scheduler.checkSchedule();
        }
      } else {
        final welfareRes = await dio.get<Map<String, dynamic>>('/welfare/pending');
        final welfareData = welfareRes.data?['data'] as Map<String, dynamic>?;
        if (welfareData?['pending'] == true) {
          final checkId = welfareData?['check_id'] as String?;
          final code = welfareData?['code'] as String?;
          final status = ref.read(wakefulnessProvider).status;
          if (checkId != null && code != null && status == WakefulnessStatus.idle && mounted) {
            ref.read(wakefulnessProvider.notifier).trigger(checkId, code);
          }
        }
      }

      // Phase 7: offline-photo schedule. Self-fire a capture only when OFFLINE —
      // online, the same mark is delivered by the server as a PHOTO_REQUEST
      // (push or the /photos/pending poll below), so one schedule never
      // double-fires. Due-ness is judged against the NTP anchor, not the device
      // clock (tamper-resistant).
      final photoScheduler = ref.read(photoScheduleProvider.notifier);
      if (photoScheduler.isArmed) {
        photoScheduler.checkSchedule(offline: !online);
      }

      final shiftId = ref.read(shiftProvider).id;
      if (shiftId != null) {
        // Phase 7: while online, keep the offline-photo nonce pool topped up and
        // the NTP anchor fresh so a verification photo can still be captured +
        // signed if the link drops mid-shift. Best-effort, fire-and-forget.
        if (online) {
          // Only top up the offline-photo nonce pool when photo verification is
          // ON for this shift (per-site setting → armed schedule). Photos off ⇒
          // no offline capture will ever fire, so a prefetch is wasted (a manual
          // online request carries its own nonce). Keeps the NTP anchor fresh
          // regardless — it's cheap and also backs wakefulness time-integrity.
          if (photoScheduler.isArmed) {
            unawaited(ref.read(noncePoolServiceProvider).refillIfLow(shiftId));
          }
          unawaited(ref.read(timeAnchorServiceProvider).ensureFresh());

          // Wakefulness push-miss fallback: discover any outstanding ONLINE
          // challenge and raise the code-entry sheet in-app, even when the FCM
          // push never landed (notably iOS with no APNs). The offline TOTP
          // scheduler above is the OFFLINE authority and uses `totp-<win>` ids;
          // this poll surfaces server-initiated challenges (real uuids), so the
          // notifier's check_id dedup keeps the two paths from double-raising.
          if (ref.read(wakefulnessProvider).status == WakefulnessStatus.idle) {
            try {
              final wRes = await dio.get<Map<String, dynamic>>(
                ApiConfig.wakefulnessPending(shiftId),
              );
              final challenge = extractPendingWakefulness(wRes.data?['data']);
              if (challenge != null &&
                  mounted &&
                  ref.read(wakefulnessProvider).status == WakefulnessStatus.idle) {
                // Tell the server the challenge was seen (fire-and-forget), then
                // raise the same sheet the push path uses.
                unawaited(ref
                    .read(wakefulnessServiceProvider)
                    .confirmReceived(challenge.checkId));
                ref.read(wakefulnessProvider.notifier).trigger(
                      challenge.checkId,
                      challenge.code,
                      responseSeconds: challenge.responseSeconds ?? 60,
                      issuedAt: challenge.issuedAt,
                    );
              }
            } on DioException catch (_) {
              // Endpoint absent (older backend) or a transient blip — the push
              // and the local scheduler remain; ignore.
            }
          }
        }

        final photoRes = await dio.get<Map<String, dynamic>>(
          ApiConfig.shiftPhotosPending(shiftId),
        );
        final pending = extractPendingPhoto(photoRes.data?['data']);
        // Skip a request we're already handling (H1) OR one we've already shown
        // once — the server keeps a missed/expired request "pending", so without
        // the seen-set it would re-open every poll and dead-end on NONCE_EXPIRED.
        if (pending != null &&
            mounted &&
            pending.requestId != _handlingPhotoRequestId &&
            !_seenPhotoRequestIds.contains(pending.requestId)) {
          ref.read(pendingPhotoProvider.notifier).setPending(
                true,
                requestId: pending.requestId,
                nonceValue: pending.nonceValue,
                issuedAt: pending.issuedAt,
                // Foreground poll — anchor to now when the server gave no time.
                receivedAt: DateTime.now().toUtc(),
                responseSeconds: pending.responseSeconds,
              );
        }

        // Supervisor review outcomes — surface any new APPROVED/REJECTED (with
        // the note) as a tray notification + in-app alert. The poll is the
        // reliable path; it also catches up on reviews a background push
        // already banner'd (deduped, no double tray thanks to isDelivering).
        await ref.read(photoReviewProvider.notifier).pollAndIngest(
              shiftId,
              pushDelivering: PushMessaging.isDelivering,
            );
      }
    } on DioException catch (_) {
      // Silently ignore network errors during polling
    }
  }

  String _greeting() {
    final h = DateTime.now().hour;
    if (h < 12) return 'Good morning,';
    if (h < 18) return 'Good afternoon,';
    return 'Good evening,';
  }

  String _formatDate() {
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    final now = DateTime.now();
    return '${days[now.weekday - 1]} ${now.day} ${months[now.month - 1]} ${now.year}';
  }

  @override
  Widget build(BuildContext context) {
    // Auto-resume ONLY a genuinely started (`active`) shift whose local state
    // is out of sync — e.g. a parse error on POST /start, or an app relaunch
    // mid-shift. `checked_in` means the guard has logged in but NOT pressed
    // START yet (scheduled → checked_in → active), so it must keep showing the
    // START button — never auto-resume it into the active/END screen.
    ref.listen<CurrentShiftModel?>(currentShiftProvider, (_, next) {
      if (next != null &&
          next.status == 'active' &&
          !ref.read(shiftProvider).active) {
        ref.read(shiftProvider.notifier).resumeFromServer(next);
      }

      // The server closed the shift on its side while we still show it active:
      // an AUTO-close (`end_type: auto`) at scheduled_end+grace, or an admin
      // CANCEL. Reconcile locally (stop GPS, cancel reminder, clear state) and
      // tell the guard. Gated on end_type=='auto'/cancelled so a guard's OWN end
      // (end_type guard/early) — which also lands as `completed` here — never
      // trips this.
      final serverClosed = next != null &&
          ref.read(shiftProvider).active &&
          ((next.status == 'completed' && next.endType == 'auto') ||
              next.status == 'cancelled');
      if (serverClosed) {
        final cancelled = next.status == 'cancelled';
        ref.read(shiftProvider.notifier).reconcileServerClosed();
        ref.read(alertsProvider.notifier).prepend(AppAlert(
              id: 'shift-closed-${next.id}',
              severity: AlertSeverity.notice,
              title: cancelled ? 'Shift cancelled' : 'Shift auto-closed',
              description: cancelled
                  ? 'Your supervisor cancelled this shift.'
                  : 'Your shift was automatically closed at its scheduled end time.',
              time: 'just now',
            ));
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(cancelled
                  ? 'This shift was cancelled by your supervisor.'
                  : 'Your shift was automatically closed at its scheduled end.'),
              duration: const Duration(seconds: 5),
            ),
          );
        }
      }
    });

    // Show wakefulness overlay when backend triggers a welfare check.
    // Both challenge listeners delegate to methods so a cold-start (where a push
    // tap set the state BEFORE this listener registered, and ref.listen won't
    // replay the current value) can re-invoke the same logic from a post-frame
    // check in initState — see [_presentPendingChallenges].
    ref.listen<WakefulnessState>(
        wakefulnessProvider, (_, next) => _onWakefulnessState(next));
    ref.listen<PendingPhotoState>(
        pendingPhotoProvider, (_, next) => _onPendingPhotoState(next));

    final profile = ref.watch(guardProfileProvider);
    final shift = ref.watch(shiftProvider);
    final currentShift = ref.watch(currentShiftProvider);
    final battery = ref.watch(batteryProvider);
    final zone = ref.watch(zoneProvider);
    final zoneUpdatedAt = ref.watch(zoneUpdatedAtProvider);
    final online = ref.watch(isOnlineProvider);
    // Device location master toggle. When off, GPS silently produces nothing, so
    // the app is gated behind a blocking overlay until it's switched back on.
    final locationOn = ref.watch(locationServiceEnabledProvider);
    // Captures buffered offline and still waiting to upload.
    final pendingSync = ref.watch(pendingSyncProvider);

    // All horizontal padding flows from one responsive value for alignment.
    final hPad = context.s(16);

    // Shared scrollable content (everything above the action button).
    final content = <Widget>[
      // Status icon strip
      _StatusIconStrip(battery: battery, zone: zone, online: online),
      SizedBox(height: context.s(16)),

      // Offline sync status — a progress bar that fills as buffered captures
      // upload, so the offline→reconnect flush is visible on-device (no debugger).
      if (pendingSync.visible) ...[
        _SyncStatusChip(progress: pendingSync, online: online),
        SizedBox(height: context.s(16)),
      ],

      // Header row
      Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(_greeting(),
                    style: AppType.label.copyWith(
                      fontSize: context.sp(14),
                      color: AppColors.muted,
                    )),
                SizedBox(height: context.s(4)),
                Text(profile.name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppType.h2.copyWith(
                      fontSize: context.sp(22),
                      letterSpacing: -0.01,
                    )),
                SizedBox(height: context.s(4)),
                Text(_formatDate(),
                    style: AppType.caption.copyWith(
                      fontSize: context.sp(12),
                    )),
              ],
            ),
          ),
          SizedBox(width: context.s(12)),
          _Avatar(profile: profile),
        ],
      ),
      SizedBox(height: context.s(20)),

      // Zone card (when shift active)
      if (shift.active) ...[
        _ZoneCard(
          zone: zone,
          siteName: currentShift?.site?.name,
          updatedAt: zoneUpdatedAt,
        ),
        SizedBox(height: context.s(16)),
      ],

      // Shift card with elapsed time and server-sourced shift window
      _ShiftCard(profile: profile, shift: shift, currentShift: currentShift),
    ];

    return Scaffold(
      backgroundColor: AppColors.bg,
      body: Stack(
        children: [
          Positioned.fill(
            child: DecoratedBox(
              decoration: const BoxDecoration(gradient: AppGradients.background),
            ),
          ),
          SafeArea(
            child: Column(
              children: [
                if (!online) const _OfflineBanner(),
                if (shift.active && ref.watch(locationDeniedProvider))
                  const _LocationOffBanner(),
                if (shift.active && currentShift != null)
                  _OverdueBanner(scheduledEnd: currentShift.scheduledEnd),
                Expanded(
                  child: shift.active
                // ACTIVE: everything scrolls together so nothing clips on any
                // phone; the End button flows comfortably below the content.
                // Pull-to-refresh re-runs the backend poll (shift state, checks,
                // location toggle) on demand instead of waiting for the 20s tick.
                ? RefreshIndicator(
                    onRefresh: _pollBackend,
                    color: AppColors.gold,
                    backgroundColor: AppColors.surface,
                    child: SingleChildScrollView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: EdgeInsets.fromLTRB(
                        hPad, context.s(8), hPad, context.s(24),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          ...content,
                          SizedBox(height: context.s(32)),
                          Center(child: _ActionButtons(shift: shift, currentShift: currentShift)),
                        ],
                      ),
                    ),
                  )
                // INACTIVE: content scrolls at top; Start button sits below
                // in a fixed-height slot; Sign Out pinned to the bottom.
                : Column(
                    children: [
                      Expanded(
                        flex: 3,
                        child: RefreshIndicator(
                          onRefresh: _pollBackend,
                          color: AppColors.gold,
                          backgroundColor: AppColors.surface,
                          child: SingleChildScrollView(
                            physics: const AlwaysScrollableScrollPhysics(),
                            padding: EdgeInsets.fromLTRB(
                              hPad, context.s(8), hPad, context.s(8),
                            ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: content,
                            ),
                          ),
                        ),
                      ),
                      Expanded(
                        flex: 2,
                        child: Center(child: _ActionButtons(shift: shift, currentShift: currentShift)),
                      ),
                      Padding(
                        padding: EdgeInsets.symmetric(horizontal: hPad),
                        child: Container(
                          height: 1,
                          decoration: const BoxDecoration(
                            gradient: LinearGradient(
                              colors: [
                                Colors.transparent,
                                AppColors.border,
                                Colors.transparent,
                              ],
                            ),
                          ),
                        ),
                      ),
                      Padding(
                        padding: EdgeInsets.all(hPad),
                        child: _SignOutButton(),
                      ),
                    ],
                  ),
                ),      // closes Expanded
              ],
            ),          // closes outer Column
          ),
          // Blocking gate whenever device location services are off DURING an
          // active shift — GPS only matters then, and the tracking requirement is
          // what the block enforces. Gated on shift.active so a guard who isn't on
          // shift (e.g. wrong account) can still reach Sign Out / the pre-shift
          // screen instead of being trapped behind it (L7).
          if (shift.active && !locationOn)
            const Positioned.fill(child: LocationRequiredOverlay()),
        ],
      ),
    );
  }
}

// ── Avatar ────────────────────────────────────────────────────────────────

class _Avatar extends StatelessWidget {
  const _Avatar({required this.profile});
  final GuardProfile profile;

  @override
  Widget build(BuildContext context) {
    final size = context.s(44);
    final dot = context.s(12);
    return Stack(
      children: [
        Container(
          width: size,
          height: size,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            gradient: AppGradients.avatar,
            border: Border.all(color: const Color(0x80D4AF37), width: 2),
          ),
          alignment: Alignment.center,
          child: Text(
            profile.initials,
            style: AppType.label.copyWith(
              fontSize: context.sp(16),
              color: AppColors.gold,
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
        Positioned(
          bottom: 0,
          right: 0,
          child: Container(
            width: dot,
            height: dot,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: AppColors.success,
              border: Border.all(color: AppColors.bg, width: 2),
            ),
          ),
        ),
      ],
    );
  }
}

// ── Status icon strip ─────────────────────────────────────────────────────

class _StatusIconStrip extends StatelessWidget {
  const _StatusIconStrip({
    required this.battery,
    required this.zone,
    required this.online,
  });

  final double? battery;
  final int zone;
  final bool online;

  Color get _batteryColor {
    final b = battery;
    if (b == null) return AppColors.muted; // unknown
    if (b > 30) return AppColors.success;
    if (b > 15) return AppColors.warning;
    return AppColors.danger;
  }

  @override
  Widget build(BuildContext context) {
    final gap = context.s(8);
    // Each tile changes its icon shape (not only its colour) when degraded, so
    // status is legible to colour-blind users and is announced to VoiceOver /
    // TalkBack via a semantic label.
    final b = battery;
    final batteryLow = b != null && b <= 15;
    final batteryWarn = b != null && b <= 30;
    // The aggregate tile must reflect real subsystem health, not just the
    // network: it's only "normal" when we're online AND inside the zone (0).
    final systemsNormal = online && zone == 0 && !batteryLow;
    return Row(
      mainAxisAlignment: MainAxisAlignment.end,
      children: [
        _StatusTile(
          icon: zone == 2 ? Icons.location_off_rounded : Icons.location_on_rounded,
          // 0 = inside (ok), 1 = outside the geofence (warn), 2 = no signal (bad).
          color: zone == 0
              ? AppColors.success
              : zone == 1
                  ? AppColors.warning
                  : AppColors.danger,
          semanticLabel: zone == 0
              ? 'GPS active, in zone'
              : zone == 1
                  ? 'Outside patrol zone'
                  : 'GPS signal lost',
        ),
        SizedBox(width: gap),
        _StatusTile(
          icon: online ? Icons.sync_rounded : Icons.sync_problem_rounded,
          color: online ? AppColors.success : AppColors.warning,
          semanticLabel: online ? 'Synced' : 'Offline, sync paused',
        ),
        SizedBox(width: gap),
        _StatusTile(
          icon: b == null
              ? Icons.battery_unknown_rounded
              : batteryLow
                  ? Icons.battery_alert_rounded
                  : batteryWarn
                      ? Icons.battery_3_bar_rounded
                      : Icons.battery_full_rounded,
          color: _batteryColor,
          semanticLabel: b == null ? 'Battery level unknown' : 'Battery ${b.round()} percent',
        ),
        SizedBox(width: gap),
        _StatusTile(
          icon: systemsNormal
              ? Icons.check_circle_outline_rounded
              : online
                  ? Icons.info_outline_rounded
                  : Icons.error_outline_rounded,
          color: systemsNormal
              ? AppColors.success
              : online
                  ? AppColors.warning
                  : AppColors.danger,
          semanticLabel: systemsNormal
              ? 'All systems normal'
              : online
                  ? 'Check status — see the cards above'
                  : 'Connection issue',
        ),
      ],
    );
  }
}

class _StatusTile extends StatelessWidget {
  const _StatusTile({
    required this.icon,
    required this.color,
    required this.semanticLabel,
  });
  final IconData icon;
  final Color color;
  final String semanticLabel;

  @override
  Widget build(BuildContext context) {
    final size = context.s(42);
    return Semantics(
      label: semanticLabel,
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(context.s(13)),
          border: Border.all(color: AppColors.border, width: 1),
          boxShadow: AppShadows.card,
        ),
        child: Stack(
          children: [
            Center(child: Icon(icon, color: color, size: context.s(20))),
            Positioned(
              top: 0,
              left: 0,
              right: 0,
              height: 1,
              child: const DecoratedBox(
                decoration: BoxDecoration(
                  gradient: AppGradients.cardTopHighlight,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}


// ── Zone card (when shift active) ─────────────────────────────────────────

class _ZoneCard extends StatefulWidget {
  const _ZoneCard({required this.zone, this.siteName, this.updatedAt});
  final int zone;
  final String? siteName;
  final DateTime? updatedAt;

  @override
  State<_ZoneCard> createState() => _ZoneCardState();
}

class _ZoneCardState extends State<_ZoneCard> with SingleTickerProviderStateMixin {
  late AnimationController _pulseCtrl;

  @override
  void initState() {
    super.initState();
    _pulseCtrl = AnimationController(
      vsync: this,
      duration: widget.zone == 2
          ? const Duration(milliseconds: 1500)
          : const Duration(milliseconds: 2500),
    );
    if (widget.zone > 0) _pulseCtrl.repeat();
  }

  @override
  void didUpdateWidget(_ZoneCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.zone > 0 && oldWidget.zone == 0) {
      _pulseCtrl.repeat();
    } else if (widget.zone == 0) {
      _pulseCtrl.stop();
    }
  }

  @override
  void dispose() {
    _pulseCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return _ZoneCardContent(
      zone: widget.zone,
      siteName: widget.siteName,
      updatedAt: widget.updatedAt,
      pulseAnimation: _pulseCtrl,
    );
  }
}

class _ZoneCardContent extends StatelessWidget {
  const _ZoneCardContent({
    required this.zone,
    required this.pulseAnimation,
    this.siteName,
    this.updatedAt,
  });
  final int zone;
  final AnimationController pulseAnimation;
  final String? siteName;
  final DateTime? updatedAt;

  String get _zoneLabel {
    switch (zone) {
      case 0:
        return 'INSIDE ZONE';
      case 1:
        return 'OUTSIDE ZONE';
      case 2:
        return 'NO SIGNAL';
      default:
        return 'INSIDE ZONE';
    }
  }

  Color get _zoneColor {
    switch (zone) {
      case 0:
        return AppColors.success;
      case 1:
        return AppColors.warning;
      case 2:
        return AppColors.danger;
      default:
        return AppColors.success;
    }
  }

  Color get _zoneBg {
    switch (zone) {
      case 0:
        return const Color(0x1722C55E);
      case 1:
        return const Color(0x19F59E0B);
      case 2:
        return const Color(0x19DC2626);
      default:
        return const Color(0x1722C55E);
    }
  }

  Color get _zoneBorder {
    switch (zone) {
      case 0:
        return const Color(0x4722C55E);
      case 1:
        return const Color(0x59F59E0B);
      case 2:
        return const Color(0x59DC2626);
      default:
        return const Color(0x4722C55E);
    }
  }

  @override
  Widget build(BuildContext context) {
    final iconSize = context.s(40);
    return AnimatedBuilder(
      animation: pulseAnimation,
      builder: (context, child) {
        final pulseValue = zone == 0 ? 0.0 : pulseAnimation.value;
        final shadowColor = zone == 1
            ? AppColors.warning.withValues(alpha: 0.3 * (1 - pulseValue))
            : AppColors.danger.withValues(alpha: 0.3 * (1 - pulseValue));

        return Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            boxShadow: zone > 0
                ? [
                    BoxShadow(
                      color: shadowColor,
                      blurRadius: 24.0 * (1 + pulseValue),
                      spreadRadius: 4.0 * pulseValue,
                    ),
                  ]
                : null,
          ),
          child: child,
        );
      },
      child: AppCard(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: iconSize,
              height: iconSize,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: _zoneBg,
                border: Border.all(color: _zoneBorder, width: 1.5),
              ),
              alignment: Alignment.center,
              child: Icon(
                zone == 0
                    ? Icons.check_rounded
                    : zone == 1
                        ? Icons.warning_amber_rounded
                        : Icons.signal_wifi_off_rounded,
                color: _zoneColor,
                size: context.sp(20),
              ),
            ),
            SizedBox(width: context.s(16)),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    _zoneLabel,
                    style: AppType.h3.copyWith(
                      fontSize: context.sp(18),
                      fontWeight: FontWeight.w700,
                      color: _zoneColor,
                    ),
                  ),
                  SizedBox(height: context.s(4)),
                  Text(
                    siteName != null && siteName!.isNotEmpty
                        ? '$siteName · on site'
                        : 'On site',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: AppType.caption.copyWith(
                      fontSize: context.sp(12),
                      color: AppColors.muted,
                    ),
                  ),
                  SizedBox(height: context.s(4)),
                  _ZoneUpdatedLabel(updatedAt: updatedAt),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ── Zone "last updated" label ─────────────────────────────────────────────
// Shows an honest relative time that ticks live. Stays explicit about the
// "no fix yet" case rather than inventing a timestamp (the simulator never
// gets a GPS fix, so this is what shows there).

class _ZoneUpdatedLabel extends StatelessWidget {
  const _ZoneUpdatedLabel({required this.updatedAt});
  final DateTime? updatedAt;

  String _ago(DateTime t) {
    final secs = DateTime.now().difference(t).inSeconds;
    if (secs < 5) return 'Updated just now · tracking';
    if (secs < 60) return 'Updated ${secs}s ago · tracking';
    final mins = secs ~/ 60;
    if (mins < 60) return 'Updated ${mins}m ago · tracking';
    final hrs = mins ~/ 60;
    return 'Updated ${hrs}h ago';
  }

  @override
  Widget build(BuildContext context) {
    final style = AppType.caption.copyWith(
      fontSize: context.sp(11),
      color: AppColors.muted,
    );
    if (updatedAt == null) {
      return Text('Awaiting first GPS fix…', style: style);
    }
    // Rebuild every second so the relative time stays current.
    return StreamBuilder<int>(
      stream: Stream.periodic(const Duration(seconds: 1), (i) => i),
      builder: (context, _) => Text(_ago(updatedAt!), style: style),
    );
  }
}

// ── Shift card ────────────────────────────────────────────────────────────

class _ShiftCard extends ConsumerStatefulWidget {
  const _ShiftCard({
    required this.profile,
    required this.shift,
    required this.currentShift,
  });
  final GuardProfile profile;
  final ShiftState shift;
  final CurrentShiftModel? currentShift;

  @override
  ConsumerState<_ShiftCard> createState() => _ShiftCardState();
}

class _ShiftCardState extends ConsumerState<_ShiftCard> {
  String _fmt(DateTime dt) {
    final h = dt.hour.toString().padLeft(2, '0');
    final m = dt.minute.toString().padLeft(2, '0');
    return '$h:$m';
  }

  String _fmtDate(DateTime dt) {
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return '${days[dt.weekday - 1]} ${dt.day} ${months[dt.month - 1]} ${dt.year}';
  }

  String get _subtitle {
    final start = widget.shift.startTime;
    if (start != null) return 'Started ${_fmt(start)} · ${_fmtDate(start)}';
    final a = widget.currentShift;
    if (a != null) return '${_fmt(a.scheduledStart)} – ${_fmt(a.scheduledEnd)} · ${_fmtDate(a.scheduledStart)}';
    return _fmtDate(DateTime.now());
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.currentShift;
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text('TODAY\'S SHIFT',
                  style: AppType.micro.copyWith(
                    fontSize: context.sp(11),
                    fontWeight: FontWeight.w600,
                    letterSpacing: 0.88,
                    color: AppColors.muted,
                  )),
              AppChip(
                label: widget.shift.shiftRef ?? a?.displayRef ?? '#--',
                variant: AppChipVariant.info,
              ),
            ],
          ),
          SizedBox(height: context.s(8)),
          Text(a?.site?.name ?? '—',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: AppType.h3.copyWith(
                fontSize: context.sp(18),
                fontWeight: FontWeight.w600,
              )),
          SizedBox(height: context.s(4)),
          Text(_subtitle,
              style: AppType.caption.copyWith(fontSize: context.sp(14))),

          // Role + notes from the current shift (only before shift starts)
          if (!widget.shift.active && a != null) ...[
            if (a.role != null) ...[
              SizedBox(height: context.s(4)),
              Text(a.role!,
                  style: AppType.caption.copyWith(
                    fontSize: context.sp(12),
                    color: AppColors.muted,
                  )),
            ],
            if (a.notes != null) ...[
              SizedBox(height: context.s(4)),
              Text(a.notes!,
                  style: AppType.caption.copyWith(
                    fontSize: context.sp(11),
                    color: AppColors.muted,
                  ),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis),
            ],
          ],

          SizedBox(height: context.s(12)),
          Container(
            height: 1,
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                colors: [Colors.transparent, AppColors.border, Colors.transparent],
              ),
            ),
          ),
          SizedBox(height: context.s(12)),

          if (widget.shift.active && widget.shift.startTime != null)
            StreamBuilder<int>(
              stream: Stream.periodic(const Duration(seconds: 1), (i) => i),
              builder: (context, _) {
                final elapsed = DateTime.now().difference(widget.shift.startTime!);
                final h = elapsed.inHours.toString().padLeft(2, '0');
                final m = (elapsed.inMinutes % 60).toString().padLeft(2, '0');
                final s = (elapsed.inSeconds % 60).toString().padLeft(2, '0');
                return Text(
                  'Elapsed: $h:$m:$s',
                  style: AppType.label.copyWith(
                    fontSize: context.sp(16),
                    color: AppColors.gold,
                    fontWeight: FontWeight.w600,
                    // Tabular figures keep every digit the same width so the
                    // timer doesn't shift left/right as the seconds tick.
                    fontFeatures: const [FontFeature.tabularFigures()],
                  ),
                );
              },
            )
          else
            // Pre-shift: only show status we can actually verify. GPS isn't
            // running until the shift starts, so we don't claim it here —
            // connectivity is real, and readiness reflects the server's
            // can_start window.
            Builder(
              builder: (context) {
                final online = ref.watch(isOnlineProvider);
                final canStart = a?.canStart ?? false;
                return Wrap(
                  spacing: context.s(8),
                  runSpacing: context.s(8),
                  children: [
                    online
                        ? const AppChip(
                            label: 'Online',
                            variant: AppChipVariant.success,
                            icon: Icons.cloud_done_outlined)
                        : const AppChip(
                            label: 'Offline',
                            variant: AppChipVariant.warning,
                            icon: Icons.cloud_off_outlined),
                    canStart
                        ? const AppChip(
                            label: 'Ready to start',
                            variant: AppChipVariant.success,
                            icon: Icons.check_circle_outline)
                        : const AppChip(
                            label: 'Awaiting window',
                            variant: AppChipVariant.info,
                            icon: Icons.schedule),
                  ],
                );
              },
            ),
        ],
      ),
    );
  }
}

// ── Action buttons ────────────────────────────────────────────────────────

class _ActionButtons extends ConsumerStatefulWidget {
  const _ActionButtons({required this.shift, required this.currentShift});
  final ShiftState shift;
  final CurrentShiftModel? currentShift;

  @override
  ConsumerState<_ActionButtons> createState() => _ActionButtonsState();
}

class _ActionButtonsState extends ConsumerState<_ActionButtons> {
  bool _starting = false;

  String _fmtHHmm(DateTime dt) =>
      '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';

  /// The server is the source of truth for `can_start` — this hint just
  /// explains *why* the button is disabled (15 minutes before the
  /// scheduled start, per the shift-gating contract).
  String? get _startHint {
    final cs = widget.currentShift;
    if (cs == null) return 'No shift scheduled for today.';
    if (cs.canStart) return null;
    if (cs.status != 'scheduled' && cs.status != 'checked_in') return null;
    final opensAt = cs.scheduledStart.subtract(const Duration(minutes: 15));
    return 'You can begin your shift from ${_fmtHHmm(opensAt)}.';
  }

  Future<void> _startWithPermissions() async {
    if (_starting) return;
    setState(() => _starting = true);
    try {
      // Location permissions (When-In-Use + the background "Always" upgrade) and
      // camera are all requested up front at the permission gate on app launch,
      // so there is no permission prompt here — the shift starts immediately.
      String? errorMessage;
      try {
        await ref.read(shiftProvider.notifier).start();
      } on DioException catch (e) {
        // The POST may have reached the server and started the shift even though
        // the app saw a timeout / slow response / odd body. Capture the message
        // but don't surface it yet — reconcile with the server first.
        errorMessage = ApiError.fromDioException(e).message;
        if (kDebugMode) {
          debugPrint('[shift] start() DioException type=${e.type} '
              'status=${e.response?.statusCode} body=${e.response?.data}');
        }
      } catch (e) {
        // Non-Dio error (e.g. response parsing). Fall through to verification.
        if (kDebugMode) debugPrint('[shift] start() non-Dio error: $e');
      }

      // Reconcile with the server regardless of how start() finished. If the
      // backend reports the shift as `active`, go active — this makes the
      // "backend started but app stuck on a disabled START button" bug impossible
      // as long as the server reports the shift's true state. Only `active`
      // counts here: `checked_in` means the start hasn't actually happened.
      if (!ref.read(shiftProvider).active) {
        await ref.read(currentShiftProvider.notifier).fetch();
        final cs = ref.read(currentShiftProvider);
        if (cs != null && cs.status == 'active') {
          ref.read(shiftProvider.notifier).resumeFromServer(cs);
        }
      }

      // Only show an error if we genuinely failed to start (server doesn't
      // report it active either).
      if (!ref.read(shiftProvider).active &&
          errorMessage != null &&
          mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(errorMessage), duration: const Duration(seconds: 4)),
        );
      }
    } finally {
      if (mounted) setState(() => _starting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final shift = widget.shift;
    if (!shift.active) {
      final canStart = widget.currentShift?.canStart ?? false;
      final hint = _startHint;
      return Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          _CircleStartButton(
            loading: _starting,
            onTap: canStart ? _startWithPermissions : null,
          ),
          if (hint != null) ...[
            SizedBox(height: context.s(12)),
            Text(
              hint,
              style: AppType.caption.copyWith(
                fontSize: context.sp(12),
                color: AppColors.muted,
              ),
              textAlign: TextAlign.center,
            ),
          ],
        ],
      );
    }

    // Before the scheduled end, ending counts as "early" and now needs
    // supervisor approval. The hint + button state below mirror where the
    // request is in that approval cycle (pending / approved / rejected).
    final cs = widget.currentShift;
    final online = ref.watch(isOnlineProvider);
    final isEarly = cs != null && DateTime.now().isBefore(cs.scheduledEnd);
    final pending = cs?.earlyEndPending ?? false;
    final approved = cs?.earlyEndApproved ?? false;
    final rejected = cs?.earlyEndRejected ?? false;

    // Ending is a server operation (duration, early-end approval, auto-close
    // reconciliation), so it can't complete offline — a tap would only fail with
    // an error. Lock END while offline with a clear reason instead of letting the
    // End-Shift sheet dead-end on a failed POST. The backend auto-close at
    // scheduled_end+grace is the safety net if the guard can't reconnect.
    final locked = pending || !online;

    // Hint explains a disabled button. Offline takes precedence — the guard must
    // know WHY END won't respond; then the early-end approval states.
    String? hint;
    Color hintColor = AppColors.muted;
    if (!online) {
      hint = "You're offline — reconnect to end your shift.";
      hintColor = AppColors.warning;
    } else if (pending) {
      hint = 'Early-end request sent · waiting for supervisor approval';
      hintColor = AppColors.warning;
    } else if (approved) {
      hint = 'Approved — tap END to finish your shift';
      hintColor = AppColors.success;
    } else if (rejected && isEarly) {
      hint = 'Early-end request declined · you can request again';
      hintColor = AppColors.warning;
    } else if (isEarly) {
      hint = 'Shift ends at ${_fmtHHmm(cs.scheduledEnd)} · ending now needs approval';
    }

    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          _CircleEndButton(
            locked: locked,
            onTap: locked ? null : () => showEndShiftSheet(context),
          ),
          if (hint != null) ...[
            SizedBox(height: context.s(12)),
            Text(
              hint,
              style: AppType.caption.copyWith(
                fontSize: context.sp(12),
                color: hintColor,
              ),
              textAlign: TextAlign.center,
            ),
          ],
        ],
      ),
    );
  }
}

// ── Circular start button ─────────────────────────────────────────────────

class _CircleStartButton extends StatefulWidget {
  const _CircleStartButton({required this.onTap, this.loading = false});
  final VoidCallback? onTap;
  final bool loading;

  @override
  State<_CircleStartButton> createState() => _CircleStartButtonState();
}

class _CircleStartButtonState extends State<_CircleStartButton> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final base = context.s(190);
    final size = base.clamp(150.0, context.screenH * 0.26);

    // While starting, the button stays visible (full opacity) but ignores taps
    // so the network call can't be double-fired.
    final tappable = widget.onTap != null && !widget.loading;
    return GestureDetector(
      onTapDown: tappable
          ? (_) {
              HapticFeedback.mediumImpact();
              setState(() => _pressed = true);
            }
          : null,
      onTapUp: tappable ? (_) => setState(() => _pressed = false) : null,
      onTapCancel: tappable ? () => setState(() => _pressed = false) : null,
      onTap: tappable ? widget.onTap : null,
      child: AnimatedScale(
        scale: _pressed ? 0.96 : 1.0,
        duration: const Duration(milliseconds: 100),
        child: Opacity(
          opacity: (tappable || widget.loading) ? 1.0 : 0.4,
          child: Container(
            width: size,
            height: size,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: AppGradients.primaryButton,
              border: Border.all(color: const Color(0x80D4AF37), width: 2),
              boxShadow: const [
                BoxShadow(
                  color: Color(0x7312355B),
                  blurRadius: 20,
                  offset: Offset(0, 4),
                ),
              ],
            ),
            alignment: Alignment.center,
            child: widget.loading
                ? SizedBox(
                    width: context.s(34),
                    height: context.s(34),
                    child: const CircularProgressIndicator(
                      color: AppColors.text,
                      strokeWidth: 3,
                    ),
                  )
                : Text(
                    'START',
                    style: AppType.bodySemi.copyWith(
                      fontSize: context.sp(26),
                      fontWeight: FontWeight.w800,
                      color: AppColors.text,
                      letterSpacing: 0.32,
                    ),
                  ),
          ),
        ),
      ),
    );
  }
}

// ── Circular end button ───────────────────────────────────────────────────

class _CircleEndButton extends StatefulWidget {
  const _CircleEndButton({required this.onTap, this.locked = false});
  // null onTap or locked=true renders the button disabled (e.g. while an
  // early-end request is awaiting supervisor approval).
  final VoidCallback? onTap;
  final bool locked;

  @override
  State<_CircleEndButton> createState() => _CircleEndButtonState();
}

class _CircleEndButtonState extends State<_CircleEndButton> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final base = context.s(190);
    final size = base.clamp(150.0, context.screenH * 0.26);
    final disabled = widget.locked || widget.onTap == null;
    final accent = disabled ? AppColors.muted : AppColors.gold;

    return GestureDetector(
      onTapDown: disabled
          ? null
          : (_) {
              HapticFeedback.mediumImpact();
              setState(() => _pressed = true);
            },
      onTapUp: disabled ? null : (_) => setState(() => _pressed = false),
      onTapCancel: disabled ? null : () => setState(() => _pressed = false),
      onTap: disabled ? null : widget.onTap,
      child: AnimatedScale(
        scale: _pressed ? 0.96 : 1.0,
        duration: const Duration(milliseconds: 100),
        child: Opacity(
          opacity: disabled ? 0.55 : 1.0,
          child: Container(
            width: size,
            height: size,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: disabled ? const Color(0x0AFFFFFF) : const Color(0x0DD4AF37),
              border: Border.all(color: accent.withValues(alpha: 0.5), width: 2),
            ),
            alignment: Alignment.center,
            child: disabled && widget.locked
                ? Icon(Icons.hourglass_top_rounded,
                    size: context.sp(34), color: accent)
                : Text(
                    'END',
                    style: AppType.bodySemi.copyWith(
                      fontSize: context.sp(26),
                      fontWeight: FontWeight.w800,
                      color: accent,
                      letterSpacing: 0.32,
                    ),
                  ),
          ),
        ),
      ),
    );
  }
}


// ── Overdue banner ────────────────────────────────────────────────────────
// Appears once the shift runs past its scheduled end and is still active —
// the on-screen half of the "your shift has ended, go end it" reminder. Ticks
// itself so it shows up without waiting for a provider change.

class _OverdueBanner extends StatefulWidget {
  const _OverdueBanner({required this.scheduledEnd});
  final DateTime scheduledEnd;

  @override
  State<_OverdueBanner> createState() => _OverdueBannerState();
}

class _OverdueBannerState extends State<_OverdueBanner> {
  Timer? _ticker;

  @override
  void initState() {
    super.initState();
    // Re-evaluate every 30s so the banner appears shortly after the end time
    // even if nothing else rebuilds the screen.
    _ticker = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  String _fmtHHmm(DateTime dt) =>
      '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    if (!DateTime.now().isAfter(widget.scheduledEnd)) {
      return const SizedBox.shrink();
    }
    return Container(
      width: double.infinity,
      padding: EdgeInsets.symmetric(
        vertical: context.s(8),
        horizontal: context.s(16),
      ),
      color: AppColors.warning.withValues(alpha: 0.14),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.schedule_rounded,
              size: context.s(15), color: AppColors.warning),
          SizedBox(width: context.s(8)),
          Flexible(
            child: Text(
              'Shift ended at ${_fmtHHmm(widget.scheduledEnd)} — tap END to close it',
              style: AppType.micro.copyWith(
                fontSize: context.sp(11),
                color: AppColors.warning,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Sync status chip ──────────────────────────────────────────────────────
// Shows how many captures are buffered offline and waiting to upload. Online →
// "Syncing…" (the flush is running); offline → "saved, will upload when online".
// Makes the offline→reconnect flush visible on-device without a debugger.

class _SyncStatusChip extends StatelessWidget {
  const _SyncStatusChip({required this.progress, required this.online});
  final SyncProgress progress;
  final bool online;

  @override
  Widget build(BuildContext context) {
    final completed = progress.completed;
    final color = completed
        ? AppColors.success
        : (online ? AppColors.gold : AppColors.warning);
    final total = progress.total;
    final done = progress.done;
    // Completed → a brief green "all synced ✓"; online → a filling bar with
    // "done of total"; offline → the count waiting (nothing moves until online).
    final label = completed
        ? 'All offline items synced ✓'
        : online
            ? (total > 0
                ? 'Uploading $done of $total offline item${total == 1 ? '' : 's'}…'
                : 'Syncing…')
            : '${progress.pending} item${progress.pending == 1 ? '' : 's'} '
                'saved offline — will upload when online';

    return Container(
      width: double.infinity,
      padding: EdgeInsets.symmetric(
        vertical: context.s(10),
        horizontal: context.s(12),
      ),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                completed
                    ? Icons.check_circle_rounded
                    : online
                        ? Icons.sync_rounded
                        : Icons.cloud_upload_outlined,
                size: context.s(16),
                color: color,
              ),
              SizedBox(width: context.s(10)),
              Expanded(
                child: Text(
                  label,
                  style: AppType.caption.copyWith(
                    fontSize: context.sp(12),
                    color: color,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              if (completed || (online && total > 0))
                Text(
                  '${(progress.fraction * 100).round()}%',
                  style: AppType.caption.copyWith(
                    fontSize: context.sp(12),
                    color: color,
                    fontWeight: FontWeight.w700,
                  ),
                ),
            ],
          ),
          if (online || completed) ...[
            SizedBox(height: context.s(8)),
            ClipRRect(
              borderRadius: BorderRadius.circular(4),
              child: LinearProgressIndicator(
                // Determinate once we know the batch size (and at 100% when just
                // completed); a thin indeterminate sweep only in the brief moment
                // before the first count lands.
                value: (completed || total > 0) ? progress.fraction : null,
                minHeight: context.s(6),
                backgroundColor: color.withValues(alpha: 0.18),
                valueColor: AlwaysStoppedAnimation(color),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

// ── Offline banner ────────────────────────────────────────────────────────

class _OfflineBanner extends StatelessWidget {
  const _OfflineBanner();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.symmetric(
        vertical: context.s(6),
        horizontal: context.s(16),
      ),
      color: AppColors.warning.withValues(alpha: 0.12),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.wifi_off_rounded,
              size: context.s(13), color: AppColors.warning),
          SizedBox(width: context.s(6)),
          Text(
            'No connection — data will sync when back online',
            style: AppType.micro.copyWith(
              fontSize: context.sp(11),
              color: AppColors.warning,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

// ── Location-off banner ───────────────────────────────────────────────────
// Persistent (not a transient snackbar) so a guard working with location denied
// can't miss that they're untracked. Danger-coloured because, for an on-site
// verification product, no location is a compliance failure, not a soft warning.

class _LocationOffBanner extends StatelessWidget {
  const _LocationOffBanner();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.symmetric(
        vertical: context.s(6),
        horizontal: context.s(16),
      ),
      color: AppColors.danger.withValues(alpha: 0.14),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.location_off_rounded,
              size: context.s(13), color: AppColors.danger),
          SizedBox(width: context.s(6)),
          Flexible(
            child: Text(
              'Location tracking OFF — enable it in Settings',
              textAlign: TextAlign.center,
              style: AppType.micro.copyWith(
                fontSize: context.sp(11),
                color: AppColors.danger,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Sign Out button ───────────────────────────────────────────────────────

class _SignOutButton extends ConsumerStatefulWidget {
  @override
  ConsumerState<_SignOutButton> createState() => _SignOutButtonState();
}

class _SignOutButtonState extends ConsumerState<_SignOutButton> {
  bool _pressed = false;

  Future<void> _confirmAndSignOut() async {
    // Signing out ends the local shift session and clears credentials, so guard
    // against an accidental tap with an explicit confirmation.
    final confirmed = await showDialog<bool>(
      context: context,
      barrierColor: const Color(0xB8000000),
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: const BorderSide(color: AppColors.border),
        ),
        title: Text('Sign out?', style: AppType.h3),
        content: Text(
          "You'll need to sign in again to access your shift.",
          style: AppType.caption.copyWith(
            fontSize: context.sp(13),
            color: AppColors.muted,
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: Text('Cancel',
                style: AppType.label.copyWith(color: AppColors.muted)),
          ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: Text('Sign Out',
                style: AppType.label.copyWith(color: const Color(0xFFE05555))),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    // Do NOT call end() here — sign-out must not silently close an active
    // shift (bypassing early-end approval). signOut() already stops GPS,
    // cancels the reminder, and clears local state; the backend auto-close
    // handles any open shift on the server side.
    await ref.read(authProvider.notifier).signOut();
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTapDown: (_) => setState(() => _pressed = true),
      onTapUp: (_) => setState(() => _pressed = false),
      onTapCancel: () => setState(() => _pressed = false),
      onTap: _confirmAndSignOut,
      child: AnimatedOpacity(
        opacity: _pressed ? 0.6 : 1.0,
        duration: const Duration(milliseconds: 100),
        child: Container(
          width: double.infinity,
          padding: EdgeInsets.symmetric(vertical: context.s(12)),
          decoration: BoxDecoration(
            color: Colors.transparent,
            border: Border.all(
              color: const Color(0x40DC2626),
              width: 1,
            ),
            borderRadius: BorderRadius.circular(context.s(12)),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.logout, size: context.s(15), color: const Color(0xFFE05555)),
              SizedBox(width: context.s(8)),
              Text(
                'Sign Out',
                style: AppType.label.copyWith(
                  fontSize: context.sp(13),
                  color: const Color(0xFFE05555),
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
