import 'package:flutter/material.dart';
import 'services/api.dart';

void main() {
  runApp(const NetPulseProviderApp());
}

class NetPulseProviderApp extends StatelessWidget {
  const NetPulseProviderApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'NetPulse Provider Workspace',
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
    final token = await ProviderApiService.getToken();
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
          child: CircularProgressIndicator(color: Color(0xFF6366F1)),
        ),
      );
    }
    return _isAuthenticated ? const MainNavigationContainer() : const ProviderLoginPage();
  }
}

class ProviderLoginPage extends StatefulWidget {
  const ProviderLoginPage({super.key});

  @override
  State<ProviderLoginPage> createState() => _ProviderLoginPageState();
}

class _ProviderLoginPageState extends State<ProviderLoginPage> {
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _isLoading = false;
  String? _errorMessage;

  Future<void> _handleLogin() async {
    final email = _emailController.text.trim();
    final password = _passwordController.text;

    if (email.isEmpty || password.isEmpty) {
      setState(() {
        _errorMessage = 'Please enter both administrator email and password.';
      });
      return;
    }

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    final result = await ProviderApiService.login(email, password);

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
          _errorMessage = result['error'] ?? 'ISP administrator authentication failed.';
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
                Color(0x1FA855F7), // 12% purple opacity
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
                    color: const Color(0x1AA855F7),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: const Color(0x33A855F7), width: 1.5),
                  ),
                  child: const Icon(
                    Icons.admin_panel_settings_rounded,
                    size: 64,
                    color: Color(0xFFA855F7),
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
                'ISP Provider Workspace',
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
                  labelText: 'Workspace Email',
                  labelStyle: const TextStyle(color: Color(0xFF94A3B8), fontSize: 13),
                  prefixIcon: const Icon(Icons.admin_panel_settings_outlined, color: Color(0xFF94A3B8)),
                  filled: true,
                  fillColor: const Color(0xFF111827),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: Color(0xFF1F2937)),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: Color(0xFFA855F7), width: 1.5),
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
                  labelText: 'Access Password',
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
                    borderSide: const BorderSide(color: Color(0xFFA855F7), width: 1.5),
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
                  backgroundColor: const Color(0xFFA855F7),
                  foregroundColor: Colors.white,
                  disabledBackgroundColor: const Color(0x80A855F7),
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
                        'Unlock Administrative Workspace',
                        style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 15),
                      ),
              ),
              const SizedBox(height: 16),
              const Text(
                'Confidential access channel for ISP Owners, Workspace Tenants, and Workspace Administrators. Subscribers must use the dedicated Customer Portal Client application.',
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
  List<dynamic>? _customers;
  List<dynamic>? _invoices;
  List<dynamic>? _zones;
  List<dynamic>? _packages;
  bool _isLoading = true;
  String _companyName = 'My ISP';

  @override
  void initState() {
    super.initState();
    _loadAllData();
  }

  Future<void> _loadAllData() async {
    setState(() {
      _isLoading = true;
    });

    final company = await ProviderApiService.getCompanyName();
    final dash = await ProviderApiService.getDashboardMetrics();
    final custs = await ProviderApiService.getCustomers();
    final invs = await ProviderApiService.getInvoices();
    final zns = await ProviderApiService.getZones();
    final pkgs = await ProviderApiService.getPackages();

    if (mounted) {
      setState(() {
        _companyName = company;
        _dashboardData = dash;
        _customers = custs;
        _invoices = invs;
        _zones = zns;
        _packages = pkgs;
        _isLoading = false;
      });
    }
  }

  Future<void> _handleLogout() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Workspace Logout', style: TextStyle(fontFamily: 'Outfit')),
        content: const Text('Are you sure you want to close this administrative session? Secure tokens will be cleared.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancel', style: TextStyle(color: Color(0xFF94A3B8))),
          ),
          ElevatedButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFFEF4444)),
            child: const Text('Secure Sign Out', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );

    if (confirm == true) {
      await ProviderApiService.logout();
      if (mounted) {
        Navigator.of(context).pushReplacement(
          MaterialPageRoute(builder: (_) => const ProviderLoginPage()),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final List<Widget> pages = [
      DashboardTab(dashboardData: _dashboardData, onRefresh: _loadAllData),
      CustomersTab(customers: _customers, zones: _zones, packages: _packages, onRefresh: _loadAllData),
      BillingDeskTab(invoices: _invoices, onRefresh: _loadAllData),
      UtilitiesTab(zones: _zones, packages: _packages, onRefresh: _loadAllData),
    ];

    return Scaffold(
      appBar: AppBar(
        backgroundColor: const Color(0xFF080B11),
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              _companyName.toUpperCase(),
              style: const TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 16, color: Colors.white),
            ),
            const Text(
              'NetPulse Tenant Workspace',
              style: TextStyle(fontSize: 10, color: Color(0xFF94A3B8), fontWeight: FontWeight.w600, letterSpacing: 0.5),
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
              child: CircularProgressIndicator(color: Color(0xFFA855F7)),
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
        selectedItemColor: const Color(0xFFA855F7),
        unselectedItemColor: const Color(0xFF64748B),
        type: BottomNavigationBarType.fixed,
        selectedLabelStyle: const TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.w600, fontSize: 11),
        unselectedLabelStyle: const TextStyle(fontFamily: 'Outfit', fontSize: 11),
        items: const [
          BottomNavigationBarItem(
            icon: Icon(Icons.bar_chart_rounded),
            label: 'Analytics',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.people_alt_rounded),
            label: 'Subscribers',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.receipt_long_rounded),
            label: 'Billing Desk',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.layers_rounded),
            label: 'Utilities',
          ),
        ],
      ),
    );
  }
}

class DashboardTab extends StatelessWidget {
  final Map<String, dynamic>? dashboardData;
  final Future<void> Function() onRefresh;

  const DashboardTab({
    super.key,
    required this.dashboardData,
    required this.onRefresh,
  });

  @override
  Widget build(BuildContext context) {
    if (dashboardData == null) {
      return const Center(child: Text('Workspace analytics sync failed.'));
    }

    final kpis = dashboardData!['kpis'] ?? {};
    final financials = dashboardData!['financials'] ?? {};
    final expenses = dashboardData!['expenses'] ?? {};

    return RefreshIndicator(
      onRefresh: onRefresh,
      color: const Color(0xFFA855F7),
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Header
            const Row(
              children: [
                Icon(Icons.insights_rounded, color: Color(0xFFA855F7)),
                SizedBox(width: 8),
                Text(
                  'Workspace Analytics',
                  style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 18),
                ),
              ],
            ),
            const SizedBox(height: 20),

            // Financial P&L Card
            Card(
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  colors: [Color(0xFF1E1B4B), Color(0xFF111827)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
              ),
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  children: [
                    const Text(
                      'ESTIMATED NET PROFIT (CURRENT MONTH)',
                      style: TextStyle(color: Color(0xFF94A3B8), fontSize: 10, fontWeight: FontWeight.bold, letterSpacing: 1),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      financials['collected_payments'] ?? 'Rs. 0.00',
                      style: const TextStyle(
                        fontFamily: 'Outfit',
                        fontWeight: FontWeight.bold,
                        fontSize: 32,
                        color: Colors.white,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, py: 4),
                          decoration: BoxDecoration(
                            color: const Color(0xFF10B981).withOpacity(0.12),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: const Row(
                            children: [
                              Icon(Icons.arrow_upward_rounded, size: 12, color: Color(0xFF10B981)),
                              SizedBox(width: 4),
                              Text(
                                'Profitable Margin',
                                style: TextStyle(color: Color(0xFF10B981), fontSize: 10, fontWeight: FontWeight.bold),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const Divider(color: Color(0xFF1F2937), height: 32),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        _buildMicroFinCard('Billed Revenue', financials['monthly_revenue_billed'] ?? 'Rs. 0'),
                        _buildMicroFinCard('Wholesale Expenses', expenses['monthly_internet_cost'] ?? 'Rs. 0'),
                        _buildMicroFinCard('Net Performance', financials['net_profit_loss'] ?? 'Rs. 0'),
                      ],
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 20),

            // subscriber KPI Grids
            const Text(
              'Subscriber Operations KPIs',
              style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 14),
            ),
            const SizedBox(height: 12),
            GridView.count(
              crossAxisCount: 2,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              mainAxisSpacing: 12,
              crossAxisSpacing: 12,
              childAspectRatio: 1.4,
              children: [
                _buildKPICard('Total Subscribers', kpis['total_subscribers']?.toString() ?? '0', Icons.people_outline_rounded, const Color(0xFF6366F1)),
                _buildKPICard('Active Broadband', kpis['active_subscribers']?.toString() ?? '0', Icons.check_circle_outline_rounded, const Color(0xFF10B981)),
                _buildKPICard('Expired Leases', kpis['expired_subscribers']?.toString() ?? '0', Icons.error_outline_rounded, const Color(0xFFEF4444)),
                _buildKPICard('Alarm Expirations', kpis['expiring_soon_count']?.toString() ?? '0', Icons.hourglass_bottom_rounded, const Color(0xFFF59E0B)),
              ],
            ),
            const SizedBox(height: 20),

            // Wholesale Capacity parameters
            Card(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Wholesale Resource Thresholds',
                      style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 15),
                    ),
                    const SizedBox(height: 16),
                    _buildCapacityRow('Wholesale Purchased Bandwidth', expenses['bandwidth_capacity'] ?? '0 Mbps'),
                    _buildCapacityRow('System Wholesale Costs', expenses['monthly_internet_cost'] ?? 'Rs. 0.00'),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildMicroFinCard(String label, String value) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(color: Color(0xFF64748B), fontSize: 10, fontWeight: FontWeight.w600)),
        const SizedBox(height: 4),
        Text(value, style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold)),
      ],
    );
  }

  Widget _buildKPICard(String label, String count, IconData icon, Color color) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Icon(icon, color: color, size: 24),
                Text(
                  count,
                  style: const TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 22, color: Colors.white),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Text(label, style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 11, fontWeight: FontWeight.w500)),
          ],
        ),
      ),
    );
  }

  Widget _buildCapacityRow(String title, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12.0),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(title, style: const TextStyle(color: Color(0xFF64748B), fontSize: 12)),
          Text(value, style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.bold)),
        ],
      ),
    );
  }
}

class CustomersTab extends StatefulWidget {
  final List<dynamic>? customers;
  final List<dynamic>? zones;
  final List<dynamic>? packages;
  final Future<void> Function() onRefresh;

  const CustomersTab({
    super.key,
    required this.customers,
    required this.zones,
    required this.packages,
    required this.onRefresh,
  });

  @override
  State<CustomersTab> createState() => _CustomersTabState();
}

class _CustomersTabState extends State<CustomersTab> {
  final _searchController = TextEditingController();
  int _selectedZoneId = 0;
  String _selectedStatus = '';
  int _expiryFilter = 0;
  List<dynamic>? _filteredCustomers;
  bool _localLoading = false;

  @override
  void initState() {
    super.initState();
    _filteredCustomers = widget.customers;
  }

  @override
  void didUpdateWidget(covariant CustomersTab oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.customers != oldWidget.customers) {
      _filteredCustomers = widget.customers;
    }
  }

  Future<void> _fetchFilteredList() async {
    setState(() {
      _localLoading = true;
    });

    final results = await ProviderApiService.getCustomers(
      search: _searchController.text.trim(),
      status: _selectedStatus,
      zoneId: _selectedZoneId,
      expiryFilter: _expiryFilter,
    );

    if (mounted) {
      setState(() {
        _filteredCustomers = results;
        _localLoading = false;
      });
    }
  }

  void _showAddCustomerForm() {
    showModalBottomSheet(
      context: context,
      backgroundColor: const Color(0xFF111827),
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) {
        return AddCustomerForm(
          zones: widget.zones ?? [],
          packages: widget.packages ?? [],
          onSuccess: () {
            Navigator.pop(ctx);
            widget.onRefresh();
          },
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton(
        onPressed: _showAddCustomerForm,
        backgroundColor: const Color(0xFFA855F7),
        foregroundColor: Colors.white,
        child: const Icon(Icons.person_add_alt_1_rounded),
      ),
      body: Column(
        children: [
          // Filter desk
          Container(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 12),
            color: const Color(0xFF111827),
            child: Column(
              children: [
                TextField(
                  controller: _searchController,
                  onChanged: (val) => _fetchFilteredList(),
                  style: const TextStyle(fontSize: 13, color: Colors.white),
                  decoration: InputDecoration(
                    hintText: 'Search Name, Phone, CNIC...',
                    hintStyle: const TextStyle(color: Color(0xFF64748B)),
                    prefixIcon: const Icon(Icons.search_rounded, color: Color(0xFF64748B)),
                    filled: true,
                    fillColor: const Color(0xFF080B11),
                    contentPadding: const EdgeInsets.symmetric(vertical: 0),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
                  ),
                ),
                const SizedBox(height: 10),
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: [
                      // Status Filters
                      _buildChipFilter('All Statuses', _selectedStatus == '', () {
                        setState(() => _selectedStatus = '');
                        _fetchFilteredList();
                      }),
                      _buildChipFilter('Active', _selectedStatus == 'active', () {
                        setState(() => _selectedStatus = 'active');
                        _fetchFilteredList();
                      }),
                      _buildChipFilter('Expired', _selectedStatus == 'expired', () {
                        setState(() => _selectedStatus = 'expired');
                        _fetchFilteredList();
                      }),
                      _buildChipFilter('Suspended', _selectedStatus == 'suspended', () {
                        setState(() => _selectedStatus = 'suspended');
                        _fetchFilteredList();
                      }),
                      const SizedBox(width: 8),
                      // Expiry Warning Filter
                      _buildChipFilter('Expiring <= 10 Days', _expiryFilter == 10, () {
                        setState(() => _expiryFilter = _expiryFilter == 10 ? 0 : 10);
                        _fetchFilteredList();
                      }),
                    ],
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: _localLoading
                ? const Center(child: CircularProgressIndicator(color: Color(0xFFA855F7)))
                : RefreshIndicator(
                    onRefresh: widget.onRefresh,
                    color: const Color(0xFFA855F7),
                    child: _filteredCustomers == null
                        ? const Center(child: Text('Unable to sync subscribers list.'))
                        : _filteredCustomers!.isEmpty
                            ? _buildEmptyState()
                            : ListView.builder(
                                physics: const AlwaysScrollableScrollPhysics(),
                                padding: const EdgeInsets.all(16),
                                itemCount: _filteredCustomers!.length,
                                itemBuilder: (ctx, index) {
                                  final c = _filteredCustomers![index];
                                  return _buildCustomerCard(c);
                                },
                              ),
                  ),
          ),
        ],
      ),
    );
  }

  Widget _buildChipFilter(String label, bool isSelected, VoidCallback onTap) {
    return Padding(
      padding: const EdgeInsets.only(right: 6.0),
      child: ChoiceChip(
        label: Text(label, style: const TextStyle(fontSize: 11)),
        selected: isSelected,
        onSelected: (_) => onTap(),
        selectedColor: const Color(0x2AA855F7),
        checkmarkColor: const Color(0xFFA855F7),
        labelStyle: TextStyle(color: isSelected ? const Color(0xFFA855F7) : const Color(0xFF94A3B8)),
        backgroundColor: const Color(0xFF080B11),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20), side: BorderSide(color: isSelected ? const Color(0xFFA855F7) : const Color(0xFF1F2937))),
      ),
    );
  }

  Widget _buildCustomerCard(Map<String, dynamic> c) {
    final status = (c['status'] ?? 'active').toString().toLowerCase();
    Color statusColor = const Color(0xFF10B981);
    if (status == 'suspended') statusColor = const Color(0xFFF59E0B);
    if (status == 'expired') statusColor = const Color(0xFFEF4444);

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Text(
                    c['name'] ?? 'Subscriber',
                    style: const TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 15, color: Colors.white),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, py: 4),
                  decoration: BoxDecoration(
                    color: statusColor.withOpacity(0.1),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: statusColor.withOpacity(0.3)),
                  ),
                  child: Text(
                    status.toUpperCase(),
                    style: TextStyle(color: statusColor, fontSize: 9, fontWeight: FontWeight.bold),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              'Package: ${c['package_name'] ?? 'Custom Plan'} (${c['connection_type'] ?? 'Fiber'})',
              style: const TextStyle(fontSize: 12, color: Color(0xFFCBD5E1)),
            ),
            const SizedBox(height: 2),
            Text(
              'Area: ${c['area'] ?? 'N/A'} (Zone: ${c['zone'] ?? 'N/A'})',
              style: const TextStyle(fontSize: 11, color: Color(0xFF64748B)),
            ),
            const Divider(color: Color(0xFF1F2937), height: 20),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  'Expiry: ${c['expiry_date'] ?? 'N/A'}',
                  style: TextStyle(fontSize: 11, color: status == 'expired' ? const Color(0xFFF87171) : const Color(0xFF94A3B8)),
                ),
                Text(
                  c['monthly_fee_formatted'] ?? 'Rs. ${c['monthly_fee']}',
                  style: const TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 14, color: Colors.white),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildEmptyState() {
    return const Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.people_outline_rounded, size: 48, color: Color(0xFF64748B)),
          SizedBox(height: 16),
          Text('No Subscribers Found', style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 16)),
          SizedBox(height: 8),
          Text('Try searching with other credentials or check connection.', style: TextStyle(color: Color(0xFF64748B), fontSize: 12)),
        ],
      ),
    );
  }
}

class AddCustomerForm extends StatefulWidget {
  final List<dynamic> zones;
  final List<dynamic> packages;
  final VoidCallback onSuccess;

  const AddCustomerForm({
    super.key,
    required this.zones,
    required this.packages,
    required this.onSuccess,
  });

  @override
  State<AddCustomerForm> createState() => _AddCustomerFormState();
}

class _AddCustomerFormState extends State<AddCustomerForm> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _cnicController = TextEditingController();
  final _phoneController = TextEditingController();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final _addressController = TextEditingController();
  final _areaController = TextEditingController();
  final _feeController = TextEditingController();
  final _installController = TextEditingController();

  int? _selectedZoneId;
  int? _selectedPackageId;
  String _selectedConnType = 'Fiber';
  bool _isSaving = false;
  String? _errorMessage;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 20, 20, MediaQuery.of(context).viewInsets.bottom + 24),
      child: Form(
        key: _formKey,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text('Register New Subscriber', style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 18)),
                  IconButton(icon: const Icon(Icons.close_rounded), onPressed: () => Navigator.pop(context)),
                ],
              ),
              const Divider(color: Color(0xFF1F2937)),
              if (_errorMessage != null) ...[
                Text(_errorMessage!, style: const TextStyle(color: Color(0xFFEF4444), fontSize: 12)),
                const SizedBox(height: 12),
              ],
              TextFormField(
                controller: _nameController,
                style: const TextStyle(fontSize: 13),
                decoration: const InputDecoration(labelText: 'Customer Full Name *', labelStyle: TextStyle(fontSize: 12)),
                validator: (val) => val == null || val.isEmpty ? 'Required' : null,
              ),
              Row(
                children: [
                  Expanded(
                    child: TextFormField(
                      controller: _phoneController,
                      style: const TextStyle(fontSize: 13),
                      decoration: const InputDecoration(labelText: 'Phone Number *', labelStyle: TextStyle(fontSize: 12)),
                      validator: (val) => val == null || val.isEmpty ? 'Required' : null,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextFormField(
                      controller: _cnicController,
                      style: const TextStyle(fontSize: 13),
                      decoration: const InputDecoration(labelText: 'CNIC ID *', labelStyle: TextStyle(fontSize: 12)),
                      validator: (val) => val == null || val.isEmpty ? 'Required' : null,
                    ),
                  ),
                ],
              ),
              TextFormField(
                controller: _emailController,
                style: const TextStyle(fontSize: 13),
                decoration: const InputDecoration(labelText: 'Login Email Address *', labelStyle: TextStyle(fontSize: 12)),
                validator: (val) => val == null || val.isEmpty ? 'Required' : null,
              ),
              TextFormField(
                controller: _passwordController,
                obscureText: true,
                style: const TextStyle(fontSize: 13),
                decoration: const InputDecoration(labelText: 'Portal Password *', labelStyle: TextStyle(fontSize: 12)),
                validator: (val) => val == null || val.isEmpty ? 'Required' : null,
              ),
              Row(
                children: [
                  Expanded(
                    child: DropdownButtonFormField<int>(
                      value: _selectedZoneId,
                      style: const TextStyle(fontSize: 13),
                      decoration: const InputDecoration(labelText: 'Zone *', labelStyle: TextStyle(fontSize: 12)),
                      items: widget.zones.map<DropdownMenuItem<int>>((z) {
                        return DropdownMenuItem<int>(
                          value: z['id'],
                          child: Text(z['name']),
                        );
                      }).toList(),
                      onChanged: (val) => setState(() => _selectedZoneId = val),
                      validator: (val) => val == null ? 'Required' : null,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: DropdownButtonFormField<int>(
                      value: _selectedPackageId,
                      style: const TextStyle(fontSize: 13),
                      decoration: const InputDecoration(labelText: 'Rate Plan Package *', labelStyle: TextStyle(fontSize: 12)),
                      items: widget.packages.map<DropdownMenuItem<int>>((p) {
                        return DropdownMenuItem<int>(
                          value: p['id'],
                          child: Text(p['name']),
                        );
                      }).toList(),
                      onChanged: (val) {
                        setState(() {
                          _selectedPackageId = val;
                          final pkg = widget.packages.firstWhere((p) => p['id'] == val, orElse: () => null);
                          if (pkg != null) {
                            _feeController.text = pkg['monthly_price'].toString();
                          }
                        });
                      },
                      validator: (val) => val == null ? 'Required' : null,
                    ),
                  ),
                ],
              ),
              Row(
                children: [
                  Expanded(
                    child: DropdownButtonFormField<String>(
                      value: _selectedConnType,
                      style: const TextStyle(fontSize: 13),
                      decoration: const InputDecoration(labelText: 'Link Type', labelStyle: TextStyle(fontSize: 12)),
                      items: const [
                        DropdownMenuItem(value: 'Fiber', child: Text('Fiber')),
                        DropdownMenuItem(value: 'GPON', child: Text('GPON FTTH')),
                        DropdownMenuItem(value: 'Cable', child: Text('Cable')),
                        DropdownMenuItem(value: 'Wireless', child: Text('Wireless')),
                      ],
                      onChanged: (val) => setState(() => _selectedConnType = val ?? 'Fiber'),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextFormField(
                      controller: _feeController,
                      keyboardType: TextInputType.number,
                      style: const TextStyle(fontSize: 13),
                      decoration: const InputDecoration(labelText: 'Monthly Price Override *', labelStyle: TextStyle(fontSize: 12)),
                      validator: (val) => val == null || val.isEmpty ? 'Required' : null,
                    ),
                  ),
                ],
              ),
              TextFormField(
                controller: _addressController,
                style: const TextStyle(fontSize: 13),
                decoration: const InputDecoration(labelText: 'Physical Address', labelStyle: TextStyle(fontSize: 12)),
              ),
              TextFormField(
                controller: _areaController,
                style: const TextStyle(fontSize: 13),
                decoration: const InputDecoration(labelText: 'Area Division Tag', labelStyle: TextStyle(fontSize: 12)),
              ),
              const SizedBox(height: 24),
              ElevatedButton(
                onPressed: _isSaving ? null : _saveCustomer,
                style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFFA855F7), padding: const EdgeInsets.symmetric(vertical: 14)),
                child: _isSaving
                    ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                    : const Text('Save Subscriber Registry', style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold)),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _saveCustomer() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isSaving = true;
      _errorMessage = null;
    });

    final payload = {
      'name': _nameController.text,
      'cnic': _cnicController.text,
      'phone': _phoneController.text,
      'email': _emailController.text,
      'password': _passwordController.text,
      'address': _addressController.text,
      'area': _areaController.text,
      'zone_id': _selectedZoneId,
      'connection_type': _selectedConnType,
      'assigned_package_id': _selectedPackageId,
      'monthly_fee': double.parse(_feeController.text),
      'installation_fee': double.tryParse(_installController.text) ?? 0.0,
    };

    final res = await ProviderApiService.addCustomer(payload);
    setState(() => _isSaving = false);

    if (res['success'] == true) {
      widget.onSuccess();
    } else {
      setState(() => _errorMessage = res['error'] ?? 'Registry save operation failed.');
    }
  }
}

class BillingDeskTab extends StatefulWidget {
  final List<dynamic>? invoices;
  final Future<void> Function() onRefresh;

  const BillingDeskTab({
    super.key,
    required this.invoices,
    required this.onRefresh,
  });

  @override
  State<BillingDeskTab> createState() => _BillingDeskTabState();
}

class _BillingDeskTabState extends State<BillingDeskTab> {
  final _searchController = TextEditingController();
  String _selectedStatus = '';
  List<dynamic>? _filteredInvoices;
  bool _localLoading = false;

  @override
  void initState() {
    super.initState();
    _filteredInvoices = widget.invoices;
  }

  @override
  void didUpdateWidget(covariant BillingDeskTab oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.invoices != oldWidget.invoices) {
      _filteredInvoices = widget.invoices;
    }
  }

  Future<void> _fetchFilteredList() async {
    setState(() {
      _localLoading = true;
    });

    final results = await ProviderApiService.getInvoices(
      search: _searchController.text.trim(),
      status: _selectedStatus,
    );

    if (mounted) {
      setState(() {
        _filteredInvoices = results;
        _localLoading = false;
      });
    }
  }

  void _showCollectPayment(Map<String, dynamic> inv) {
    showDialog(
      context: context,
      builder: (ctx) {
        final amountController = TextEditingController(text: inv['remaining_amount'].toString());
        bool isSubmitting = false;

        return StatefulBuilder(
          builder: (context, setModalState) {
            return AlertDialog(
              title: const Text('Collect Payments Log', style: TextStyle(fontFamily: 'Outfit')),
              content: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Subscriber: ${inv['subscriber']}', style: const TextStyle(fontWeight: FontWeight.bold)),
                  const SizedBox(height: 12),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text('Total Bill: Rs. ${inv['total_amount']}', style: const TextStyle(fontSize: 12, color: Color(0xFF94A3B8))),
                      Text('Outstanding: Rs. ${inv['remaining_amount']}', style: const TextStyle(fontSize: 12, color: Color(0xFFEF4444), fontWeight: FontWeight.bold)),
                    ],
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: amountController,
                    keyboardType: TextInputType.number,
                    style: const TextStyle(fontSize: 13),
                    decoration: const InputDecoration(labelText: 'Collect Amount (Rs.)', labelStyle: TextStyle(fontSize: 12)),
                  ),
                ],
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx),
                  child: const Text('Cancel', style: TextStyle(color: Color(0xFF94A3B8))),
                ),
                ElevatedButton(
                  onPressed: isSubmitting
                      ? null
                      : () async {
                          final amt = double.tryParse(amountController.text) ?? 0.0;
                          if (amt <= 0 || amt > inv['remaining_amount']) {
                            ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Invalid payment collection bounds.')));
                            return;
                          }

                          setModalState(() => isSubmitting = true);
                          final res = await ProviderApiService.collectPayment(inv['id'], amt);
                          setModalState(() => isSubmitting = false);

                          if (res['success'] == true) {
                            Navigator.pop(ctx);
                            widget.onRefresh();
                          } else {
                            ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(res['error'] ?? 'Payment collect error.')));
                          }
                        },
                  style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF10B981)),
                  child: isSubmitting
                      ? const SizedBox(height: 16, width: 16, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                      : const Text('Save Payment', style: TextStyle(color: Colors.white)),
                ),
              ],
            );
          },
        );
      },
    );
  }

  void _showAuditedInvoiceEditor(Map<String, dynamic> inv) {
    showModalBottomSheet(
      context: context,
      backgroundColor: const Color(0xFF111827),
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) {
        final formKey = GlobalKey<FormState>();
        final pkgController = TextEditingController(text: inv['package']);
        final totalController = TextEditingController(text: inv['total_amount'].toString());
        final paidController = TextEditingController(text: inv['paid_amount'].toString());
        final dueController = TextEditingController(text: inv['due_date'].toString().split(' ')[0]);
        final reasonController = TextEditingController();
        bool isSaving = false;

        return StatefulBuilder(
          builder: (context, setModalState) {
            return Padding(
              padding: EdgeInsets.fromLTRB(20, 20, 20, MediaQuery.of(context).viewInsets.bottom + 24),
              child: Form(
                key: formKey,
                child: SingleChildScrollView(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text('Edit Invoice Audited Details', style: const TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 16)),
                          IconButton(icon: const Icon(Icons.close_rounded), onPressed: () => Navigator.pop(ctx)),
                        ],
                      ),
                      const Divider(color: Color(0xFF1F2937)),
                      const Text(
                        'Audit Rule Enforced: Every change made to an invoice is recorded in dynamic revisions change log with detailed administrative justifications.',
                        style: TextStyle(color: Color(0xFFF59E0B), fontSize: 11, height: 1.4),
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: pkgController,
                        style: const TextStyle(fontSize: 13),
                        decoration: const InputDecoration(labelText: 'Package Snap Label *', labelStyle: TextStyle(fontSize: 12)),
                        validator: (val) => val == null || val.isEmpty ? 'Required' : null,
                      ),
                      Row(
                        children: [
                          Expanded(
                            child: TextFormField(
                              controller: totalController,
                              keyboardType: TextInputType.number,
                              style: const TextStyle(fontSize: 13),
                              decoration: const InputDecoration(labelText: 'Total Bill (Rs.) *', labelStyle: TextStyle(fontSize: 12)),
                              validator: (val) => val == null || val.isEmpty ? 'Required' : null,
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: TextFormField(
                              controller: paidController,
                              keyboardType: TextInputType.number,
                              style: const TextStyle(fontSize: 13),
                              decoration: const InputDecoration(labelText: 'Paid So Far (Rs.) *', labelStyle: TextStyle(fontSize: 12)),
                              validator: (val) => val == null || val.isEmpty ? 'Required' : null,
                            ),
                          ),
                        ],
                      ),
                      TextFormField(
                        controller: dueController,
                        style: const TextStyle(fontSize: 13),
                        decoration: const InputDecoration(labelText: 'Due Date (YYYY-MM-DD) *', labelStyle: TextStyle(fontSize: 12)),
                        validator: (val) => val == null || val.isEmpty ? 'Required' : null,
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: reasonController,
                        maxLines: 3,
                        style: const TextStyle(fontSize: 13),
                        decoration: const InputDecoration(
                          labelText: 'Reason for modification (Mandatory Audit Trail) *',
                          labelStyle: TextStyle(fontSize: 12, color: Color(0xFFF87171)),
                          border: OutlineInputBorder(borderSide: BorderSide(color: Color(0xFFEF4444))),
                        ),
                        validator: (val) => val == null || val.trim().isEmpty ? 'Audited justification reason is strictly required' : null,
                      ),
                      const SizedBox(height: 20),
                      ElevatedButton(
                        onPressed: isSaving
                            ? null
                            : () async {
                                if (!formKey.currentState!.validate()) return;

                                setModalState(() => isSaving = true);
                                final res = await ProviderApiService.editInvoice(
                                  inv['id'],
                                  packageName: pkgController.text.trim(),
                                  totalAmount: double.parse(totalController.text),
                                  paidAmount: double.parse(paidController.text),
                                  dueDate: dueController.text.trim(),
                                  reason: reasonController.text.trim(),
                                );
                                setModalState(() => isSaving = false);

                                if (res['success'] == true) {
                                  Navigator.pop(ctx);
                                  widget.onRefresh();
                                  ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Invoice audited modifications applied successfully!')));
                                } else {
                                  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(res['error'] ?? 'Edit failed.')));
                                }
                              },
                        style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFFA855F7), padding: const EdgeInsets.symmetric(vertical: 14)),
                        child: isSaving
                            ? const SizedBox(height: 16, width: 16, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                            : const Text('Apply Audited Changes', style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold)),
                      ),
                    ],
                  ),
                ),
              ),
            );
          },
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        // Filter card
        Container(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 12),
          color: const Color(0xFF111827),
          child: Column(
            children: [
              TextField(
                controller: _searchController,
                onChanged: (val) => _fetchFilteredList(),
                style: const TextStyle(fontSize: 13, color: Colors.white),
                decoration: InputDecoration(
                  hintText: 'Search Subscriber, Invoice ID...',
                  hintStyle: const TextStyle(color: Color(0xFF64748B)),
                  prefixIcon: const Icon(Icons.search_rounded, color: Color(0xFF64748B)),
                  filled: true,
                  fillColor: const Color(0xFF080B11),
                  contentPadding: const EdgeInsets.symmetric(vertical: 0),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
                ),
              ),
              const SizedBox(height: 10),
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: [
                    _buildChipFilter('All Statuses', _selectedStatus == '', () {
                      setState(() => _selectedStatus = '');
                      _fetchFilteredList();
                    }),
                    _buildChipFilter('Paid', _selectedStatus == 'paid', () {
                      setState(() => _selectedStatus = 'paid');
                      _fetchFilteredList();
                    }),
                    _buildChipFilter('Partial', _selectedStatus == 'partial', () {
                      setState(() => _selectedStatus = 'partial');
                      _fetchFilteredList();
                    }),
                    _buildChipFilter('Pending', _selectedStatus == 'pending', () {
                      setState(() => _selectedStatus = 'pending');
                      _fetchFilteredList();
                    }),
                    _buildChipFilter('Overdue', _selectedStatus == 'overdue', () {
                      setState(() => _selectedStatus = 'overdue');
                      _fetchFilteredList();
                    }),
                  ],
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: _localLoading
              ? const Center(child: CircularProgressIndicator(color: Color(0xFFA855F7)))
              : RefreshIndicator(
                  onRefresh: widget.onRefresh,
                  color: const Color(0xFFA855F7),
                  child: _filteredInvoices == null
                      ? const Center(child: Text('Unable to sync invoices roster.'))
                      : _filteredInvoices!.isEmpty
                          ? _buildEmptyState()
                          : ListView.builder(
                              physics: const AlwaysScrollableScrollPhysics(),
                              padding: const EdgeInsets.all(16),
                              itemCount: _filteredInvoices!.length,
                              itemBuilder: (ctx, index) {
                                final inv = _filteredInvoices![index];
                                return _buildInvoiceCard(inv);
                              },
                            ),
                ),
        ),
      ],
    );
  }

  Widget _buildChipFilter(String label, bool isSelected, VoidCallback onTap) {
    return Padding(
      padding: const EdgeInsets.only(right: 6.0),
      child: ChoiceChip(
        label: Text(label, style: const TextStyle(fontSize: 11)),
        selected: isSelected,
        onSelected: (_) => onTap(),
        selectedColor: const Color(0x2AA855F7),
        checkmarkColor: const Color(0xFFA855F7),
        labelStyle: TextStyle(color: isSelected ? const Color(0xFFA855F7) : const Color(0xFF94A3B8)),
        backgroundColor: const Color(0xFF080B11),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20), side: BorderSide(color: isSelected ? const Color(0xFFA855F7) : const Color(0xFF1F2937))),
      ),
    );
  }

  Widget _buildInvoiceCard(Map<String, dynamic> inv) {
    final status = (inv['payment_status'] ?? 'pending').toString().toLowerCase();
    Color badgeColor = const Color(0xFFEF4444);
    if (status == 'paid') badgeColor = const Color(0xFF10B981);
    if (status == 'partial') badgeColor = const Color(0xFFF59E0B);
    if (status == 'pending') badgeColor = const Color(0xFF3B82F6);

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  inv['invoice_number'] ?? 'INV-N/A',
                  style: const TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 13, color: Colors.white),
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
                    style: TextStyle(color: badgeColor, fontSize: 9, fontWeight: FontWeight.bold),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Text(
              inv['subscriber'] ?? 'Subscriber Name',
              style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Colors.white),
            ),
            Text(
              'Package Snap: ${inv['package']}',
              style: const TextStyle(fontSize: 12, color: Color(0xFF94A3B8)),
            ),
            const SizedBox(height: 4),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('Total: ${inv['total_amount_formatted']}', style: const TextStyle(fontSize: 12, color: Color(0xFFCBD5E1))),
                Text('Due Balance: ${inv['remaining_amount_formatted']}', style: const TextStyle(fontSize: 12, color: Color(0xFFF87171), fontWeight: FontWeight.bold)),
              ],
            ),
            const Divider(color: Color(0xFF1F2937), height: 24),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                if (status != 'paid') ...[
                  IconButton(
                    icon: const Icon(Icons.payment_rounded, color: Color(0xFF10B981)),
                    tooltip: 'Collect Outstanding',
                    onPressed: () => _showCollectPayment(inv),
                  ),
                  const SizedBox(width: 8),
                ],
                IconButton(
                  icon: const Icon(Icons.edit_note_rounded, color: Color(0xFFF59E0B)),
                  tooltip: 'Edit Audited Parameters',
                  onPressed: () => _showAuditedInvoiceEditor(inv),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildEmptyState() {
    return const Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.receipt_long_rounded, size: 48, color: Color(0xFF64748B)),
          SizedBox(height: 16),
          Text('No Invoices generated', style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 16)),
          SizedBox(height: 8),
          Text('Try another keyword or filter criteria.', style: TextStyle(color: Color(0xFF64748B), fontSize: 12)),
        ],
      ),
    );
  }
}

class UtilitiesTab extends StatelessWidget {
  final List<dynamic>? zones;
  final List<dynamic>? packages;
  final Future<void> Function() onRefresh;

  const UtilitiesTab({
    super.key,
    required this.zones,
    required this.packages,
    required this.onRefresh,
  });

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: onRefresh,
      color: const Color(0xFFA855F7),
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Packages header
            const Row(
              children: [
                Icon(Icons.wifi_tethering_rounded, color: Color(0xFFA855F7)),
                SizedBox(width: 8),
                Text('Broadband Packages Registry', style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 15)),
              ],
            ),
            const SizedBox(height: 12),
            if (packages == null || packages!.isEmpty)
              const Center(child: Text('No custom packages configured.'))
            else
              ListView.builder(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: packages!.length,
                itemBuilder: (ctx, idx) {
                  final p = packages![idx];
                  return Card(
                    margin: const EdgeInsets.only(bottom: 8),
                    child: ListTile(
                      title: Text(p['name'] ?? 'Package Name', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                      subtitle: Text(p['description'] ?? 'No description.', style: const TextStyle(fontSize: 11)),
                      trailing: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text('Rs. ${p['monthly_price']}', style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.white, fontSize: 13)),
                          Text('${p['speed_mbps']} Mbps Quota', style: const TextStyle(color: Color(0xFF6366F1), fontSize: 10, fontWeight: FontWeight.w600)),
                        ],
                      ),
                    ),
                  );
                },
              ),

            const SizedBox(height: 24),

            // Zones header
            const Row(
              children: [
                Icon(Icons.map_rounded, color: Color(0xFF6366F1)),
                SizedBox(width: 8),
                Text('Active Coverage Zones', style: TextStyle(fontFamily: 'Outfit', fontWeight: FontWeight.bold, fontSize: 15)),
              ],
            ),
            const SizedBox(height: 12),
            if (zones == null || zones!.isEmpty)
              const Center(child: Text('No active zones configured.'))
            else
              ListView.builder(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: zones!.length,
                itemBuilder: (ctx, idx) {
                  final z = zones![idx];
                  return Card(
                    margin: const EdgeInsets.only(bottom: 8),
                    child: ListTile(
                      title: Text(z['name'] ?? 'Zone Area', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                      subtitle: Text(z['description'] ?? 'No coverage breakdown.', style: const TextStyle(fontSize: 11)),
                      leading: const CircleAvatar(
                        backgroundColor: Color(0x1A6366F1),
                        foregroundColor: Color(0xFF6366F1),
                        child: Icon(Icons.location_on_outlined, size: 20),
                      ),
                    ),
                  );
                },
              ),
          ],
        ),
      ),
    );
  }
}
