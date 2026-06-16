import 'package:flutter/material.dart';
import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';
import '../theme/app_gradients.dart';

class AppPushToast extends StatefulWidget {
  const AppPushToast({
    super.key,
    required this.title,
    required this.subtitle,
    this.time,
    this.onTap,
    this.logo,
  });

  final String title;
  final String subtitle;
  final String? time;
  final VoidCallback? onTap;
  final Widget? logo;

  @override
  State<AppPushToast> createState() => _AppPushToastState();
}

class _AppPushToastState extends State<AppPushToast>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  late final Animation<Offset> _slide;
  late final Animation<double> _fade;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 380),
    );
    _slide = Tween<Offset>(
      begin: const Offset(0, -1.2),
      end: Offset.zero,
    ).animate(CurvedAnimation(
      parent: _ctrl,
      curve: const Cubic(0.32, 0.72, 0.0, 1.0),
    ));
    _fade = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(parent: _ctrl, curve: Curves.easeIn),
    );
    _ctrl.forward();
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  void dismiss() {
    _ctrl.reverse().then((_) => widget.onTap?.call());
  }

  @override
  Widget build(BuildContext context) {
    return SlideTransition(
      position: _slide,
      child: FadeTransition(
        opacity: _fade,
        child: GestureDetector(
          onTap: dismiss,
          child: Container(
            margin: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
            padding: const EdgeInsets.all(AppSpacing.md),
            decoration: BoxDecoration(
              color: const Color(0xF80C1426),
              borderRadius: BorderRadius.circular(AppRadius.toast),
              border: Border.all(color: const Color(0x14FFFFFF)),
              boxShadow: const [
                BoxShadow(
                  color: Color(0xB2000000),
                  blurRadius: 40,
                  offset: Offset(0, 8),
                ),
              ],
            ),
            child: Row(
              children: [
                _AppIcon(logo: widget.logo),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(widget.title, style: AppType.captionSemi.copyWith(
                        fontSize: 13,
                        letterSpacing: 0.01,
                      )),
                      const SizedBox(height: 2),
                      Text(widget.subtitle, style: AppType.micro.copyWith(
                        color: AppColors.subtle,
                        fontWeight: FontWeight.w400,
                      )),
                    ],
                  ),
                ),
                if (widget.time != null) ...[
                  const SizedBox(width: AppSpacing.sm),
                  Text(widget.time!, style: AppType.micro.copyWith(
                    fontSize: 10,
                    color: const Color(0xFF4A5E78),
                    fontWeight: FontWeight.w400,
                  )),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _AppIcon extends StatelessWidget {
  const _AppIcon({this.logo});
  final Widget? logo;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 36,
      height: 36,
      decoration: BoxDecoration(
        gradient: AppGradients.logoCard,
        borderRadius: BorderRadius.circular(9),
        border: Border.all(color: const Color(0x4DD4AF37)),
      ),
      alignment: Alignment.center,
      child: logo ?? const Icon(Icons.shield, color: AppColors.gold, size: 20),
    );
  }
}
