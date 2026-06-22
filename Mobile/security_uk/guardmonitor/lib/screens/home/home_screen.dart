import 'dart:async';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
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
  Timer? _pollingTimer;
  StreamSubscription<String>? _zoneSub;

  @override
  void initState() {
    super.initState();
    _pollingTimer = Timer.periodic(const Duration(seconds: 20), (_) {
      _pollBackend();
    });
    // Update zone state whenever the GPS service gets a server response.
    _zoneSub = ref.read(gpsServiceProvider).zoneStream.listen((zone) {
      if (!mounted) return;
      final zoneIndex = zone == 'INSIDE_ZONE' ? 0 : zone == 'OUTSIDE_ZONE' ? 1 : 2;
      ref.read(zoneProvider.notifier).set(zoneIndex);
      ref.read(zoneUpdatedAtProvider.notifier).markNow();
    });
  }

  @override
  void dispose() {
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
    });

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
    final zoneUpdatedAt = ref.watch(zoneUpdatedAtProvider);
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
                if (shift.active && currentShift != null)
                  _OverdueBanner(scheduledEnd: currentShift.scheduledEnd),
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
    return Row(
      mainAxisAlignment: MainAxisAlignment.end,
      children: [
        _StatusTile(
          icon: zone == 2 ? Icons.location_off_rounded : Icons.location_on_rounded,
          color: zone == 2 ? AppColors.danger : AppColors.success,
          semanticLabel: zone == 2 ? 'GPS signal lost' : 'GPS active',
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
          icon: online ? Icons.check_circle_outline_rounded : Icons.error_outline_rounded,
          color: online ? AppColors.success : AppColors.danger,
          semanticLabel: online ? 'All systems normal' : 'Connection issue',
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
      final status = await Permission.locationWhenInUse.request();
      if (!mounted) return;
      if (status.isPermanentlyDenied) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Location permission denied — enable it in Settings for zone tracking'),
            duration: Duration(seconds: 4),
          ),
        );
      }
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

    // Before the scheduled end, ending counts as "early" and the sheet will
    // require a reason — surface that here so it isn't a surprise, mirroring
    // the START hint.
    final cs = widget.currentShift;
    final isEarly = cs != null && DateTime.now().isBefore(cs.scheduledEnd);
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          _CircleEndButton(
            onTap: () => showEndShiftSheet(context),
          ),
          if (isEarly) ...[
            SizedBox(height: context.s(12)),
            Text(
              'Shift ends at ${_fmtHHmm(cs.scheduledEnd)} · ending now needs a reason',
              style: AppType.caption.copyWith(
                fontSize: context.sp(12),
                color: AppColors.muted,
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
      onTapDown: (_) {
        HapticFeedback.mediumImpact();
        setState(() => _pressed = true);
      },
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
    await ref.read(shiftProvider.notifier).end();
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
