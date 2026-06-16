import 'package:flutter/material.dart';
import '../theme/app_elevation.dart';

/// Card with subtle gradient overlay - minimal but elevated
class GradientOverlayCard extends StatelessWidget {
  const GradientOverlayCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.borderRadius = 16.0,
    this.elevation = AppElevation.level2,
  });

  final Widget child;
  final EdgeInsets padding;
  final double borderRadius;
  final List<BoxShadow> elevation;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(borderRadius),
        boxShadow: elevation,
      ),
      child: Stack(
        children: [
          // Base card
          Container(
            decoration: BoxDecoration(
              color: const Color(0xFF0F172A),
              borderRadius: BorderRadius.circular(borderRadius),
              border: Border.all(
                color: const Color(0xFF23344D),
                width: 1,
              ),
            ),
            child: Padding(
              padding: padding,
              child: child,
            ),
          ),

          // Subtle top gradient overlay (light refraction effect)
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            height: 1,
            child: Container(
              decoration: BoxDecoration(
                borderRadius: BorderRadius.only(
                  topLeft: Radius.circular(borderRadius),
                  topRight: Radius.circular(borderRadius),
                ),
                gradient: LinearGradient(
                  colors: [
                    Colors.white.withValues(alpha: 0.08),
                    Colors.white.withValues(alpha: 0.02),
                    Colors.transparent,
                  ],
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
