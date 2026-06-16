import 'package:flutter/material.dart';
import '../theme/app_colors.dart';
import '../theme/app_elevation.dart';
import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';
import '../theme/app_gradients.dart';
import '../theme/responsive.dart';

enum AppButtonVariant { primary, secondary, danger }

class AppButton extends StatelessWidget {
  const AppButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.variant = AppButtonVariant.primary,
    this.leading,
    this.loading = false,
    this.enabled = true,
  });

  final String label;
  final VoidCallback? onPressed;
  final AppButtonVariant variant;
  final Widget? leading;
  final bool loading;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    final isEnabled = enabled && !loading;

    return SizedBox(
      height: context.s(56),
      width: double.infinity,
      child: _buildButton(isEnabled),
    );
  }

  Widget _buildButton(bool isEnabled) {
    switch (variant) {
      case AppButtonVariant.primary:
        return _GradientButton(
          label: label,
          onPressed: isEnabled ? onPressed : null,
          gradient: AppGradients.primaryButton,
          shadow: AppElevation.blueShadow,
          loading: loading,
          leading: leading,
        );

      case AppButtonVariant.secondary:
        return _OutlineButton(
          label: label,
          onPressed: isEnabled ? onPressed : null,
          loading: loading,
          leading: leading,
        );

      case AppButtonVariant.danger:
        return _GradientButton(
          label: label,
          onPressed: isEnabled ? onPressed : null,
          gradient: AppGradients.dangerButton,
          shadow: AppElevation.dangerShadow,
          loading: loading,
          leading: leading,
        );
    }
  }
}

class _GradientButton extends StatefulWidget {
  const _GradientButton({
    required this.label,
    required this.onPressed,
    required this.gradient,
    required this.shadow,
    required this.loading,
    this.leading,
  });

  final String label;
  final VoidCallback? onPressed;
  final LinearGradient gradient;
  final List<BoxShadow> shadow;
  final bool loading;
  final Widget? leading;

  @override
  State<_GradientButton> createState() => _GradientButtonState();
}

class _GradientButtonState extends State<_GradientButton> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final disabled = widget.onPressed == null;

    return GestureDetector(
      onTapDown: disabled ? null : (_) => setState(() => _pressed = true),
      onTapUp: disabled ? null : (_) => setState(() => _pressed = false),
      onTapCancel: disabled ? null : () => setState(() => _pressed = false),
      onTap: widget.onPressed,
      child: Stack(
        children: [
          // Glow effect (shows when pressed)
          if (_pressed)
            Container(
              height: context.s(56),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(16),
                boxShadow: [
                  BoxShadow(
                    color: widget.gradient.colors[1].withValues(alpha: 0.4),
                    blurRadius: 24,
                    spreadRadius: 4,
                  ),
                ],
              ),
            ),
          // Main button
          AnimatedScale(
            scale: _pressed ? 0.98 : 1.0,
            duration: const Duration(milliseconds: 100),
            child: Opacity(
              opacity: disabled ? 0.38 : 1.0,
              child: Container(
                height: context.s(56),
                decoration: BoxDecoration(
                  gradient: widget.gradient,
                  borderRadius: BorderRadius.circular(16),
                  boxShadow: _pressed ? AppElevation.level2 : widget.shadow,
                ),
                child: _content(context),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _content(BuildContext context) {
    if (widget.loading) {
      return const Center(
        child: SizedBox(
          width: 20,
          height: 20,
          child: CircularProgressIndicator(
            strokeWidth: 2,
            color: Colors.white,
          ),
        ),
      );
    }
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        if (widget.leading != null) ...[widget.leading!, const SizedBox(width: AppSpacing.sm)],
        Text(widget.label,
          style: AppType.label.copyWith(
            fontSize: context.sp(16),
            letterSpacing: 0.32,
          )),
      ],
    );
  }
}

class _OutlineButton extends StatelessWidget {
  const _OutlineButton({
    required this.label,
    required this.onPressed,
    required this.loading,
    this.leading,
  });

  final String label;
  final VoidCallback? onPressed;
  final bool loading;
  final Widget? leading;

  @override
  Widget build(BuildContext context) {
    return OutlinedButton(
      onPressed: onPressed,
      style: OutlinedButton.styleFrom(
        foregroundColor: AppColors.gold,
        backgroundColor: const Color(0x0DD4AF37),
        side: BorderSide(
          color: onPressed == null
              ? AppColors.gold.withValues(alpha: 0.2)
              : const Color(0x61D4AF37),
          width: 1.5,
        ),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
        ),
        textStyle: AppType.body.copyWith(
          fontSize: context.sp(16),
          fontWeight: FontWeight.w600,
          letterSpacing: 0.32,
          color: AppColors.gold,
        ),
        minimumSize: Size(double.infinity, context.s(56)),
        padding: const EdgeInsets.symmetric(vertical: 0, horizontal: 16),
      ),
      child: loading
          ? const SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.gold),
            )
          : Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (leading != null) ...[leading!, const SizedBox(width: AppSpacing.sm)],
                Text(label),
              ],
            ),
    );
  }
}
