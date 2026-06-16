import 'package:flutter/material.dart';
import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';

/// Enhanced input with real-time validation feedback
class ValidatedInput extends StatefulWidget {
  const ValidatedInput({
    super.key,
    required this.controller,
    this.label,
    this.hint,
    this.validator,
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
  final String? Function(String)? validator;
  final bool obscureText;
  final TextInputType? keyboardType;
  final TextInputAction? textInputAction;
  final ValueChanged<String>? onSubmitted;
  final Widget? suffix;
  final bool autofocus;
  final bool enabled;
  final FocusNode? focusNode;

  @override
  State<ValidatedInput> createState() => _ValidatedInputState();
}

class _ValidatedInputState extends State<ValidatedInput> {
  late FocusNode _internalFocusNode;
  bool _isFocused = false;
  String? _validationError;
  bool? _isValid;

  @override
  void initState() {
    super.initState();
    _internalFocusNode = widget.focusNode ?? FocusNode();
    _internalFocusNode.addListener(_onFocusChange);
    widget.controller.addListener(_validateInput);
  }

  @override
  void dispose() {
    if (widget.focusNode == null) {
      _internalFocusNode.dispose();
    } else {
      _internalFocusNode.removeListener(_onFocusChange);
    }
    widget.controller.removeListener(_validateInput);
    super.dispose();
  }

  void _onFocusChange() {
    setState(() => _isFocused = _internalFocusNode.hasFocus);
    if (!_isFocused && widget.controller.text.isNotEmpty) {
      _validateInput();
    }
  }

  void _validateInput() {
    if (widget.validator == null) return;

    final error = widget.validator!(widget.controller.text);
    setState(() {
      _validationError = error;
      _isValid = error == null && widget.controller.text.isNotEmpty;
    });
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
                fontSize: 12,
                fontWeight: FontWeight.w600,
                letterSpacing: 0.48,
                color: const Color(0xFF6A8099),
              )),
          const SizedBox(height: 8),
        ],
        Stack(
          children: [
            // Focus glow
            if (_isFocused)
              Container(
                height: 52,
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
            // Validation glow (success)
            if (_isValid == true)
              Container(
                height: 52,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(12),
                  boxShadow: [
                    BoxShadow(
                      color: const Color(0xFF10B981).withValues(alpha: 0.2),
                      blurRadius: 12,
                      spreadRadius: 1,
                    ),
                  ],
                ),
              ),
            // Input field
            TextFormField(
              controller: widget.controller,
              obscureText: widget.obscureText,
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
                suffixIcon: _buildSuffixIcon(),
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
                errorBorder: _border(_validationError != null ? AppColors.danger : AppColors.border),
              ),
            ),
          ],
        ),
        // Validation message
        if (_validationError != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(
              children: [
                Icon(Icons.info_outline, size: 14, color: AppColors.danger),
                const SizedBox(width: 4),
                Expanded(
                  child: Text(
                    _validationError!,
                    style: AppType.micro.copyWith(
                      fontSize: 12,
                      color: AppColors.danger,
                    ),
                  ),
                ),
              ],
            ),
          )
        else if (_isValid == true)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(
              children: [
                Icon(Icons.check_circle, size: 14, color: const Color(0xFF10B981)),
                const SizedBox(width: 4),
                Text(
                  'Looks good',
                  style: AppType.micro.copyWith(
                    fontSize: 12,
                    color: const Color(0xFF10B981),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }

  Widget? _buildSuffixIcon() {
    if (_isValid == true) {
      return Icon(Icons.check_circle, color: const Color(0xFF10B981).withValues(alpha: 0.6), size: 20);
    }
    if (_validationError != null) {
      return Icon(Icons.error_outline, color: AppColors.danger.withValues(alpha: 0.6), size: 20);
    }
    return widget.suffix;
  }

  OutlineInputBorder _border(Color color, {double width = 1.5}) {
    return OutlineInputBorder(
      borderRadius: BorderRadius.circular(12),
      borderSide: BorderSide(color: color, width: width),
    );
  }
}
