import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class ProviderApiService {
  // Update this to your Hostinger deployment URL in production (e.g. 'https://yourdomain.com')
  static const String baseUrl = 'https://nayanet.freehub.live'; // Live Hostinger server endpoint
  
  static const String tokenKey = 'provider_jwt_token';
  static const String companyKey = 'provider_company_name';
  static const String emailKey = 'provider_email';
  
  /**
   * Secure REST Login Endpoint for Tenant / ISP Owner
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
          'role': 'tenant' // Secure tenant admin workspace role
        }),
      );
      
      final data = jsonDecode(response.body);
      
      if (response.statusCode == 200) {
        // Save JWT and metadata inside SharedPreferences
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString(tokenKey, data['token']);
        await prefs.setString(companyKey, data['company_name'] ?? 'My ISP');
        await prefs.setString(emailKey, data['email'] ?? email);
        return {'success': true, 'company_name': data['company_name']};
      } else {
        return {'success': false, 'error': data['error'] ?? 'Authentication failed.'};
      }
    } catch (e) {
      return {'success': false, 'error': 'Server connection failed. Verify internet connection.'};
    }
  }
  
  /**
   * Fetch saved JWT Bearer token
   */
  static Future<String?> getToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(tokenKey);
  }
  
  /**
   * Fetch company name metadata
   */
  static Future<String> getCompanyName() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(companyKey) ?? 'My ISP';
  }

  /**
   * Fetch email metadata
   */
  static Future<String> getEmail() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(emailKey) ?? '';
  }
  
  /**
   * Clear session token upon logout
   */
  static Future<void> logout() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(tokenKey);
    await prefs.remove(companyKey);
    await prefs.remove(emailKey);
  }
  
  /**
   * Fetch ISP Admin dashboard KPIs, finances, and cost analytics
   */
  static Future<Map<String, dynamic>?> getDashboardMetrics() async {
    final token = await getToken();
    if (token == null) return null;
    
    final url = Uri.parse('$baseUrl/api/isp/dashboard.php');
    try {
      final response = await http.get(
        url,
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer $token' // Inject JWT Secure Bearer Token
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
   * Fetch available coverages zones
   */
  static Future<List<dynamic>?> getZones() async {
    final token = await getToken();
    if (token == null) return null;
    
    final url = Uri.parse('$baseUrl/api/isp/zones.php');
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
      print('Zones API failure: $e');
    }
    return null;
  }

  /**
   * Fetch available packages rates
   */
  static Future<List<dynamic>?> getPackages() async {
    final token = await getToken();
    if (token == null) return null;
    
    final url = Uri.parse('$baseUrl/api/isp/packages.php');
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
      print('Packages API failure: $e');
    }
    return null;
  }
  
  /**
   * Fetch subscriber base directory with custom filter parameters
   */
  static Future<List<dynamic>?> getCustomers({
    String search = '',
    String status = '',
    int zoneId = 0,
    int expiryFilter = 0,
    int page = 1,
  }) async {
    final token = await getToken();
    if (token == null) return null;
    
    var query = 'page=$page';
    if (search.isNotEmpty) query += '&search=${Uri.encodeComponent(search)}';
    if (status.isNotEmpty) query += '&status=$status';
    if (zoneId > 0) query += '&zone=$zoneId';
    if (expiryFilter > 0) query += '&expiry_filter=$expiryFilter';

    final url = Uri.parse('$baseUrl/api/isp/customers.php?$query');
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
      print('Customers API failure: $e');
    }
    return null;
  }
  
  /**
   * Register a new subscriber to this workspace
   */
  static Future<Map<String, dynamic>> addCustomer(Map<String, dynamic> customerData) async {
    final token = await getToken();
    if (token == null) return {'success': false, 'error': 'Session expired.'};
    
    final url = Uri.parse('$baseUrl/api/isp/customers.php');
    try {
      final response = await http.post(
        url,
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer $token'
        },
        body: jsonEncode(customerData),
      );
      
      final data = jsonDecode(response.body);
      if (response.statusCode == 201) {
        return {'success': true};
      } else {
        return {'success': false, 'error': data['error'] ?? 'Failed to save customer.'};
      }
    } catch (e) {
      return {'success': false, 'error': 'Database interaction failed.'};
    }
  }
  
  /**
   * Fetch invoice list records for the billing desk
   */
  static Future<List<dynamic>?> getInvoices({
    String search = '',
    String status = '',
    int page = 1,
  }) async {
    final token = await getToken();
    if (token == null) return null;
    
    var query = 'page=$page';
    if (search.isNotEmpty) query += '&search=${Uri.encodeComponent(search)}';
    if (status.isNotEmpty) query += '&status=$status';

    final url = Uri.parse('$baseUrl/api/isp/billing.php?$query');
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
  
  /**
   * Process in-app payments collection
   */
  static Future<Map<String, dynamic>> collectPayment(int invoiceId, double paidAmount) async {
    final token = await getToken();
    if (token == null) return {'success': false, 'error': 'Session expired.'};
    
    final url = Uri.parse('$baseUrl/api/isp/billing.php');
    try {
      final response = await http.post(
        url,
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer $token'
        },
        body: jsonEncode({
          'action': 'collect',
          'invoice_id': invoiceId,
          'paid_amount': paidAmount,
        }),
      );
      
      final data = jsonDecode(response.body);
      if (response.statusCode == 200) {
        return {'success': true, 'payment_status': data['payment_status']};
      } else {
        return {'success': false, 'error': data['error'] ?? 'Collect transaction failed.'};
      }
    } catch (e) {
      return {'success': false, 'error': 'Network connection issue.'};
    }
  }
  
  /**
   * Modify invoice details with administrative revisions auditing
   */
  static Future<Map<String, dynamic>> editInvoice(
    int invoiceId, {
    required String packageName,
    required double totalAmount,
    required double paidAmount,
    required String dueDate,
    required String reason,
  }) async {
    final token = await getToken();
    if (token == null) return {'success': false, 'error': 'Session expired.'};
    
    final url = Uri.parse('$baseUrl/api/isp/billing.php');
    try {
      final response = await http.post(
        url,
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer $token'
        },
        body: jsonEncode({
          'action': 'edit',
          'invoice_id': invoiceId,
          'package_name': packageName,
          'total_amount': totalAmount,
          'paid_amount': paidAmount,
          'due_date': dueDate,
          'modification_reason': reason,
        }),
      );
      
      final data = jsonDecode(response.body);
      if (response.statusCode == 200) {
        return {'success': true};
      } else {
        return {'success': false, 'error': data['error'] ?? 'Edit transaction failed.'};
      }
    } catch (e) {
      return {'success': false, 'error': 'Network connection issue.'};
    }
  }
}
