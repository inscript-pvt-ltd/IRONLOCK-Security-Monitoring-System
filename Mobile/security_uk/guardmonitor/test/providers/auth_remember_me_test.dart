import 'package:dio/dio.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/models/auth_token_model.dart';
import 'package:guardmonitor/models/current_shift_model.dart';
import 'package:guardmonitor/providers/app_providers.dart';
import 'package:guardmonitor/services/auth_service.dart';
import 'package:guardmonitor/services/shift_service.dart';

/// "Remember me" is a privacy control: when the guard unticks it on a shared
/// device, the long-lived refresh token and their email must NOT be persisted,
/// so the session can't be silently restored and the next guard's login screen
/// starts blank. The checkbox used to be cosmetic — these tests make the
/// rememberMe branch in AuthNotifier.signIn the thing we actually verify.

/// In-memory stand-in for the flutter_secure_storage platform channel so the
/// real SecureStorageService writes/reads work under `flutter test`.
final Map<String, String> _store = {};

/// Test seam: the real AuthNotifier.build() wires the JWT interceptor's
/// forced-sign-out callback (a side effect on another provider) which is
/// irrelevant to — and noisy for — testing the signIn() persistence logic.
/// We keep the real signIn() and only neutralise build().
class _TestAuthNotifier extends AuthNotifier {
  @override
  Future<AuthState> build() async => AuthState.signedOut;
}

class _FakeAuthService extends AuthService {
  _FakeAuthService() : super(Dio());

  @override
  Future<AuthTokenModel> login(String identifier, String password) async {
    return AuthTokenModel(
      tokenType: 'Bearer',
      accessToken: 'access-123',
      refreshToken: 'refresh-456',
      expiresAt: DateTime.utc(2026, 6, 18, 11),
    );
  }
}

class _FakeShiftService extends ShiftService {
  _FakeShiftService() : super(Dio());
  @override
  Future<CurrentShiftModel?> fetchCurrent() async => null;
}

void main() {
  const channel = MethodChannel('plugins.it_nomads.com/flutter_secure_storage');

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
        case 'containsKey':
          return _store.containsKey(key);
        case 'readAll':
          return Map<String, String>.from(_store);
        case 'deleteAll':
          _store.clear();
          return null;
      }
      return null;
    });
  });

  tearDown(() {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, null);
  });

  ProviderContainer makeContainer() {
    final container = ProviderContainer(overrides: [
      authProvider.overrideWith(_TestAuthNotifier.new),
      authServiceProvider.overrideWithValue(_FakeAuthService()),
      shiftServiceProvider.overrideWithValue(_FakeShiftService()),
    ]);
    addTearDown(container.dispose);
    return container;
  }

  test('rememberMe ON persists the refresh token, email, and access token', () async {
    final container = makeContainer();
    await container.read(authProvider.future); // settle the no-op build()

    await container
        .read(authProvider.notifier)
        .signIn('a.guard@ironlock.co.uk', 'pw', rememberMe: true);

    expect(_store['ironlock_auth_token'], 'access-123');
    expect(_store['ironlock_refresh_token'], 'refresh-456');
    expect(_store['ironlock_guard_email'], 'a.guard@ironlock.co.uk');
  });

  test('rememberMe OFF keeps the access token but withholds refresh token + email', () async {
    final container = makeContainer();
    await container.read(authProvider.future);

    await container
        .read(authProvider.notifier)
        .signIn('a.guard@ironlock.co.uk', 'pw', rememberMe: false);

    // Access token is still needed for the live session...
    expect(_store['ironlock_auth_token'], 'access-123');
    // ...but nothing that would let the session be silently restored later.
    expect(_store.containsKey('ironlock_refresh_token'), isFalse);
    expect(_store.containsKey('ironlock_guard_email'), isFalse);
  });
}
