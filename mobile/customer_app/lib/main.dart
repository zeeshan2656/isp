import 'package:flutter/material.dart';
import 'services/api.dart';

void main() {
  runApp(const NetPulseCustomerApp());
}

class NetPulseCustomerApp extends StatelessWidget {
  const NetPulseCustomerApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'NetPulse Client',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        brightness: Brightness.dark,
        scaffoldBackgroundColor: const Color(0xFF080B11),
        colorScheme: const ColorScheme.dark(
          primary: Color(0xFF6366F1), // Indigo
          secondary: Color(0xFFA855F7), // Purple
          surface: Color(0xFF111827), // Slate dark surface
          background: Color(0xFF080B11),
          error: Color(0xFFEF4444),
        ),
        textTheme: const TextTheme(
          displayMedium: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, color: Colors.white),
          titleLarge: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.w600, color: Colors.white),
          titleMedium: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.w500, color: Colors.white),
          bodyLarge: TextStyle(fontFamily: 'Inter', color: Color(0xFFCBD5E1)),
          bodyMedium: TextStyle(fontFamily: 'Inter', color: Color(0xFF94A3B8)),
        ),
        cardTheme: const CardTheme(
          color: Color(0xFF111827),
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.all(Radius.circular(16)),
            side: BorderSide(color: Color(0xFF1F2937), width: 1),
          ),
        ),
      ),
      home: const AuthGate(),
    );
  }
}

class AuthGate extends StatefulWidget {
  const AuthGate({super.key});

  @override
  State<AuthGate> createState() => _AuthGateState();
}

class _AuthGateState extends State<AuthGate> {
  bool _isLoading = true;
  bool _isAuthenticated = false;

  @override
  void initState() {
    super.initState();
    _checkAuth();
  }

  Future<void> _checkAuth() async {
    final token = await CustomerApiService.getToken();
    setState(() {
      _isAuthenticated = token != null;
      _isLoading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const Scaffold(
        body: Center(
          child: CircularProgressIndicator(
            color: Color(0xFF6366F1),
          ),
        ),
      );
    }
    return _isAuthenticated ? const MainNavigationContainer() : const CustomerLoginPage();
  }
}

class CustomerLoginPage extends StatefulWidget {
  const CustomerLoginPage({super.key});

  @override
  State<CustomerLoginPage> createState() => _CustomerLoginPageState();
}

class _CustomerLoginPageState extends State<CustomerLoginPage> {
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _isLoading = false;
  String? _errorMessage;

  Future<void> _handleLogin() async {
    final email = _emailController.text.trim();
    final password = _passwordController.text;

    if (email.isEmpty || password.isEmpty) {
      setState(() {
        _errorMessage = 'Please enter both email and password.';
      });
      return;
    }

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    final result = await CustomerApiService.login(email, password);

    if (mounted) {
      setState(() {
        _isLoading = false;
      });

      if (result['success'] == true) {
        Navigator.of(context).pushReplacement(
          MaterialPageRoute(builder: (_) => const MainNavigationContainer()),
        );
      } else {
        setState(() {
          _errorMessage = result['error'] ?? 'Authentication failed.';
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;

    return Scaffold(
      body: SingleChildScrollView(
        child: Container(
          height: size.height,
          padding: const EdgeInsets.symmetric(horizontal: 24),
          decoration: const BoxDecoration(
            gradient: RadialGradient(
              center: Alignment.topLeft,
              radius: 1.2,
              colors: [
                Color(0x1F6366F1), // 12% opacity Indigo
                Color(0xFF080B11),
              ],
            ),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SizedBox(height: 40),
              Center(
                child: Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: const Color(0x1A6366F1),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: const Color(0x336366F1), width: 1.5),
                  ),
                  child: const Icon(
                    Icons.wifi_rounded,
                    size: 64,
                    color: Color(0xFF6366F1),
                  ),
                ),
              ),
              const SizedBox(height: 24),
              Text(
                'NetPulse',
                style: Theme.of(context).textTheme.displayMedium?.copyWith(
                  fontFamily: 'Outfit',
                  letterSpacing: -1,
                ),
                textAlign: Center,
              ),
              const SizedBox(height: 8),
              Text(
                'Customer Portal Login',
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontSize: 16,
                  color: const Color(0xFF94A3B8),
                ),
                textAlign: Center,
              ),
              const SizedBox(height: 40),
              if (_errorMessage != null) ...[
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: const Color(0x1AEF4444),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0x33EF4444)),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.error_outline, color: Color(0xFFEF4444), size: 20),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          _errorMessage!,
                          style: const TextStyle(color: Color(0xFFF87171), fontSize: 13),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
              ],
              TextField(
                controller: _emailController,
                keyboardType: TextInputType.emailAddress,
                style: const TextStyle(color: Colors.white, fontSize: 14),
                decoration: InputDecoration(
                  labelText: 'Email Address',
                  labelStyle: const TextStyle(color: Color(0xFF94A3B8), fontSize: 13),
                  prefixIcon: const Icon(Icons.email_outlined, color: Color(0xFF94A3B8)),
                  filled: true,
                  fillColor: const Color(0xFF111827),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: Color(0xFF1F2937)),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: Color(0xFF6366F1), width: 1.5),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: Color(0xFF1F2937)),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: _passwordController,
                obscureText: true,
                style: const TextStyle(color: Colors.white, fontSize: 14),
                decoration: InputDecoration(
                  labelText: 'Security Password',
                  labelStyle: const TextStyle(color: Color(0xFF94A3B8), fontSize: 13),
                  prefixIcon: const Icon(Icons.lock_outline_rounded, color: Color(0xFF94A3B8)),
                  filled: true,
                  fillColor: const Color(0xFF111827),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: Color(0xFF1F2937)),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: Color(0xFF6366F1), width: 1.5),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: Color(0xFF1F2937)),
                  ),
                ),
              ),
              const SizedBox(height: 28),
              ElevatedButton(
                onPressed: _isLoading ? null : _handleLogin,
                style: ElevatedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  backgroundColor: const Color(0xFF6366F1),
                  foregroundColor: Colors.white,
                  disabledBackgroundColor: const Color(0x806366F1),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                  elevation: 0,
                ),
                child: _isLoading
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                      )
                    : const Text(
                        'Authenticate Login',
                        style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 15),
                      ),
              ),
              const SizedBox(height: 16),
              const Text(
                'Intended only for authorized customers. For ISP manager controls, use the NetPulse Provider Application.',
                style: TextStyle(color: Color(0xFF64748B), fontSize: 11, height: 1.4),
                textAlign: Center,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class MainNavigationContainer extends StatefulWidget {
  const MainNavigationContainer({super.key});

  @override
  State<MainNavigationContainer> createState() => _MainNavigationContainerState();
}

class _MainNavigationContainerState extends State<MainNavigationContainer> {
  int _currentIndex = 0;
  Map<String, dynamic>? _dashboardData;
  List<dynamic>? _invoices;
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadAllData();
  }

  Future<void> _loadAllData() async {
    setState(() {
      _isLoading = true;
    });

    final dash = await CustomerApiService.getDashboardMetrics();
    final invs = await CustomerApiService.getInvoiceLogs();

    if (mounted) {
      setState(() {
        _dashboardData = dash;
        _invoices = invs;
        _isLoading = false;
      });
    }
  }

  Future<void> _handleLogout() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Access Sign Out', style: TextStyle(fontFamily: 'Outfit')),
        content: const Text('Are you sure you want to log out of NetPulse? This will clear your secure session cache.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancel', style: TextStyle(color: Color(0xFF94A3B8))),
          ),
          ElevatedButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFFEF4444)),
            child: const Text('Sign Out', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );

    if (confirm == true) {
      await CustomerApiService.logout();
      if (mounted) {
        Navigator.of(context).pushReplacement(
          MaterialPageRoute(builder: (_) => const CustomerLoginPage()),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final List<Widget> pages = [
      OverviewTab(dashboardData: _dashboardData, onRefresh: _loadAllData),
      BillingTab(invoices: _invoices, onRefresh: _loadAllData),
      InboxTab(notifications: _dashboardData?['notifications'], onRefresh: _loadAllData),
    ];

    return Scaffold(
      appBar: AppBar(
        backgroundColor: const Color(0xFF080B11),
        title: const Row(
          children: [
            Icon(Icons.wifi_rounded, color: Color(0xFF6366F1), size: 24),
            SizedBox(width: 8),
            Text(
              'NetPulse Client',
              style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 18),
            ),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded, color: Color(0xFF94A3B8)),
            onPressed: _loadAllData,
          ),
          IconButton(
            icon: const Icon(Icons.logout_rounded, color: Color(0xFFEF4444)),
            onPressed: _handleLogout,
          ),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(1),
          child: Container(color: const Color(0xFF1F2937), height: 1),
        ),
      ),
      body: _isLoading
          ? const Center(
              child: CircularProgressIndicator(color: Color(0xFF6366F1)),
            )
          : pages[_currentIndex],
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: _currentIndex,
        onTap: (index) {
          setState(() {
            _currentIndex = index;
          });
        },
        backgroundColor: const Color(0xFF111827),
        selectedItemColor: const Color(0xFF6366F1),
        unselectedItemColor: const Color(0xFF64748B),
        selectedLabelStyle: const TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.w600, fontSize: 11),
        unselectedLabelStyle: const TextStyle(fontFamily: 'Outfit', fontSize: 11),
        items: const [
          BottomNavigationBarItem(
            icon: Icon(Icons.grid_view_rounded),
            label: 'Overview',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.receipt_long_rounded),
            label: 'Billing',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.notifications_active_rounded),
            label: 'Inbox',
          ),
        ],
      ),
    );
  }
}

class OverviewTab extends StatelessWidget {
  final Map<String, dynamic>? dashboardData;
  final Future<void> Function() onRefresh;

  const OverviewTab({
    super.key,
    required this.dashboardData,
    required this.onRefresh,
  });

  @override
  Widget build(BuildContext context) {
    if (dashboardData == null) {
      return _buildErrorState();
    }

    final cust = dashboardData!['customer'] ?? {};
    final pkg = dashboardData!['package'] ?? {};
    final isp = dashboardData!['isp'] ?? {};

    final remainingDays = cust['days_remaining'] ?? 0;
    final status = (cust['status'] ?? 'inactive').toString().toUpperCase();
    final expiryStatus = cust['expiry_status'] ?? 'Safe';
    final name = cust['name'] ?? 'Client';

    // Color coordination based on subscriber status
    Color statusColor = const Color(0xFF10B981); // active - Emerald
    if (status == 'SUSPENDED') {
      statusColor = const Color(0xFFF59E0B); // Amber
    } else if (status == 'EXPIRED') {
      statusColor = const Color(0xFFEF4444); // Red
    }

    return RefreshIndicator(
      onRefresh: onRefresh,
      color: const Color(0xFF6366F1),
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Welcome Card
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Welcome Back,',
                        style: TextStyle(color: Color(0xFF94A3B8), fontSize: 13),
                      ),
                      Text(
                        name,
                        style: const TextStyle(
                          fontFamily: 'Outfit',
                          fontWeight: FontWeight.bold,
                          fontSize: 20,
                          color: Colors.white,
                        ),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, py: 6),
                  decoration: BoxDecoration(
                    color: statusColor.withOpacity(0.12),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: statusColor.withOpacity(0.3), width: 1.5),
                  ),
                  child: Text(
                    status,
                    style: TextStyle(
                      color: statusColor,
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                      letterSpacing: 0.05,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 24),

            // Expiry Countdown Badge Panel
            Card(
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 24, horizontal: 16),
                child: Column(
                  children: [
                    Stack(
                      alignment: Alignment.center,
                      children: [
                        SizedBox(
                          height: 140,
                          width: 140,
                          child: CircularProgressIndicator(
                            value: (remainingDays > 30) ? 1.0 : (remainingDays / 30.0),
                            strokeWidth: 10,
                            backgroundColor: const Color(0xFF1F2937),
                            color: remainingDays <= 5 ? const Color(0xFFEF4444) : const Color(0xFF6366F1),
                          ),
                        ),
                        Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(
                              '$remainingDays',
                              style: const TextStyle(
                                fontFamily: 'Outfit',
                                fontWeight: FontWeight.bold,
                                fontSize: 42,
                                color: Colors.white,
                              ),
                            ),
                            const Text(
                              'DAYS LEFT',
                              style: TextStyle(
                                fontSize: 10,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF64748B),
                                letterSpacing: 1,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                    const SizedBox(height: 24),
                    Text(
                      remainingDays <= 5
                          ? 'Renewal Required Soon!'
                          : 'Your connection is stable and secured.',
                      style: TextStyle(
                        fontWeight: FontWeight.w600,
                        fontSize: 14,
                        color: remainingDays <= 5 ? const Color(0xFFF87171) : const Color(0xFFCBD5E1),
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Account Expiry: ${cust['expiry_date'] ?? 'N/A'}',
                      style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),

            // Speed & Quota Card Group
            Row(
              children: [
                Expanded(
                  child: Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Icon(Icons.speed_rounded, color: Color(0xFF6366F1), size: 28),
                          const SizedBox(height: 12),
                          const Text('Internet Bandwidth', style: TextStyle(color: Color(0xFF64748B), fontSize: 11)),
                          const SizedBox(height: 4),
                          Text(
                            pkg['speed'] ?? 'N/A',
                            style: const TextStyle(
                              fontFamily: 'Outfit',
                              fontWeight: FontWeight.bold,
                              fontSize: 20,
                              color: Colors.white,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Icon(Icons.payment_rounded, color: Color(0xFFA855F7), size: 28),
                          const SizedBox(height: 12),
                          const Text('Monthly Charge', style: TextStyle(color: Color(0xFF64748B), fontSize: 11)),
                          const SizedBox(height: 4),
                          Text(
                            pkg['monthly_fee'] ?? 'N/A',
                            style: const TextStyle(
                              fontFamily: 'Outfit',
                              fontWeight: FontWeight.bold,
                              fontSize: 20,
                              color: Colors.white,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),

            // Connection Details Card
            Card(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Connection Parameters',
                      style: TextStyle(
                        fontFamily: 'Outfit',
                        fontWeight: FontWeight.bold,
                        fontSize: 15,
                        color: Colors.white,
                      ),
                    ),
                    const SizedBox(height: 16),
                    _buildDetailRow('Assigned Plan', pkg['name'] ?? 'N/A'),
                    _buildDetailRow('Connection Type', cust['connection_type'] ?? 'Fiber'),
                    _buildDetailRow('Coverage Area', cust['area'] ?? 'N/A'),
                    _buildDetailRow('Zone', cust['zone'] ?? 'N/A'),
                    _buildDetailRow('Activation Date', cust['activation_date'] ?? 'N/A'),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),

            // ISP Support Helpdesk Card
            Card(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      isp['company_name'] ?? 'ISP Support desk',
                      style: const TextStyle(
                        fontFamily: 'Outfit',
                        fontWeight: FontWeight.bold,
                        fontSize: 15,
                        color: Colors.white,
                      ),
                    ),
                    const SizedBox(height: 4),
                    const Text(
                      'For queries, upgrades, or connection failures, reach us via:',
                      style: TextStyle(color: Color(0xFF94A3B8), fontSize: 12),
                    ),
                    const SizedBox(height: 16),
                    Row(
                      children: [
                        const Icon(Icons.phone_outlined, size: 18, color: Color(0xFF6366F1)),
                        const SizedBox(width: 8),
                        Text(
                          isp['support_phone'] ?? 'N/A',
                          style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.bold),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        const Icon(Icons.email_outlined, size: 18, color: Color(0xFF6366F1)),
                        const SizedBox(width: 8),
                        Text(
                          isp['support_email'] ?? 'N/A',
                          style: const TextStyle(color: Colors.white, fontSize: 13),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildDetailRow(String title, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12.0),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(title, style: const TextStyle(color: Color(0xFF64748B), fontSize: 12)),
          Text(value, style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }

  Widget _buildErrorState() {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(24),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.cloud_off_rounded, size: 48, color: Color(0xFFEF4444)),
            SizedBox(height: 16),
            Text(
              'Database Sync Unsuccessful',
              style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 16),
            ),
            SizedBox(height: 8),
            Text(
              'We were unable to load dashboard parameters. Pull to refresh or sign in again.',
              style: TextStyle(color: Color(0xFF64748B), fontSize: 12),
              textAlign: Center,
            ),
          ],
        ),
      ),
    );
  }
}

class BillingTab extends StatelessWidget {
  final List<dynamic>? invoices;
  final Future<void> Function() onRefresh;

  const BillingTab({
    super.key,
    required this.invoices,
    required this.onRefresh,
  });

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: onRefresh,
      color: const Color(0xFF6366F1),
      child: invoices == null
          ? _buildErrorState()
          : invoices!.isEmpty
              ? _buildEmptyState()
              : ListView.builder(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(16),
                  itemCount: invoices!.length,
                  itemBuilder: (ctx, index) {
                    final inv = invoices![index];
                    return _buildInvoiceCard(ctx, inv);
                  },
                ),
    );
  }

  Widget _buildInvoiceCard(BuildContext context, Map<String, dynamic> inv) {
    final String status = (inv['payment_status'] ?? 'pending').toString().toLowerCase();
    final double amount = (inv['total_amount'] ?? 0.0).toDouble();
    final String invoiceNum = inv['invoice_number'] ?? 'INV-N/A';
    final String pkgName = inv['package_name'] ?? 'Plan Package';

    Color badgeColor = const Color(0xFFEF4444); // overdue/pending - Red
    if (status == 'paid') {
      badgeColor = const Color(0xFF10B981); // Emerald
    } else if (status == 'partial') {
      badgeColor = const Color(0xFFF59E0B); // Amber
    } else if (status == 'pending') {
      badgeColor = const Color(0xFF3B82F6); // Blue
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => _showInvoiceDetails(context, inv),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    invoiceNum,
                    style: const TextStyle(
                      fontFamily: 'Outfit',
                      fontWeight: FontWeight.bold,
                      fontSize: 14,
                      color: Colors.white,
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, py: 4),
                    decoration: BoxDecoration(
                      color: badgeColor.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: badgeColor.withOpacity(0.3)),
                    ),
                    child: Text(
                      status.toUpperCase(),
                      style: TextStyle(
                        color: badgeColor,
                        fontSize: 10,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Text(
                pkgName,
                style: const TextStyle(fontSize: 13, color: Color(0xFFCBD5E1), fontWeight: FontWeight.w600),
              ),
              const SizedBox(height: 4),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    'Due Date: ${inv['due_date_formatted'] ?? inv['due_date'] ?? 'N/A'}',
                    style: const TextStyle(fontSize: 11, color: Color(0xFF64748B)),
                  ),
                  Text(
                    inv['total_amount_formatted'] ?? 'Rs. $amount',
                    style: const TextStyle(
                      fontFamily: 'Outfit',
                      fontWeight: FontWeight.bold,
                      fontSize: 16,
                      color: Colors.white,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _showInvoiceDetails(BuildContext context, Map<String, dynamic> inv) {
    showModalBottomSheet(
      context: context,
      backgroundColor: const Color(0xFF111827),
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) {
        final double total = (inv['total_amount'] ?? 0.0).toDouble();
        final double paid = (inv['paid_amount'] ?? 0.0).toDouble();
        final double balance = (inv['remaining_amount'] ?? 0.0).toDouble();

        return Padding(
          padding: EdgeInsets.fromLTRB(20, 20, 20, MediaQuery.of(ctx).viewInsets.bottom + 32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  height: 4,
                  width: 40,
                  decoration: BoxDecoration(
                    color: const Color(0xFF1F2937),
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
              const SizedBox(height: 20),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text(
                    'Receipt Parameters',
                    style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 18),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close_rounded),
                    onPressed: () => Navigator.pop(ctx),
                  ),
                ],
              ),
              const Divider(color: Color(0xFF1F2937)),
              const SizedBox(height: 12),
              _buildDetailItem('Invoice Number', inv['invoice_number'] ?? 'N/A'),
              _buildDetailItem('Service Package', inv['package_name'] ?? 'N/A'),
              _buildDetailItem('Issue Date', inv['created_at'] != null ? inv['created_at'].toString().split(' ')[0] : 'N/A'),
              _buildDetailItem('Due Date', inv['due_date'] ?? 'N/A'),
              _buildDetailItem('Payment Date', inv['payment_date_formatted'] ?? 'N/A'),
              _buildDetailItem('Payment Status', (inv['payment_status'] ?? 'N/A').toString().toUpperCase()),
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: const Color(0xFF080B11),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: const Color(0xFF1F2937)),
                ),
                child: Column(
                  children: [
                    _buildBalanceRow('Gross Amount', inv['total_amount_formatted'] ?? 'Rs. $total', isBold: false),
                    const SizedBox(height: 8),
                    _buildBalanceRow('Payments Logged', inv['paid_amount_formatted'] ?? 'Rs. $paid', color: const Color(0xFF10B981)),
                    const Divider(color: Color(0xFF1F2937), height: 16),
                    _buildBalanceRow('Outstanding Balance', inv['remaining_amount_formatted'] ?? 'Rs. $balance', color: balance > 0 ? const Color(0xFFEF4444) : Colors.white, isBold: true),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _buildDetailItem(String title, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6.0),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(title, style: const TextStyle(color: Color(0xFF64748B), fontSize: 12)),
          Text(value, style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }

  Widget _buildBalanceRow(String title, String value, {Color? color, bool isBold = false, bool isStrike = false}) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(title, style: TextStyle(color: isBold ? Colors.white : const Color(0xFF94A3B8), fontSize: isBold ? 13 : 12, fontWeight: isBold ? FontWeight.bold : FontWeight.normal)),
        Text(
          value,
          style: TextStyle(
            color: color ?? Colors.white,
            fontSize: isBold ? 14 : 12,
            fontWeight: isBold ? FontWeight.bold : FontWeight.w600,
            decoration: isStrike ? TextDecoration.lineThrough : null,
          ),
        ),
      ],
    );
  }

  Widget _buildErrorState() {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(24),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.cloud_off_rounded, size: 48, color: Color(0xFFEF4444)),
            SizedBox(height: 16),
            Text(
              'Database Sync Unsuccessful',
              style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 16),
            ),
            SizedBox(height: 8),
            Text(
              'We were unable to load invoice registry records. Pull down to refresh.',
              style: TextStyle(color: Color(0xFF64748B), fontSize: 12),
              textAlign: Center,
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildEmptyState() {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(24),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.receipt_rounded, size: 48, color: Color(0xFF64748B)),
            SizedBox(height: 16),
            Text(
              'No Invoices Logged',
              style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 16),
            ),
            SizedBox(height: 8),
            Text(
              'No billing invoices have been created for your customer account yet.',
              style: TextStyle(color: Color(0xFF64748B), fontSize: 12),
              textAlign: Center,
            ),
          ],
        ),
      ),
    );
  }
}

class InboxTab extends StatelessWidget {
  final List<dynamic>? notifications;
  final Future<void> Function() onRefresh;

  const InboxTab({
    super.key,
    required this.notifications,
    required this.onRefresh,
  });

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: onRefresh,
      color: const Color(0xFF6366F1),
      child: notifications == null
          ? _buildErrorState()
          : notifications!.isEmpty
              ? _buildEmptyState()
              : ListView.builder(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(16),
                  itemCount: notifications!.length,
                  itemBuilder: (ctx, index) {
                    final notif = notifications![index];
                    return _buildNotificationCard(notif);
                  },
                ),
    );
  }

  Widget _buildNotificationCard(Map<String, dynamic> notif) {
    final type = (notif['type'] ?? 'info').toString().toLowerCase();
    final title = notif['title'] ?? 'Alert Notification';
    final message = notif['message'] ?? '';
    final date = notif['created_at'] ?? '';

    IconData icon = Icons.info_outline_rounded;
    Color iconColor = const Color(0xFF6366F1); // Info - Indigo
    if (type == 'payment') {
      icon = Icons.payment_rounded;
      iconColor = const Color(0xFF10B981); // Emerald
    } else if (type == 'expiry') {
      icon = Icons.hourglass_bottom_rounded;
      iconColor = const Color(0xFFEF4444); // Red
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: iconColor.withOpacity(0.1),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, color: iconColor, size: 20),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Expanded(
                        child: Text(
                          title,
                          style: const TextStyle(
                            fontFamily: 'Outfit',
                            fontWeight: FontWeight.bold,
                            fontSize: 13,
                            color: Colors.white,
                          ),
                        ),
                      ),
                      if (date.isNotEmpty)
                        Text(
                          date.split(' ')[0],
                          style: const TextStyle(color: Color(0xFF64748B), fontSize: 10),
                        ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(
                    message,
                    style: const TextStyle(fontSize: 12, color: Color(0xFFCBD5E1), height: 1.4),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildErrorState() {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(24),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.cloud_off_rounded, size: 48, color: Color(0xFFEF4444)),
            SizedBox(height: 16),
            Text(
              'Database Sync Unsuccessful',
              style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 16),
            ),
            SizedBox(height: 8),
            Text(
              'We were unable to sync message feeds. Pull down to refresh.',
              style: TextStyle(color: Color(0xFF64748B), fontSize: 12),
              textAlign: Center,
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildEmptyState() {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(24),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.chat_bubble_outline_rounded, size: 48, color: Color(0xFF64748B)),
            SizedBox(height: 16),
            Text(
              'Your Inbox is Clear',
              style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 16),
            ),
            SizedBox(height: 8),
            Text(
              'No active network broadcast or payment alerts at this moment.',
              style: TextStyle(color: Color(0xFF64748B), fontSize: 12),
              textAlign: Center,
            ),
          ],
        ),
      ),
    );
  }
}
