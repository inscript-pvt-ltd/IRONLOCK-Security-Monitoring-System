import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:guardmonitor/screens/login/login_screen.dart';

/// Widget-level coverage for the login screen's two interactive behaviours we
/// recently added: the Remember-me checkbox actually toggles, and a previously
/// saved email is pre-filled on open so the guard doesn't retype it.
final Map<String, String> _store = {};

void main() {
  const channel = MethodChannel('plugins.it_nomads.com/flutter_secure_storage');

  setUpAll(() {
    // Don't let AppType's Google Fonts try to hit the network during tests.
    GoogleFonts.config.allowRuntimeFetching = false;
  });

  setUp(() {
    TestWidgetsFlutterBinding.ensureInitialized();
    _store.clear();
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (call) async {
      final args = (call.arguments as Map?) ?? const {};
      final key = args['key'] as String?;
      switch (call.method) {
        case 'write':
          _store[key!] = args['value'] as String;
          return null;
        case 'read':
          return _store[key];
        case 'delete':
          _store.remove(key);
          return null;
        case 'readAll':
          return Map<String, String>.from(_store);
      }
      return null;
    });
  });

  tearDown(() {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, null);
  });

  Future<void> pumpLogin(WidgetTester tester) async {
    await tester.pumpWidget(
      const ProviderScope(child: MaterialApp(home: LoginScreen())),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('Remember-me defaults to ticked and toggles off on tap', (tester) async {
    await pumpLogin(tester);

    Checkbox box() => tester.widget<Checkbox>(find.byType(Checkbox));
    expect(box().value, isTrue);

    await tester.tap(find.text('Remember me'));
    await tester.pump();

    expect(box().value, isFalse);
  });

  testWidgets('pre-fills the saved email on open', (tester) async {
    _store['ironlock_guard_email'] = 'j.smith@ironlock.co.uk';

    await pumpLogin(tester);

    expect(find.text('j.smith@ironlock.co.uk'), findsOneWidget);
  });
}
