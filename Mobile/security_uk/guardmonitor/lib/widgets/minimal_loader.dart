import 'package:flutter/material.dart';

/// Minimal, refined loading indicator - subtle and professional
class MinimalLoader extends StatefulWidget {
  const MinimalLoader({
    super.key,
    this.size = 24,
    this.color = const Color(0xFFD4AF37),
  });

  final double size;
  final Color color;

  @override
  State<MinimalLoader> createState() => _MinimalLoaderState();
}

class _MinimalLoaderState extends State<MinimalLoader>
    with SingleTickerProviderStateMixin {
  late AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1200),
    )..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: widget.size,
      height: widget.size,
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, child) {
          return Transform.rotate(
            angle: _controller.value * 2 * 3.14159,
            child: CustomPaint(
              painter: _MinimalLoaderPainter(
                color: widget.color,
                progress: _controller.value,
              ),
              size: Size(widget.size, widget.size),
            ),
          );
        },
      ),
    );
  }
}

class _MinimalLoaderPainter extends CustomPainter {
  final Color color;
  final double progress;

  _MinimalLoaderPainter({required this.color, required this.progress});

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    final radius = size.width / 2;

    // Draw rotating arc
    final paint = Paint()
      ..color = color
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round
      ..style = PaintingStyle.stroke;

    const startAngle = -3.14159 / 2;
    const sweepAngle = 3.14159 * 1.5;

    canvas.drawArc(
      Rect.fromCircle(center: center, radius: radius - 2),
      startAngle,
      sweepAngle,
      false,
      paint,
    );
  }

  @override
  bool shouldRepaint(_MinimalLoaderPainter oldDelegate) {
    return oldDelegate.progress != progress;
  }
}
