import 'package:flutter/material.dart';
import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';

class AppSectionLabel extends StatelessWidget {
  const AppSectionLabel(this.text, {super.key, this.bottomSpacing = AppSpacing.sm});

  final String text;
  final double bottomSpacing;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: bottomSpacing),
      child: Text(text.toUpperCase(), style: AppType.section),
    );
  }
}
