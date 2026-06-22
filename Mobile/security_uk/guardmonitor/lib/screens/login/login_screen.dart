import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../models/api_response.dart';
import '../../providers/app_providers.dart';
import '../../services/secure_storage_service.dart';
import '../../theme/app_colors.dart';
import '../../theme/app_gradients.dart';
import '../../theme/app_spacing.dart';
import '../../theme/app_typography.dart';
import '../../theme/responsive.dart';
import '../../widgets/app_button.dart';
import '../../widgets/app_input.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _emailCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  bool _obscure = true;
  bool _rememberMe = true;
  bool _loading = false;
  String? _error;
  bool _windowExpired = false;

  @override
  void initState() {
    super.initState();
    _loadSavedEmail();
  }

  Future<void> _loadSavedEmail() async {
    final email = await SecureStorageService.getSavedEmail();
    if (email != null && mounted) {
      setState(() => _emailCtrl.text = email);
    }
  }

  @override
  void dispose() {
    _emailCtrl.dispose();
    _passCtrl.dispose();
    super.dispose();
  }

  bool get _canSubmit =>
      _emailCtrl.text.trim().isNotEmpty &&
      _passCtrl.text.isNotEmpty &&
      !_loading;

  Future<void> _signIn() async {
    if (!_canSubmit) return;

    setState(() {
      _loading = true;
      _error = null;
      _windowExpired = false;
    });

    try {
      await ref.read(authProvider.notifier).signIn(
        _emailCtrl.text.trim(),
        _passCtrl.text,
        rememberMe: _rememberMe,
      );
      // authProvider state change triggers navigation in main.dart automatically
    } on DioException catch (e) {
      if (!mounted) return;
      final apiError = ApiError.fromDioException(e);
      setState(() {
        _loading = false;
        _error = apiError.code == 'ACCOUNT_LOCKED'
            ? '⚠ Account locked. Please contact your supervisor.'
            : '⚠ ${apiError.message}';
        _windowExpired = apiError.code == 'LOGIN_WINDOW_CLOSED' &&
            apiError.details?['reason'] == 'expired';
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = '⚠ Connection error. Please check your network and try again.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg,
      body: Stack(
        children: [
          // Background gradient
          Positioned.fill(
            child: DecoratedBox(decoration: const BoxDecoration(gradient: AppGradients.background)),
          ),
          // Content
          SafeArea(
            child: _loading ? _buildLoading() : _buildFormLayout(),
          ),
        ],
      ),
    );
  }

  Widget _buildFormLayout() {
    return ListenableBuilder(
      listenable: Listenable.merge([_emailCtrl, _passCtrl]),
      builder: (context, _) {
        return Column(
          children: [
            // Scrollable content
            Expanded(
              child: SingleChildScrollView(
                padding: EdgeInsets.fromLTRB(
                  context.s(AppSpacing.xl),
                  context.s(36),
                  context.s(AppSpacing.xl),
                  context.s(AppSpacing.base),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    // Logo
                    _LogoCard(),
                    SizedBox(height: context.s(24)),

                    // Email
                    AppInput(
                      controller: _emailCtrl,
                      label: 'Employee ID or Email',
                      hint: 'guard@ironlock.co.uk',
                      keyboardType: TextInputType.emailAddress,
                      textInputAction: TextInputAction.next,
                    ),
                    SizedBox(height: context.s(16)),

                    // Password
                    AppInput(
                      controller: _passCtrl,
                      label: 'Passcode',
                      hint: '8-digit code',
                      obscureText: _obscure,
                      keyboardType: TextInputType.number,
                      textInputAction: TextInputAction.done,
                      onSubmitted: (_canSubmit) ? (_) => _signIn() : null,
                      suffix: IconButton(
                        icon: Icon(
                          _obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined,
                          color: AppColors.subtle,
                          size: context.s(20),
                        ),
                        onPressed: () => setState(() => _obscure = !_obscure),
                      ),
                    ),

                    // Error box
                    if (_error != null) ...[
                      SizedBox(height: context.s(AppSpacing.md)),
                      _MessageBox(message: _error!, isError: true),
                      if (_windowExpired) ...[
                        SizedBox(height: context.s(8)),
                        _MessageBox(
                          message: 'Once your supervisor authorises your access, tap Sign In again to retry.',
                          isError: false,
                        ),
                      ],
                    ],

                    SizedBox(height: context.s(AppSpacing.base)),

                    // Remember Me
                    GestureDetector(
                      onTap: () => setState(() => _rememberMe = !_rememberMe),
                      behavior: HitTestBehavior.opaque,
                      child: Row(
                        children: [
                          SizedBox(
                            width: context.s(20),
                            height: context.s(20),
                            child: Checkbox(
                              value: _rememberMe,
                              onChanged: (v) => setState(() => _rememberMe = v ?? false),
                              activeColor: AppColors.gold,
                              checkColor: AppColors.bg,
                              side: const BorderSide(color: AppColors.border, width: 1.5),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(4),
                              ),
                            ),
                          ),
                          SizedBox(width: context.s(AppSpacing.sm)),
                          Text('Remember me', style: AppType.label.copyWith(color: AppColors.muted)),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),

            // Fixed bottom section with Sign In button and Footer
            Padding(
              padding: EdgeInsets.fromLTRB(
                context.s(AppSpacing.xl),
                context.s(AppSpacing.base),
                context.s(AppSpacing.xl),
                context.s(AppSpacing.xl),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // Sign In button
                  AppButton(
                    label: 'Sign In',
                    variant: AppButtonVariant.primary,
                    onPressed: _canSubmit ? _signIn : null,
                    enabled: _canSubmit,
                  ),
                  SizedBox(height: context.s(AppSpacing.lg)),

                  // Footer
                  _Footer(),
                ],
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _buildLoading() {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          _buildMinimalLoader(),
          const SizedBox(height: AppSpacing.lg),
          Text('Signing in…', style: AppType.label.copyWith(color: AppColors.muted)),
        ],
      ),
    );
  }

  Widget _buildMinimalLoader() {
    return SizedBox(
      width: 32,
      height: 32,
      child: AnimatedBuilder(
        animation: AlwaysStoppedAnimation(0),
        builder: (context, child) {
          return CustomPaint(
            painter: _LoaderPainter(),
            size: const Size(32, 32),
          );
        },
      ),
    );
  }
}

// ── Logo card ─────────────────────────────────────────────────────────────

class _LogoCard extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Image.asset(
      'assets/images/logo.png',
      height: context.s(80),
      fit: BoxFit.contain,
      errorBuilder: (_, _, _) => Text(
        'IL',
        style: AppType.h2.copyWith(
          fontSize: context.sp(20),
          fontWeight: FontWeight.w800,
          color: const Color(0xFFB8962A),
        ),
      ),
    );
  }
}

// ── Message box ───────────────────────────────────────────────────────────

class _MessageBox extends StatelessWidget {
  const _MessageBox({required this.message, this.isError = false});
  final String message;
  final bool isError;

  @override
  Widget build(BuildContext context) {
    final color = isError ? AppColors.danger : AppColors.warning;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.base,
        vertical: AppSpacing.md,
      ),
      decoration: BoxDecoration(
        color: isError ? AppColors.dangerBg : AppColors.warningBg,
        borderRadius: BorderRadius.circular(AppRadius.input),
        border: Border.all(color: color.withValues(alpha: 0.3)),
      ),
      child: Text(
        message,
        style: AppType.caption.copyWith(fontSize: context.sp(13), color: color),
      ),
    );
  }
}

// ── Footer ────────────────────────────────────────────────────────────────

class _Footer extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text(
          'Privacy Notice · Ironlock Civil Engineering & Security',
          style: AppType.micro.copyWith(color: AppColors.faint),
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: 4),
        Text(
          'v4.0 · UK',
          style: AppType.micro.copyWith(color: AppColors.faint),
        ),
      ],
    );
  }
}

// ── Minimal loader painter ────────────────────────────────────────────

class _LoaderPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    final radius = size.width / 2.5;

    // Animated arc (simplified - shows rotating indicator)
    final paint = Paint()
      ..color = AppColors.gold
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round
      ..style = PaintingStyle.stroke;

    canvas.drawArc(
      Rect.fromCircle(center: center, radius: radius),
      -3.14159 / 2,
      3.14159 * 1.2,
      false,
      paint,
    );
  }

  @override
  bool shouldRepaint(_LoaderPainter oldDelegate) => true;
}
