/// Spacing scale (base 8px) and border-radius tokens.
/// Mirrors FLUTTER_SPEC.md §2.3 and §2.4.
abstract class AppSpacing {
  static const xs = 4.0; // icon/label gap
  static const sm = 8.0; // inner card gap, row gap
  static const md = 12.0; // compact card padding
  static const base = 16.0; // standard card / screen padding
  static const lg = 20.0; // comfortable card padding
  static const xl = 24.0; // screen horizontal / overlay padding
  static const xxl = 32.0; // section vertical spacing
}

abstract class AppRadius {
  static const card = 16.0;
  static const statTile = 13.0; // status icon strip tiles
  static const alert = 14.0;
  static const button = 16.0;
  static const chip = 20.0; // pill
  static const input = 12.0;
  static const keypad = 16.0;
  static const sheet = 24.0; // top corners
  static const logoCard = 22.0;
  static const toast = 16.0;
  static const camera = 12.0;
}
