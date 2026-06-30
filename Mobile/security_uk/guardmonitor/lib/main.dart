import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'providers/app_providers.dart';
import 'screens/login/login_screen.dart';
import 'screens/home/home_screen.dart';
import 'services/connectivity_service.dart';
import 'services/deep_link_service.dart';
import 'services/notification_service.dart';
import 'services/push_messaging_service.dart';
import 'services/sync_flush_service.dart';
import 'theme/app_colors.dart';
import 'theme/app_theme.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // Initialise local notifications (timezone DB + plugin) early; permission is
  // requested contextually when a shift starts.
  NotificationService.init();
  // Initialise Firebase/FCM (best-effort — stays off if the platform isn't
  // configured yet, e.g. iOS before its plist is added). Token registration +
  // handlers start after sign-in (see IronlockApp).
  await PushMessaging.init();
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

class IronlockApp extends ConsumerStatefulWidget {
  const IronlockApp({super.key});

  @override
  ConsumerState<IronlockApp> createState() => _IronlockAppState();
}

class _IronlockAppState extends ConsumerState<IronlockApp> {
  @override
  void initState() {
    super.initState();
    // Start FCM (token registration + handlers) whenever the guard is signed in.
    // `listenManual(..., fireImmediately: true)` also covers the app being
    // **relaunched into an already-signed-in session** (e.g. reopened mid-shift):
    // a plain build-time `ref.listen` only fires on a fresh sign-in transition,
    // so on relaunch the token would never register and pushes (photo /
    // wakefulness) would never reach the device. `start()` is idempotent — repeat
    // calls just re-register the token, wiring the message listeners once.
    ref.listenManual(
      authProvider,
      (prev, next) {
        if (next.asData?.value == AuthState.signedIn) {
          PushMessaging.start(ref);
          // Phase 7: start the offline-sync flush engine for the session. It
          // drains any queued backlog now and flushes on every reconnect.
          // Idempotent — safe on relaunch into an existing session.
          ref.read(syncFlushServiceProvider).start(connectivityBoolStream());
        } else {
          ref.read(syncFlushServiceProvider).stop();
        }
      },
      fireImmediately: true,
    );

    // Begin listening for Shift Access Link (SSO) deep links — both the
    // cold-start link (app launched by the link) and warm taps while running.
    ref.read(deepLinkServiceProvider).start();
  }

  @override
  Widget build(BuildContext context) {
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
