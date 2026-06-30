import 'package:flutter_test/flutter_test.dart';
import 'package:sqlite3/sqlite3.dart';

/// Regression guard for the Phase 7 encrypted queue: the `sqlite3` engine must
/// be the SQLCipher build (selected by `hooks.user_defines.sqlite3.source:
/// sqlcipher` in pubspec.yaml). If that define is removed, `PRAGMA cipher_version`
/// returns empty and the offline queue would silently fall back to plaintext —
/// this test fails loudly before that can ship.
void main() {
  test('sqlite3 engine is SQLCipher (PRAGMA cipher_version non-empty)', () {
    final db = sqlite3.openInMemory();
    addTearDown(db.close);
    db.execute("PRAGMA key = 'probe';");
    expect(
      db.select('PRAGMA cipher_version;'),
      isNotEmpty,
      reason: 'SQLCipher engine must be active for at-rest encryption',
    );
  });
}
