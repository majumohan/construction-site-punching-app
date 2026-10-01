import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:site_tracker/providers/auth_provider.dart';
import 'package:site_tracker/providers/site_provider.dart';
import 'package:site_tracker/main.dart';

void main() {
  testWidgets('App smoke test', (WidgetTester tester) async {
    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => AuthProvider()),
          ChangeNotifierProvider(create: (_) => SiteProvider()),
        ],
        child: const SiteTrackApp(),
      ),
    );
    expect(find.byType(SiteTrackApp), findsOneWidget);
  });
}
