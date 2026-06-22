import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'providers/app_providers.dart';
import 'screens/login/login_screen.dart';
import 'screens/home/home_screen.dart';
import 'services/notification_service.dart';
import 'theme/app_colors.dart';
import 'theme/app_theme.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  // Initialise local notifications (timezone DB + plugin) early; permission is
  // requested contextually when a shift starts.
  NotificationService.init();
  SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: Colors.transparent,
    statusBarIconBrightness: Brightness.light,
  ));
  runApp(const ProviderScope(child: IronlockApp()));
}

class IronlockApp extends ConsumerWidget {
  const IronlockApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final authValue = ref.watch(authProvider);

    return MaterialApp(
      title: 'Ironlock Guard Monitor',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.dark,
      builder: (context, child) {
        final mq = MediaQuery.of(context);
        return MediaQuery(
          data: mq.copyWith(
            textScaler: mq.textScaler.clamp(
              minScaleFactor: 1.0,
              maxScaleFactor: 1.1,
            ),
          ),
          // The UI is designed phone-first. On wide screens (tablets, landscape,
          // desktop/web) stretching it edge-to-edge looks unintentional, so we
          // cap the content width and letterbox the sides with the app bg.
          // This is a no-op on phones: any screen narrower than the cap fills
          // the width exactly as before.
          child: ColoredBox(
            color: AppColors.bg,
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 560),
                child: child!,
              ),
            ),
          ),
        );
      },
      home: authValue.when(
        data: (auth) => AnimatedSwitcher(
          duration: const Duration(milliseconds: 220),
          transitionBuilder: (child, animation) {
            return FadeTransition(
              opacity: animation,
              child: SlideTransition(
                position: Tween<Offset>(
                  begin: const Offset(0, 0.04),
                  end: Offset.zero,
                ).animate(
                    CurvedAnimation(parent: animation, curve: Curves.ease)),
                child: child,
              ),
            );
          },
          child: auth == AuthState.signedIn
              ? const HomeScreen(key: ValueKey('home'))
              : const LoginScreen(key: ValueKey('login')),
        ),
        loading: () => const _SplashView(),
        error: (_, _) => const LoginScreen(key: ValueKey('login')),
      ),
    );
  }
}

class _SplashView extends StatelessWidget {
  const _SplashView();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      backgroundColor: AppColors.bg,
      body: Center(
        child: CircularProgressIndicator(
          color: AppColors.gold,
          strokeWidth: 2,
        ),
      ),
    );
  }
}
