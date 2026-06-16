import 'package:flutter/material.dart';
import 'app_colors.dart';

abstract class AppShadows {
  static const List<BoxShadow> card = [
    BoxShadow(
      color: Color(0x73000000),
      blurRadius: 14,
      offset: Offset(0, 2),
    ),
  ];

  static const List<BoxShadow> elevated = [
    BoxShadow(
      color: Color(0x8C000000),
      blurRadius: 28,
      offset: Offset(0, 6),
    ),
  ];

  static const List<BoxShadow> goldGlow = [
    BoxShadow(
      color: AppColors.goldGlow,
      blurRadius: 40,
      spreadRadius: 0,
    ),
  ];
}
