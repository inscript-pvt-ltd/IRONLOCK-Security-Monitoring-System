import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'app_colors.dart';

/// Typography scale — Inter (Google Fonts).
/// Mirrors FLUTTER_SPEC.md §2.2.
abstract class AppType {
  static TextStyle _base(double size, FontWeight weight,
      {double? spacing, Color? color, double? height}) {
    return GoogleFonts.inter(
      fontSize: size,
      fontWeight: weight,
      letterSpacing: spacing,
      height: height,
      color: color ?? AppColors.text,
    );
  }

  // Display / headings
  static TextStyle display = _base(32, FontWeight.w700, spacing: -0.32);
  static TextStyle h1 = _base(24, FontWeight.w700, spacing: -0.24);
  static TextStyle h2 = _base(20, FontWeight.w700, spacing: -0.20);
  static TextStyle h3 = _base(18, FontWeight.w600, spacing: -0.18);

  // Body / labels
  static TextStyle body = _base(16, FontWeight.w400);
  static TextStyle bodySemi = _base(16, FontWeight.w600);
  static TextStyle label = _base(14, FontWeight.w600);
  static TextStyle labelMuted = _base(14, FontWeight.w400, color: AppColors.muted);

  // Small text
  static TextStyle caption = _base(12, FontWeight.w400, color: AppColors.muted);
  static TextStyle captionSemi = _base(12, FontWeight.w600);
  static TextStyle micro = _base(11, FontWeight.w600, spacing: 0.22);

  // Uppercase section label (11/700, 0.10em)
  static TextStyle section = _base(11, FontWeight.w700,
      spacing: 1.1, color: AppColors.faint);

  // Wakefulness challenge code (50/800, wide tracking)
  static TextStyle code = _base(50, FontWeight.w800,
      spacing: 22, color: AppColors.gold);
}
