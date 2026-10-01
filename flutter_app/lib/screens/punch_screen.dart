import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import '../constants/app_colors.dart';
import '../models/site.dart';
import '../providers/site_provider.dart';
import '../services/location_service.dart';
import 'on_site_screen.dart';
import 'site_selection_screen.dart';

class PunchScreen extends StatefulWidget {
  final Site site;
  final bool isPunchOutMode;

  const PunchScreen({
    super.key,
    required this.site,
    this.isPunchOutMode = false,
  });

  @override
  State<PunchScreen> createState() => _PunchScreenState();
}

class _PunchScreenState extends State<PunchScreen> {
  LocationResult? _currentLocation;
  bool _isLoadingLocation = true;
  bool _locationDenied = false;
  bool _isProcessingPunch = false;
  StreamSubscription<LocationResult>? _locationSub;
  final ImagePicker _picker = ImagePicker();

  static const double _geofenceRadiusMeters = 150.0;

  double? get _distanceToSite {
    if (_currentLocation == null) return null;
    final sLat = widget.site.location.latitude;
    final sLng = widget.site.location.longitude;
    if (sLat == 0 && sLng == 0) return null; // no site coords set
    return LocationService.getDistanceMeters(
      startLatitude: _currentLocation!.latitude,
      startLongitude: _currentLocation!.longitude,
      endLatitude: sLat,
      endLongitude: sLng,
    );
  }

  bool get _isInsideGeofence {
    if (_locationDenied) return false; // block punch if no permission
    final d = _distanceToSite;
    if (d == null) return true; // allow if no site coords (not enforced)
    return d <= _geofenceRadiusMeters;
  }

  @override
  void initState() {
    super.initState();
    _startLocationStream();
  }

  @override
  void dispose() {
    _locationSub?.cancel();
    super.dispose();
  }

  Future<void> _startLocationStream() async {
    setState(() {
      _isLoadingLocation = true;
      _locationDenied = false;
    });

    // One-shot to get the first fix quickly
    final initial = await LocationService.getCurrentLocation();
    if (!mounted) return;

    if (initial == null) {
      setState(() {
        _isLoadingLocation = false;
        _locationDenied = true;
      });
      return;
    }

    setState(() {
      _currentLocation = initial;
      _isLoadingLocation = false;
    });

    // Then subscribe to continuous stream — updates on every ~5m of movement
    _locationSub = LocationService.getPositionStream(distanceFilterMeters: 5)
        .listen((loc) {
      if (mounted) {
        setState(() {
          _currentLocation = loc;
          _locationDenied = false;
        });
      }
    }, onError: (_) {
      // Stream error — keep last known position, don't lock the UI
    });
  }


  Future<void> _handlePunchAction() async {
    if (_isProcessingPunch) return;

    File? capturedImage;

    try {
      final XFile? photo = await _picker.pickImage(
        source: ImageSource.camera,
        maxWidth: 1024,
        maxHeight: 1024,
        imageQuality: 75,
        preferredCameraDevice: CameraDevice.front,
      );

      if (photo == null) {
        // User cancelled camera capture
        return;
      }
      capturedImage = File(photo.path);
    } catch (e) {
      // Fallback to gallery if camera is unavailable on device/simulator
      try {
        final XFile? photo = await _picker.pickImage(
          source: ImageSource.gallery,
          maxWidth: 1024,
          maxHeight: 1024,
          imageQuality: 75,
        );
        if (photo == null) return;
        capturedImage = File(photo.path);
      } catch (err) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Could not open camera: $err'),
            backgroundColor: AppColors.error,
          ),
        );
        return;
      }
    }

    if (!mounted) return;

    setState(() => _isProcessingPunch = true);

    final provider = context.read<SiteProvider>();
    bool success;

    if (widget.isPunchOutMode) {
      success = await provider.punchOut(photoFile: capturedImage);
    } else {
      success = await provider.punchIn(photoFile: capturedImage);
    }

    if (!mounted) return;
    setState(() => _isProcessingPunch = false);

    if (success) {
      _showSuccessDialog(capturedImage);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(provider.errorMessage ?? 'Punch operation failed. Please try again.'),
          backgroundColor: AppColors.error,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        ),
      );
    }
  }

  void _showSuccessDialog(File photoFile) {
    final nowFormatted = DateFormat('hh:mm a, dd MMM yyyy').format(DateTime.now());

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        backgroundColor: Colors.white,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        contentPadding: const EdgeInsets.symmetric(horizontal: 24, vertical: 24),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Green checkmark
            Container(
              padding: const EdgeInsets.all(16),
              decoration: const BoxDecoration(
                color: Color(0xFFDCFCE7),
                shape: BoxShape.circle,
              ),
              child: const Icon(
                Icons.check_circle_rounded,
                color: Color(0xFF16A34A),
                size: 56,
              ),
            ),
            const SizedBox(height: 16),
            Text(
              widget.isPunchOutMode ? 'Punched Out Successfully!' : 'Punched In Successfully!',
              textAlign: TextAlign.center,
              style: GoogleFonts.plusJakartaSans(
                fontSize: 20,
                fontWeight: FontWeight.w800,
                color: const Color(0xFF0F172A),
              ),
            ),
            const SizedBox(height: 8),
            Text(
              'Your photo, timestamp, and GPS coordinates have been recorded and sent to the Admin Panel.',
              textAlign: TextAlign.center,
              style: GoogleFonts.plusJakartaSans(
                fontSize: 13,
                color: const Color(0xFF64748B),
                height: 1.4,
              ),
            ),
            const SizedBox(height: 16),

            // Summary Card with thumbnail and info
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFF8FAFC),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: const Color(0xFFE2E8F0)),
              ),
              child: Row(
                children: [
                  // Photo thumbnail
                  ClipRRect(
                    borderRadius: BorderRadius.circular(8),
                    child: Image.file(
                      photoFile,
                      width: 52,
                      height: 52,
                      fit: BoxFit.cover,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          widget.site.name,
                          style: GoogleFonts.plusJakartaSans(
                            fontSize: 13.5,
                            fontWeight: FontWeight.w700,
                            color: const Color(0xFF0F172A),
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const SizedBox(height: 2),
                        Text(
                          nowFormatted,
                          style: GoogleFonts.plusJakartaSans(
                            fontSize: 12,
                            fontWeight: FontWeight.w600,
                            color: AppColors.primary,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 22),

            // Done / Continue button
            SizedBox(
              width: double.infinity,
              height: 48,
              child: ElevatedButton(
                onPressed: () {
                  Navigator.of(ctx).pop();
                  if (!widget.isPunchOutMode) {
                    // Read the actual punch-in time recorded by the server so
                    // the OnSiteScreen timer reflects the real elapsed time.
                    final punchInTime = context
                        .read<SiteProvider>()
                        .activeAttendance
                        ?.punchIn
                        .timestamp;
                    Navigator.of(context).pushReplacement(
                      MaterialPageRoute(
                        builder: (_) => OnSiteScreen(
                          site: widget.site,
                          punchInTime: punchInTime ?? DateTime.now(),
                        ),
                      ),
                    );
                  } else {
                    Navigator.of(context).pop();
                  }
                },
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.primary,
                  elevation: 0,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                ),
                child: Text(
                  widget.isPunchOutMode ? 'Done' : 'Proceed to On-Site',
                  style: GoogleFonts.plusJakartaSans(
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    color: Colors.white,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  void _navigateBack() {
    if (Navigator.of(context).canPop()) {
      Navigator.of(context).pop();
    } else {
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const SiteSelectionScreen()),
        (route) => false,
      );
    }
  }

  Widget _buildGeofenceCard() {
    // Location permission denied — show actionable card
    if (_locationDenied) {
      return GestureDetector(
        onTap: () async {
          await LocationService.getCurrentLocation();
          _startLocationStream();
        },
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          decoration: BoxDecoration(
            color: const Color(0xFFFEF2F2),
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: const Color(0xFFFCA5A5)),
          ),
          child: Row(
            children: [
              Container(
                width: 38,
                height: 38,
                decoration: const BoxDecoration(
                  color: Color(0xFFEF4444),
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.location_disabled_rounded, color: Colors.white, size: 20),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      'Location Permission Required',
                      style: GoogleFonts.plusJakartaSans(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                        color: const Color(0xFF991B1B),
                      ),
                    ),
                    const SizedBox(height: 1),
                    Text(
                      'Tap to open settings and enable location',
                      style: GoogleFonts.plusJakartaSans(
                        fontSize: 12,
                        fontWeight: FontWeight.w500,
                        color: const Color(0xFFEF4444),
                      ),
                    ),
                  ],
                ),
              ),
              const Icon(Icons.settings_rounded, color: Color(0xFFEF4444), size: 18),
            ],
          ),
        ),
      );
    }

    final distance = _distanceToSite;
    final inside = _isInsideGeofence;

    String distanceLabel;
    if (_isLoadingLocation) {
      distanceLabel = 'Getting GPS…';
    } else if (distance == null) {
      distanceLabel = 'Site coordinates not set';
    } else if (distance < 1000) {
      distanceLabel = '${distance.round()} m from site';
    } else {
      distanceLabel = '${(distance / 1000).toStringAsFixed(2)} km from site';
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: inside ? const Color(0xFFECFDF5) : const Color(0xFFFFF7ED),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: inside ? const Color(0xFFA7F3D0) : const Color(0xFFFED7AA),
        ),
      ),
      child: Row(
        children: [
          // Status icon
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              color: inside ? const Color(0xFF10B981) : const Color(0xFFEA580C),
              shape: BoxShape.circle,
            ),
            child: Icon(
              inside ? Icons.my_location_rounded : Icons.location_off_rounded,
              color: Colors.white,
              size: 20,
            ),
          ),
          const SizedBox(width: 12),
          // Status text
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  inside ? 'Inside Geofence' : 'Outside Geofence',
                  style: GoogleFonts.plusJakartaSans(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                    color: inside ? const Color(0xFF065F46) : const Color(0xFF9A3412),
                  ),
                ),
                const SizedBox(height: 1),
                Text(
                  distanceLabel,
                  style: GoogleFonts.plusJakartaSans(
                    fontSize: 12,
                    fontWeight: FontWeight.w500,
                    color: inside ? const Color(0xFF047857) : const Color(0xFFEA580C),
                  ),
                ),
              ],
            ),
          ),
          // Distance chip
          if (!_isLoadingLocation && distance != null)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(
                color: inside ? const Color(0xFF10B981) : const Color(0xFFEA580C),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Text(
                inside ? 'INSIDE' : 'OUTSIDE',
                style: GoogleFonts.plusJakartaSans(
                  fontSize: 11,
                  fontWeight: FontWeight.w800,
                  color: Colors.white,
                  letterSpacing: 0.5,
                ),
              ),
            )
          else if (_isLoadingLocation)
            const SizedBox(
              width: 18,
              height: 18,
              child: CircularProgressIndicator(
                strokeWidth: 2,
                color: AppColors.primary,
              ),
            ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isPunchOut = widget.isPunchOutMode;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, result) {
        if (didPop) return;
        _navigateBack();
      },
      child: Scaffold(
        backgroundColor: const Color(0xFFF8FAFC),
        appBar: AppBar(
          backgroundColor: Colors.white,
          elevation: 0,
          leading: IconButton(
            icon: const Icon(Icons.arrow_back_ios_new_rounded, color: Color(0xFF0F172A), size: 20),
            onPressed: _navigateBack,
          ),
        centerTitle: true,
        title: Text(
          isPunchOut ? 'Punch Out' : 'Punch In',
          style: GoogleFonts.plusJakartaSans(
            fontSize: 18,
            fontWeight: FontWeight.w700,
            color: const Color(0xFF0F172A),
          ),
        ),
      ),
      body: Stack(
        children: [
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // Site Info Card
                  Container(
                    padding: const EdgeInsets.all(18),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(16),
                      border: Border.all(color: const Color(0xFFE2E8F0)),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withValues(alpha: 0.03),
                          blurRadius: 10,
                          offset: const Offset(0, 4),
                        ),
                      ],
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Container(
                              padding: const EdgeInsets.all(8),
                              decoration: BoxDecoration(
                                color: const Color(0xFFEFF6FF),
                                borderRadius: BorderRadius.circular(10),
                              ),
                              child: const Icon(Icons.apartment_rounded, color: AppColors.primary, size: 22),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    widget.site.name,
                                    style: GoogleFonts.plusJakartaSans(
                                      fontSize: 16,
                                      fontWeight: FontWeight.w700,
                                      color: const Color(0xFF0F172A),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 14),
                        const Divider(height: 1, color: Color(0xFFF1F5F9)),
                        const SizedBox(height: 12),
                        Row(
                          children: [
                            Icon(
                              _locationDenied
                                  ? Icons.location_off_rounded
                                  : Icons.location_on_rounded,
                              size: 16,
                              color: _locationDenied
                                  ? const Color(0xFFEF4444)
                                  : _isLoadingLocation
                                      ? const Color(0xFF94A3B8)
                                      : const Color(0xFF16A34A),
                            ),
                            const SizedBox(width: 6),
                            Expanded(
                              child: _locationDenied
                                  ? GestureDetector(
                                      onTap: () async {
                                        await LocationService.getCurrentLocation();
                                        _startLocationStream();
                                      },
                                      child: Text(
                                        'Location access denied — tap to enable',
                                        style: GoogleFonts.plusJakartaSans(
                                          fontSize: 12,
                                          fontWeight: FontWeight.w600,
                                          color: const Color(0xFFEF4444),
                                          decoration: TextDecoration.underline,
                                        ),
                                      ),
                                    )
                                  : Text(
                                      _isLoadingLocation
                                          ? 'Acquiring GPS signal…'
                                          : 'GPS signal connected',
                                      style: GoogleFonts.plusJakartaSans(
                                        fontSize: 12,
                                        fontWeight: FontWeight.w600,
                                        color: _isLoadingLocation
                                            ? const Color(0xFF94A3B8)
                                            : const Color(0xFF334155),
                                      ),
                                    ),
                            ),
                            if (_isLoadingLocation && !_locationDenied)
                              const SizedBox(
                                width: 14,
                                height: 14,
                                child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.primary),
                              ),
                          ],
                        ),
                      ],
                    ),
                  ),

                  const SizedBox(height: 14),

                  // ── Geofence Status Card ─────────────────────────────────
                  _buildGeofenceCard(),

                  const Spacer(),

                  // HERO PUNCH BUTTON (Center of screen)
                  Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        // Outer pulsating / decorative halo
                        Container(
                          width: 220,
                          height: 220,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: _isInsideGeofence
                                ? (isPunchOut
                                    ? const Color(0xFFFEE2E2).withValues(alpha: 0.6)
                                    : const Color(0xFFDBEAFE).withValues(alpha: 0.6))
                                : const Color(0xFFE2E8F0).withValues(alpha: 0.7),
                          ),
                          padding: const EdgeInsets.all(16),
                          child: Container(
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              color: _isInsideGeofence
                                  ? (isPunchOut
                                      ? const Color(0xFFFEE2E2)
                                      : const Color(0xFFEFF6FF))
                                  : const Color(0xFFF1F5F9),
                            ),
                            padding: const EdgeInsets.all(12),
                            child: Material(
                              color: Colors.transparent,
                              shape: const CircleBorder(),
                              clipBehavior: Clip.antiAlias,
                              child: InkWell(
                                onTap: (_isProcessingPunch || !_isInsideGeofence)
                                    ? null
                                    : _handlePunchAction,
                                splashColor: Colors.white24,
                                child: Container(
                                  decoration: BoxDecoration(
                                    shape: BoxShape.circle,
                                    gradient: LinearGradient(
                                      begin: Alignment.topLeft,
                                      end: Alignment.bottomRight,
                                      colors: _isInsideGeofence
                                          ? (isPunchOut
                                              ? [const Color(0xFFEF4444), const Color(0xFFDC2626)]
                                              : [const Color(0xFF2563EB), const Color(0xFF1D4ED8)])
                                          : [const Color(0xFF94A3B8), const Color(0xFF64748B)],
                                    ),
                                    boxShadow: [
                                      BoxShadow(
                                        color: (_isInsideGeofence
                                                ? (isPunchOut ? const Color(0xFFDC2626) : const Color(0xFF1D4ED8))
                                                : const Color(0xFF94A3B8))
                                            .withValues(alpha: _isInsideGeofence ? 0.4 : 0.2),
                                        blurRadius: 20,
                                        offset: const Offset(0, 10),
                                      ),
                                    ],
                                  ),
                                  child: Center(
                                    child: Column(
                                      mainAxisAlignment: MainAxisAlignment.center,
                                      children: [
                                        const Icon(
                                          Icons.camera_alt_rounded,
                                          color: Colors.white,
                                          size: 44,
                                        ),
                                        const SizedBox(height: 10),
                                        Text(
                                          isPunchOut ? 'PUNCH OUT' : 'PUNCH IN',
                                          style: GoogleFonts.plusJakartaSans(
                                            fontSize: 20,
                                            fontWeight: FontWeight.w800,
                                            color: Colors.white,
                                            letterSpacing: 0.5,
                                          ),
                                        ),
                                        const SizedBox(height: 4),
                                        Text(
                                          'Tap to Capture Photo',
                                          style: GoogleFonts.plusJakartaSans(
                                            fontSize: 11.5,
                                            fontWeight: FontWeight.w500,
                                            color: Colors.white.withValues(alpha: 0.85),
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                            ),
                          ),
                        ),
                        const SizedBox(height: 20),

                        // Status label below button
                        if (!_isInsideGeofence && _distanceToSite != null)
                          Container(
                            margin: const EdgeInsets.symmetric(horizontal: 12),
                            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                            decoration: BoxDecoration(
                              color: const Color(0xFFFFF7ED),
                              borderRadius: BorderRadius.circular(12),
                              border: Border.all(color: const Color(0xFFFED7AA)),
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                const Icon(Icons.warning_amber_rounded, color: Color(0xFFEA580C), size: 18),
                                const SizedBox(width: 8),
                                Flexible(
                                  child: Text(
                                    'Move within 150 m of the site to punch in. Currently ${_distanceToSite!.round()} m away.',
                                    style: GoogleFonts.plusJakartaSans(
                                      fontSize: 12.5,
                                      fontWeight: FontWeight.w600,
                                      color: const Color(0xFFEA580C),
                                    ),
                                    textAlign: TextAlign.center,
                                  ),
                                ),
                              ],
                            ),
                          )
                        else
                          Text(
                            _isLoadingLocation
                                ? 'Checking your location…'
                                : 'Tapping opens the camera automatically for live site verification.',
                            textAlign: TextAlign.center,
                            style: GoogleFonts.plusJakartaSans(
                              fontSize: 13,
                              fontWeight: FontWeight.w500,
                              color: const Color(0xFF64748B),
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),

          // Loading overlay when transmitting punch data
          if (_isProcessingPunch)
            Container(
              color: Colors.black.withValues(alpha: 0.5),
              child: Center(
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 24),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const CircularProgressIndicator(color: AppColors.primary),
                      const SizedBox(height: 18),
                      Text(
                        'Recording Punch In...',
                        style: GoogleFonts.plusJakartaSans(
                          fontSize: 16,
                          fontWeight: FontWeight.w700,
                          color: const Color(0xFF0F172A),
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        'Verifying GPS & sending to Admin Panel',
                        style: GoogleFonts.plusJakartaSans(
                          fontSize: 12.5,
                          color: const Color(0xFF64748B),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    ),
    );
  }
}
