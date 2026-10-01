import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import '../constants/api_constants.dart';
import '../models/user.dart';
import '../models/site.dart';
import '../models/attendance.dart';
import 'storage_service.dart';

class ApiResponse<T> {
  final bool success;
  final String message;
  final T? data;

  ApiResponse({required this.success, required this.message, this.data});
}

class ApiService {
  static Future<Map<String, String>> _getHeaders({bool isMultipart = false}) async {
    final token = await StorageService.getToken();
    final headers = <String, String>{
      if (!isMultipart) 'Content-Type': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
    };
    return headers;
  }

  // Login
  static Future<ApiResponse<User>> login({
    required String identifier,
    required String password,
    bool keepMeLoggedIn = true,
  }) async {
    try {
      final response = await http
          .post(
            Uri.parse(ApiConstants.login),
            headers: {'Content-Type': 'application/json'},
            body: jsonEncode({
              'identifier': identifier,
              'password': password,
            }),
          )
          .timeout(const Duration(seconds: 5));

      final data = jsonDecode(response.body);

      if (response.statusCode == 200 && data['success'] == true) {
        final token = (data['token'] as String?) ?? 'session-token-${data['user']?['id']}';
        final user = User.fromJson(data['user']);
        await StorageService.saveAuthData(token: token, user: user, keepLoggedIn: keepMeLoggedIn);
        return ApiResponse(
          success: true,
          message: data['message'] ?? 'Login successful',
          data: user,
        );
      } else {
        return ApiResponse(
          success: false,
          message: data['message'] ?? 'Invalid credentials. Only accounts created by the administrator can log in.',
        );
      }
    } catch (e) {
      return ApiResponse(
        success: false,
        message: 'Could not connect to the server. Please verify your connection.',
      );
    }
  }

  // Get Sites
  static Future<List<Site>> getSites() async {
    try {
      final headers = await _getHeaders();
      final response = await http
          .get(Uri.parse(ApiConstants.sites), headers: headers)
          .timeout(const Duration(seconds: 5));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        final list = (data['sites'] ?? data['data']) as List?;
        if (list != null) {
          return list.map((json) => Site.fromJson(json)).toList();
        }
      }
    } catch (e) {
      // Return empty list on failure
    }

    return [];
  }

  // Active Punch
  static Future<AttendanceRecord?> getActivePunch() async {
    try {
      final headers = await _getHeaders();
      final response = await http
          .get(Uri.parse(ApiConstants.activePunch), headers: headers)
          .timeout(const Duration(seconds: 5));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['hasActivePunch'] == true && data['attendance'] != null) {
          return AttendanceRecord.fromJson(data['attendance']);
        }
      }
    } catch (_) {}
    return null;
  }

  // Punch In
  static Future<ApiResponse<AttendanceRecord>> punchIn({
    required String siteId,
    required double latitude,
    required double longitude,
    required File photoFile,
    String? address,
    String? notes,
  }) async {
    try {
      final token = await StorageService.getToken();
      final request = http.MultipartRequest('POST', Uri.parse(ApiConstants.punchIn));
      if (token != null) {
        request.headers['Authorization'] = 'Bearer $token';
      }

      request.fields['siteId'] = siteId;
      request.fields['latitude'] = latitude.toString();
      request.fields['longitude'] = longitude.toString();
      if (address != null) request.fields['address'] = address;
      if (notes != null) request.fields['notes'] = notes;

      request.files.add(await http.MultipartFile.fromPath('photo', photoFile.path));

      final streamedResponse = await request.send().timeout(const Duration(seconds: 45));
      final response = await http.Response.fromStream(streamedResponse);
      final data = jsonDecode(response.body);

      if ((response.statusCode == 200 || response.statusCode == 201) && data['success'] == true) {
        final record = AttendanceRecord.fromJson(data['attendance']);
        return ApiResponse(
          success: true,
          message: data['message'] ?? 'Punched in successfully',
          data: record,
        );
      } else {
        return ApiResponse(
          success: false,
          message: data['message'] ?? 'Failed to punch in (HTTP ${response.statusCode})',
        );
      }
    } catch (e) {
      String msg = e.toString();
      if (e.toString().contains('TimeoutException')) {
        msg = 'Connection timed out while transmitting punch-in data. Please retry.';
      }
      return ApiResponse(
        success: false,
        message: 'Punch-in error: $msg',
      );
    }
  }

  // Punch Out
  static Future<ApiResponse<AttendanceRecord>> punchOut({
    String? attendanceId,
    required double latitude,
    required double longitude,
    File? photoFile,
    String? address,
    String? notes,
  }) async {
    try {
      final token = await StorageService.getToken();
      final request = http.MultipartRequest('POST', Uri.parse(ApiConstants.punchOut));
      if (token != null) {
        request.headers['Authorization'] = 'Bearer $token';
      }

      if (attendanceId != null) request.fields['attendanceId'] = attendanceId;
      request.fields['latitude'] = latitude.toString();
      request.fields['longitude'] = longitude.toString();
      if (address != null) request.fields['address'] = address;
      if (notes != null) request.fields['notes'] = notes;

      if (photoFile != null) {
        request.files.add(await http.MultipartFile.fromPath('photo', photoFile.path));
      }

      final streamedResponse = await request.send().timeout(const Duration(seconds: 45));
      final response = await http.Response.fromStream(streamedResponse);
      final data = jsonDecode(response.body);

      if (response.statusCode == 200 && data['success'] == true) {
        final record = AttendanceRecord.fromJson(data['attendance']);
        return ApiResponse(
          success: true,
          message: data['message'] ?? 'Punched out successfully',
          data: record,
        );
      } else {
        return ApiResponse(
          success: false,
          message: data['message'] ?? 'Failed to punch out (HTTP ${response.statusCode})',
        );
      }
    } catch (e) {
      String msg = e.toString();
      if (e.toString().contains('TimeoutException')) {
        msg = 'Connection timed out while transmitting punch-out data. Please retry.';
      }
      return ApiResponse(
        success: false,
        message: 'Punch-out error: $msg',
      );
    }
  }
}
