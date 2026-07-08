abstract class ApiConfig {
  // Environment + base URL come from compile-time config, not hardcoded. Pass a
  // config file per environment:
  //   flutter run        --dart-define-from-file=config/dev.json
  //   flutter build apk  --dart-define-from-file=config/prod.json
  // These values are NOT secrets (the base URL is visible in network traffic
  // anyway) — config files just keep environment switching out of source. Real
  // secrets (tokens, hmac_secret) live in secure storage, never here. The
  // defaults below keep a plain `flutter run` working (points at prod).
  static const String env = String.fromEnvironment('ENV', defaultValue: 'prod');

  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://dashboard.ironlock.co.uk/api/mobile/v1',
  );

  /// True in production builds — gate debug-only behaviour (verbose logging,
  /// diagnostic banners) on `!isProduction` so it can never ship enabled.
  static bool get isProduction => env == 'prod';

  static const Duration connectTimeout = Duration(seconds: 10);
  static const Duration receiveTimeout = Duration(seconds: 30);

  // Health
  static const String status = '/status';

  // Auth
  static const String login = '/auth/login';
  static const String refresh = '/auth/refresh';
  static const String logout = '/auth/logout';
  // Shift Access Link (SSO): redeem a one-time passwordless link. Public, no auth
  // header — it mints the session, returning the same payload as /auth/login.
  static const String shiftAccess = '/auth/shift-access';
  static const String me = '/me';

  // Shifts
  static const String shiftCurrent = '/shifts/current';
  static String shiftStart(String id) => '/shifts/$id/start';
  static String shiftEnd(String id) => '/shifts/$id/end';
  // Early-end approval flow: the guard requests an early end and must wait for
  // a supervisor/admin to approve before the shift can actually be ended.
  static String shiftEarlyEndRequest(String id) => '/shifts/$id/early-end-request';

  // GPS
  static String shiftLocations(String id) => '/shifts/$id/locations';

  // Photo verification
  static String shiftPhotos(String id) => '/shifts/$id/photos';
  // Per-shift pending poll — carries request_id + nonce_value for an online
  // (server-initiated) photo check.
  static String shiftPhotosPending(String id) => '/shifts/$id/photos/pending';
  // Supervisor review outcomes (APPROVED/REJECTED + note) for submitted photos.
  // Polled as the reliable fallback to the PHOTO_REVIEWED push.
  static String shiftPhotosReviews(String id) => '/shifts/$id/photos/reviews';
  // Phase 7 offline pool: pre-fetch a batch of single-use OFFLINE_POOL nonces
  // (15-min TTL) while online, so photos can still be captured + signed offline.
  static String shiftNoncesPrefetch(String id) => '/shifts/$id/nonces/prefetch';

  // Wakefulness
  static String wakefulnessRespond(String checkId) => '/wakefulness/$checkId/respond';
  // Confirms an online wakefulness push actually arrived (Phase 6). Online-only.
  static String wakefulnessReceived(String checkId) => '/wakefulness/$checkId/received';
  // Non-contractual interim poll — only the local mock serves this. The real
  // backend drives wakefulness from the TOTP schedule returned at shift start.
  static const String welfarePending = '/welfare/pending';

  // Push
  static const String pushToken = '/devices/push-token';

  // Alerts
  static const String alerts = '/alerts';
  static String alertDismiss(String id) => '/alerts/$id/dismiss';
}
