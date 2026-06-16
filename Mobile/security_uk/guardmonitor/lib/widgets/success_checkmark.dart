import 'package:flutter/material.dart';

/// Minimal success checkmark animation - refined and professional
class SuccessCheckmark extends StatefulWidget {
  const SuccessCheckmark({
    super.key,
    this.size = 64,
    this.color = const Color(0xFF10B981),
    this.duration = const Duration(milliseconds: 600),
  });

  final double size;
  final Color color;
  final Duration duration;

  @override
  State<SuccessCheckmark> createState() => _SuccessCheckmarkState();
}

class _SuccessCheckmarkState extends State<SuccessCheckmark>
    with SingleTickerProviderStateMixin {
  late AnimationController _controller;
  late Animation<double> _scaleAnimation;
  late Animation<double> _strokeAnimation;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(vsync: this, duration: widget.duration);

    _scaleAnimation = Tween<double>(begin: 0.3, end: 1.0).animate(
      CurvedAnimation(parent: _controller, curve: Curves.easeOutBack),
    );

    _strokeAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _controller, curve: const Interval(0.3, 1.0, curve: Curves.easeInOut)),
    );

    _controller.forward();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, child) {
        return Transform.scale(
          scale: _scaleAnimation.value,
          child: CustomPaint(
            painter: _SuccessCheckmarkPainter(
              color: widget.color,
              strokeProgress: _strokeAnimation.value,
            ),
            size: Size(widget.size, widget.size),
          ),
        );
      },
    );
  }
}

class _SuccessCheckmarkPainter extends CustomPainter {
  final Color color;
  final double strokeProgress;

  _SuccessCheckmarkPainter({required this.color, required this.strokeProgress});

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    final radius = size.width / 2.5;

    // Draw circle background
    final circlePaint = Paint()
      ..color = color.withValues(alpha: 0.1)
      ..style = PaintingStyle.fill;

    canvas.drawCircle(center, radius, circlePaint);

    // Draw circle border
    final borderPaint = Paint()
      ..color = color
      ..strokeWidth = 2
      ..style = PaintingStyle.stroke;

    canvas.drawCircle(center, radius, borderPaint);

    // Draw checkmark
    final checkPaint = Paint()
      ..color = color
      ..strokeWidth = 2.5
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..style = PaintingStyle.stroke;

    final path = Path();
    // Checkmark path (relative to center)
    final leftPoint = Offset(center.dx - radius * 0.3, center.dy + radius * 0.1);
    final midPoint = Offset(center.dx - radius * 0.05, center.dy + radius * 0.35);
    final rightPoint = Offset(center.dx + radius * 0.35, center.dy - radius * 0.25);

    path.moveTo(leftPoint.dx, leftPoint.dy);
    path.lineTo(midPoint.dx, midPoint.dy);
    path.lineTo(rightPoint.dx, rightPoint.dy);

    // Draw only the progress of the checkmark
    final pathMetrics = path.computeMetrics();
    for (var metric in pathMetrics) {
      final extractPath = metric.extractPath(0, metric.length * strokeProgress);
      canvas.drawPath(extractPath, checkPaint);
    }
  }

  @override
  bool shouldRepaint(_SuccessCheckmarkPainter oldDelegate) {
    return oldDelegate.strokeProgress != strokeProgress;
  }
}
