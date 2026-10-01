class User {
  final String id;
  final String name;
  final String email;
  final String? phone;
  final String role;
  final String? employeeId;
  final String? department;
  final String? avatar;
  final List<String> assignedSiteIds;

  User({
    required this.id,
    required this.name,
    required this.email,
    this.phone,
    required this.role,
    this.employeeId,
    this.department,
    this.avatar,
    this.assignedSiteIds = const [],
  });

  String get initials {
    if (name.trim().isEmpty) return 'ST';
    final parts = name.trim().split(RegExp(r'\s+'));
    if (parts.length >= 2 && parts[0].isNotEmpty && parts[1].isNotEmpty) {
      return '${parts[0][0]}${parts[1][0]}'.toUpperCase();
    }
    return parts[0].isNotEmpty ? parts[0][0].toUpperCase() : 'ST';
  }

  factory User.fromJson(Map<String, dynamic> json) {
    List<String> sites = [];
    if (json['assignedSites'] != null) {
      if (json['assignedSites'] is List) {
        sites = (json['assignedSites'] as List).map((e) {
          if (e is Map) return e['_id']?.toString() ?? '';
          return e.toString();
        }).where((id) => id.isNotEmpty).toList();
      }
    }

    return User(
      id: json['_id']?.toString() ?? json['id']?.toString() ?? '',
      name: json['name'] ?? json['full_name'] ?? json['username'] ?? '',
      email: json['email'] ?? '',
      phone: json['phone']?.toString(),
      role: json['role'] ?? 'supervisor',
      employeeId: json['employeeId']?.toString() ?? json['employee_id']?.toString(),
      department: json['department'] ?? 'Site Operations',
      avatar: json['avatar'],
      assignedSiteIds: sites,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'email': email,
      'phone': phone,
      'role': role,
      'employeeId': employeeId,
      'department': department,
      'avatar': avatar,
      'assignedSites': assignedSiteIds,
    };
  }
}
