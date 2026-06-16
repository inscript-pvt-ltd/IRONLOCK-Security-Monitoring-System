import 'package:flutter/material.dart';
import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';
import '../theme/responsive.dart';

enum AppChipVariant { success, warning, danger, info }

class AppChip extends StatelessWidget {
  const AppChip({
    super.key,
    required this.label,
    this.variant = AppChipVariant.info,
  });

  final String label;
  final AppChipVariant variant;

  @override
  Widget build(BuildContext context) {
    final colors = _colors();
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: context.s(AppSpacing.sm + 2),
        vertical: context.s(AppSpacing.xs),
      ),
      decoration: BoxDecoration(
        color: colors.bg,
        borderRadius: BorderRadius.circular(AppRadius.chip),
        border: Border.all(color: colors.border),
      ),
      child: Text(label,
          style: AppType.micro.copyWith(
            fontSize: context.sp(11),
            color: colors.fg,
          )),
    );
  }

  _ChipColors _colors() {
    switch (variant) {
      case AppChipVariant.success:
        return _ChipColors(
          bg: const Color(0x1A22C55E),
          fg: AppColors.success,
          border: const Color(0x3822C55E),
        );
      case AppChipVariant.warning:
        return _ChipColors(
          bg: const Color(0x1AF59E0B),
          fg: AppColors.warning,
          border: const Color(0x38F59E0B),
        );
      case AppChipVariant.danger:
        return _ChipColors(
          bg: const Color(0x1ADC2626),
          fg: AppColors.danger,
          border: const Color(0x38DC2626),
        );
      case AppChipVariant.info:
        return _ChipColors(
          bg: const Color(0x1294A3B8),
          fg: AppColors.muted,
          border: const Color(0x2694A3B8),
        );
    }
  }
}

class _ChipColors {
  const _ChipColors({required this.bg, required this.fg, required this.border});
  final Color bg;
  final Color fg;
  final Color border;
}
