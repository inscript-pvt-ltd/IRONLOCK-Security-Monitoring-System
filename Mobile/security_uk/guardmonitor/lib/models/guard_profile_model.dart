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
    return GuardProfileModel(
      id: json['id'] as String,
      employeeCode: json['employee_code'] as String,
      firstName: json['first_name'] as String,
      lastName: json['last_name'] as String,
      username: json['username'] as String,
      email: json['email'] as String,
      employmentStatus: json['employment_status'] as String? ?? 'active',
      phone: json['phone'] as String?,
      siaLicenceNumber: json['sia_licence_number'] as String?,
      siaLicenceExpiry: json['sia_licence_expiry'] as String?,
      siaLicenceType: json['sia_licence_type'] as String?,
    );
  }
}
