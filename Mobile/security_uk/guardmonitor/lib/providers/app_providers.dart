/// Barrel file. The providers were split by topic into the files below to keep
/// each concern small and navigable (matching `alerts_provider.dart` and
/// `wakefulness_provider.dart`). Re-exporting them here means every existing
/// `import '.../providers/app_providers.dart'` keeps resolving every symbol
/// exactly as before — no consumer needed to change.
///
///  - auth_provider.dart   → AuthNotifier/authProvider, GuardProfile/guardProfileProvider
///  - shift_provider.dart  → CurrentShift*, Shift*
///  - photo_provider.dart  → Photo*, PendingPhoto*
///  - ui_providers.dart    → zone, battery, privacy, activeTab
library;

export 'auth_provider.dart';
export 'shift_access_provider.dart';
export 'shift_provider.dart';
export 'photo_provider.dart';
export 'photo_review_provider.dart';
export 'photo_schedule_provider.dart';
export 'ui_providers.dart';
