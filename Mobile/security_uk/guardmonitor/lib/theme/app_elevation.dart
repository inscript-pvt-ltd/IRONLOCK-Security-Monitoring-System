import 'package:flutter/material.dart';

/// Elevation/Shadow system following Material Design 3 principles
abstract class AppElevation {
  // ── Elevation levels (1dp to 24dp) ────────────────────────────────────

  /// Level 0: No elevation (flat surface)
  static const BoxShadow none = BoxShadow(color: Colors.transparent);

  /// Level 1: Minimal elevation (subtle cards)
  static const List<BoxShadow> level1 = [
    BoxShadow(
      color: Color(0x0D000000),
      blurRadius: 2,
      offset: Offset(0, 1),
    ),
    BoxShadow(
      color: Color(0x0A000000),
      blurRadius: 1,
      offset: Offset(0, 0),
    ),
  ];

  /// Level 2: Low elevation (default cards)
  static const List<BoxShadow> level2 = [
    BoxShadow(
      color: Color(0x15000000),
      blurRadius: 6,
      offset: Offset(0, 2),
    ),
    BoxShadow(
      color: Color(0x0A000000),
      blurRadius: 2,
      offset: Offset(0, 0),
    ),
  ];

  /// Level 3: Medium elevation (elevated cards)
  static const List<BoxShadow> level3 = [
    BoxShadow(
      color: Color(0x1A000000),
      blurRadius: 10,
      offset: Offset(0, 4),
    ),
    BoxShadow(
      color: Color(0x0D000000),
      blurRadius: 3,
      offset: Offset(0, 1),
    ),
  ];

  /// Level 4: High elevation (modals)
  static const List<BoxShadow> level4 = [
    BoxShadow(
      color: Color(0x20000000),
      blurRadius: 14,
      offset: Offset(0, 6),
    ),
    BoxShadow(
      color: Color(0x10000000),
      blurRadius: 4,
      offset: Offset(0, 2),
    ),
  ];

  /// Level 5: Maximum elevation (floating elements)
  static const List<BoxShadow> level5 = [
    BoxShadow(
      color: Color(0x26000000),
      blurRadius: 20,
      offset: Offset(0, 8),
    ),
    BoxShadow(
      color: Color(0x12000000),
      blurRadius: 6,
      offset: Offset(0, 3),
    ),
  ];

  // ── Color-tinted shadows (brand-specific) ──────────────────────────────

  /// Blue-tinted shadow for primary elements
  static const List<BoxShadow> blueShadow = [
    BoxShadow(
      color: Color(0x1A1A4272),
      blurRadius: 16,
      offset: Offset(0, 4),
    ),
    BoxShadow(
      color: Color(0x0D1A4272),
      blurRadius: 2,
      offset: Offset(0, 1),
    ),
  ];

  /// Gold-tinted shadow for accent elements
  static const List<BoxShadow> goldShadow = [
    BoxShadow(
      color: Color(0x20D4AF37),
      blurRadius: 16,
      offset: Offset(0, 4),
    ),
    BoxShadow(
      color: Color(0x0DD4AF37),
      blurRadius: 2,
      offset: Offset(0, 1),
    ),
  ];

  /// Red-tinted shadow for danger elements
  static const List<BoxShadow> dangerShadow = [
    BoxShadow(
      color: Color(0x1ADC2626),
      blurRadius: 12,
      offset: Offset(0, 4),
    ),
    BoxShadow(
      color: Color(0x0DDC2626),
      blurRadius: 2,
      offset: Offset(0, 1),
    ),
  ];

  /// Green-tinted shadow for success elements
  static const List<BoxShadow> successShadow = [
    BoxShadow(
      color: Color(0x1A10B981),
      blurRadius: 12,
      offset: Offset(0, 4),
    ),
    BoxShadow(
      color: Color(0x0D10B981),
      blurRadius: 2,
      offset: Offset(0, 1),
    ),
  ];
}
