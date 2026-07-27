import 'package:flutter/services.dart' show rootBundle;
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/screens/legal/legal_screen.dart';

/// Guards that the bundled legal docs stay wired into the app: if the asset is
/// dropped from pubspec or renamed, the in-app viewer (and the Play privacy-policy
/// source) silently breaks — this fails loudly instead.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('both LegalDoc assets load and carry their expected content', () async {
    final privacy = await rootBundle.loadString(LegalDoc.privacy.asset);
    expect(privacy, contains('IronLock Privacy Policy'));
    expect(privacy, contains('UK GDPR'));
    expect(privacy, contains('## 10. Your Rights'));

    final terms = await rootBundle.loadString(LegalDoc.terms.asset);
    expect(terms, contains('Terms & Conditions'));
    expect(terms, contains('Governing Law'));
  });

  test('LegalDoc exposes both docs with titles + asset paths', () {
    expect(LegalDoc.values, hasLength(2));
    expect(LegalDoc.privacy.title, 'Privacy Policy');
    expect(LegalDoc.privacy.asset, 'assets/legal/privacy_policy.md');
    expect(LegalDoc.terms.title, 'Terms');
    expect(LegalDoc.terms.asset, 'assets/legal/terms.md');
  });
}
