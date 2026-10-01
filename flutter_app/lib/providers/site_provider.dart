import 'dart:io';
import 'package:flutter/material.dart';
import '../models/site.dart';
import '../models/attendance.dart';
import '../services/api_service.dart';
import '../services/location_service.dart';

class SiteProvider extends ChangeNotifier {
  List<Site> _sites = [];
  Site? _selectedSite;
  AttendanceRecord? _activeAttendance;
  bool _isLoading = false;
  String? _errorMessage;

  List<Site> get sites => _sites;
  Site? get selectedSite => _selectedSite;
  AttendanceRecord? get activeAttendance => _activeAttendance;
  bool get isPunchedIn => _activeAttendance != null && _activeAttendance!.isPunchedIn;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  Site? get punchedSite {
    if (!isPunchedIn || _activeAttendance == null) return null;
    if (_activeAttendance!.site != null) return _activeAttendance!.site;
    try {
      return _sites.firstWhere((s) => s.id == _activeAttendance!.siteId);
    } catch (_) {
      return _selectedSite;
    }
  }

  // Always show all sites; the UI disables non-active sites while punched in
  List<Site> get visibleSites => _sites;

  Future<void> fetchSites() async {
    _isLoading = true;
    notifyListeners();

    _sites = await ApiService.getSites();
    if (_sites.isEmpty) {
      _selectedSite = null;
    } else if (_selectedSite == null || !_sites.any((s) => s.id == _selectedSite!.id)) {
      _selectedSite = _sites.first;
    }

    _activeAttendance = await ApiService.getActivePunch();

    _isLoading = false;
    notifyListeners();
  }

  void selectSite(Site site) {
    _selectedSite = site;
    notifyListeners();
  }

  Future<bool> punchIn({
    required File photoFile,
    String? notes,
  }) async {
    if (_selectedSite == null) {
      _errorMessage = 'Please select a construction site';
      notifyListeners();
      return false;
    }

    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    final location = await LocationService.getCurrentLocation();
    if (location == null) {
      _isLoading = false;
      _errorMessage = 'Unable to get GPS location. Please check location permissions.';
      notifyListeners();
      return false;
    }

    final res = await ApiService.punchIn(
      siteId: _selectedSite!.id,
      latitude: location.latitude,
      longitude: location.longitude,
      photoFile: photoFile,
      address: location.address,
      notes: notes,
    );

    _isLoading = false;
    if (res.success && res.data != null) {
      _activeAttendance = res.data;
      if (_activeAttendance?.site != null) {
        _selectedSite = _activeAttendance!.site;
      }
      notifyListeners();
      return true;
    } else {
      _errorMessage = res.message;
      notifyListeners();
      return false;
    }
  }

  Future<bool> punchOut({
    File? photoFile,
    String? notes,
  }) async {
    if (_activeAttendance == null) {
      _errorMessage = 'No active punch found.';
      notifyListeners();
      return false;
    }

    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    final location = await LocationService.getCurrentLocation();
    if (location == null) {
      _isLoading = false;
      _errorMessage = 'Unable to get GPS location.';
      notifyListeners();
      return false;
    }

    final res = await ApiService.punchOut(
      attendanceId: _activeAttendance!.id,
      latitude: location.latitude,
      longitude: location.longitude,
      photoFile: photoFile,
      address: location.address,
      notes: notes,
    );

    _isLoading = false;
    if (res.success) {
      _activeAttendance = null;
      if (_sites.isEmpty) {
        _selectedSite = null;
      } else if (_selectedSite == null || !_sites.any((s) => s.id == _selectedSite!.id)) {
        _selectedSite = _sites.first;
      }
      notifyListeners();
      return true;
    } else {
      _errorMessage = res.message;
      notifyListeners();
      return false;
    }
  }
}
