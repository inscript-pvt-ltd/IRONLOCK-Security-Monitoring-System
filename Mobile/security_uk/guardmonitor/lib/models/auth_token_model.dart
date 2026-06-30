import 'guard_profile_model.dart';

class AuthTokenModel {
  const AuthTokenModel({
    required this.tokenType,
    required this.accessToken,
    required this.expiresAt,
    this.refreshToken,
    this.hmacSecret,
    this.guard,
  });

  final String tokenType;
  final String accessToken;
  final DateTime expiresAt;
  final String? refreshToken;

  /// Per-guard/session 64-char hex shared key returned on every login. Used to
  /// sign photo uploads (HMAC-SHA256); stored in secure storage, never logged.
  /// Null when the backend doesn't issue one (e.g. the local mock).
  final String? hmacSecret;
  final GuardProfileModel? guard;

  /// `POST /auth/login` response — always includes `refresh_token` and `guard`.
  factory AuthTokenModel.fromJson(Map<String, dynamic> json) {
    return AuthTokenModel(
      tokenType: json['token_type'] as String? ?? 'Bearer',
      accessToken: json['access_token'] as String,
      refreshToken: json['refresh_token'] as String?,
      hmacSecret: json['hmac_secret'] as String?,
      expiresAt: DateTime.parse(json['expires_at'] as String),
      guard: json['guard'] != null
          ? GuardProfileModel.fromJson(json['guard'] as Map<String, dynamic>)
          : null,
    );
  }
}
