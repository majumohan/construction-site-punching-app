import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/user.dart';

class StorageService {
  static const String _keyToken = 'sitetrack_auth_token';
  static const String _keyUser = 'sitetrack_user_data';
  static const String _keyKeepMeLoggedIn = 'sitetrack_keep_logged_in';

  static Future<void> saveAuthData({
    required String token,
    required User user,
    bool keepLoggedIn = true,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keyToken, token);
    await prefs.setString(_keyUser, jsonEncode(user.toJson()));
    await prefs.setBool(_keyKeepMeLoggedIn, keepLoggedIn);
  }

  static Future<void> setKeepMeLoggedIn(bool keepLoggedIn) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_keyKeepMeLoggedIn, keepLoggedIn);
  }

  static Future<bool> isKeepMeLoggedIn() async {
    final prefs = await SharedPreferences.getInstance();
    // Default to true if not explicitly set
    return prefs.getBool(_keyKeepMeLoggedIn) ?? true;
  }

  static Future<String?> getToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_keyToken);
  }

  static Future<User?> getUser() async {
    final prefs = await SharedPreferences.getInstance();
    final userJson = prefs.getString(_keyUser);
    if (userJson != null) {
      try {
        return User.fromJson(jsonDecode(userJson));
      } catch (e) {
        return null;
      }
    }
    return null;
  }

  static Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_keyToken);
    await prefs.remove(_keyUser);
    await prefs.remove(_keyKeepMeLoggedIn);
  }
}
