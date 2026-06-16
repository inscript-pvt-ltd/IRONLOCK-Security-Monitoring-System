import 'package:flutter/material.dart';
import 'app_colors.dart';

abstract class AppGradients {
  static const background = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFF0D1B2E), AppColors.bg, AppColors.bg],
    stops: [0.0, 0.5, 1.0],
  );

  static const primaryButton = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFF1A4272), AppColors.blue, Color(0xFF0E2A48)],
  );

  static const dangerButton = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFFC41F1F), AppColors.danger, Color(0xFFA81818)],
  );

  static const avatar = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFF1A3D6B), AppColors.blue, Color(0xFF0D2240)],
  );

  static const logoCard = LinearGradient(
    begin: Alignment(-0.34, -1.0),
    end: Alignment(0.34, 1.0),
    colors: [Color(0xFF1A3D6B), Color(0xFF0D2240)],
  );

  static const bottomNav = LinearGradient(
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
    colors: [Color(0xFF0C1829), AppColors.surface],
  );

  static const bottomSheet = LinearGradient(
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
    colors: [Color(0xFF131D30), AppColors.surface],
  );

  static const cardTopHighlight = LinearGradient(
    colors: [
      Colors.transparent,
      Color(0x0FFFFFFF),
      Colors.transparent,
    ],
    stops: [0.0, 0.5, 1.0],
  );
}
