abstract class ApiConfig {
  // Dev: Android emulator → 10.0.2.2, iOS simulator → 127.0.0.1, real device → your machine IP
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://127.0.0.1:8000/api/mobile/v1',
  );

  static const Duration connectTimeout = Duration(seconds: 10);
  static const Duration receiveTimeout = Duration(seconds: 30);

  // Health
  static const String status = '/status';

  // Auth
  static const String login = '/auth/login';
  static const String refresh = '/auth/refresh';
  static const String logout = '/auth/logout';
  static const String me = '/me';

  // Shifts
  static const String shiftCurrent = '/shifts/current';
  static String shiftStart(String id) => '/shifts/$id/start';
  static String shiftEnd(String id) => '/shifts/$id/end';

  // GPS
  static String shiftLocations(String id) => '/shifts/$id/locations';

  // Photo verification
  static String shiftPhotos(String id) => '/shifts/$id/photos';
  static const String photoPending = '/photos/pending';

  // Wakefulness
  static String wakefulnessRespond(String checkId) => '/wakefulness/$checkId/respond';
  static const String welfarePending = '/welfare/pending';

  // Alerts
  static const String alerts = '/alerts';
  static String alertDismiss(String id) => '/alerts/$id/dismiss';

  // HMAC secret shared with backend (extra anti-replay field, not part of the
  // official contract — kept alongside the spec's required photo fields)
  static const String photoHmacSecret = 'IRONLOCK_PHOTO_SECRET_v1';
}
