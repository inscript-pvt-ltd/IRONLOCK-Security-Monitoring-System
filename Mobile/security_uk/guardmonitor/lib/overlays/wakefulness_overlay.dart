import 'dart:async';
import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/wakefulness_provider.dart';
import '../providers/alerts_provider.dart';
import '../services/connectivity_service.dart';
import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';
import '../theme/responsive.dart';
import '../widgets/app_chip.dart';

class WakefulnessOverlay extends ConsumerStatefulWidget {
  const WakefulnessOverlay({super.key});

  @override
  ConsumerState<WakefulnessOverlay> createState() => _WakefulnessOverlayState();
}

class _WakefulnessOverlayState extends ConsumerState<WakefulnessOverlay>
    with TickerProviderStateMixin, WidgetsBindingObserver {
  Timer? _countdown;
  bool _resolved = false; // guards against handling success/failed twice
  late final AnimationController _shakeCtrl;
  late final Animation<double> _shake;
  late final AnimationController _fadeCtrl;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _shakeCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 400),
    );
    _shake = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(parent: _shakeCtrl, curve: Curves.elasticIn),
    );
    _fadeCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 250),
    )..forward();

    _startCountdown();
  }

  /// The 1s timer freezes while the app is backgrounded; on resume, recompute
  /// the remaining time from the wall-clock deadline so a guard can't pause the
  /// countdown by locking the screen mid-challenge.
  @override
  void didChangeAppLifecycleState(AppLifecycleState lifecycle) {
    if (lifecycle == AppLifecycleState.resumed) {
      if (ref.read(wakefulnessProvider).status == WakefulnessStatus.challenge) {
        ref.read(wakefulnessProvider.notifier).tick();
      }
    }
  }

  void _startCountdown() {
    _countdown?.cancel();
    _countdown = Timer.periodic(const Duration(seconds: 1), (_) {
      final s = ref.read(wakefulnessProvider);
      // Stop ticking once the challenge is being verified or has resolved; the
      // outcome side-effects are driven by the status listener in build().
      if (s.status != WakefulnessStatus.challenge) {
        _countdown?.cancel();
        return;
      }
      ref.read(wakefulnessProvider.notifier).tick();
    });
  }

  /// Side-effects for a resolved challenge, driven from the status listener so a
  /// success/failure decided asynchronously by the server verdict is handled the
  /// same as a synchronous one.
  void _onResolved(WakefulnessStatus status) {
    if (_resolved) return;
    _resolved = true;
    _countdown?.cancel();
    if (status == WakefulnessStatus.success) {
      Future.delayed(const Duration(milliseconds: 900), _close);
    } else {
      _onFailed();
    }
  }

  void _onKeyTap(String key) {
    final s = ref.read(wakefulnessProvider);
    if (s.status != WakefulnessStatus.challenge) return;

    if (key == 'DEL') {
      ref.read(wakefulnessProvider.notifier).deleteDigit();
    } else if (key == 'OK') {
      if (s.entry.length == 4) _submit();
    } else {
      ref.read(wakefulnessProvider.notifier).addDigit(key);
    }
  }

  void _submit() {
    _countdown?.cancel();
    // Fire-and-forget: a correct code goes to `verifying` and resolves on the
    // server verdict; the status listener in build() handles the outcome.
    ref.read(wakefulnessProvider.notifier).submit();
  }

  void _onFailed() {
    _shakeCtrl.forward(from: 0);
    ref.read(alertsProvider.notifier).prepend(AppAlert(
      id: 'w${DateTime.now().millisecondsSinceEpoch}',
      severity: AlertSeverity.urgent,
      title: 'Welfare check not completed',
      description:
          'A check-in code was not entered in time — your supervisor has been notified',
      time: 'just now',
    ));
    Future.delayed(const Duration(milliseconds: 1600), _close);
  }

  void _close() {
    if (!mounted) return;
    _fadeCtrl.reverse().then((_) {
      if (mounted) Navigator.of(context).pop();
      ref.read(wakefulnessProvider.notifier).reset();
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _countdown?.cancel();
    _shakeCtrl.dispose();
    _fadeCtrl.dispose();
    // Guarantee return to idle even when the overlay is torn down without going
    // through _close() (navigation, error, app lifecycle). Calling reset() twice
    // (here and in _close()) is safe — it's idempotent.
    ref.read(wakefulnessProvider.notifier).reset();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(wakefulnessProvider);
    final online = ref.watch(isOnlineProvider);

    // The challenge can resolve to success/failed either synchronously (wrong
    // code / timeout) or asynchronously (server verdict on a correct code), so
    // drive the close/alert side-effects off the status transition itself.
    ref.listen<WakefulnessStatus>(
      wakefulnessProvider.select((s) => s.status),
      (prev, next) {
        if (next == WakefulnessStatus.success ||
            next == WakefulnessStatus.failed) {
          _onResolved(next);
        }
      },
    );

    return FadeTransition(
      opacity: _fadeCtrl,
      child: Material(
        color: const Color(0xF007111F),
        child: SafeArea(
          child: Column(
            children: [
              // Banner
              _Banner(online: online),

              Expanded(
                child: Column(
                  children: [
                    // Code display
                    Padding(
                      padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, 0),
                      child: _CodeDisplay(code: state.code),
                    ),
                    const SizedBox(height: 6),

                    // Hint
                    Text(
                      'Enter code within ${state.responseSeconds} seconds',
                      style: AppType.caption.copyWith(fontSize: 11),
                    ),
                    const SizedBox(height: 8),

                    // Countdown ring
                    _CountdownRing(
                      seconds: state.secondsRemaining,
                      total: state.responseSeconds,
                      status: state.status,
                    ),
                    const SizedBox(height: 8),

                    // Pin dots
                    AnimatedBuilder(
                      animation: _shake,
                      builder: (context, child) {
                        final offset = _shakeCtrl.isAnimating
                            ? math.sin(_shake.value * math.pi * 6) * 8
                            : 0.0;
                        return Transform.translate(
                          offset: Offset(offset, 0),
                          child: child,
                        );
                      },
                      child: _PinDots(filled: state.entry.length),
                    ),
                    const SizedBox(height: 10),

                    // Keypad
                    Expanded(
                      child: AnimatedBuilder(
                        animation: _shake,
                        builder: (context, child) {
                          final offset = _shakeCtrl.isAnimating
                              ? math.sin(_shake.value * math.pi * 6) * 8
                              : 0.0;
                          return Transform.translate(
                            offset: Offset(offset, 0),
                            child: child,
                          );
                        },
                        child: _Keypad(
                          onKey: _onKeyTap,
                          entryLength: state.entry.length,
                          enabled: state.status == WakefulnessStatus.challenge,
                        ),
                      ),
                    ),

                    // Footer message
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md, vertical: AppSpacing.sm),
                      child: _FooterMessage(status: state.status),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Banner ────────────────────────────────────────────────────────────────

class _Banner extends StatelessWidget {
  const _Banner({required this.online});
  final bool online;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.base,
        vertical: AppSpacing.md,
      ),
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [Color(0x2EDC2626), Color(0x14DC2626)],
          begin: Alignment.centerLeft,
          end: Alignment.centerRight,
        ),
        border: Border(bottom: BorderSide(color: Color(0x4DDC2626))),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('⚠ WELFARE CHECK',
                    style: AppType.bodySemi.copyWith(
                      color: AppColors.danger,
                      fontSize: context.sp(16),
                    )),
                const SizedBox(height: 2),
                Text(
                  'Stay alert — enter the code to confirm you are present',
                  style: AppType.caption,
                ),
              ],
            ),
          ),
          AppChip(
            label: online ? '● ONLINE' : '● OFFLINE',
            variant: AppChipVariant.info,
          ),
        ],
      ),
    );
  }
}

// ── Code display ──────────────────────────────────────────────────────────

class _CodeDisplay extends StatelessWidget {
  const _CodeDisplay({required this.code});
  final String code;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: context.s(24),
        vertical: context.s(20),
      ),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Color(0xFF0E1C34), Color(0xFF0A1931)],
        ),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: const Color(0x47D4AF37)),
        boxShadow: const [
          BoxShadow(color: Color(0x2ED4AF37), blurRadius: 40),
          BoxShadow(color: Color(0x1AD4AF37), blurRadius: 1, offset: Offset(0, -1)),
        ],
      ),
      child: Text(
        code.split('').join('   '),
        style: TextStyle(
          fontSize: context.sp(40),
          fontWeight: FontWeight.w800,
          color: const Color(0xFFD4AF37),
          letterSpacing: 8,
        ),
        textAlign: TextAlign.center,
        maxLines: 1,
        overflow: TextOverflow.visible,
      ),
    );
  }
}

// ── Countdown ring ────────────────────────────────────────────────────────

class _CountdownRing extends StatelessWidget {
  const _CountdownRing({
    required this.seconds,
    required this.total,
    required this.status,
  });
  final int seconds;
  final int total;
  final WakefulnessStatus status;

  @override
  Widget build(BuildContext context) {
    const circumference = 2 * math.pi * 46;
    final progress = total > 0 ? (seconds / total).clamp(0.0, 1.0) : 0.0;
    final dashOffset = circumference * (1 - progress);

    // Urgency scales with the window: last 3s for a short challenge, last 10s
    // for the standard 60s one.
    final isUrgent = seconds <= (total <= 15 ? 3 : 10);
    final arcColor = status == WakefulnessStatus.success
        ? AppColors.success
        : isUrgent
            ? AppColors.danger
            : AppColors.gold;

    String label;
    Color labelColor;
    if (status == WakefulnessStatus.success) {
      label = '✓';
      labelColor = AppColors.success;
    } else if (status == WakefulnessStatus.failed) {
      label = '✗';
      labelColor = AppColors.danger;
    } else {
      label = '$seconds';
      labelColor = isUrgent ? AppColors.danger : AppColors.gold;
    }

    return SizedBox(
      width: 110,
      height: 110,
      child: CustomPaint(
        painter: _RingPainter(
          dashOffset: dashOffset,
          circumference: circumference,
          arcColor: arcColor,
        ),
        child: Center(
          // While the server is reconciling a correct code, show a spinner
          // rather than a frozen number.
          child: status == WakefulnessStatus.verifying
              ? const SizedBox(
                  width: 26,
                  height: 26,
                  child: CircularProgressIndicator(
                    strokeWidth: 2.5,
                    valueColor: AlwaysStoppedAnimation(AppColors.gold),
                  ),
                )
              : Text(
                  label,
                  style: AppType.display.copyWith(
                    fontSize: 32,
                    fontWeight: FontWeight.w800,
                    color: labelColor,
                    letterSpacing: -0.02,
                  ),
                ),
        ),
      ),
    );
  }
}

class _RingPainter extends CustomPainter {
  const _RingPainter({
    required this.dashOffset,
    required this.circumference,
    required this.arcColor,
  });

  final double dashOffset;
  final double circumference;
  final Color arcColor;

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    const radius = 46.0;

    // Track
    canvas.drawCircle(
      center,
      radius,
      Paint()
        ..color = const Color(0xCC23344D)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 7,
    );

    // Arc
    final arcPaint = Paint()
      ..color = arcColor
      ..style = PaintingStyle.stroke
      ..strokeWidth = 7
      ..strokeCap = StrokeCap.round;

    canvas.drawArc(
      Rect.fromCircle(center: center, radius: radius),
      -math.pi / 2,
      (circumference - dashOffset) / radius,
      false,
      arcPaint,
    );
  }

  @override
  bool shouldRepaint(_RingPainter old) =>
      old.dashOffset != dashOffset || old.arcColor != arcColor;
}

// ── Pin dots ──────────────────────────────────────────────────────────────

class _PinDots extends StatelessWidget {
  const _PinDots({required this.filled});
  final int filled;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: List.generate(4, (i) {
        final isFilled = i < filled;
        return Container(
          width: 16,
          height: 16,
          margin: const EdgeInsets.symmetric(horizontal: 10),
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: isFilled ? AppColors.gold : Colors.transparent,
            border: Border.all(
              color: isFilled
                  ? AppColors.gold
                  : const Color(0x33D4AF37),
              width: 2,
            ),
            boxShadow: isFilled
                ? const [BoxShadow(color: Color(0x66D4AF37), blurRadius: 10)]
                : null,
          ),
        );
      }),
    );
  }
}

// ── Keypad ────────────────────────────────────────────────────────────────

class _Keypad extends StatelessWidget {
  const _Keypad({
    required this.onKey,
    required this.entryLength,
    required this.enabled,
  });

  final ValueChanged<String> onKey;
  final int entryLength;
  final bool enabled;

  static const _keys = [
    ['1', '2', '3'],
    ['4', '5', '6'],
    ['7', '8', '9'],
    ['DEL', '0', 'OK'],
  ];

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.base),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.spaceEvenly,
        children: _keys.map((row) {
          return Row(
            children: row.map((key) {
              return Expanded(
                child: Padding(
                  padding: EdgeInsets.only(
                    left: key == row.first ? 0 : 3,
                    right: key == row.last ? 0 : 3,
                  ),
                  child: _Key(
                    label: key,
                    onTap: enabled ? () => onKey(key) : null,
                    isOk: key == 'OK',
                    okEnabled: key == 'OK' && entryLength == 4,
                  ),
                ),
              );
            }).toList(),
          );
        }).toList(),
      ),
    );
  }
}

class _Key extends StatefulWidget {
  const _Key({
    required this.label,
    required this.onTap,
    this.isOk = false,
    this.okEnabled = false,
  });

  final String label;
  final VoidCallback? onTap;
  final bool isOk;
  final bool okEnabled;

  @override
  State<_Key> createState() => _KeyState();
}

class _KeyState extends State<_Key> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final isOkActive = widget.isOk && widget.okEnabled;

    return GestureDetector(
      onTapDown: widget.onTap == null ? null : (_) => setState(() => _pressed = true),
      onTapUp: widget.onTap == null ? null : (_) => setState(() => _pressed = false),
      onTapCancel: widget.onTap == null ? null : () => setState(() => _pressed = false),
      onTap: widget.onTap,
      child: AnimatedScale(
        scale: _pressed ? 0.92 : 1.0,
        duration: const Duration(milliseconds: 80),
        child: Container(
          height: context.s(52),
          decoration: BoxDecoration(
            gradient: _pressed
                ? LinearGradient(colors: [AppColors.blue, AppColors.blue])
                : isOkActive
                    ? AppGradients._okGradient
                    : widget.isOk
                        ? null
                        : const LinearGradient(
                            begin: Alignment.topCenter,
                            end: Alignment.bottomCenter,
                            colors: [Color(0xFF141F35), Color(0xFF0F1828)],
                          ),
            color: widget.isOk && !isOkActive ? const Color(0x0AFFFFFF) : null,
            borderRadius: BorderRadius.circular(AppRadius.keypad),
            border: Border.all(color: AppColors.border),
            boxShadow: isOkActive
                ? const [
                    BoxShadow(color: Color(0x7312355B), blurRadius: 14, offset: Offset(0, 3)),
                  ]
                : const [
                    BoxShadow(color: Color(0x59000000), blurRadius: 8, offset: Offset(0, 2)),
                  ],
          ),
          alignment: Alignment.center,
          child: Text(
            widget.label,
            style: widget.label == 'DEL'
                ? AppType.label.copyWith(color: AppColors.muted, fontSize: context.sp(13))
                : widget.isOk
                    ? AppType.label.copyWith(
                        fontSize: context.sp(15),
                        color: isOkActive ? Colors.white : const Color(0xFF3A5070),
                      )
                    : AppType.h3.copyWith(color: AppColors.text),
          ),
        ),
      ),
    );
  }
}

// Workaround: expose OK gradient inside the file scope
class AppGradients {
  static const _okGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFF1A4272), Color(0xFF12355B)],
  );
}

// ── Footer message ────────────────────────────────────────────────────────

class _FooterMessage extends StatelessWidget {
  const _FooterMessage({required this.status});
  final WakefulnessStatus status;

  @override
  Widget build(BuildContext context) {
    if (status == WakefulnessStatus.failed) {
      return Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.base,
          vertical: AppSpacing.md,
        ),
        decoration: BoxDecoration(
          color: AppColors.dangerBg,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: const Color(0x4DDC2626)),
        ),
        child: Text(
          'Check-in missed — Your supervisor has been alerted',
          style: AppType.caption.copyWith(color: AppColors.danger, fontSize: 13),
          textAlign: TextAlign.center,
        ),
      );
    }
    if (status == WakefulnessStatus.success) {
      return Text(
        '✓ Check-in confirmed',
        style: AppType.label.copyWith(color: AppColors.success),
      );
    }
    if (status == WakefulnessStatus.verifying) {
      return Text(
        'Checking…',
        style: AppType.caption.copyWith(color: AppColors.gold),
      );
    }
    return Text(
      'Tap OK after entering all 4 digits',
      style: AppType.caption,
    );
  }
}

// Re-export spacing for use in this file
class AppRadius {
  static const keypad = 16.0;
}
