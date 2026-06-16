import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/app_providers.dart';
import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';
import '../widgets/app_button.dart';

class PrivacyNoticeOverlay extends ConsumerStatefulWidget {
  const PrivacyNoticeOverlay({super.key, this.onAccepted});
  final VoidCallback? onAccepted;

  @override
  ConsumerState<PrivacyNoticeOverlay> createState() =>
      _PrivacyNoticeOverlayState();
}

class _PrivacyNoticeOverlayState extends ConsumerState<PrivacyNoticeOverlay>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  late final Animation<double> _fade;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 250),
    )..forward();
    _fade = CurvedAnimation(parent: _ctrl, curve: Curves.ease);
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  void _accept() {
    ref.read(privacyAcceptedProvider.notifier).accept();
    _ctrl.reverse().then((_) {
      widget.onAccepted?.call();
      if (mounted) Navigator.of(context).pop();
    });
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _fade,
      child: Scaffold(
        body: Container(
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [Color(0xFA07111F), Color(0xFC04090F)],
            ),
          ),
          child: SafeArea(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.xl, 32, AppSpacing.xl, AppSpacing.xl,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Privacy Notice', style: AppType.h2),
                  const SizedBox(height: 4),
                  Text('Please read before your first shift',
                      style: AppType.caption),
                  const SizedBox(height: AppSpacing.xl),

                  _Section(
                    title: 'What we collect',
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: const [
                        _Bullet('GPS location while your shift is active'),
                        _Bullet('Wakefulness check responses (timing and codes)'),
                        _Bullet('Photo captures submitted during your shift'),
                        _Bullet('Device connectivity status (online/offline)'),
                      ],
                    ),
                  ),

                  _Section(
                    title: 'How it is used',
                    child: Text(
                      'All data collected during your shift is used solely for '
                      'operational security purposes — to verify your location, '
                      'welfare, and compliance with your patrol assignment. '
                      'Data is transmitted securely to Ironlock supervisors and '
                      'is not shared with third parties.',
                      style: AppType.body.copyWith(color: AppColors.muted, fontSize: 14),
                    ),
                  ),

                  _Section(
                    title: 'Your rights',
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Under UK GDPR you have the right to access, correct, '
                          'or request deletion of your personal data. To exercise '
                          'these rights contact your supervisor or reach us at:',
                          style: AppType.body.copyWith(
                            color: AppColors.muted,
                            fontSize: 14,
                          ),
                        ),
                        const SizedBox(height: AppSpacing.sm),
                        Text(
                          'privacy@ironlock.co.uk',
                          style: AppType.label.copyWith(color: AppColors.gold),
                        ),
                      ],
                    ),
                  ),

                  const SizedBox(height: AppSpacing.xl),
                  AppButton(
                    label: 'I Understand — Continue',
                    onPressed: _accept,
                  ),
                  const SizedBox(height: AppSpacing.base),
                  Center(
                    child: Text(
                      'Ironlock Civil Engineering & Security · UK GDPR compliant',
                      style: AppType.micro.copyWith(color: AppColors.faint),
                      textAlign: TextAlign.center,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section({required this.title, required this.child});
  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.xl),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title,
              style: AppType.label.copyWith(
                color: AppColors.gold,
                fontSize: 13,
              )),
          const SizedBox(height: AppSpacing.sm),
          child,
        ],
      ),
    );
  }
}

class _Bullet extends StatelessWidget {
  const _Bullet(this.text);
  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('• ', style: AppType.body.copyWith(color: AppColors.muted)),
          Expanded(
            child: Text(
              text,
              style: AppType.body.copyWith(color: AppColors.muted, fontSize: 14),
            ),
          ),
        ],
      ),
    );
  }
}
