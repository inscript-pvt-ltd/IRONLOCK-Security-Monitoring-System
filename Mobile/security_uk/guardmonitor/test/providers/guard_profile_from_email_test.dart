import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/providers/auth_provider.dart';

/// Guards the email→display-name fallback (used when `/me` is unavailable at
/// sign-in). The leading/empty-separator cases used to throw `RangeError`
/// (audit L1); these lock in that they now degrade gracefully instead.
void main() {
  group('GuardProfile.fromEmail', () {
    test('derives name + initials from a normal email', () {
      final p = GuardProfile.fromEmail('john.doe@ironlock.co.uk');
      expect(p.name, 'John Doe');
      expect(p.initials, 'JD');
    });

    test('handles a single-token local part', () {
      final p = GuardProfile.fromEmail('jsmith@ironlock.co.uk');
      expect(p.name, 'Jsmith');
      expect(p.initials, 'JS');
    });

    test('does not crash on a leading separator (was RangeError)', () {
      final p = GuardProfile.fromEmail('_john.doe@ironlock.co.uk');
      expect(p.name, 'John Doe');
      expect(p.initials, 'JD');
    });

    test('does not crash on doubled separators', () {
      final p = GuardProfile.fromEmail('john..doe@ironlock.co.uk');
      expect(p.name, 'John Doe');
      expect(p.initials, 'JD');
    });

    test('falls back to a neutral placeholder when the local part is empty', () {
      final p = GuardProfile.fromEmail('@ironlock.co.uk');
      expect(p.name, 'Guard');
      expect(p.initials, 'G');
    });
  });
}
