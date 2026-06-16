import 'package:flutter/material.dart';
import '../theme/app_spacing.dart';

class AppDivider extends StatelessWidget {
  const AppDivider({super.key, this.margin = const EdgeInsets.symmetric(vertical: AppSpacing.sm)});

  final EdgeInsets margin;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: margin,
      height: 1,
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [Colors.transparent, Color(0xFF23344D), Colors.transparent],
        ),
      ),
    );
  }
}
