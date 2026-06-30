import 'package:flutter/material.dart';
import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';
import '../theme/responsive.dart';

class AppInput extends StatefulWidget {
  const AppInput({
    super.key,
    required this.controller,
    this.label,
    this.hint,
    this.obscureText = false,
    this.keyboardType,
    this.textInputAction,
    this.onSubmitted,
    this.suffix,
    this.autofocus = false,
    this.enabled = true,
    this.focusNode,
  });

  final TextEditingController controller;
  final String? label;
  final String? hint;
  final bool obscureText;
  final TextInputType? keyboardType;
  final TextInputAction? textInputAction;
  final ValueChanged<String>? onSubmitted;
  final Widget? suffix;
  final bool autofocus;
  final bool enabled;
  final FocusNode? focusNode;

  @override
  State<AppInput> createState() => _AppInputState();
}

class _AppInputState extends State<AppInput> {
  late FocusNode _internalFocusNode;
  bool _isFocused = false;

  @override
  void initState() {
    super.initState();
    _internalFocusNode = widget.focusNode ?? FocusNode();
    _internalFocusNode.addListener(_onFocusChange);
  }

  @override
  void dispose() {
    if (widget.focusNode == null) {
      _internalFocusNode.dispose();
    } else {
      _internalFocusNode.removeListener(_onFocusChange);
    }
    super.dispose();
  }

  void _onFocusChange() {
    setState(() => _isFocused = _internalFocusNode.hasFocus);
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (widget.label != null) ...[
          Text(widget.label!.toUpperCase(),
              style: AppType.section.copyWith(
                fontSize: context.sp(12),
                fontWeight: FontWeight.w600,
                letterSpacing: 0.48,
                color: const Color(0xFF6A8099),
              )),
          SizedBox(height: context.s(8)),
        ],
        Stack(
          children: [
            // Focus glow effect
            if (_isFocused)
              Container(
                height: context.s(52),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(12),
                  boxShadow: [
                    BoxShadow(
                      color: AppColors.gold.withValues(alpha: 0.25),
                      blurRadius: 16,
                      spreadRadius: 2,
                    ),
                  ],
                ),
              ),
            // Input field
            TextFormField(
              controller: widget.controller,
              obscureText: widget.obscureText,
              // Obscured fields (the passcode) must not feed the keyboard's
              // learning/suggestion dictionary — that can cache the credential.
              autocorrect: !widget.obscureText,
              enableSuggestions: !widget.obscureText,
              keyboardType: widget.keyboardType,
              textInputAction: widget.textInputAction,
              onFieldSubmitted: widget.onSubmitted,
              autofocus: widget.autofocus,
              enabled: widget.enabled,
              focusNode: _internalFocusNode,
              style: AppType.body,
              cursorColor: AppColors.gold,
              decoration: InputDecoration(
                hintText: widget.hint,
                hintStyle: AppType.body.copyWith(color: AppColors.placeholder),
                suffixIcon: widget.suffix,
                filled: true,
                fillColor: const Color(0xCC0A1931),
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.base,
                  vertical: AppSpacing.md + 2,
                ),
                border: _border(AppColors.border),
                enabledBorder: _border(AppColors.border),
                focusedBorder: _border(
                  _isFocused ? AppColors.gold : AppColors.border,
                  width: 1.5,
                ),
                disabledBorder: _border(AppColors.border),
                focusedErrorBorder: _border(AppColors.danger, width: 1.5),
              ),
            ),
          ],
        ),
      ],
    );
  }

  OutlineInputBorder _border(Color color, {double width = 1.5}) {
    return OutlineInputBorder(
      borderRadius: BorderRadius.circular(12),
      borderSide: BorderSide(color: color, width: width),
    );
  }
}
