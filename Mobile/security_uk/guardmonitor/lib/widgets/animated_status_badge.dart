import 'package:flutter/material.dart';
import '../theme/app_typography.dart';

/// Minimal animated status badge with subtle pulse effect
class AnimatedStatusBadge extends StatefulWidget {
  const AnimatedStatusBadge({
    super.key,
    required this.label,
    required this.color,
    this.size = 14,
  });

  final String label;
  final Color color;
  final double size;

  @override
  State<AnimatedStatusBadge> createState() => _AnimatedStatusBadgeState();
}

class _AnimatedStatusBadgeState extends State<AnimatedStatusBadge>
    with SingleTickerProviderStateMixin {
  late AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2000),
    )..repeat();
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
        // Subtle opacity pulse: 1.0 → 0.7 → 1.0
        final opacity = 0.85 + (0.15 * (1 - (_controller.value - 0.5).abs() * 2).clamp(0, 1));

        return Opacity(
          opacity: opacity,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
            decoration: BoxDecoration(
              color: widget.color.withValues(alpha: 0.15),
              border: Border.all(color: widget.color.withValues(alpha: 0.3), width: 1),
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text(
              widget.label,
              style: AppType.micro.copyWith(
                fontSize: widget.size,
                fontWeight: FontWeight.w500,
                color: widget.color,
              ),
            ),
          ),
        );
      },
    );
  }
}
