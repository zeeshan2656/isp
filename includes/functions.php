<?php
/**
 * Global Utility Helper Functions
 */

defined('SECURE_ACCESS') or die('Direct access denied');

/**
 * Format Currency Values
 */
function format_currency($amount) {
    return 'Rs. ' . number_format((double)$amount, 2);
}

/**
 * Format Bandwidth values
 */
function format_bandwidth($speed) {
    return (int)$speed . ' Mbps';
}

/**
 * Calculate Remaining Days to Expiry
 */
function calculate_days_remaining($expiry_date) {
    $today = new DateTime();
    $expiry = new DateTime($expiry_date);
    
    // Set times to 00:00:00 to calculate true calendar days
    $today->setTime(0, 0, 0);
    $expiry->setTime(0, 0, 0);
    
    if ($today > $expiry) {
        return 0; // Already expired
    }
    
    $interval = $today->diff($expiry);
    return (int)$interval->format('%r%a');
}

/**
 * Get Color-coded CSS Class for Subscription Expiry
 */
function get_expiry_alert_config($expiry_date) {
    $days = calculate_days_remaining($expiry_date);
    
    if ($days <= 0) {
        return [
            'class' => 'danger-pulse',
            'label' => 'Expired',
            'bg' => '#EF4444',
            'text' => '#FFFFFF',
            'days' => $days
        ];
    } elseif ($days == 1) {
        return [
            'class' => 'expiry-1day',
            'label' => '1 Day Left',
            'bg' => '#DC2626',
            'text' => '#FFFFFF',
            'days' => $days
        ];
    } elseif ($days <= 3) {
        return [
            'class' => 'expiry-3days',
            'label' => $days . ' Days Left',
            'bg' => '#EA580C',
            'text' => '#FFFFFF',
            'days' => $days
        ];
    } elseif ($days <= 5) {
        return [
            'class' => 'expiry-5days',
            'label' => $days . ' Days Left',
            'bg' => '#F59E0B',
            'text' => '#0F172A',
            'days' => $days
        ];
    } elseif ($days <= 7) {
        return [
            'class' => 'expiry-7days',
            'label' => $days . ' Days Left',
            'bg' => '#10B981',
            'text' => '#FFFFFF',
            'days' => $days
        ];
    } elseif ($days <= 10) {
        return [
            'class' => 'expiry-10days',
            'label' => $days . ' Days Left',
            'bg' => '#3B82F6',
            'text' => '#FFFFFF',
            'days' => $days
        ];
    } else {
        return [
            'class' => 'expiry-active',
            'label' => $days . ' Days Left',
            'bg' => '#1F2937',
            'text' => '#9CA3AF',
            'days' => $days
        ];
    }
}

/**
 * Render Bootstrap Invoice Status Badges
 */
function get_invoice_status_badge($status) {
    switch (strtolower($status)) {
        case 'paid':
            return '<span class="badge bg-success-soft text-success px-2.5 py-1 rounded-pill" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.25);">Paid</span>';
        case 'partial':
            return '<span class="badge bg-warning-soft text-warning px-2.5 py-1 rounded-pill" style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.25);">Partial</span>';
        case 'pending':
            return '<span class="badge bg-info-soft text-info px-2.5 py-1 rounded-pill" style="background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.25);">Pending</span>';
        case 'overdue':
            return '<span class="badge bg-danger-soft text-danger px-2.5 py-1 rounded-pill animate-pulse" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.25);">Overdue</span>';
        default:
            return '<span class="badge bg-secondary-soft text-light px-2.5 py-1 rounded-pill">' . e($status) . '</span>';
    }
}

/**
 * Display Alert Messages (Flash Sessions)
 */
function display_session_alerts() {
    if (isset($_SESSION['flash_alert'])) {
        $alert = $_SESSION['flash_alert'];
        unset($_SESSION['flash_alert']);
        
        $type = $alert['type'] ?? 'info';
        $message = $alert['message'] ?? '';
        
        $bg = '#1E293B';
        $border = 'rgba(255,255,255,0.08)';
        $text = '#E2E8F0';
        
        if ($type === 'success') {
            $bg = 'rgba(16, 185, 129, 0.1)';
            $border = 'rgba(16, 185, 129, 0.2)';
            $text = '#34D399';
        } elseif ($type === 'error' || $type === 'danger') {
            $bg = 'rgba(239, 68, 68, 0.1)';
            $border = 'rgba(239, 68, 68, 0.2)';
            $text = '#F87171';
        } elseif ($type === 'warning') {
            $bg = 'rgba(245, 158, 11, 0.1)';
            $border = 'rgba(245, 158, 11, 0.2)';
            $text = '#FBBF24';
        }
        
        echo "<div class='alert border d-flex align-items-center justify-content-between p-3 rounded-lg mb-4' style='background: {$bg}; border-color: {$border}; color: {$text}; backdrop-filter: blur(8px);'>
                <div class='d-flex align-items-center gap-2'>
                    <span>" . e($message) . "</span>
                </div>
                <button type='button' class='btn-close btn-close-white' data-bs-dismiss='alert' aria-label='Close' style='font-size: 0.8rem; opacity: 0.7; box-shadow: none;'></button>
              </div>";
    }
}

/**
 * Set Session Flash Alert Helper
 */
function set_session_alert($message, $type = 'success') {
    $_SESSION['flash_alert'] = [
        'message' => $message,
        'type' => $type
    ];
}

/**
 * Generate highly-unique invoice number
 */
function generate_invoice_number($tenant_id, $customer_id) {
    return 'INV-' . date('Ymd') . '-' . str_pad($tenant_id, 3, '0', STR_PAD_LEFT) . '-' . str_pad($customer_id, 4, '0', STR_PAD_LEFT) . '-' . rand(100, 999);
}

/**
 * Format dynamic dates
 */
function format_date($date) {
    if (!$date) return 'N/A';
    return date('d M, Y', strtotime($date));
}

/**
 * Trigger Customer-to-Customer Referral Rewards on Broadband Paid Invoice
 */
function trigger_customer_referral_reward($pdo, $tenant_id, $customer_id, $paid_amount, $invoice_id) {
    try {
        // 1. Fetch if the customer was referred by another customer
        $stmt = $pdo->prepare("SELECT referred_by_id FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$customer_id, $tenant_id]);
        $referred_by_id = $stmt->fetchColumn();
        
        if ($referred_by_id && $referred_by_id > 0) {
            // 2. Fetch tenant referral settings
            $stmt_set = $pdo->prepare("SELECT enabled, reward_type, reward_value FROM tenant_referral_settings WHERE tenant_id = ? LIMIT 1");
            $stmt_set->execute([$tenant_id]);
            $settings = $stmt_set->fetch();
            
            if ($settings && (int)$settings['enabled'] === 1) {
                $reward_type = $settings['reward_type'];
                $reward_val = (double)$settings['reward_value'];
                
                // Calculate Reward
                $reward_amt = 0.00;
                if ($reward_type === 'fixed') {
                    $reward_amt = $reward_val;
                } elseif ($reward_type === 'percentage') {
                    $reward_amt = ($paid_amount * $reward_val) / 100;
                }
                
                if ($reward_amt > 0) {
                    // Credit referring customer wallet
                    $stmt_c = $pdo->prepare("UPDATE customers SET referral_wallet = referral_wallet + ? WHERE id = ? AND tenant_id = ?");
                    $stmt_c->execute([$reward_amt, $referred_by_id, $tenant_id]);
                    
                    // Log to ledger
                    $notes = "Commission credit for referring new customer ID: $customer_id. Paid on invoice ID: $invoice_id.";
                    $stmt_ledg = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES ('customer', ?, 'commission_credit', ?, ?, 'approved', ?)");
                    $stmt_ledg->execute([$referred_by_id, $reward_amt, $invoice_id, $notes]);
                    
                    // Create Notification for the referrer
                    $notif_title = "Referral Reward Credited!";
                    $notif_msg = "Awesome! You have been credited Rs. " . number_format($reward_amt, 2) . " to your referral wallet for referring a new subscriber.";
                    $stmt_notif = $pdo->prepare("INSERT INTO notifications (tenant_id, customer_id, title, message, type) VALUES (?, ?, ?, ?, 'payment')");
                    $stmt_notif->execute([$tenant_id, $referred_by_id, $notif_title, $notif_msg]);
                    
                    error_log("Triggered customer referral reward of Rs. $reward_amt for referrer customer ID: $referred_by_id");
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Failed to trigger customer referral rewards: " . $e->getMessage());
    }
}

/**
 * Fetch dynamic platform/organization name
 */
function get_platform_name() {
    global $pdo;
    static $platform_name = null;
    if ($platform_name !== null) {
        return $platform_name;
    }
    
    if (!isset($pdo)) {
        try {
            // Attempt to load database configuration if not already loaded
            $db_path = __DIR__ . '/../config/database.php';
            if (file_exists($db_path)) {
                require_once $db_path;
            }
        } catch (Exception $e) {
            // ignore
        }
    }
    
    // Fallback if DB is not initialized or query fails
    if (isset($pdo)) {
        try {
            $stmt = $pdo->query("SELECT setting_value FROM saas_settings WHERE setting_key = 'organization_name' LIMIT 1");
            $val = $stmt->fetchColumn();
            if ($val !== false && trim($val) !== '') {
                $platform_name = trim($val);
                return $platform_name;
            }
        } catch (Exception $e) {
            // Table might not exist yet or connection is down
        }
    }
    
    return 'NetPulse';
}

/**
 * Dynamically extract capital alphabets from platform name to generate logo (e.g. NetPluls -> NP, NayeNet -> NN)
 */
function get_platform_logo($name = null) {
    if ($name === null) {
        $name = get_platform_name();
    }
    
    // Extract all capital letters
    preg_match_all('/[A-Z]/', $name, $matches);
    if (!empty($matches[0])) {
        $logo = implode('', $matches[0]);
        if (strlen($logo) > 0) {
            return $logo;
        }
    }
    
    // Fallback: If no capital letters are found, take first two letters of the name uppercase
    $clean = preg_replace('/[^A-Za-z0-9 ]/', '', $name);
    $words = explode(' ', $clean);
    if (count($words) >= 2) {
        return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
    }
    return strtoupper(substr($clean, 0, min(2, strlen($clean))));
}

/**
 * Generate platform email domain dynamically based on platform name
 */
function get_platform_email($role = 'admin') {
    $name = get_platform_name();
    // Sanitized lowercased name for domain (no spaces or special chars)
    $domain = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', $name));
    if (empty($domain)) {
        $domain = 'netpulse';
    }
    
    if ($role === 'support') {
        return "support@{$domain}-saas.net";
    }
    return "admin@{$domain}.saas";
}

/**
 * Get dynamic domain name based on platform name
 */
function get_platform_domain() {
    $name = get_platform_name();
    $domain = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', $name));
    if (empty($domain)) {
        return 'netpulse.net';
    }
    return "{$domain}.net";
}

