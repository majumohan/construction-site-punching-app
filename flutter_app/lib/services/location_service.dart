import 'package:geolocator/geolocator.dart';

class LocationResult {
  final double latitude;
  final double longitude;
  final double accuracy;
  final String? address;

  LocationResult({
    required this.latitude,
    required this.longitude,
    required this.accuracy,
    this.address,
  });
}

class LocationService {
  /// Request permission; returns the resulting [LocationPermission].
  static Future<LocationPermission> requestPermission() async {
    final serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) {
      return LocationPermission.denied;
    }

    LocationPermission permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    return permission;
  }

  /// Returns true only when the app has at least "while in use" permission
  /// and the device location service is switched on.
  static Future<bool> checkPermission() async {
    final permission = await requestPermission();
    return permission == LocationPermission.whileInUse ||
        permission == LocationPermission.always;
  }

  /// One-shot accurate GPS fix.
  /// Returns **null** if permission is denied or the service is disabled —
  /// callers must handle null and show an appropriate UI message.
  static Future<LocationResult?> getCurrentLocation() async {
    try {
      final permission = await requestPermission();

      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        // Don't fake coordinates — return null so the UI can warn the user.
        if (permission == LocationPermission.deniedForever) {
          await Geolocator.openAppSettings();
        }
        return null;
      }

      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          // bestForNavigation gives the highest accuracy the hardware can provide
          accuracy: LocationAccuracy.bestForNavigation,
          timeLimit: Duration(seconds: 20),
        ),
      );

      return LocationResult(
        latitude: position.latitude,
        longitude: position.longitude,
        accuracy: position.accuracy,
      );
    } catch (e) {
      // Any other error (timeout, hardware failure) — return null.
      return null;
    }
  }

  /// Continuous live position stream.
  /// Emits a new [LocationResult] whenever the device moves > [distanceFilterMeters].
  /// Use in widgets that need real-time geofence updates.
  static Stream<LocationResult> getPositionStream({
    double distanceFilterMeters = 5,
  }) {
    return Geolocator.getPositionStream(
      locationSettings: LocationSettings(
        accuracy: LocationAccuracy.bestForNavigation,
        distanceFilter: distanceFilterMeters.toInt(),
      ),
    ).map((pos) => LocationResult(
          latitude: pos.latitude,
          longitude: pos.longitude,
          accuracy: pos.accuracy,
        ));
  }

  /// Haversine distance between two coordinates in metres.
  static double getDistanceMeters({
    required double startLatitude,
    required double startLongitude,
    required double endLatitude,
    required double endLongitude,
  }) {
    return Geolocator.distanceBetween(
      startLatitude,
      startLongitude,
      endLatitude,
      endLongitude,
    );
  }
}
