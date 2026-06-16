import 'package:flutter/material.dart';
import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';
import '../theme/app_shadows.dart';
import '../theme/app_gradients.dart';

class AppCard extends StatelessWidget {
  const AppCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(AppSpacing.base),
    this.margin = EdgeInsets.zero,
    this.borderColor = AppColors.border,
    this.topHighlight = true,
  });

  final Widget child;
  final EdgeInsets padding;
  final EdgeInsets margin;
  final Color borderColor;
  final bool topHighlight;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: margin,
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(color: borderColor),
        boxShadow: AppShadows.card,
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(AppRadius.card - 1),
        child: Stack(
          children: [
            Padding(padding: padding, child: child),
            if (topHighlight)
              Positioned(
                top: 0,
                left: 0,
                right: 0,
                height: 1,
                child: DecoratedBox(
                  decoration: BoxDecoration(gradient: AppGradients.cardTopHighlight),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
