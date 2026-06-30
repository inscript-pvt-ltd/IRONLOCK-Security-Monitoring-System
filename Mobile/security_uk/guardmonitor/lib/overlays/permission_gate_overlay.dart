import 'package:flutter/material.dart';
import 'package:permission_handler/permission_handler.dart';
import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';
import '../theme/responsive.dart';
import '../widgets/app_button.dart';

/// Full-screen, non-dismissible gate shown on first launch (and every launch
/// until both location + camera are granted). The guard cannot access the app
/// without these permissions — location is required for patrol zone tracking
/// and camera for photo-evidence checks.
class PermissionGateOverlay extends StatefulWidget {
  const PermissionGateOverlay({super.key, required this.onGranted});

  /// Called after this overlay pops itself, once both permissions are granted.
  final VoidCallback onGranted;

  @override
  State<PermissionGateOverlay> createState() => _PermissionGateOverlayState();
}

class _PermissionGateOverlayState extends State<PermissionGateOverlay>
    with WidgetsBindingObserver {
  bool _requesting = false;
  bool _finished = false;
  PermissionStatus? _locationStatus;
  PermissionStatus? _cameraStatus;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _checkStatuses();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  // Re-check after returning from the Settings app.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _checkStatuses();
  }

  Future<void> _checkStatuses() async {
    final loc = await Permission.locationWhenInUse.status;
    final cam = await Permission.camera.status;
    if (!mounted) return;
    setState(() {
      _locationStatus = loc;
      _cameraStatus = cam;
    });
    if (_allGranted) _finish();
  }

  bool get _allGranted =>
      (_locationStatus?.isGranted ?? false) && (_cameraStatus?.isGranted ?? false);

  bool get _anyPermanentlyDenied =>
      (_locationStatus?.isPermanentlyDenied ?? false) ||
      (_cameraStatus?.isPermanentlyDenied ?? false);

  Future<void> _requestAll() async {
    if (_requesting) return;
    setState(() => _requesting = true);

    final loc = await Permission.locationWhenInUse.request();
    // Escalate to background ("Always") here so the guard is never prompted
    // again at shift start — all permission dialogs happen up front at the gate.
    // Best-effort: foreground ("When In Use") is enough to pass the gate, and
    // iOS may show its own separate "Always" dialog or defer it (the OS controls
    // that). A declined background upgrade does not block entry — foreground
    // tracking still works while the app is open.
    if (loc.isGranted) {
      await Permission.locationAlways.request();
    }
    final cam = await Permission.camera.request();

    if (!mounted) return;
    setState(() {
      _requesting = false;
      _locationStatus = loc;
      _cameraStatus = cam;
    });

    if (_allGranted) _finish();
  }

  void _finish() {
    // Guard against a double-pop. Both _requestAll() and the lifecycle-resume
    // _checkStatuses() can observe _allGranted in the same resume storm after
    // the user grants the system dialogs. Without this flag the second _finish()
    // pops the route *below* the gate (HomeScreen / privacy notice), leaving the
    // app on a blank, stuck screen until it's force-quit and reopened.
    if (_finished || !mounted) return;
    _finished = true;
    // Defer the pop to the next frame. _finish() can be reached from inside a
    // lifecycle callback (didChangeAppLifecycleState → _checkStatuses) during the
    // permission-dialog resume storm; popping a route from within that callback,
    // while the OS is still settling the app back to the foreground, is what left
    // the app on a frozen/blank screen. Running it post-frame pops cleanly, and
    // canPop() ensures we only remove the gate (never the screen beneath it).
    // Capture the navigator now while the context is valid — the gate may be
    // disposed by the time the post-frame callback runs.
    final navigator = Navigator.of(context, rootNavigator: true);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (navigator.canPop()) navigator.pop();
      widget.onGranted();
    });
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      child: Scaffold(
        backgroundColor: const Color(0xFF04090F),
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.xl,
              vertical: AppSpacing.xxl,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(children: [
                  Image.asset(
                    'assets/images/logo-app.png',
                    height: context.s(30),
                    fit: BoxFit.contain,
                    // Fall back to the old shield if the asset ever fails to load.
                    errorBuilder: (_, _, _) => const Icon(
                        Icons.shield_outlined, color: AppColors.gold, size: 26),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Text('IronLock',
                      style: AppType.h2.copyWith(color: AppColors.gold)),
                ]),
                const SizedBox(height: AppSpacing.xl),
                Text('Permissions required', style: AppType.h3),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  'IronLock needs access to your location and camera to '
                  'monitor your patrol shift. These are required for '
                  'compliance and cannot be skipped.',
                  style: AppType.body.copyWith(
                    color: AppColors.muted,
                    fontSize: 14,
                  ),
                ),
                const SizedBox(height: AppSpacing.xxl),

                _PermissionRow(
                  icon: Icons.location_on_rounded,
                  title: 'Location',
                  description: 'GPS patrol zone tracking',
                  status: _locationStatus,
                ),
                const SizedBox(height: AppSpacing.base),
                _PermissionRow(
                  icon: Icons.camera_alt_rounded,
                  title: 'Camera',
                  description: 'Photo checks during your shift',
                  status: _cameraStatus,
                ),

                const Spacer(),

                if (_anyPermanentlyDenied) ...[
                  Container(
                    padding: const EdgeInsets.all(AppSpacing.base),
                    decoration: BoxDecoration(
                      color: AppColors.warningBg,
                      borderRadius: BorderRadius.circular(AppRadius.alert),
                      border: Border.all(
                        color: AppColors.warning.withValues(alpha: 0.4),
                      ),
                    ),
                    child: Text(
                      'A permission was permanently denied. Open Settings '
                      'and enable Location and Camera for IronLock.',
                      style: AppType.body.copyWith(
                          color: AppColors.warning, fontSize: 13),
                    ),
                  ),
                  const SizedBox(height: AppSpacing.base),
                  AppButton(
                    label: 'Open Settings',
                    onPressed: openAppSettings,
                  ),
                ] else
                  AppButton(
                    label: _requesting ? 'Requesting…' : 'Grant Permissions',
                    onPressed: _requesting ? null : _requestAll,
                  ),
                const SizedBox(height: AppSpacing.base),
                Center(
                  child: Text(
                    'You cannot use IronLock without these permissions.',
                    style: AppType.caption.copyWith(color: AppColors.faint),
                    textAlign: TextAlign.center,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _PermissionRow extends StatelessWidget {
  const _PermissionRow({
    required this.icon,
    required this.title,
    required this.description,
    required this.status,
  });

  final IconData icon;
  final String title;
  final String description;
  final PermissionStatus? status;

  @override
  Widget build(BuildContext context) {
    final granted = status?.isGranted ?? false;
    final denied = status != null && !granted;

    return Container(
      padding: const EdgeInsets.all(AppSpacing.base),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(
          color: granted
              ? AppColors.success.withValues(alpha: 0.35)
              : denied
                  ? AppColors.danger.withValues(alpha: 0.35)
                  : AppColors.border,
        ),
      ),
      child: Row(
        children: [
          Icon(
            icon,
            color: granted
                ? AppColors.success
                : denied
                    ? AppColors.danger
                    : AppColors.faint,
            size: 22,
          ),
          const SizedBox(width: AppSpacing.base),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: AppType.label),
                Text(description, style: AppType.caption),
              ],
            ),
          ),
          if (status == null)
            const SizedBox(
              width: 18,
              height: 18,
              child: CircularProgressIndicator(
                strokeWidth: 1.5,
                color: AppColors.muted,
              ),
            )
          else if (granted)
            const Icon(Icons.check_circle_rounded,
                color: AppColors.success, size: 20)
          else
            const Icon(Icons.cancel_rounded, color: AppColors.danger, size: 20),
        ],
      ),
    );
  }
}
