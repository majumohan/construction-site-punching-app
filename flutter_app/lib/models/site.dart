class SiteLocation {
  final double latitude;
  final double longitude;
  final double radiusMeters;

  SiteLocation({
    required this.latitude,
    required this.longitude,
    this.radiusMeters = 200,
  });

  factory SiteLocation.fromJson(Map<String, dynamic> json) {
    return SiteLocation(
      latitude: (json['latitude'] as num?)?.toDouble() ?? 0.0,
      longitude: (json['longitude'] as num?)?.toDouble() ?? 0.0,
      radiusMeters: (json['radiusMeters'] as num?)?.toDouble() ?? 200.0,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'latitude': latitude,
      'longitude': longitude,
      'radiusMeters': radiusMeters,
    };
  }
}

class Site {
  final String id;
  final String name;
  final String code;
  final String? clientName;
  final String address;
  final String? city;
  final SiteLocation location;
  final String status;
  final String? imageUrl;

  Site({
    required this.id,
    required this.name,
    required this.code,
    this.clientName,
    required this.address,
    this.city,
    required this.location,
    this.status = 'active',
    this.imageUrl,
  });

  factory Site.fromJson(Map<String, dynamic> json) {
    return Site(
      id: json['_id'] ?? json['id'] ?? '',
      name: json['name'] ?? '',
      code: json['code'] ?? '',
      clientName: json['clientName'],
      address: json['address'] ?? '',
      city: json['city'],
      location: json['location'] != null
          ? SiteLocation.fromJson(json['location'])
          : SiteLocation(latitude: 0, longitude: 0),
      status: json['status'] ?? 'active',
      imageUrl: json['imageUrl'],
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'code': code,
      'clientName': clientName,
      'address': address,
      'city': city,
      'location': location.toJson(),
      'status': status,
      'imageUrl': imageUrl,
    };
  }

  String get effectiveImage {
    if (imageUrl != null && imageUrl!.isNotEmpty) {
      return imageUrl!;
    }
    final lower = name.toLowerCase();
    if (lower.contains('palm')) {
      return 'assets/images/palm_residency.jpg';
    }
    if (lower.contains('lakeview')) {
      return 'assets/images/lakeview_villas.jpg';
    }
    return 'assets/images/green_valley.jpg';
  }
}
