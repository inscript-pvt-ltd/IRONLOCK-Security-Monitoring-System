import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/providers/wakefulness_provider.dart';

/// Wakefulness codes are ALWAYS 4 digits, online and offline. A server that
/// drops a leading zero (sends `472` where the notification shows `0472`) must
/// be normalised to 4 — never shown or accepted as a 3-digit code.
void main() {
  void enter(WakefulnessNotifier n, String code) {
    for (final d in code.split('')) {
      n.addDigit(d);
    }
  }

  test('an unpadded 3-digit push code is zero-padded to 4', () {
    final c = ProviderContainer();
    addTearDown(c.dispose);
    final n = c.read(wakefulnessProvider.notifier);
    n.trigger('chk-3', '472');
    expect(c.read(wakefulnessProvider).code, '0472');
  });

  test('a 2-digit code is padded to 4', () {
    final c = ProviderContainer();
    addTearDown(c.dispose);
    final n = c.read(wakefulnessProvider.notifier);
    n.trigger('chk-2', '48');
    expect(c.read(wakefulnessProvider).code, '0048');
  });

  test('an offline TOTP code is padded too', () {
    final c = ProviderContainer();
    addTearDown(c.dispose);
    final n = c.read(wakefulnessProvider.notifier);
    n.triggerLocal('chk-off', '9', windowReference: 1);
    expect(c.read(wakefulnessProvider).code, '0009');
  });

  test('entry is always capped at exactly 4 digits', () {
    final c = ProviderContainer();
    addTearDown(c.dispose);
    final n = c.read(wakefulnessProvider.notifier);
    n.trigger('chk-4', '4821');
    enter(n, '48215'); // try to type a 5th
    expect(c.read(wakefulnessProvider).entry, '4821');
  });
}
