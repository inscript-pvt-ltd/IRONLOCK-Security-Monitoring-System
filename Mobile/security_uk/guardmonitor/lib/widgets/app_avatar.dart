import 'package:flutter/material.dart';
import '../theme/app_colors.dart';
import '../theme/app_gradients.dart';
import '../theme/app_typography.dart';

class AppAvatar extends StatelessWidget {
  const AppAvatar({
    super.key,
    required this.initials,
    this.size = 72,
  });

  final String initials;
  final double size;

  @override
  Widget build(BuildContext context) {
    final fontSize = size * 0.36;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: AppGradients.avatar,
        border: Border.all(color: const Color(0x80D4AF37), width: 2),
        boxShadow: const [
          BoxShadow(color: Color(0x1FD4AF37), spreadRadius: 1),
          BoxShadow(
            color: Color(0x73000000),
            blurRadius: 18,
            offset: Offset(0, 4),
          ),
        ],
      ),
      alignment: Alignment.center,
      child: Text(
        initials,
        style: AppType.h1.copyWith(
          fontSize: fontSize,
          color: AppColors.gold,
          shadows: const [Shadow(color: Color(0x4D000000), blurRadius: 4)],
        ),
      ),
    );
  }

  /// Derives initials from an email address.
  /// `j.smith@example.com` → `JS`
  static String fromEmail(String email) {
    final local = email.split('@').first;
    final parts = local.split(RegExp(r'[._\-]'));
    if (parts.length >= 2) {
      return '${parts[0][0]}${parts[1][0]}'.toUpperCase();
    }
    return local.substring(0, local.length.clamp(0, 2)).toUpperCase();
  }
}
