import 'package:flutter/material.dart';

/// Ironlock Guard Monitor — color tokens.
/// Mirrors FLUTTER_SPEC.md §2.1. Design ratio: 80% Navy · 15% Neutral · 5% Gold.
abstract class AppColors {
  // Core surfaces
  static const bg = Color(0xFF07111F); // app + screen background
  static const surface = Color(0xFF0F172A); // cards, nav bar
  static const surfaceHi = Color(0xFF141F35); // raised surfaces, keypad keys
  static const navy = Color(0xFF0A1931); // inputs, secondary surfaces
  static const blue = Color(0xFF12355B); // primary button base, icon bg
  static const border = Color(0xFF23344D); // card borders, dividers

  // Text
  static const text = Color(0xFFFFFFFF); // primary text
  static const muted = Color(0xFFB0BEC5); // secondary text, placeholders
  static const subtle = Color(0xFF6A8099); // captions, sub-labels
  static const faint = Color(0xFF4A6080); // hints, section labels
  static const placeholder = Color(0xFF2D4060); // input placeholders

  // Brand accent
  static const gold = Color(0xFFD4AF37);
  static const softgold = Color(0xFFE8C76A);

  // Semantic
  static const success = Color(0xFF22C55E); // GPS active, verified, inside zone
  static const warning = Color(0xFFF59E0B); // outside zone, pending, notices
  static const danger = Color(0xFFDC2626); // no signal, failed, urgent

  // Gold glow / dim (shadows, overlays)
  static const goldGlow = Color(0x2ED4AF37); // rgba(212,175,55,0.18)
  static const goldDim = Color(0x14D4AF37); // rgba(212,175,55,0.08)

  // Convenience translucent helpers
  static Color successBg = const Color(0x1A22C55E); // 0.10
  static Color warningBg = const Color(0x1AF59E0B);
  static Color dangerBg = const Color(0x1ADC2626);
  static Color infoBg = const Color(0x1294A3B8); // rgba(148,163,184,0.07)
}
