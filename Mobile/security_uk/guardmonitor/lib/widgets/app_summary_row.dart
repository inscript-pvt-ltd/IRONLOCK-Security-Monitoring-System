import 'package:flutter/material.dart';
import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';

class AppSummaryRow extends StatelessWidget {
  const AppSummaryRow({
    super.key,
    required this.label,
    required this.value,
    this.isLast = false,
    this.valueColor,
  });

  final String label;
  final String value;
  final bool isLast;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 9),
      decoration: isLast
          ? null
          : const BoxDecoration(
              border: Border(
                bottom: BorderSide(color: Color(0xB223344D)),
              ),
            ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: AppType.caption.copyWith(
            fontSize: 13,
            color: AppColors.subtle,
          )),
          const SizedBox(width: AppSpacing.base),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.end,
              style: AppType.labelMuted.copyWith(
                fontSize: 14,
                fontWeight: FontWeight.w500,
                color: valueColor ?? AppColors.text,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
