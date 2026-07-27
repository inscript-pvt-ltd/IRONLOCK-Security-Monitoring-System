class GuardProfileModel {
  const GuardProfileModel({
    required this.id,
    required this.employeeCode,
    required this.firstName,
    required this.lastName,
    required this.username,
    required this.email,
    required this.employmentStatus,
    this.phone,
    this.siaLicenceNumber,
    this.siaLicenceExpiry,
    this.siaLicenceType,
  });

  final String id;
  final String employeeCode;
  final String firstName;
  final String lastName;
  final String username;
  final String email;
  final String employmentStatus;
  final String? phone;
  final String? siaLicenceNumber;
  final String? siaLicenceExpiry;
  final String? siaLicenceType;

  String get fullName => '$firstName $lastName';

  factory GuardProfileModel.fromJson(Map<String, dynamic> json) {
    // Default every required string rather than hard-casting: an incomplete guard
    // record (a null last_name, a missing username) must NOT throw here — this
    // parses the login/redeem response, and a throw would fail the whole sign-in
    // with a cryptic TypeError instead of just showing a blank field.
    String s(String key) => json[key] as String? ?? '';
    return GuardProfileModel(
      id: s('id'),
      employeeCode: s('employee_code'),
      firstName: s('first_name'),
      lastName: s('last_name'),
      username: s('username'),
      email: s('email'),
      employmentStatus: json['employment_status'] as String? ?? 'active',
      phone: json['phone'] as String?,
      siaLicenceNumber: json['sia_licence_number'] as String?,
      siaLicenceExpiry: json['sia_licence_expiry'] as String?,
      siaLicenceType: json['sia_licence_type'] as String?,
    );
  }
}
