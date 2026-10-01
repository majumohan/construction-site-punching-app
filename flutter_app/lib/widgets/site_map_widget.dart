import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../models/site.dart';

class SiteMapWidget extends StatefulWidget {
  final Site site;
  final String distanceText;
  final VoidCallback? onMyLocationPressed;
  final bool showCircularLocationButton;
  final bool showDistanceOverlay;
  final bool showMyLocationPill;

  const SiteMapWidget({
    super.key,
    required this.site,
    this.distanceText = '42 meters',
    this.onMyLocationPressed,
    this.showCircularLocationButton = false,
    this.showDistanceOverlay = true,
    this.showMyLocationPill = true,
  });

  @override
  State<SiteMapWidget> createState() => _SiteMapWidgetState();
}

class _SiteMapWidgetState extends State<SiteMapWidget> with SingleTickerProviderStateMixin {
  late AnimationController _pulseController;

  @override
  void initState() {
    super.initState();
    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2000),
    )..repeat(reverse: true);
  }

  @override
  void dispose() {
    _pulseController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 220,
      width: double.infinity,
      decoration: BoxDecoration(
        color: const Color(0xFFF1F5F9),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFE2E8F0)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.04),
            blurRadius: 10,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(16),
        child: Stack(
          children: [
            // Custom Painted Map with roads, parks, dashed line, and radar
            AnimatedBuilder(
              animation: _pulseController,
              builder: (context, child) {
                return CustomPaint(
                  size: Size.infinite,
                  painter: _MapCanvasPainter(
                    pulseValue: _pulseController.value,
                  ),
                );
              },
            ),

            // Site Callout Marker (Red Pin + Tooltip)
            Positioned(
              top: 24,
              right: 48,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // Speech bubble tooltip
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(10),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withValues(alpha: 0.12),
                          blurRadius: 8,
                          offset: const Offset(0, 2),
                        ),
                      ],
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          widget.site.name,
                          style: GoogleFonts.plusJakartaSans(
                            fontSize: 12,
                            fontWeight: FontWeight.w700,
                            color: const Color(0xFF0F172A),
                          ),
                        ),
                        Text(
                          widget.site.address,
                          style: GoogleFonts.plusJakartaSans(
                            fontSize: 10.5,
                            fontWeight: FontWeight.w500,
                            color: const Color(0xFF64748B),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 2),
                  // Red Pin Icon
                  const Icon(
                    Icons.location_on,
                    color: Color(0xFFEF4444),
                    size: 32,
                  ),
                ],
              ),
            ),

            // User Location Dot
            Positioned(
              left: 128,
              top: 110,
              child: Container(
                width: 18,
                height: 18,
                decoration: BoxDecoration(
                  color: const Color(0xFF0066FF),
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white, width: 3),
                  boxShadow: [
                    BoxShadow(
                      color: const Color(0xFF0066FF).withValues(alpha: 0.4),
                      blurRadius: 6,
                    ),
                  ],
                ),
              ),
            ),

            // Floating "My Location" Button on bottom-left
            if (widget.showMyLocationPill)
              Positioned(
                left: 12,
                bottom: 12,
                child: Material(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(20),
                  elevation: 2,
                  shadowColor: Colors.black.withValues(alpha: 0.15),
                  child: InkWell(
                    onTap: widget.onMyLocationPressed,
                    borderRadius: BorderRadius.circular(20),
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: const Color(0xFFE2E8F0)),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(
                            Icons.my_location_rounded,
                            size: 15,
                            color: Color(0xFF1E293B),
                          ),
                          const SizedBox(width: 6),
                          Text(
                            'My Location',
                            style: GoogleFonts.plusJakartaSans(
                              fontSize: 12,
                              fontWeight: FontWeight.w600,
                              color: const Color(0xFF1E293B),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),

            // Floating "Distance" Info Card on bottom-right
            if (widget.showDistanceOverlay)
              Positioned(
                right: 12,
                bottom: 12,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFFE2E8F0)),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withValues(alpha: 0.08),
                        blurRadius: 6,
                        offset: const Offset(0, 2),
                      ),
                    ],
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(
                        Icons.location_on,
                        color: Color(0xFF16A34A),
                        size: 20,
                      ),
                      const SizedBox(width: 6),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            'Distance',
                            style: GoogleFonts.plusJakartaSans(
                              fontSize: 10,
                              fontWeight: FontWeight.w500,
                              color: const Color(0xFF64748B),
                            ),
                          ),
                          Text(
                            widget.distanceText,
                            style: GoogleFonts.plusJakartaSans(
                              fontSize: 13.5,
                              fontWeight: FontWeight.w700,
                              color: const Color(0xFF16A34A),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),

            // Floating circular location target button on bottom-right (for On-Site view)
            if (widget.showCircularLocationButton)
              Positioned(
                right: 12,
                bottom: 12,
                child: Material(
                  color: Colors.white,
                  shape: const CircleBorder(),
                  elevation: 3,
                  shadowColor: Colors.black.withValues(alpha: 0.15),
                  child: InkWell(
                    onTap: widget.onMyLocationPressed,
                    customBorder: const CircleBorder(),
                    child: Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        border: Border.all(color: const Color(0xFFE2E8F0)),
                      ),
                      child: const Center(
                        child: Icon(
                          Icons.my_location_rounded,
                          size: 20,
                          color: Color(0xFF0F172A),
                        ),
                      ),
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

class _MapCanvasPainter extends CustomPainter {
  final double pulseValue;

  _MapCanvasPainter({required this.pulseValue});

  @override
  void paint(Canvas canvas, Size size) {
    // 1. Base Map Background
    final bgPaint = Paint()..color = const Color(0xFFF4F6F9);
    canvas.drawRect(Rect.fromLTWH(0, 0, size.width, size.height), bgPaint);

    // 2. Green Park Areas (subtle pastel greens)
    final parkPaint = Paint()..color = const Color(0xFFE4F3E8);
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(20, 10, 110, 70),
        const Radius.circular(8),
      ),
      parkPaint,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(200, 120, 130, 80),
        const Radius.circular(8),
      ),
      parkPaint,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(size.width - 90, 20, 80, 50),
        const Radius.circular(8),
      ),
      parkPaint,
    );

    // 3. Water body (soft pastel blue wavy corner)
    final waterPaint = Paint()..color = const Color(0xFFE0F2FE);
    final waterPath = Path()
      ..moveTo(0, 0)
      ..lineTo(50, 0)
      ..quadraticBezierTo(40, 45, 0, 50)
      ..close();
    canvas.drawPath(waterPath, waterPaint);

    // 4. City Roads (Light beige / grey road grid)
    final roadBorderPaint = Paint()
      ..color = const Color(0xFFE2E8F0)
      ..strokeWidth = 14
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;

    final roadPaint = Paint()
      ..color = Colors.white
      ..strokeWidth = 10
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;

    // Road 1 (Diagonal main road)
    final road1 = Path()
      ..moveTo(-10, size.height * 0.75)
      ..lineTo(size.width + 10, size.height * 0.15);
    canvas.drawPath(road1, roadBorderPaint);
    canvas.drawPath(road1, roadPaint);

    // Road 2 (Diagonal secondary road)
    final road2 = Path()
      ..moveTo(size.width * 0.25, size.height + 10)
      ..lineTo(size.width * 0.85, -10);
    canvas.drawPath(road2, roadBorderPaint);
    canvas.drawPath(road2, roadPaint);

    // Road 3 (Horizontal link road)
    final road3 = Path()
      ..moveTo(-10, 118)
      ..lineTo(size.width + 10, 118);
    canvas.drawPath(road3, roadBorderPaint);
    canvas.drawPath(road3, roadPaint);

    // Road 4 (Vertical branch)
    final road4 = Path()
      ..moveTo(137, -10)
      ..lineTo(137, size.height + 10);
    canvas.drawPath(road4, roadBorderPaint);
    canvas.drawPath(road4, roadPaint);

    // 5. Geofence Radius Circle (around user location at approx x:137, y:118)
    final userX = 137.0;
    final userY = 118.0;

    // Outer radar aura
    final geofenceFill = Paint()
      ..color = const Color(0xFF6EE7B7).withValues(alpha: 0.25 + pulseValue * 0.08)
      ..style = PaintingStyle.fill;
    canvas.drawCircle(Offset(userX, userY), 54 + pulseValue * 6, geofenceFill);

    final geofenceBorder = Paint()
      ..color = const Color(0xFF34D399).withValues(alpha: 0.5)
      ..strokeWidth = 1.5
      ..style = PaintingStyle.stroke;
    canvas.drawCircle(Offset(userX, userY), 54 + pulseValue * 6, geofenceBorder);

    // 6. Dashed Line connecting user location to Site marker pin
    // Site pin target approx: size.width - 92, 54
    final targetX = size.width - 95;
    final targetY = 56.0;

    final dashPaint = Paint()
      ..color = const Color(0xFF0066FF)
      ..strokeWidth = 1.8
      ..style = PaintingStyle.stroke;

    _drawDashedLine(canvas, Offset(userX, userY), Offset(targetX, targetY), dashPaint);
  }

  void _drawDashedLine(Canvas canvas, Offset p1, Offset p2, Paint paint) {
    const dashLength = 5.0;
    const dashSpace = 4.0;
    final dx = p2.dx - p1.dx;
    final dy = p2.dy - p1.dy;
    final distance = math.sqrt(dx * dx + dy * dy);
    final count = (distance / (dashLength + dashSpace)).floor();

    for (int i = 0; i < count; i++) {
      final startFraction = (i * (dashLength + dashSpace)) / distance;
      final endFraction = math.min(1.0, ((i * (dashLength + dashSpace)) + dashLength) / distance);

      final start = Offset(p1.dx + dx * startFraction, p1.dy + dy * startFraction);
      final end = Offset(p1.dx + dx * endFraction, p1.dy + dy * endFraction);
      canvas.drawLine(start, end, paint);
    }
  }

  @override
  bool shouldRepaint(covariant _MapCanvasPainter oldDelegate) {
    return oldDelegate.pulseValue != pulseValue;
  }
}
