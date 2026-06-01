import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class CustomerApiService {
  // Update this to your Hostinger deployment URL in production (e.g. 'https://yourdomain.com')
  static const String baseUrl = 'https://nayanet.freehub.live'; // Live Hostinger server endpoint
  
  static const String tokenKey = 'customer_jwt_token';
  
  /**
   * Secure REST Login Endpoint
   */
  static Future<Map<String, dynamic>> login(String email, String password) async {
    final url = Uri.parse('$baseUrl/api/auth.php');
    
    try {
      final response = await http.post(
        url,
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'email': email,
          'password': password,
          'role': 'customer' // Secure customer portal role
        }),
      );
      
      final data = jsonDecode(response.body);
      
      if (response.statusCode == 200) {
        // Save JWT locally inside SharedPreferences
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString(tokenKey, data['token']);
        return {'success': true, 'name': data['name']};
      } else {
        return {'success': false, 'error': data['error'] ?? 'Authentication failed.'};
      }
    } catch (e) {
      return {'success': false, 'error': 'Server connection failed. Verify internet connection.'};
    }
  }
  
  /**
   * Helper to retrieve cached Bearer JWT
   */
  static Future<String?> getToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(tokenKey);
  }
  
  /**
   * Clear session token upon logout
   */
  static Future<void> logout() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(tokenKey);
  }
  
  /**
   * Fetch Customer profile, speed quotas, remaining days, and notifications
   */
  static Future<Map<String, dynamic>?> getDashboardMetrics() async {
    final token = await getToken();
    if (token == null) return null;
    
    final url = Uri.parse('$baseUrl/api/customer/dashboard.php');
    try {
      final response = await http.get(
        url,
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer $token' // Inject JWT secure bearer token
        },
      );
      
      if (response.statusCode == 200) {
        return jsonDecode(response.body);
      }
    } catch (e) {
      print('Dashboard API failure: $e');
    }
    return null;
  }
  
  /**
   * Fetch paginated list of subscriber invoices
   */
  static Future<List<dynamic>?> getInvoiceLogs() async {
    final token = await getToken();
    if (token == null) return null;
    
    final url = Uri.parse('$baseUrl/api/customer/invoices.php');
    try {
      final response = await http.get(
        url,
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer $token'
        },
      );
      
      if (response.statusCode == 200) {
        return jsonDecode(response.body);
      }
    } catch (e) {
      print('Invoices API failure: $e');
    }
    return null;
  }
}
