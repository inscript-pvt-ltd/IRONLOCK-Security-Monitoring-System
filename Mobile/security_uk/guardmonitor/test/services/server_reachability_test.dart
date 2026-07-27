import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/services/api_client.dart';

/// Locks in the reachability signal that drives the captive-portal / dead-Wi-Fi
/// fallback. `isOnlineProvider` only reflects the OS interface; this provider
/// reflects whether the backend actually answered. The home poll gates the
/// offline welfare/photo scheduler on BOTH (see `home_screen._pollBackend`), so
/// a "connected but unreachable" phone still prompts the guard.
void main() {
  late ProviderContainer container;

  setUp(() => container = ProviderContainer());
  tearDown(() => container.dispose());

  test('defaults to reachable (optimistic until a request proves otherwise)', () {
    expect(container.read(serverReachableProvider), isTrue);
  });

  test('a null-response failure marks the server unreachable', () {
    container.read(serverReachableProvider.notifier).report(reachable: false);
    expect(container.read(serverReachableProvider), isFalse);
  });

  test('a later HTTP response (any status) marks it reachable again', () {
    final n = container.read(serverReachableProvider.notifier);
    n.report(reachable: false);
    expect(container.read(serverReachableProvider), isFalse);
    // A 4xx/5xx still carries a response → the box IS reachable, just rejecting.
    n.report(reachable: true);
    expect(container.read(serverReachableProvider), isTrue);
  });
}
