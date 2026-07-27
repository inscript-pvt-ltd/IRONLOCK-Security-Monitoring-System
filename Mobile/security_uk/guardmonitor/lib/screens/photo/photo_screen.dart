import 'dart:async';
import 'dart:io';
import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:permission_handler/permission_handler.dart';
import '../../providers/app_providers.dart';
import '../../services/nonce_pool_service.dart';
import '../../services/offline_photo_service.dart';
import '../../services/photo_service.dart';
import '../../services/secure_storage_service.dart';
import '../../theme/app_colors.dart';
import '../../theme/app_spacing.dart';
import '../../theme/app_typography.dart';
import '../../theme/responsive.dart';
import '../../widgets/app_button.dart';

class PhotoScreen extends ConsumerStatefulWidget {
  const PhotoScreen({
    super.key,
    required String this.requestId,
    required String this.nonceValue,
    this.issuedAt,
    this.receivedAt,
    this.responseSeconds,
  }) : scheduled = false;

  /// Phase 7 — OFFLINE schedule-triggered capture. No server request id / nonce
  /// and no response countdown: the guard captures, and on submit the screen
  /// draws a pool nonce, signs, and queues for flush on reconnect.
  const PhotoScreen.scheduled({super.key})
      : requestId = null,
        nonceValue = null,
        issuedAt = null,
        receivedAt = null,
        responseSeconds = null,
        scheduled = true;

  // Null in scheduled (offline) mode.
  final String? requestId;
  // Server-issued nonce for this online request — used to sign the upload.
  final String? nonceValue;
  // True for the offline schedule-triggered capture (no request id / countdown).
  final bool scheduled;
  // Server issue time of the request, when sent (`issued_at`). Highest-priority
  // anchor for the response countdown.
  final DateTime? issuedAt;
  // When the foreground push/poll first saw the request. Fallback anchor when
  // there's no server `issued_at` and no stamped background arrival.
  final DateTime? receivedAt;
  // Server-supplied window length, when sent. Null → [kPhotoWindowSeconds].
  final int? responseSeconds;

  @override
  ConsumerState<PhotoScreen> createState() => _PhotoScreenState();
}

class _PhotoScreenState extends ConsumerState<PhotoScreen>
    with TickerProviderStateMixin {
  Timer? _timer;
  late final AnimationController _scanCtrl;
  late final AnimationController _flashCtrl;

  List<CameraDescription> _cameras = [];
  CameraController? _cameraCtrl;
  int _cameraIndex = 0;   // index into _cameras for the active lens
  bool _cameraReady = false;
  bool _switching = false; // guards re-entrant front/back toggles
  bool _noHardware = false; // true only when no camera hardware found (simulator)
  bool _permissionDenied = false; // camera runtime permission not granted
  String? _cameraError; // real-device camera init failure (surfaced, not swallowed)
  String? _capturedPath; // the just-taken photo, awaiting Retake / Add / Use
  // Photos already committed via "Add another" — a request may be answered with
  // up to kMaxPhotosPerRequest images in one upload. The held [_capturedPath] is
  // the (N+1)th under review; on Use Photos it's appended and the batch uploads.
  final List<String> _capturedPaths = [];
  bool _popping = false;  // guards the auto-pop after a successful upload
  // Captured in initState so dispose() can reset the FSM WITHOUT `ref` — using
  // `ref` in dispose throws once the element is unmounted during tree teardown.
  late final PhotoNotifier _photoNotifier;

  @override
  void initState() {
    super.initState();
    _photoNotifier = ref.read(photoProvider.notifier);

    _scanCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 3500),
    )..repeat();

    _flashCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 350),
    );

    _initCamera();

    // Offline scheduled mode has no *server* response window, but leaving the
    // overlay open forever if the guard never takes the photo is worse. So it
    // gets the SAME fixed 90s window as an online request — anchored to when the
    // screen opens (there's no server issue-time offline). On expiry the screen
    // closes and the mark is recorded as a miss (the server also logs the missed
    // scheduled check from the reconstructed timeline). The captured photo is
    // still validated server-side by its NTP-anchored timestamp, not this timer.
    if (widget.scheduled) {
      WidgetsBinding.instance.addPostFrameCallback(
          (_) => ref.read(photoProvider.notifier).openScheduled());
    } else {
      // Open the request's window anchored to when it was *issued/arrived*, not to
      // now — the server's response window is fixed, so a late tap (e.g. the guard
      // opened the notification minutes later) must spend the elapsed time and may
      // even open already-expired. The provider is global and may still hold the
      // previous request's terminal state, so set it explicitly here.
      WidgetsBinding.instance.addPostFrameCallback((_) => _openWindow());
    }

    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      final s = ref.read(photoProvider);
      // Tick through every state where the response window is still live —
      // including capturing and reviewing, so the countdown never pauses while
      // the guard is deciding whether to keep the shot.
      if (s.status == PhotoStatus.idle ||
          s.status == PhotoStatus.capturing ||
          s.status == PhotoStatus.reviewing ||
          s.status == PhotoStatus.expired) {
        ref.read(photoProvider.notifier).tick();
      }
    });
  }

  /// Anchor and open the response window for this request. Reads the highest-
  /// priority anchor available (server `issued_at` → the FCM background isolate's
  /// stamped arrival → the foreground `receivedAt` → now) and opens the window
  /// with whatever time is left.
  Future<void> _openWindow() async {
    if (!mounted) return;
    final windowSeconds = widget.responseSeconds ?? kPhotoWindowSeconds;
    // Arrival stamped by the background isolate when the push landed while
    // locked — only set for *this* request id.
    final bgReceipt = widget.issuedAt == null
        ? await SecureStorageService.getPhotoReceipt(widget.requestId!)
        : null;
    // One-shot: clear it so a later screen for a new request can't read a stale
    // arrival. Best-effort.
    await SecureStorageService.clearPhotoReceipt();
    if (!mounted) return;
    final remaining = photoSecondsRemaining(
      issuedAt: widget.issuedAt,
      receivedAt: bgReceipt ?? widget.receivedAt,
      windowSeconds: windowSeconds,
      now: DateTime.now().toUtc(),
    );
    ref.read(photoProvider.notifier).startWindow(
          remaining: remaining,
          windowSeconds: windowSeconds,
        );
  }

  Future<void> _initCamera() async {
    // Camera needs runtime permission. Without it, availableCameras() /
    // CameraController.initialize() throw on many Android devices — which used
    // to be swallowed and faked as a "simulated" view, leaving the guard with no
    // preview, no flip button, and a shutter that dead-ends. Request it up front
    // and surface the real outcome instead.
    final status = await Permission.camera.request();
    if (!mounted) return;
    if (!status.isGranted) {
      setState(() => _permissionDenied = true);
      return;
    }

    try {
      // Some devices return an empty list on the first query right after a
      // permission grant (the camera service hasn't refreshed yet) — retry a
      // few times before concluding there's no hardware.
      for (var attempt = 0; attempt < 3; attempt++) {
        _cameras = await availableCameras();
        if (_cameras.isNotEmpty) break;
        await Future<void>.delayed(const Duration(milliseconds: 400));
      }
      if (_cameras.isEmpty) {
        setState(() => _noHardware = true); // genuinely no hardware (simulator)
        return;
      }
      // Photo verification is a presence/identity check, so default to the
      // front-facing camera; the guard can switch to the rear lens (e.g. to
      // capture their surroundings) via the flip button.
      _cameraIndex = _indexForDirection(CameraLensDirection.front);
      await _setController(_cameras[_cameraIndex]);
    } catch (e) {
      // A real failure on a real device — show it (with a Retry) rather than a
      // misleading simulated view.
      if (mounted) setState(() => _cameraError = e.toString());
    }
  }

  /// Re-attempt camera init after a permission grant or a transient failure.
  void _retryCamera() {
    setState(() {
      _permissionDenied = false;
      _cameraError = null;
      _cameraReady = false;
      _noHardware = false;
    });
    _initCamera();
  }

  /// The full-bleed live layer behind the controls: the real preview when ready,
  /// an actionable problem view when permission/init failed (instead of a fake
  /// "simulated" view that hides the failure), the simulated view only on a
  /// genuine no-camera device, else a brief loading state.
  Widget _buildLivePreview() {
    if (_cameraReady && _cameraCtrl != null) {
      return _RealCameraView(controller: _cameraCtrl!);
    }
    if (_permissionDenied) {
      return _CameraProblemView(
        icon: Icons.no_photography_outlined,
        title: 'Camera access needed',
        message:
            'Allow camera permission to take your verification photo, then return here.',
        actionLabel: 'Open Settings',
        onAction: () => openAppSettings(),
      );
    }
    if (_cameraError != null) {
      return _CameraProblemView(
        icon: Icons.error_outline,
        title: 'Camera unavailable',
        message: 'The camera could not be started on this device. Tap retry.',
        actionLabel: 'Retry',
        onAction: _retryCamera,
      );
    }
    if (_noHardware) return _SimulatedCameraView(scanCtrl: _scanCtrl);
    // Permission granted, still initialising the controller.
    return const ColoredBox(
      color: Colors.black,
      child: Center(
        child: CircularProgressIndicator(
          valueColor: AlwaysStoppedAnimation<Color>(AppColors.gold),
        ),
      ),
    );
  }

  /// (Re)build the controller for [camera] and mark the preview ready.
  Future<void> _setController(CameraDescription camera) async {
    // Release the current camera BEFORE opening the next. Most Android devices
    // only allow one open camera at a time, so initializing the new controller
    // while the old one is still alive throws "camera in use" — which made the
    // back-camera preview come up blank after a flip. Tear down first.
    final old = _cameraCtrl;
    if (old != null) {
      _cameraCtrl = null;
      await old.dispose();
    }

    final ctrl = CameraController(
      camera,
      // Medium (~480p) keeps the upload payload small — a presence/identity
      // check doesn't need full resolution, and the smaller JPEG uploads far
      // faster and is less likely to hit the 60s receive timeout on weak
      // mobile signal. Bump back up to .high if image detail ever matters.
      ResolutionPreset.medium,
      enableAudio: false,
    );
    await ctrl.initialize();
    if (!mounted) {
      await ctrl.dispose();
      return;
    }
    setState(() {
      _cameraCtrl = ctrl;
      _cameraReady = true;
    });
  }

  /// The index of the **normal** lens for [dir]. Modern iPhones expose several
  /// back lenses (wide, ultra-wide, telephoto) as separate cameras; cycling all
  /// of them is what landed the flip on the ultra-wide (the zoomed-out look).
  /// `availableCameras()` lists the default wide-angle first for each side, so
  /// the first match is the standard lens. Falls back to 0 if none matches.
  int _indexForDirection(CameraLensDirection dir) {
    final i = _cameras.indexWhere((c) => c.lensDirection == dir);
    return i >= 0 ? i : 0;
  }

  bool get _hasFrontAndBack =>
      _cameras.any((c) => c.lensDirection == CameraLensDirection.front) &&
      _cameras.any((c) => c.lensDirection == CameraLensDirection.back);

  /// Toggle strictly between the normal front and normal back lens — NOT a cycle
  /// through every physical lens (which would step onto the ultra-wide /
  /// telephoto). Only meaningful while lining up the shot (idle).
  Future<void> _switchCamera() async {
    if (_switching || !_hasFrontAndBack) return;
    if (ref.read(photoProvider).status != PhotoStatus.idle) return;
    _switching = true;
    setState(() => _cameraReady = false); // brief loading state during the swap
    try {
      final current = _cameras[_cameraIndex].lensDirection;
      final target = current == CameraLensDirection.back
          ? CameraLensDirection.front
          : CameraLensDirection.back;
      _cameraIndex = _indexForDirection(target);
      await _setController(_cameras[_cameraIndex]);
    } catch (e) {
      // Surface it (with a Retry) rather than leaving a silent blank preview.
      if (mounted) setState(() => _cameraError = e.toString());
    } finally {
      _switching = false;
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _scanCtrl.dispose();
    _flashCtrl.dispose();
    _cameraCtrl?.dispose();
    // Best-effort cleanup of any temp shots not consumed by an upload.
    _deleteFiles([_capturedPath, ..._capturedPaths]);
    // Reset the (global) photo FSM so a terminal state (expired/failed) left on
    // screen close can't block the next request or the offline scheduler. Use the
    // captured [_photoNotifier] (not `ref` — throws once unmounted) AND defer off
    // the current frame: mutating a provider synchronously in dispose throws
    // "modified a provider while the widget tree was building". Microtask runs
    // after the frame, when it's safe.
    Future.microtask(_photoNotifier.reset);
    super.dispose();
  }

  /// Best-effort delete of temp capture files (the OS clears them regardless).
  void _deleteFiles(Iterable<String?> paths) {
    for (final p in paths) {
      if (p == null) continue;
      try {
        File(p).delete();
      } catch (_) {}
    }
  }

  int get _totalCaptured => _capturedPaths.length + (_capturedPath != null ? 1 : 0);
  bool get _canAddMore => _totalCaptured < kMaxPhotosPerRequest;

  Future<void> _capture() async {
    final s = ref.read(photoProvider);
    if (s.status != PhotoStatus.idle) return;

    _flashCtrl.forward(from: 0).then((_) => _flashCtrl.reverse());
    ref.read(photoProvider.notifier).startCapture();

    String? filePath;
    String? captureError;

    if (_cameraReady && _cameraCtrl != null) {
      try {
        final xFile = await _cameraCtrl!.takePicture();
        filePath = xFile.path;
        // Guard against a path the review Image.file can't actually read.
        if (!File(filePath).existsSync()) {
          captureError = 'captured file missing: $filePath';
          filePath = null;
        }
      } catch (e) {
        captureError = e.toString();
      }
    }

    if (filePath == null) {
      if (_noHardware) {
        // Simulator only — write a minimal valid JPEG so the upload path stays
        // exercisable without real camera hardware.
        final f = File('${Directory.systemTemp.path}/guard_sim_${DateTime.now().millisecondsSinceEpoch}.jpg');
        await f.writeAsBytes(_kMinimalJpeg);
        filePath = f.path;
      } else {
        // Real device: the shot failed — surface WHY (instead of swallowing it)
        // and return to idle keeping the remaining window so the guard can retry.
        ref.read(photoProvider.notifier).retake();
        if (mounted) {
          setState(() => _cameraError = captureError ?? 'capture returned no file');
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('Capture failed: ${captureError ?? 'no image returned'}'),
              duration: const Duration(seconds: 6),
            ),
          );
        }
        return;
      }
    }

    // Hold the shot for review instead of uploading immediately — the guard
    // decides Retake or Use Photo, all inside the same response window.
    if (!mounted) return;
    setState(() => _capturedPath = filePath);
    ref.read(photoProvider.notifier).review();
  }

  /// Discard the held shot and return to the live camera, keeping the time left
  /// and any already-committed shots.
  void _retake() {
    _deleteFiles([_capturedPath]);
    setState(() => _capturedPath = null);
    ref.read(photoProvider.notifier).retake();
  }

  /// Keep the held shot (commit it to the batch) and return to the live camera
  /// for the next one — same response window. Only when under the 5-photo cap.
  void _addAnother() {
    final path = _capturedPath;
    if (path == null || _capturedPaths.length >= kMaxPhotosPerRequest) return;
    setState(() {
      _capturedPaths.add(path);
      _capturedPath = null;
    });
    ref.read(photoProvider.notifier).retake(); // back to idle/live camera
  }

  /// Remove an already-committed shot from the batch.
  void _removeCommitted(int index) {
    if (index < 0 || index >= _capturedPaths.length) return;
    final removed = _capturedPaths[index];
    setState(() => _capturedPaths.removeAt(index));
    _deleteFiles([removed]);
  }

  /// Commit the held shot and upload the whole batch (1–5).
  Future<void> _confirmUpload() async {
    if (_capturedPath == null && _capturedPaths.isEmpty) return;
    // Fold the held shot into the batch so it uploads with the rest.
    final batch = [..._capturedPaths, ?_capturedPath];
    setState(() {
      _capturedPaths
        ..clear()
        ..addAll(batch);
      _capturedPath = null;
    });
    ref.read(photoProvider.notifier).uploading();
    await _upload(batch);
  }

  Future<void> _upload(List<String> filePaths) async {
    final shiftId = ref.read(shiftProvider).id;
    if (shiftId == null) {
      ref.read(photoProvider.notifier).setResult(PhotoStatus.failed);
      return;
    }

    // Bind the photo to where it was taken so the server can confirm it was
    // captured on-site. A failed/timed-out fix uploads without coordinates
    // rather than blocking the verification entirely.
    double? latitude;
    double? longitude;
    try {
      final pos = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 8),
        ),
      );
      latitude = pos.latitude;
      longitude = pos.longitude;
    } catch (_) {
      // Location unavailable (denied, simulator, timeout) — proceed without it.
    }

    // Phase 7 — OFFLINE schedule-triggered capture: don't upload now. Draw a
    // pool nonce, sign + persist + queue; the flush engine sends it on reconnect.
    // Capture time is the NTP-anchored proof, never the device clock.
    if (widget.scheduled) {
      final id = await ref.read(offlinePhotoServiceProvider).enqueueCapture(
            shiftId: shiftId,
            filePaths: filePaths,
            latitude: latitude,
            longitude: longitude,
          );
      if (!mounted) return;
      if (id != null) {
        // The capture was durably copied + queued — count it as answered and
        // close. (Server validation / review arrives later via the reviews poll.)
        ref.read(shiftProvider.notifier).recordPhoto(passed: true);
        _deleteFiles(filePaths); // durable copies were made by enqueueCapture
        _capturedPaths.clear();
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text("Saved — will upload when you're back online."),
          duration: Duration(seconds: 3),
        ));
        await Navigator.of(context).maybePop();
      } else {
        // enqueueCapture returned null — can't queue, so this capture is lost (a
        // scheduled mark won't re-fire). Record the miss AND surface the actual
        // reason: an empty/expired nonce pool and a missing signing key are very
        // different faults, and the generic message hid which one we hit. (No
        // nonce was consumed on a null return, so these reads are accurate.)
        final poolDepth =
            await ref.read(noncePoolServiceProvider).available(shiftId);
        final hasKey = await SecureStorageService.getHmacSecret() != null;
        if (!mounted) return;
        ref.read(photoProvider.notifier).setResult(PhotoStatus.failed);
        ref.read(shiftProvider.notifier).recordPhoto(passed: false);
        final msg = !hasKey
            ? 'Missing signing key — sign out and back in, then retry.'
            : 'No offline photo credential available (pool empty — depth '
                '$poolDepth). Offline capture needs a nonce prefetched within the '
                'last ~15 min; reconnect to refresh.';
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(msg),
          duration: const Duration(seconds: 6),
        ));
      }
      return;
    }

    try {
      // Online (server-initiated) check: sign with the nonce delivered on the
      // request, not a pool nonce. One nonce covers all 1–5 images.
      final result = await ref.read(photoServiceProvider).uploadPhotos(
        filePaths: filePaths,
        shiftId: shiftId,
        requestId: widget.requestId,
        // Non-null here: scheduled (offline) mode returned above.
        nonceValue: widget.nonceValue!,
        latitude: latitude,
        longitude: longitude,
      );

      if (!mounted) return;

      final photoStatus = switch (result.result) {
        'VALIDATED' => PhotoStatus.validated,
        'FLAGGED'   => PhotoStatus.flagged,
        _           => PhotoStatus.failed,
      };

      ref.read(photoProvider.notifier).setResult(photoStatus);
      // FLAGGED still means the photo was accepted and stored ("no action
      // needed" for the guard) — only a rejection/transport failure is a miss.
      // Count both VALIDATED and FLAGGED as a completed photo (ONE answered
      // request, regardless of how many images it carried) in the summary.
      final stored = photoStatus == PhotoStatus.validated ||
          photoStatus == PhotoStatus.flagged;
      ref.read(shiftProvider.notifier).recordPhoto(passed: stored);
      // Accepted → the batch is consumed; drop the temp files. (On failure we
      // keep them so Try Again can re-upload the same set.)
      if (stored) {
        _deleteFiles(filePaths);
        _capturedPaths.clear();
      }
    } on PhotoRejectedException catch (e) {
      // The photo was not stored. Surface the reason; an invalid signature
      // means the signing key may have rotated, so prompt a re-login path.
      if (!mounted) return;
      ref.read(photoProvider.notifier).setResult(PhotoStatus.failed);
      ref.read(shiftProvider.notifier).recordPhoto(passed: false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.needsRelogin
              ? 'Verification failed (signature). Please sign out and back in, then retry.'
              : 'Photo rejected: ${_rejectionLabel(e.reason)}. Tap Try Again.'),
          duration: const Duration(seconds: 4),
        ),
      );
    } catch (_) {
      // Transport failure (timeout / connection drop) — NOT a rejection. The
      // guard did respond; the upload just didn't land. Don't record a local
      // "miss" (that would double-count against a successful Try Again, and the
      // server owns the real missed-photo verdict). Show Try Again instead.
      if (!mounted) return;
      ref.read(photoProvider.notifier).setResult(PhotoStatus.failed);
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Upload failed — check your connection and tap Try Again.'),
        duration: Duration(seconds: 4),
      ));
    }
  }

  /// Human-readable label for a server `PHOTO_REJECTED` reason.
  String _rejectionLabel(String reason) => switch (reason) {
        'NONCE_EXPIRED' => 'the time window expired',
        'NONCE_ALREADY_USED' => 'already submitted',
        'NONCE_NOT_FOUND' => 'invalid request token',
        'TIMELINE_ANOMALY' => 'capture time looks wrong — check your clock',
        'REQUEST_NOT_FOUND' => 'request no longer valid',
        _ => 'verification failed',
      };

  @override
  Widget build(BuildContext context) {
    final photo = ref.watch(photoProvider);
    final isIdle = photo.status == PhotoStatus.idle;
    final isReviewing = photo.status == PhotoStatus.reviewing;
    final isExpired = photo.status == PhotoStatus.expired;
    final timerColor = photo.secondsRemaining <= 10 ? AppColors.danger : AppColors.warning;
    // The shutter only does something when the camera is genuinely usable: the
    // real preview is ready, or it's the simulator dummy-image path.
    final canCapture = isIdle && (_cameraReady || _noHardware);

    // The held shot is shown frozen on screen from review through to the result
    // — including `expired`, so a window that lapses mid-review keeps the shot on
    // screen instead of snapping back to the live camera.
    final showCaptured = _capturedPath != null &&
        (isReviewing ||
            photo.status == PhotoStatus.uploading ||
            photo.status == PhotoStatus.validated ||
            photo.status == PhotoStatus.flagged ||
            photo.status == PhotoStatus.failed ||
            isExpired);

    // Once the upload lands (verified or flagged-but-accepted), close the
    // overlay and drop the guard back on the shift screen.
    ref.listen<PhotoState>(photoProvider, (prev, next) {
      final done = next.status == PhotoStatus.validated ||
          next.status == PhotoStatus.flagged;
      if (done && prev?.status != next.status && !_popping) {
        _popping = true;
        final navigator = Navigator.of(context);
        Future.delayed(const Duration(milliseconds: 1000), () {
          if (mounted) navigator.maybePop();
        });
      }
      // Window lapsed. The request is dead server-side — a capture now would only
      // fail NONCE_EXPIRED — so drop any committed batch and CLOSE the screen
      // after briefly showing "timed out", instead of reopening a live camera. A
      // new capture must come from a new server request.
      if (next.status == PhotoStatus.expired &&
          prev?.status != PhotoStatus.expired) {
        if (_capturedPaths.isNotEmpty) {
          final stale = List<String>.from(_capturedPaths);
          setState(() => _capturedPaths.clear());
          _deleteFiles(stale);
        }
        // An OFFLINE scheduled capture that timed out uncaptured is a genuine
        // miss — nothing was queued, so record it locally for an honest end-shift
        // summary (the server also logs the missed scheduled check from the
        // reconstructed timeline). Guarded to once by the `prev != expired` check.
        // The online path leaves the verdict to the server, so don't count it here.
        if (widget.scheduled) {
          ref.read(shiftProvider.notifier).recordPhoto(passed: false);
        }
        if (!_popping) {
          _popping = true;
          final navigator = Navigator.of(context);
          Future.delayed(const Duration(seconds: 3), () {
            if (mounted) navigator.maybePop();
          });
        }
      }
    });

    return Scaffold(
      backgroundColor: Colors.black,
      // Camera-app style: the preview fills the whole screen and every control
      // is overlaid on top of it, instead of sitting in a small boxed card.
      body: Stack(
        fit: StackFit.expand,
        children: [
          // Full-bleed: the frozen captured shot while reviewing/uploading,
          // otherwise the live preview (real camera on device, simulated on sim).
          if (showCaptured)
            Image.file(
              File(_capturedPath!),
              fit: BoxFit.cover,
              width: double.infinity,
              height: double.infinity,
            )
          else
            _buildLivePreview(),

          // Top scrim so the header + timer stay legible over a bright preview.
          Align(
            alignment: Alignment.topCenter,
            child: Container(
              height: context.s(190),
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [Color(0xCC000000), Color(0x00000000)],
                ),
              ),
            ),
          ),

          // Header + response window + progress, pinned to the top.
          SafeArea(
            bottom: false,
            child: Padding(
              padding: const EdgeInsets.symmetric(
                horizontal: AppSpacing.base,
                vertical: AppSpacing.base,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Row(
                    children: [
                      GestureDetector(
                        onTap: () => Navigator.of(context).pop(),
                        child: const Icon(Icons.arrow_back, color: AppColors.text),
                      ),
                      const SizedBox(width: AppSpacing.md),
                      Text('Photo Verification', style: AppType.h3),
                    ],
                  ),
                  SizedBox(height: context.s(12)),
                  // Response window + progress. Offline scheduled captures now run
                  // the SAME fixed 90s window as an online request (so the overlay
                  // can't linger forever) — the only difference is a note that the
                  // shot saves offline instead of uploading now.
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text('Respond within', style: AppType.caption),
                      Text(
                        isExpired ? 'Expired' : '${photo.secondsRemaining}s',
                        style: AppType.label.copyWith(
                          color: isExpired ? AppColors.danger : timerColor,
                        ),
                      ),
                    ],
                  ),
                  SizedBox(height: context.s(8)),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(2),
                    child: LinearProgressIndicator(
                      value: isExpired
                          ? 0
                          : photo.secondsRemaining /
                              (photo.windowSeconds == 0 ? 1 : photo.windowSeconds),
                      backgroundColor: AppColors.border,
                      valueColor: AlwaysStoppedAnimation<Color>(
                        photo.secondsRemaining <= 10 ? AppColors.danger : AppColors.warning,
                      ),
                      minHeight: 3,
                    ),
                  ),
                  if (widget.scheduled) ...[
                    SizedBox(height: context.s(8)),
                    Row(
                      children: [
                        Icon(Icons.cloud_off,
                            size: context.s(14), color: AppColors.muted),
                        const SizedBox(width: AppSpacing.sm),
                        Expanded(
                          child: Text(
                            'Offline — saved and uploaded when you reconnect.',
                            style: AppType.caption,
                          ),
                        ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
          ),

          // Bottom scrim behind the capture controls.
          Align(
            alignment: Alignment.bottomCenter,
            child: Container(
              height: context.s(280),
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.bottomCenter,
                  end: Alignment.topCenter,
                  colors: [Color(0xD9000000), Color(0x00000000)],
                ),
              ),
            ),
          ),

          // Capture controls overlaid at the bottom.
          SafeArea(
            top: false,
            child: Align(
              alignment: Alignment.bottomCenter,
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.base,
                  vertical: AppSpacing.lg,
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (isReviewing) ...[
                      // Committed shots so far (removable), then this held shot.
                      if (_capturedPaths.isNotEmpty) ...[
                        _CapturedStrip(
                          paths: _capturedPaths,
                          onRemove: _removeCommitted,
                        ),
                        SizedBox(height: context.s(12)),
                      ],
                      // Held shot — keep it, add another, or submit. All inside
                      // the one response window (up to 5 photos per request).
                      Text(
                        _canAddMore
                            ? 'Use this photo, or add another ($_totalCaptured/$kMaxPhotosPerRequest)'
                            : 'Maximum $kMaxPhotosPerRequest photos',
                        style: AppType.label,
                        textAlign: TextAlign.center,
                      ),
                      SizedBox(height: context.s(14)),
                      Row(
                        children: [
                          Expanded(
                            child: AppButton(
                              label: 'Retake',
                              variant: AppButtonVariant.secondary,
                              onPressed: _retake,
                            ),
                          ),
                          if (_canAddMore) ...[
                            const SizedBox(width: AppSpacing.md),
                            Expanded(
                              child: AppButton(
                                label: 'Add',
                                variant: AppButtonVariant.secondary,
                                onPressed: _addAnother,
                              ),
                            ),
                          ],
                          const SizedBox(width: AppSpacing.md),
                          Expanded(
                            child: AppButton(
                              label: _totalCaptured > 1
                                  ? 'Use $_totalCaptured Photos'
                                  : 'Use Photo',
                              onPressed: _confirmUpload,
                            ),
                          ),
                        ],
                      ),
                    ] else if (isIdle || photo.status == PhotoStatus.capturing) ...[
                      // Already-committed shots remain visible + removable while
                      // lining up the next one.
                      if (_capturedPaths.isNotEmpty) ...[
                        _CapturedStrip(
                          paths: _capturedPaths,
                          onRemove: _removeCommitted,
                        ),
                        SizedBox(height: context.s(12)),
                      ],
                      Text(
                        _permissionDenied
                            ? 'Camera permission needed — see above'
                            : _cameraError != null
                                ? 'Camera unavailable — tap Retry above'
                                : _cameraReady
                                    ? 'Point camera at your surroundings and tap capture'
                                    : _noHardware
                                        ? 'Simulated camera · will use real camera on device'
                                        : 'Starting camera…',
                        style: AppType.caption,
                        textAlign: TextAlign.center,
                      ),
                      SizedBox(height: context.s(16)),
                      SizedBox(
                        // Full width so the Stack spans the screen — otherwise it
                        // shrink-wraps to the shutter and the flip's `right:` lands
                        // ON TOP of the shutter (hidden, and stealing its taps).
                        width: double.infinity,
                        height: context.s(72),
                        child: Stack(
                          alignment: Alignment.center,
                          children: [
                            // Only fireable once the camera is actually usable
                            // (ready, or the simulator dummy path) — otherwise it
                            // dead-ends. Permission/error recovery is driven by
                            // the problem view's button instead.
                            _ShutterButton(
                              onTap: canCapture ? _capture : null,
                              enabled: canCapture,
                            ),
                            // Flip front/back — pinned to the right edge, vertically
                            // centred. Only when both a front and a back lens
                            // exist, and while idle.
                            if (_hasFrontAndBack)
                              Positioned(
                                right: context.s(28),
                                top: 0,
                                bottom: 0,
                                child: Center(
                                  child: _FlipButton(
                                    onTap: isIdle ? _switchCamera : null,
                                  ),
                                ),
                              ),
                          ],
                        ),
                      ),
                    ],
                    if (photo.status != PhotoStatus.idle &&
                        !isReviewing &&
                        photo.status != PhotoStatus.capturing) ...[
                      _UploadStatus(status: photo.status),
                    ],
                    // Only a genuine miss (rejection / transport failure) offers
                    // Try Again. FLAGGED means the photo WAS accepted & stored
                    // ("no action needed") and auto-pops — showing Try Again for
                    // it just flashed the button for ~1s before the screen closed.
                    if (photo.status == PhotoStatus.failed) ...[
                      SizedBox(height: context.s(10)),
                      AppButton(
                        label: 'Try Again',
                        variant: AppButtonVariant.secondary,
                        onPressed: () {
                          // Fresh start: drop the held shot AND the whole batch.
                          _deleteFiles([_capturedPath, ..._capturedPaths]);
                          setState(() {
                            _capturedPath = null;
                            _capturedPaths.clear();
                          });
                          ref.read(photoProvider.notifier).tryAgain();
                        },
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),

          // Flash overlay
          IgnorePointer(
            child: AnimatedBuilder(
              animation: _flashCtrl,
              builder: (_, _) => Opacity(
                opacity: _flashCtrl.value,
                child: const ColoredBox(color: Colors.white),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Real camera view ──────────────────────────────────────────────────────────

class _RealCameraView extends StatelessWidget {
  const _RealCameraView({required this.controller});
  final CameraController controller;

  @override
  Widget build(BuildContext context) {
    // Fill the whole screen the way a native camera app does: scale the preview
    // up so it *covers* the area (cropping the overflow) rather than leaving
    // letterbox bars. Without this the preview keeps its sensor aspect ratio and
    // looks small/boxed.
    final size = MediaQuery.of(context).size;
    var scale = size.aspectRatio * controller.value.aspectRatio;
    if (scale < 1) scale = 1 / scale;
    return ClipRect(
      child: Transform.scale(
        scale: scale,
        alignment: Alignment.center,
        child: Center(child: CameraPreview(controller)),
      ),
    );
  }
}

// ── Camera problem view (permission denied / init failed on a real device) ───

class _CameraProblemView extends StatelessWidget {
  const _CameraProblemView({
    required this.icon,
    required this.title,
    required this.message,
    required this.actionLabel,
    required this.onAction,
  });
  final IconData icon;
  final String title;
  final String message;
  final String actionLabel;
  final VoidCallback onAction;

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: const Color(0xFF05070F),
      child: Center(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, color: AppColors.gold, size: context.s(48)),
              SizedBox(height: context.s(16)),
              Text(title, style: AppType.h3, textAlign: TextAlign.center),
              SizedBox(height: context.s(8)),
              Text(
                message,
                style: AppType.caption,
                textAlign: TextAlign.center,
              ),
              SizedBox(height: context.s(20)),
              AppButton(label: actionLabel, onPressed: onAction),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Simulated camera view (simulator / no camera available) ──────────────────

class _SimulatedCameraView extends StatelessWidget {
  const _SimulatedCameraView({required this.scanCtrl});
  final AnimationController scanCtrl;

  @override
  Widget build(BuildContext context) {
    // Full-bleed to match the real-camera layout — no fixed height or rounded
    // card, it fills the Stack it's placed in.
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Color(0xFF020408), Color(0xFF05070F)],
        ),
      ),
      child: CustomPaint(
        painter: _CameraOverlayPainter(scanCtrl),
        child: const _CameraLabels(),
      ),
    );
  }
}

class _CameraOverlayPainter extends CustomPainter {
  _CameraOverlayPainter(this.animation) : super(repaint: animation);
  final Animation<double> animation;

  @override
  void paint(Canvas canvas, Size size) {
    final w = size.width;
    final h = size.height;

    final gridPaint = Paint()
      ..color = const Color(0x0DD4AF37)
      ..strokeWidth = 0.8;
    canvas.drawLine(Offset(w / 3, 0), Offset(w / 3, h), gridPaint);
    canvas.drawLine(Offset(2 * w / 3, 0), Offset(2 * w / 3, h), gridPaint);
    canvas.drawLine(Offset(0, h / 3), Offset(w, h / 3), gridPaint);
    canvas.drawLine(Offset(0, 2 * h / 3), Offset(w, 2 * h / 3), gridPaint);

    final scanY = animation.value * h;
    final scanFade = (1 - animation.value).clamp(0.0, 1.0);
    final scanPaint = Paint()
      ..shader = LinearGradient(
        colors: [
          Colors.transparent,
          Color.fromRGBO(212, 175, 55, scanFade * 0.55),
          Colors.transparent,
        ],
      ).createShader(Rect.fromLTWH(0, scanY, w, 1));
    canvas.drawLine(Offset(0, scanY), Offset(w, scanY), scanPaint..strokeWidth = 1.5);

    const boxSize = 72.0;
    final crosshairPaint = Paint()
      ..color = const Color(0x8CD4AF37)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5;
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromCenter(center: Offset(w * 0.5, h * 0.5), width: boxSize, height: boxSize),
        const Radius.circular(4),
      ),
      crosshairPaint,
    );

    const bSize = 22.0;
    const inset = 20.0;
    final bracketPaint = Paint()
      ..color = AppColors.gold
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.5
      ..strokeCap = StrokeCap.square;

    canvas.drawPath(Path()
      ..moveTo(inset, inset + bSize)..lineTo(inset, inset)..lineTo(inset + bSize, inset), bracketPaint);
    canvas.drawPath(Path()
      ..moveTo(w - inset - bSize, inset)..lineTo(w - inset, inset)..lineTo(w - inset, inset + bSize), bracketPaint);
    canvas.drawPath(Path()
      ..moveTo(inset, h - inset - bSize)..lineTo(inset, h - inset)..lineTo(inset + bSize, h - inset), bracketPaint);
    canvas.drawPath(Path()
      ..moveTo(w - inset - bSize, h - inset)..lineTo(w - inset, h - inset)..lineTo(w - inset, h - inset - bSize), bracketPaint);
  }

  @override
  bool shouldRepaint(_CameraOverlayPainter old) => true;
}

class _CameraLabels extends StatelessWidget {
  const _CameraLabels();

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.bottomCenter,
      child: Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
        child: Text(
          'Simulated camera · gallery disabled',
          style: AppType.micro.copyWith(
            fontSize: context.sp(10),
            color: const Color(0x73D4AF37),
            fontWeight: FontWeight.w400,
          ),
        ),
      ),
    );
  }
}

// ── Captured-photos strip (multi-photo verification, up to 5) ────────────────

/// Horizontal row of the shots committed to the current upload batch, each with
/// a tap-to-remove ✕. Lets the guard build up 1–5 photos for one request and
/// drop any they don't want before submitting.
class _CapturedStrip extends StatelessWidget {
  const _CapturedStrip({required this.paths, required this.onRemove});
  final List<String> paths;
  final void Function(int index) onRemove;

  @override
  Widget build(BuildContext context) {
    final side = context.s(56);
    return SizedBox(
      height: side + context.s(6),
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        shrinkWrap: true,
        itemCount: paths.length,
        separatorBuilder: (_, _) => SizedBox(width: context.s(8)),
        itemBuilder: (context, i) {
          return Stack(
            clipBehavior: Clip.none,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(context.s(8)),
                child: Image.file(
                  File(paths[i]),
                  width: side,
                  height: side,
                  fit: BoxFit.cover,
                ),
              ),
              Positioned(
                top: -context.s(6),
                right: -context.s(6),
                child: GestureDetector(
                  onTap: () => onRemove(i),
                  behavior: HitTestBehavior.opaque,
                  child: Container(
                    width: context.s(22),
                    height: context.s(22),
                    decoration: const BoxDecoration(
                      shape: BoxShape.circle,
                      color: AppColors.danger,
                    ),
                    alignment: Alignment.center,
                    child: Icon(Icons.close, size: context.s(14), color: Colors.white),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

// ── Flip (front/back) button ────────────────────────────────────────────────

class _FlipButton extends StatelessWidget {
  const _FlipButton({required this.onTap});
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final enabled = onTap != null;
    return GestureDetector(
      onTap: onTap,
      child: Opacity(
        opacity: enabled ? 1.0 : 0.4,
        child: Container(
          width: context.s(46),
          height: context.s(46),
          decoration: const BoxDecoration(
            shape: BoxShape.circle,
            color: Color(0x4DFFFFFF),
          ),
          alignment: Alignment.center,
          child: Icon(
            Icons.flip_camera_ios_outlined,
            color: Colors.white,
            size: context.s(24),
          ),
        ),
      ),
    );
  }
}

// ── Shutter button ────────────────────────────────────────────────────────────

class _ShutterButton extends StatefulWidget {
  const _ShutterButton({required this.onTap, required this.enabled});
  final VoidCallback? onTap;
  final bool enabled;

  @override
  State<_ShutterButton> createState() => _ShutterButtonState();
}

class _ShutterButtonState extends State<_ShutterButton> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTapDown: widget.enabled ? (_) => setState(() => _pressed = true) : null,
      onTapUp: widget.enabled ? (_) => setState(() => _pressed = false) : null,
      onTapCancel: widget.enabled ? () => setState(() => _pressed = false) : null,
      onTap: widget.onTap,
      child: AnimatedScale(
        scale: _pressed ? 0.88 : 1.0,
        duration: const Duration(milliseconds: 80),
        child: Opacity(
          opacity: widget.enabled ? 1.0 : 0.25,
          child: Container(
            width: context.s(72),
            height: context.s(72),
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: const Color(0xEBFFFFFF),
              boxShadow: _pressed
                  ? null
                  : const [
                      BoxShadow(color: Color(0x2DFFFFFF), spreadRadius: 4),
                      BoxShadow(color: Color(0x0FFFFFFF), spreadRadius: 6),
                      BoxShadow(color: Color(0x66000000), blurRadius: 20, offset: Offset(0, 4)),
                    ],
            ),
            alignment: Alignment.center,
            child: Container(
              width: context.s(58),
              height: context.s(58),
              decoration: const BoxDecoration(shape: BoxShape.circle, color: Colors.white),
            ),
          ),
        ),
      ),
    );
  }
}

// ── Upload status ─────────────────────────────────────────────────────────────

class _UploadStatus extends StatelessWidget {
  const _UploadStatus({required this.status});
  final PhotoStatus status;

  @override
  Widget build(BuildContext context) {
    final cfg = _config();
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.base,
        vertical: AppSpacing.md,
      ),
      decoration: BoxDecoration(
        color: cfg.bg,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: cfg.border),
      ),
      child: Row(
        children: [
          Text(cfg.icon, style: TextStyle(fontSize: context.sp(16))),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Text(
              status == PhotoStatus.expired
                  ? '⏱ Verification window expired — closing…'
                  : cfg.text,
              style: AppType.caption.copyWith(fontSize: context.sp(13), color: cfg.color),
            ),
          ),
        ],
      ),
    );
  }

  _StatusConfig _config() => switch (status) {
    PhotoStatus.uploading => _StatusConfig(
      bg: const Color(0x12D4AF37), border: const Color(0x2ED4AF37),
      color: AppColors.gold, icon: '⏳', text: 'Submitting photo…',
    ),
    PhotoStatus.validated => _StatusConfig(
      bg: const Color(0x1222C55E), border: const Color(0x2E22C55E),
      color: AppColors.success, icon: '✓', text: '✓ Photo verified and securely stored',
    ),
    PhotoStatus.flagged => _StatusConfig(
      bg: const Color(0x12F59E0B), border: const Color(0x2EF59E0B),
      color: AppColors.warning, icon: '⚠', text: 'Photo flagged for supervisor review — no action needed',
    ),
    _ => _StatusConfig(
      bg: const Color(0x12DC2626), border: const Color(0x2EDC2626),
      color: AppColors.danger, icon: '⊘', text: 'Upload failed — try again',
    ),
  };
}

class _StatusConfig {
  const _StatusConfig({
    required this.bg, required this.border,
    required this.color, required this.icon, required this.text,
  });
  final Color bg, border, color;
  final String icon, text;
}

class AppRadius {
  static const camera = 12.0;
}

// Minimal 1×1 white JPEG for simulator fallback uploads.
const _kMinimalJpeg = <int>[
  0xFF,0xD8,0xFF,0xE0,0x00,0x10,0x4A,0x46,0x49,0x46,0x00,0x01,0x01,0x00,
  0x00,0x01,0x00,0x01,0x00,0x00,0xFF,0xDB,0x00,0x43,0x00,0x08,0x06,0x06,
  0x07,0x06,0x05,0x08,0x07,0x07,0x07,0x09,0x09,0x08,0x0A,0x0C,0x14,0x0D,
  0x0C,0x0B,0x0B,0x0C,0x19,0x12,0x13,0x0F,0x14,0x1D,0x1A,0x1F,0x1E,0x1D,
  0x1A,0x1C,0x1C,0x20,0x24,0x2E,0x27,0x20,0x22,0x2C,0x23,0x1C,0x1C,0x28,
  0x37,0x29,0x2C,0x30,0x31,0x34,0x34,0x34,0x1F,0x27,0x39,0x3D,0x38,0x32,
  0x3C,0x2E,0x33,0x34,0x32,0xFF,0xC0,0x00,0x0B,0x08,0x00,0x01,0x00,0x01,
  0x01,0x01,0x11,0x00,0xFF,0xC4,0x00,0x1F,0x00,0x00,0x01,0x05,0x01,0x01,
  0x01,0x01,0x01,0x01,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01,0x02,
  0x03,0x04,0x05,0x06,0x07,0x08,0x09,0x0A,0x0B,0xFF,0xC4,0x00,0xB5,0x10,
  0x00,0x02,0x01,0x03,0x03,0x02,0x04,0x03,0x05,0x05,0x04,0x04,0x00,0x00,
  0x01,0x7D,0x01,0x02,0x03,0x00,0x04,0x11,0x05,0x12,0x21,0x31,0x41,0x06,
  0x13,0x51,0x61,0x07,0x22,0x71,0x14,0x32,0x81,0x91,0xA1,0x08,0x23,0x42,
  0xB1,0xC1,0x15,0x52,0xD1,0xF0,0x24,0x33,0x62,0x72,0x82,0x09,0x0A,0x16,
  0x17,0x18,0x19,0x1A,0x25,0x26,0x27,0x28,0x29,0x2A,0x34,0x35,0x36,0x37,
  0x38,0x39,0x3A,0x43,0x44,0x45,0x46,0x47,0x48,0x49,0x4A,0x53,0x54,0x55,
  0x56,0x57,0x58,0x59,0x5A,0x63,0x64,0x65,0x66,0x67,0x68,0x69,0x6A,0x73,
  0x74,0x75,0x76,0x77,0x78,0x79,0x7A,0x83,0x84,0x85,0x86,0x87,0x88,0x89,
  0x8A,0x92,0x93,0x94,0x95,0x96,0x97,0x98,0x99,0x9A,0xA2,0xA3,0xA4,0xA5,
  0xA6,0xA7,0xA8,0xA9,0xAA,0xB2,0xB3,0xB4,0xB5,0xB6,0xB7,0xB8,0xB9,0xBA,
  0xC2,0xC3,0xC4,0xC5,0xC6,0xC7,0xC8,0xC9,0xCA,0xD2,0xD3,0xD4,0xD5,0xD6,
  0xD7,0xD8,0xD9,0xDA,0xE1,0xE2,0xE3,0xE4,0xE5,0xE6,0xE7,0xE8,0xE9,0xEA,
  0xF1,0xF2,0xF3,0xF4,0xF5,0xF6,0xF7,0xF8,0xF9,0xFA,0xFF,0xDA,0x00,0x08,
  0x01,0x01,0x00,0x00,0x3F,0x00,0xFB,0xD3,0xFF,0xD9,
];
