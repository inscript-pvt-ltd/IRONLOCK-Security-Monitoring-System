import 'package:flutter/material.dart';

/// Standard animation curves and durations for the app
abstract class AppAnimations {
  // ── Curves ────────────────────────────────────────────────────────────

  /// Entrance animation curve - smooth ease in
  static const Curve entrance = Curves.easeOut;

  /// Exit animation curve - smooth ease out
  static const Curve exit = Curves.easeIn;

  /// Interactive curve - responsive feedback
  static const Curve interactive = Curves.easeInOutCubic;

  /// Bounce curve - subtle elasticity (not cartoonish)
  static const Curve subtleBounce = Curves.easeOutBack;

  // ── Durations ─────────────────────────────────────────────────────────

  /// Ultra-fast interactions (button press feedback)
  static const Duration ultraFast = Duration(milliseconds: 100);

  /// Fast interactions (color transitions, focus changes)
  static const Duration fast = Duration(milliseconds: 200);

  /// Standard animation (page transitions, expansions)
  static const Duration standard = Duration(milliseconds: 300);

  /// Slow animations (loaders, long transitions)
  static const Duration slow = Duration(milliseconds: 600);

  /// Very slow (background animations, subtle pulses)
  static const Duration verySlow = Duration(milliseconds: 1200);

  // ── Stagger delays ────────────────────────────────────────────────────

  /// Delay for first child in stagger animation
  static const Duration staggerStart = Duration(milliseconds: 0);

  /// Delay between staggered children
  static const Duration staggerInterval = Duration(milliseconds: 50);

  /// Delay for second child
  static Duration get stagger2 => staggerStart + staggerInterval;

  /// Delay for third child
  static Duration get stagger3 => staggerStart + (staggerInterval * 2);

  /// Delay for fourth child
  static Duration get stagger4 => staggerStart + (staggerInterval * 3);
}
