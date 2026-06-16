import 'package:flutter/material.dart';

/// Enhanced countdown ring with smooth color transitions
class AnimatedCountdownRing extends StatelessWidget {
  const AnimatedCountdownRing({
    super.key,
    required this.progress,
    required this.size,
    this.strokeWidth = 3.0,
  });

  final double progress; // 0.0 to 1.0
  final double size;
  final double strokeWidth;

  Color _getColor() {
    // Smooth color transition: gold → amber → red
    if (progress > 0.3) {
      // Gold to amber transition
      return Color.lerp(
        const Color(0xFFD4AF37),
        const Color(0xFFF59E0B),
        (0.3 - progress).abs() / 0.3,
      )!;
    } else {
      // Amber to red transition
      return Color.lerp(
        const Color(0xFFF59E0B),
        const Color(0xFFDC2626),
        progress / 0.3,
      )!;
    }
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: CustomPaint(
        painter: _CountdownRingPainter(
          progress: progress,
          color: _getColor(),
          strokeWidth: strokeWidth,
        ),
      ),
    );
  }
}

class _CountdownRingPainter extends CustomPainter {
  final double progress;
  final Color color;
  final double strokeWidth;

  _CountdownRingPainter({
    required this.progress,
    required this.color,
    required this.strokeWidth,
  });

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    final radius = (size.width / 2) - (strokeWidth / 2);

    // Background circle
    final bgPaint = Paint()
      ..color = color.withValues(alpha: 0.1)
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth;

    canvas.drawCircle(center, radius, bgPaint);

    // Progress arc with smooth animation
    final progressPaint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;

    // Sweep from -90 degrees (top) clockwise
    const startAngle = -3.14159 / 2;
    final sweepAngle = 2 * 3.14159 * progress;

    canvas.drawArc(
      Rect.fromCircle(center: center, radius: radius),
      startAngle,
      sweepAngle,
      false,
      progressPaint,
    );
  }

  @override
  bool shouldRepaint(_CountdownRingPainter oldDelegate) {
    return oldDelegate.progress != progress || oldDelegate.color != color;
  }
}
