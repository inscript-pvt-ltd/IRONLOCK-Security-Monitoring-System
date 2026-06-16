import 'dart:async';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:permission_handler/permission_handler.dart';
import '../../models/api_response.dart';
import '../../models/current_shift_model.dart';
import '../../providers/app_providers.dart';
import '../../providers/wakefulness_provider.dart';
import '../../services/api_client.dart';
import '../../services/connectivity_service.dart';
import '../../services/gps_service.dart';
import '../../theme/app_colors.dart';
import '../../theme/app_gradients.dart';
import '../../theme/app_shadows.dart';
import '../../theme/app_typography.dart';
import '../../theme/responsive.dart';
import '../../overlays/end_shift_sheet.dart';
import '../../overlays/wakefulness_overlay.dart' hide AppGradients;
import '../../widgets/app_card.dart';
import '../../widgets/app_chip.dart';
import '../photo/photo_screen.dart';

class HomeScreen extends ConsumerStatefulWidget {
  const HomeScreen({super.key});

  @override
  ConsumerState<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends ConsumerState<HomeScreen> {
  Timer? _batteryTimer;
  Timer? _pollingTimer;
  StreamSubscription<String>? _zoneSub;

  @override
  void initState() {
    super.initState();
    _batteryTimer = Timer.periodic(const Duration(seconds: 4), (_) {
      ref.read(batteryProvider.notifier).tick();
    });
    _pollingTimer = Timer.periodic(const Duration(seconds: 20), (_) {
      _pollBackend();
    });
    // Update zone state whenever the GPS service gets a server response.
    _zoneSub = ref.read(gpsServiceProvider).zoneStream.listen((zone) {
      if (!mounted) return;
      final zoneIndex = zone == 'INSIDE_ZONE' ? 0 : zone == 'OUTSIDE_ZONE' ? 1 : 2;
      ref.read(zoneProvider.notifier).set(zoneIndex);
    });
  }

  @override
  void dispose() {
    _batteryTimer?.cancel();
    _pollingTimer?.cancel();
    _zoneSub?.cancel();
    super.dispose();
  }

  Future<void> _pollBackend() async {
    // Always refresh the current shift so `can_start`/`can_end` stay live —
    // this is what flips the START button on once the server's 15-minute
    // pre-shift window opens, with no user action needed.
    await ref.read(currentShiftProvider.notifier).fetch();

    if (!ref.read(shiftProvider).active) return;
    try {
      final dio = ref.read(dioProvider);

      final welfareRes = await dio.get<Map<String, dynamic>>('/welfare/pending');
      final welfareData = welfareRes.data?['data'] as Map<String, dynamic>?;
      if (welfareData?['pending'] == true) {
        final status = ref.read(wakefulnessProvider).status;
        if (status == WakefulnessStatus.idle && mounted) {
          ref.read(wakefulnessProvider.notifier).trigger(
            welfareData!['check_id'] as String,
            welfareData['code'] as String,
          );
        }
      }

      final photoRes = await dio.get<Map<String, dynamic>>('/photos/pending');
      final photoData = photoRes.data?['data'] as Map<String, dynamic>?;
      if (photoData?['pending'] == true && mounted) {
        ref.read(pendingPhotoProvider.notifier).setPending(
          true,
          requestId: photoData!['request_id'] as String,
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
    // Show wakefulness overlay when backend triggers a welfare check.
    ref.listen<WakefulnessState>(wakefulnessProvider, (prev, next) {
      if (prev?.status != WakefulnessStatus.challenge &&
          next.status == WakefulnessStatus.challenge) {
        showDialog(
          context: context,
          barrierDismissible: false,
          builder: (_) => const WakefulnessOverlay(),
        );
      }
    });

    // Navigate to photo screen when backend requests photo verification.
    ref.listen<PendingPhotoState>(pendingPhotoProvider, (_, next) {
      if (next.pending && next.requestId != null) {
        final requestId = next.requestId!;
        ref.read(pendingPhotoProvider.notifier).setPending(false);
        Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => PhotoScreen(requestId: requestId)),
        );
      }
    });

    final profile = ref.watch(guardProfileProvider);
    final shift = ref.watch(shiftProvider);
    final currentShift = ref.watch(currentShiftProvider);
    final battery = ref.watch(batteryProvider);
    final zone = ref.watch(zoneProvider);
    final online = ref.watch(isOnlineProvider);

    // All horizontal padding flows from one responsive value for alignment.
    final hPad = context.s(16);

    // Shared scrollable content (everything above the action button).
    final content = <Widget>[
      // Status icon strip
      _StatusIconStrip(battery: battery, zone: zone, online: online),
      SizedBox(height: context.s(16)),

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
        _ZoneCard(zone: zone),
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
                Expanded(
                  child: shift.active
                // ACTIVE: everything scrolls together so nothing clips on any
                // phone; the End button flows comfortably below the content.
                ? SingleChildScrollView(
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
                  )
                // INACTIVE: content scrolls at top; Start button sits below
                // in a fixed-height slot; Sign Out pinned to the bottom.
                : Column(
                    children: [
                      Expanded(
                        flex: 3,
                        child: SingleChildScrollView(
                          padding: EdgeInsets.fromLTRB(
                            hPad, context.s(8), hPad, context.s(8),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: content,
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

  final double battery;
  final int zone;
  final bool online;

  Color get _batteryColor {
    if (battery > 30) return AppColors.success;
    if (battery > 15) return AppColors.warning;
    return AppColors.danger;
  }

  @override
  Widget build(BuildContext context) {
    final gap = context.s(8);
    return Row(
      mainAxisAlignment: MainAxisAlignment.end,
      children: [
        _StatusTile(
          icon: Icons.wifi,
          color: zone == 2 ? AppColors.danger : AppColors.success,
        ),
        SizedBox(width: gap),
        _StatusTile(
          icon: Icons.sync,
          color: online ? AppColors.success : AppColors.warning,
        ),
        SizedBox(width: gap),
        _StatusTile(
          icon: Icons.battery_std_outlined,
          color: _batteryColor,
        ),
        SizedBox(width: gap),
        _StatusTile(
          icon: Icons.check_circle_outline,
          color: online ? AppColors.success : AppColors.danger,
        ),
      ],
    );
  }
}

class _StatusTile extends StatelessWidget {
  const _StatusTile({required this.icon, required this.color});
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final size = context.s(42);
    return Container(
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
    );
  }
}


// ── Zone card (when shift active) ─────────────────────────────────────────

class _ZoneCard extends StatefulWidget {
  const _ZoneCard({required this.zone});
  final int zone;

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
      pulseAnimation: _pulseCtrl,
    );
  }
}

class _ZoneCardContent extends StatelessWidget {
  const _ZoneCardContent({
    required this.zone,
    required this.pulseAnimation,
  });
  final int zone;
  final AnimationController pulseAnimation;

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
              child: Text(
                zone == 0
                    ? '✓'
                    : zone == 1
                        ? '⚠'
                        : '⊘',
                style: TextStyle(color: _zoneColor, fontSize: context.sp(18)),
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
                    'Westfield Shopping Centre A · on site',
                    style: AppType.caption.copyWith(
                      fontSize: context.sp(12),
                      color: AppColors.muted,
                    ),
                  ),
                  SizedBox(height: context.s(4)),
                  Text(
                    'Last updated: 15s ago · updating automatically',
                    style: AppType.caption.copyWith(
                      fontSize: context.sp(11),
                      color: AppColors.muted,
                    ),
                  ),
                  if (zone == 1) ...[
                    SizedBox(height: context.s(8)),
                    ClipRRect(
                      borderRadius: BorderRadius.circular(2),
                      child: const LinearProgressIndicator(
                        value: 0.75,
                        backgroundColor: AppColors.border,
                        valueColor: AlwaysStoppedAnimation<Color>(
                            Color(0xFFF59E0B)),
                        minHeight: 3,
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
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
                  ),
                );
              },
            )
          else
            Wrap(
              spacing: context.s(8),
              runSpacing: context.s(8),
              children: const [
                AppChip(label: '✓ GPS Active', variant: AppChipVariant.success),
                AppChip(label: '● Online', variant: AppChipVariant.info),
                AppChip(label: 'All synced', variant: AppChipVariant.info),
              ],
            ),
        ],
      ),
    );
  }
}

// ── Action buttons ────────────────────────────────────────────────────────

class _ActionButtons extends ConsumerWidget {
  const _ActionButtons({required this.shift, required this.currentShift});
  final ShiftState shift;
  final CurrentShiftModel? currentShift;

  String _fmtHHmm(DateTime dt) =>
      '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';

  /// The server is the source of truth for `can_start` — this hint just
  /// explains *why* the button is disabled (15 minutes before the
  /// scheduled start, per the shift-gating contract).
  String? get _startHint {
    final cs = currentShift;
    if (cs == null) return 'No shift scheduled for today.';
    if (cs.canStart) return null;
    if (cs.status != 'scheduled') return null;
    final opensAt = cs.scheduledStart.subtract(const Duration(minutes: 15));
    return 'You can begin your shift from ${_fmtHHmm(opensAt)}.';
  }

  Future<void> _startWithPermissions(BuildContext context, WidgetRef ref) async {
    final status = await Permission.locationWhenInUse.request();
    if (!context.mounted) return;
    if (status.isPermanentlyDenied) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Location permission denied — enable it in Settings for zone tracking'),
          duration: Duration(seconds: 4),
        ),
      );
    }
    try {
      await ref.read(shiftProvider.notifier).start();
    } on DioException catch (e) {
      if (!context.mounted) return;
      final apiError = ApiError.fromDioException(e);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(apiError.message), duration: const Duration(seconds: 4)),
      );
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!shift.active) {
      final canStart = currentShift?.canStart ?? false;
      final hint = _startHint;
      return Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          _CircleStartButton(
            onTap: canStart ? () => _startWithPermissions(context, ref) : null,
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

    return Center(
      child: _CircleEndButton(
        onTap: () => showEndShiftSheet(context),
      ),
    );
  }
}

// ── Circular start button ─────────────────────────────────────────────────

class _CircleStartButton extends StatefulWidget {
  const _CircleStartButton({required this.onTap});
  final VoidCallback? onTap;

  @override
  State<_CircleStartButton> createState() => _CircleStartButtonState();
}

class _CircleStartButtonState extends State<_CircleStartButton> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final base = context.s(190);
    final size = base.clamp(150.0, context.screenH * 0.26);

    final enabled = widget.onTap != null;
    return GestureDetector(
      onTapDown: enabled ? (_) => setState(() => _pressed = true) : null,
      onTapUp: enabled ? (_) => setState(() => _pressed = false) : null,
      onTapCancel: enabled ? () => setState(() => _pressed = false) : null,
      onTap: widget.onTap,
      child: AnimatedScale(
        scale: _pressed ? 0.96 : 1.0,
        duration: const Duration(milliseconds: 100),
        child: Opacity(
          opacity: enabled ? 1.0 : 0.4,
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
            child: Text(
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
  const _CircleEndButton({required this.onTap});
  final VoidCallback onTap;

  @override
  State<_CircleEndButton> createState() => _CircleEndButtonState();
}

class _CircleEndButtonState extends State<_CircleEndButton> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final base = context.s(190);
    final size = base.clamp(150.0, context.screenH * 0.26);

    return GestureDetector(
      onTapDown: (_) => setState(() => _pressed = true),
      onTapUp: (_) => setState(() => _pressed = false),
      onTapCancel: () => setState(() => _pressed = false),
      onTap: widget.onTap,
      child: AnimatedScale(
        scale: _pressed ? 0.96 : 1.0,
        duration: const Duration(milliseconds: 100),
        child: Container(
          width: size,
          height: size,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: const Color(0x0DD4AF37),
            border: Border.all(color: const Color(0x80D4AF37), width: 2),
          ),
          alignment: Alignment.center,
          child: Text(
            'END',
            style: AppType.bodySemi.copyWith(
              fontSize: context.sp(26),
              fontWeight: FontWeight.w800,
              color: AppColors.gold,
              letterSpacing: 0.32,
            ),
          ),
        ),
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

// ── Sign Out button ───────────────────────────────────────────────────────

class _SignOutButton extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return GestureDetector(
      onTap: () async {
        await ref.read(shiftProvider.notifier).end();
        await ref.read(authProvider.notifier).signOut();
      },
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
    );
  }
}
