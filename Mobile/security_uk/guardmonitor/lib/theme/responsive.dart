import 'package:flutter/material.dart';

/// Responsive scaling system.
///
/// The UI was designed against a ~390px-wide reference phone (iPhone 14/15,
/// Pixel). Every dimension is scaled proportionally to the real screen width
/// so the layout keeps **identical proportions on any device** — that's what
/// makes it look and feel the same on every phone.
///
/// Scale factors are clamped so the design never becomes extreme on very small
/// phones or very large ones / tablets.
const double _kRefWidth = 390.0;

extension Responsive on BuildContext {
  double get _screenWidth => MediaQuery.sizeOf(this).width;

  /// Raw screen dimensions.
  double get screenW => MediaQuery.sizeOf(this).width;
  double get screenH => MediaQuery.sizeOf(this).height;

  /// Scale a layout dimension (spacing, padding, widget size, radius).
  /// Clamped 0.86–1.14 so small phones stay usable and big phones don't bloat.
  double s(double value) {
    final factor = (_screenWidth / _kRefWidth).clamp(0.86, 1.14);
    return value * factor;
  }

  /// Scale a font size — gentler clamp so text never gets too small/large.
  double sp(double value) {
    final factor = (_screenWidth / _kRefWidth).clamp(0.92, 1.12);
    return value * factor;
  }

  /// Convenience: true on compact phones (<360px logical width).
  bool get isCompact => _screenWidth < 360;
}
