import 'package:flutter/material.dart';
import '../models/user.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';

class AuthProvider extends ChangeNotifier {
  User? _currentUser;
  bool _isLoading = false;
  bool _isInitialized = false;
  String? _errorMessage;

  User? get currentUser => _currentUser;
  bool get isAuthenticated => _currentUser != null;
  bool get isLoading => _isLoading;
  bool get isInitialized => _isInitialized;
  String? get errorMessage => _errorMessage;

  AuthProvider() {
    _loadUserFromStorage();
  }

  Future<void> _loadUserFromStorage() async {
    try {
      final keepLoggedIn = await StorageService.isKeepMeLoggedIn();
      if (keepLoggedIn) {
        _currentUser = await StorageService.getUser();
      } else {
        await StorageService.clear();
        _currentUser = null;
      }
    } catch (e) {
      _currentUser = null;
    } finally {
      _isInitialized = true;
      notifyListeners();
    }
  }

  Future<bool> login({
    required String identifier,
    required String password,
    bool keepMeLoggedIn = true,
  }) async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    try {
      final res = await ApiService.login(
        identifier: identifier,
        password: password,
        keepMeLoggedIn: keepMeLoggedIn,
      );

      _isLoading = false;
      if (res.success && res.data != null) {
        _currentUser = res.data;
        notifyListeners();
        return true;
      } else {
        _errorMessage = res.message;
        notifyListeners();
        return false;
      }
    } catch (e) {
      _isLoading = false;
      _errorMessage = e.toString();
      notifyListeners();
      return false;
    }
  }

  Future<void> logout() async {
    await StorageService.clear();
    _currentUser = null;
    notifyListeners();
  }
}
