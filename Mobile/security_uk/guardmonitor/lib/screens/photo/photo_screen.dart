import 'dart:async';
import 'dart:io';
import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../providers/app_providers.dart';
import '../../services/photo_service.dart';
import '../../theme/app_colors.dart';
import '../../theme/app_spacing.dart';
import '../../theme/app_typography.dart';
import '../../theme/responsive.dart';
import '../../widgets/app_button.dart';

class PhotoScreen extends ConsumerStatefulWidget {
  const PhotoScreen({super.key, required this.requestId});
  final String requestId;

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
  bool _cameraReady = false;

  @override
  void initState() {
    super.initState();

    _scanCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 3500),
    )..repeat();

    _flashCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 350),
    );

    _initCamera();

    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      final s = ref.read(photoProvider);
      if (s.status == PhotoStatus.idle || s.status == PhotoStatus.expired) {
        ref.read(photoProvider.notifier).tick();
      }
    });
  }

  Future<void> _initCamera() async {
    try {
      _cameras = await availableCameras();
      if (_cameras.isEmpty) return; // simulator — use simulated view
      _cameraCtrl = CameraController(
        _cameras.first,
        ResolutionPreset.high,
        enableAudio: false,
      );
      await _cameraCtrl!.initialize();
      if (mounted) setState(() => _cameraReady = true);
    } catch (_) {
      // Camera unavailable on simulator; simulated view shown instead.
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _scanCtrl.dispose();
    _flashCtrl.dispose();
    _cameraCtrl?.dispose();
    super.dispose();
  }

  Future<void> _capture() async {
    final s = ref.read(photoProvider);
    if (s.status != PhotoStatus.idle) return;

    _flashCtrl.forward(from: 0).then((_) => _flashCtrl.reverse());
    ref.read(photoProvider.notifier).capture();

    String? filePath;

    if (_cameraReady && _cameraCtrl != null) {
      try {
        final xFile = await _cameraCtrl!.takePicture();
        filePath = xFile.path;
      } catch (_) {
        // Fall through to simulator fallback
      }
    }

    // Simulator fallback: write a minimal valid JPEG so the upload has a real file.
    if (filePath == null) {
      final f = File('${Directory.systemTemp.path}/guard_sim_${DateTime.now().millisecondsSinceEpoch}.jpg');
      await f.writeAsBytes(_kMinimalJpeg);
      filePath = f.path;
    }

    await _upload(filePath);
  }

  Future<void> _upload(String filePath) async {
    final shiftId = ref.read(shiftProvider).id;
    if (shiftId == null) {
      ref.read(photoProvider.notifier).setResult(PhotoStatus.failed);
      return;
    }

    final nonce = ref.read(noncePoolProvider.notifier).consume();

    try {
      final result = await ref.read(photoServiceProvider).uploadPhoto(
        filePath: filePath,
        shiftId: shiftId,
        requestId: widget.requestId,
        nonce: nonce,
      );

      if (!mounted) return;

      final photoStatus = switch (result.result) {
        'VALIDATED' => PhotoStatus.validated,
        'FLAGGED'   => PhotoStatus.flagged,
        _           => PhotoStatus.failed,
      };

      ref.read(photoProvider.notifier).setResult(photoStatus);
      ref.read(shiftProvider.notifier).recordPhoto(
        passed: photoStatus == PhotoStatus.validated,
      );
    } catch (_) {
      if (!mounted) return;
      ref.read(photoProvider.notifier).setResult(PhotoStatus.failed);
      ref.read(shiftProvider.notifier).recordPhoto(passed: false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final photo = ref.watch(photoProvider);
    final isIdle = photo.status == PhotoStatus.idle;
    final isExpired = photo.status == PhotoStatus.expired;
    final timerColor = photo.secondsRemaining <= 10 ? AppColors.danger : AppColors.warning;

    return Scaffold(
      backgroundColor: AppColors.bg,
      body: Stack(
        children: [
          SafeArea(
            child: SingleChildScrollView(
              child: Column(
                children: [
                  // Header
                  Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: AppSpacing.base,
                      vertical: AppSpacing.base,
                    ),
                    child: Row(
                      children: [
                        GestureDetector(
                          onTap: () => Navigator.of(context).pop(),
                          child: const Icon(Icons.arrow_back, color: AppColors.text),
                        ),
                        const SizedBox(width: AppSpacing.md),
                        Text('Photo Verification', style: AppType.h3),
                      ],
                    ),
                  ),

                  // Response window bar
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: AppSpacing.base),
                    child: Row(
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
                  ),
                  const SizedBox(height: 8),

                  // Progress bar
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: AppSpacing.base),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(2),
                      child: LinearProgressIndicator(
                        value: isExpired ? 0 : photo.secondsRemaining / 78,
                        backgroundColor: AppColors.border,
                        valueColor: AlwaysStoppedAnimation<Color>(
                          photo.secondsRemaining <= 10 ? AppColors.danger : AppColors.warning,
                        ),
                        minHeight: 3,
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),

                  // Camera view — real on device, simulated on simulator
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: AppSpacing.base),
                    child: _cameraReady && _cameraCtrl != null
                        ? _RealCameraView(controller: _cameraCtrl!)
                        : _SimulatedCameraView(scanCtrl: _scanCtrl),
                  ),
                  const SizedBox(height: 16),

                  Text('Photograph your current location', style: AppType.label),
                  const SizedBox(height: 4),
                  Text(
                    _cameraReady
                        ? 'Point camera at your surroundings and tap capture'
                        : 'Simulated camera · will use real camera on device',
                    style: AppType.caption,
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: AppSpacing.xl),

                  _ShutterButton(onTap: isIdle ? _capture : null, enabled: isIdle),
                  const SizedBox(height: AppSpacing.xl),

                  if (photo.status != PhotoStatus.idle) ...[
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.base),
                      child: _UploadStatus(
                        status: photo.status,
                        expireCountdown: photo.expireCountdown,
                      ),
                    ),
                    const SizedBox(height: AppSpacing.md),
                  ],

                  if (photo.status == PhotoStatus.flagged ||
                      photo.status == PhotoStatus.failed) ...[
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.base),
                      child: AppButton(
                        label: 'Try Again',
                        variant: AppButtonVariant.secondary,
                        onPressed: () => ref.read(photoProvider.notifier).tryAgain(),
                      ),
                    ),
                  ],
                ],
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
    return ClipRRect(
      borderRadius: BorderRadius.circular(AppRadius.camera),
      child: SizedBox(
        height: context.s(270),
        width: double.infinity,
        child: CameraPreview(controller),
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
    return ClipRRect(
      borderRadius: BorderRadius.circular(AppRadius.camera),
      child: Container(
        height: context.s(270),
        width: double.infinity,
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [Color(0xFF020408), Color(0xFF05070F)],
          ),
          borderRadius: BorderRadius.circular(AppRadius.camera),
          border: Border.all(color: const Color(0x1AD4AF37)),
        ),
        child: CustomPaint(
          painter: _CameraOverlayPainter(scanCtrl),
          child: const _CameraLabels(),
        ),
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
            fontSize: 10,
            color: const Color(0x73D4AF37),
            fontWeight: FontWeight.w400,
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
  const _UploadStatus({required this.status, required this.expireCountdown});
  final PhotoStatus status;
  final int expireCountdown;

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
          Text(cfg.icon, style: const TextStyle(fontSize: 16)),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Text(
              status == PhotoStatus.expired
                  ? '⏱ Request expired — new request in ${expireCountdown}s'
                  : cfg.text,
              style: AppType.caption.copyWith(fontSize: 13, color: cfg.color),
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
