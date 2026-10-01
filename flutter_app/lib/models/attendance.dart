import 'site.dart';

class PunchDetail {
  final DateTime timestamp;
  final double latitude;
  final double longitude;
  final double? accuracy;
  final String address;
  final String photoUrl;
  final String notes;
  final bool isWithinGeofence;
  final int? distanceFromSiteMeters;

  PunchDetail({
    required this.timestamp,
    required this.latitude,
    required this.longitude,
    this.accuracy,
    required this.address,
    required this.photoUrl,
    this.notes = '',
    this.isWithinGeofence = true,
    this.distanceFromSiteMeters,
  });

  factory PunchDetail.fromJson(Map<String, dynamic> json) {
    return PunchDetail(
      timestamp: json['timestamp'] != null
          ? DateTime.parse(json['timestamp'].toString()).toLocal()
          : DateTime.now(),
      latitude: (json['latitude'] as num?)?.toDouble() ?? 0.0,
      longitude: (json['longitude'] as num?)?.toDouble() ?? 0.0,
      accuracy: (json['accuracy'] as num?)?.toDouble(),
      address: json['address'] ?? '',
      photoUrl: json['photoUrl'] ?? '',
      notes: json['notes'] ?? '',
      isWithinGeofence: json['isWithinGeofence'] ?? true,
      distanceFromSiteMeters: json['distanceFromSiteMeters'],
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'timestamp': timestamp.toIso8601String(),
      'latitude': latitude,
      'longitude': longitude,
      'accuracy': accuracy,
      'address': address,
      'photoUrl': photoUrl,
      'notes': notes,
      'isWithinGeofence': isWithinGeofence,
      'distanceFromSiteMeters': distanceFromSiteMeters,
    };
  }
}

class AttendanceRecord {
  final String id;
  final String supervisorId;
  final Site? site;
  final String siteId;
  final String status; // 'punched_in' or 'punched_out'
  final PunchDetail punchIn;
  final PunchDetail? punchOut;
  final int durationMinutes;
  final String? dateString;

  AttendanceRecord({
    required this.id,
    required this.supervisorId,
    this.site,
    required this.siteId,
    required this.status,
    required this.punchIn,
    this.punchOut,
    this.durationMinutes = 0,
    this.dateString,
  });

  bool get isPunchedIn => status == 'punched_in';

  factory AttendanceRecord.fromJson(Map<String, dynamic> json) {
    Site? parsedSite;
    String sId = '';
    if (json['site'] != null) {
      if (json['site'] is Map<String, dynamic>) {
        parsedSite = Site.fromJson(json['site']);
        sId = parsedSite.id;
      } else {
        sId = json['site'].toString();
      }
    } else if (json['siteId'] != null) {
      sId = json['siteId'].toString();
    }

    // Safely parse punchIn from nested map or flat fields
    PunchDetail punchInDetail;
    if (json['punchIn'] is Map<String, dynamic>) {
      punchInDetail = PunchDetail.fromJson(json['punchIn']);
    } else {
      punchInDetail = PunchDetail(
        timestamp: json['punchInTime'] != null
            ? (DateTime.tryParse(json['punchInTime'].toString()) ?? DateTime.now()).toLocal()
            : DateTime.now(),
        latitude: (json['punchInLatitude'] as num?)?.toDouble() ?? 0.0,
        longitude: (json['punchInLongitude'] as num?)?.toDouble() ?? 0.0,
        address: json['address']?.toString() ?? '',
        photoUrl: json['photoUrl']?.toString() ?? '',
        isWithinGeofence: json['isWithinGeofence'] == true || json['isWithinGeofence'] == 1,
        distanceFromSiteMeters: (json['distanceMeters'] as num?)?.toInt(),
      );
    }

    // Safely parse punchOut from nested map or flat fields
    PunchDetail? punchOutDetail;
    if (json['punchOut'] is Map<String, dynamic>) {
      punchOutDetail = PunchDetail.fromJson(json['punchOut']);
    } else if (json['punchOutTime'] != null) {
      punchOutDetail = PunchDetail(
        timestamp: (DateTime.tryParse(json['punchOutTime'].toString()) ?? DateTime.now()).toLocal(),
        latitude: (json['punchOutLatitude'] as num?)?.toDouble() ?? 0.0,
        longitude: (json['punchOutLongitude'] as num?)?.toDouble() ?? 0.0,
        address: json['address']?.toString() ?? '',
        photoUrl: json['punchOutPhotoUrl']?.toString() ?? '',
        isWithinGeofence: true,
      );
    }

    return AttendanceRecord(
      id: json['_id']?.toString() ?? json['id']?.toString() ?? '',
      supervisorId: json['supervisor'] is Map
          ? (json['supervisor']['_id']?.toString() ?? '')
          : (json['supervisor']?.toString() ?? json['employeeId']?.toString() ?? ''),
      site: parsedSite,
      siteId: sId,
      status: json['status']?.toString() ?? 'punched_in',
      punchIn: punchInDetail,
      punchOut: punchOutDetail,
      durationMinutes: (json['durationMinutes'] as num?)?.toInt() ?? 0,
      dateString: json['dateString']?.toString(),
    );
  }
}
