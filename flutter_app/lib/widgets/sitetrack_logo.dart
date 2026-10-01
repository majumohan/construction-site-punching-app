import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../constants/app_colors.dart';

class SiteTrackLogo extends StatelessWidget {
  final double iconSize;
  final bool showText;
  final bool showTagline;

  const SiteTrackLogo({
    super.key,
    this.iconSize = 56,
    this.showText = true,
    this.showTagline = true,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        CustomPaint(
          size: Size(iconSize, iconSize * 1.05),
          painter: _SkyscraperLogoPainter(color: AppColors.primary),
        ),
        if (showText) ...[
          const SizedBox(height: 8),
          RichText(
            textAlign: TextAlign.center,
            text: TextSpan(
              children: [
                TextSpan(
                  text: 'Site',
                  style: GoogleFonts.plusJakartaSans(
                    fontSize: 28,
                    fontWeight: FontWeight.w800,
                    color: const Color(0xFF111827),
                    letterSpacing: -0.5,
                  ),
                ),
                TextSpan(
                  text: 'Track',
                  style: GoogleFonts.plusJakartaSans(
                    fontSize: 28,
                    fontWeight: FontWeight.w800,
                    color: AppColors.primary,
                    letterSpacing: -0.5,
                  ),
                ),
              ],
            ),
          ),
        ],
        if (showTagline) ...[
          const SizedBox(height: 3),
          Text(
            'Track Sites. Build Progress.',
            style: GoogleFonts.plusJakartaSans(
              fontSize: 13,
              fontWeight: FontWeight.w500,
              color: const Color(0xFF374151),
              letterSpacing: 0.1,
            ),
          ),
        ],
      ],
    );
  }
}

class _SkyscraperLogoPainter extends CustomPainter {
  final Color color;

  _SkyscraperLogoPainter({required this.color});

  @override
  void paint(Canvas canvas, Size size) {
    final strokePaint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = size.width * 0.065
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;

    final fillPaint = Paint()
      ..color = color.withValues(alpha: 0.12)
      ..style = PaintingStyle.fill;

    final w = size.width;
    final h = size.height;

    // Center skyscraper path (tallest with pointed spire)
    final centerTower = Path()
      ..moveTo(w * 0.5, h * 0.05) // spire peak
      ..lineTo(w * 0.40, h * 0.22)
      ..lineTo(w * 0.40, h * 0.95)
      ..lineTo(w * 0.60, h * 0.95)
      ..lineTo(w * 0.60, h * 0.22)
      ..close();

    canvas.drawPath(centerTower, fillPaint);
    canvas.drawPath(centerTower, strokePaint);

    // Left building
    final leftTower = Path()
      ..moveTo(w * 0.18, h * 0.38)
      ..lineTo(w * 0.38, h * 0.30)
      ..lineTo(w * 0.38, h * 0.95)
      ..lineTo(w * 0.18, h * 0.95)
      ..close();

    canvas.drawPath(leftTower, strokePaint);

    // Right building
    final rightTower = Path()
      ..moveTo(w * 0.62, h * 0.32)
      ..lineTo(w * 0.82, h * 0.40)
      ..lineTo(w * 0.82, h * 0.95)
      ..lineTo(w * 0.62, h * 0.95)
      ..close();

    canvas.drawPath(rightTower, strokePaint);

    // Interior architectural accent lines
    // Center horizontal divide
    canvas.drawLine(
      Offset(w * 0.40, h * 0.52),
      Offset(w * 0.60, h * 0.52),
      strokePaint..strokeWidth = size.width * 0.045,
    );

    canvas.drawLine(
      Offset(w * 0.40, h * 0.72),
      Offset(w * 0.60, h * 0.72),
      strokePaint,
    );

    // Spire antenna vertical
    canvas.drawLine(
      Offset(w * 0.5, h * 0.02),
      Offset(w * 0.5, h * 0.12),
      strokePaint..strokeWidth = size.width * 0.065,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
