class ApiConstants {
  // Configurable base URL for PHP MySQL server in XAMPP (htdocs/sitetracker)
  static String get defaultBaseUrl {
    // Use the Mac's local network IP address so physical devices on the same Wi-Fi can connect.
    // 10.0.2.2 is only for the Android emulator. 127.0.0.1 is only for the host itself.
    return 'http://192.168.1.35/sitetracker/backend';
  }

  static String baseUrl = defaultBaseUrl;

  // Endpoints mapped to PHP scripts in XAMPP sitetracker
  static String get login => '$baseUrl/auth/login.php';
  static String get profile => '$baseUrl/auth/login.php';
  static String get sites => '$baseUrl/sites/list.php';
  static String get punchIn => '$baseUrl/attendance/punch-in.php';
  static String get punchOut => '$baseUrl/attendance/punch-out.php';
  static String get activePunch => '$baseUrl/attendance/active.php';
  static String get history => '$baseUrl/attendance/list.php';
}
