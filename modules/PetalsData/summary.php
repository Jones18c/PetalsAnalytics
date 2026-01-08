<?php
$header['pageTitle'] = "Key Metrics Summary - Petals Data";
require(__DIR__ . '/../../includes/header.inc.php');
require(__DIR__ . '/../../includes/config.inc.php');
require_once(__DIR__ . '/../../includes/functions.inc.php');

// Module Access Control
$moduleCode = 'petals_data';
$userEmail = getUserEmail();
if (!hasModuleAccess($userEmail, $moduleCode)) {
    logModuleAccess($userEmail, $moduleCode, 'denied');
    denyAccess();
}
logModuleAccess($userEmail, $moduleCode, 'view');

// Get date parameters
$from_date = $_GET['from_date'] ?? date('Y-m-01'); // First day of current month
$to_date = $_GET['to_date'] ?? date('Y-m-d'); // Today
$selected_branch = $_GET['branch'] ?? 'all'; // Branch filter (default to 'all')
$customer_filter = $_GET['customer_filter'] ?? 'all'; // Customer filter: all, enrolled, can_enroll_not_enrolled

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
    $from_date = date('Y-m-01');
    $to_date = date('Y-m-d');
}

// Ensure start date is not after end date
if ($from_date > $to_date) {
    $temp = $from_date;
    $from_date = $to_date;
    $to_date = $temp;
}

// Initialize metrics
$metrics = [
    'enrollment' => [
        'total_enrolled' => 0,
        'can_enroll' => 0,
        'can_enroll_not_enrolled' => 0,
        'enrollment_percent' => 0,
        'can_enroll_with_orders_6m' => 0,
        'enrollment_percent_6m' => 0,
        'enrolled_with_orders_6m' => 0,
        'can_enroll_with_orders_12m' => 0,
        'enrollment_percent_12m' => 0,
        'enrolled_with_orders_12m' => 0
    ],
    'points' => [
        'total_earned' => 0,
        'available' => 0,
        'pending' => 0,
        'canceled' => 0,
        'expired' => 0,
        'redeemed' => 0
    ],
    'orders' => [
        'total_orders' => 0,
        'total_revenue' => 0,
        'aov' => 0,
        'orders_high' => 0,
        'orders_low' => 0,
        'revenue_enrolled' => 0,
        'orders_enrolled' => 0,
        'orders_enrolled_high' => 0,
        'orders_enrolled_low' => 0
    ],
    'redemptions' => [
        'points_redeemed' => 0,
        'claimed_rewards' => 0,
        'redemption_rate' => 0
    ]
];

$branches = [];
$error = null;

try {
    $conn = new mysqli($config['dbServer'], $config['dbUser'], $config['dbPassword'], $config['dbName']);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Get list of branches
    $branchSql = "
        SELECT id, name 
        FROM branches 
        WHERE LOWER(name) NOT LIKE '%mass market%'
          AND LOWER(name) NOT LIKE '%twin cities%'
          AND LOWER(name) NOT LIKE '%accounts receivable%'
        ORDER BY name ASC
    ";
    $branchResult = $conn->query($branchSql);
    if ($branchResult) {
        while ($row = $branchResult->fetch_assoc()) {
            $branches[] = $row;
        }
    }
    
    // Build branch filter
    $branchFilter = "";
    if ($selected_branch !== 'all') {
        $branchId = (int)$selected_branch;
        $branchFilter = " AND b.id = $branchId";
    }
    
    // Build customer filter for orders
    $customerFilter = "";
    if ($customer_filter === 'enrolled') {
        $customerFilter = " AND c.is_enrolled_loyalty = 1 AND c.status = 1";
    } elseif ($customer_filter === 'can_enroll_not_enrolled') {
        $customerFilter = " AND c.can_enroll_loyalty = 1 AND c.is_enrolled_loyalty = 0 AND c.status = 1";
    }
    
    // 1. ENROLLMENT METRICS
    $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
    $twelveMonthsAgo = date('Y-m-d', strtotime('-12 months'));
    $enrollmentSql = "
        SELECT 
            COUNT(DISTINCT CASE WHEN c.is_enrolled_loyalty = 1 THEN c.id END) AS total_enrolled,
            COUNT(DISTINCT CASE WHEN c.can_enroll_loyalty = 1 THEN c.id END) AS can_enroll,
            COUNT(DISTINCT CASE WHEN c.can_enroll_loyalty = 1 AND c.is_enrolled_loyalty = 0 THEN c.id END) AS can_enroll_not_enrolled,
            COUNT(DISTINCT CASE 
                WHEN c.can_enroll_loyalty = 1 
                AND c.is_enrolled_loyalty = 0
                AND EXISTS (
                    SELECT 1 FROM orders o 
                    WHERE o.company_id = c.id 
                    AND o.invoice_date >= ?
                    AND o.order_status_id = 2
                )
                THEN c.id 
            END) AS can_enroll_with_orders_6m,
            COUNT(DISTINCT CASE 
                WHEN c.is_enrolled_loyalty = 1
                AND EXISTS (
                    SELECT 1 FROM orders o 
                    WHERE o.company_id = c.id 
                    AND o.invoice_date >= ?
                    AND o.order_status_id = 2
                )
                THEN c.id 
            END) AS enrolled_with_orders_6m,
            COUNT(DISTINCT CASE 
                WHEN c.can_enroll_loyalty = 1 
                AND c.is_enrolled_loyalty = 0
                AND EXISTS (
                    SELECT 1 FROM orders o 
                    WHERE o.company_id = c.id 
                    AND o.invoice_date >= ?
                    AND o.order_status_id = 2
                )
                THEN c.id 
            END) AS can_enroll_with_orders_12m,
            COUNT(DISTINCT CASE 
                WHEN c.is_enrolled_loyalty = 1
                AND EXISTS (
                    SELECT 1 FROM orders o 
                    WHERE o.company_id = c.id 
                    AND o.invoice_date >= ?
                    AND o.order_status_id = 2
                )
                THEN c.id 
            END) AS enrolled_with_orders_12m
        FROM companies c
        INNER JOIN branches b ON c.branch_id = b.id
        WHERE c.status = 1
          AND LOWER(b.name) NOT LIKE '%mass market%'
          AND LOWER(b.name) NOT LIKE '%twin cities%'
          AND LOWER(b.name) NOT LIKE '%accounts receivable%'
          $branchFilter
    ";
    
    $stmt = $conn->prepare($enrollmentSql);
    if ($stmt) {
        $stmt->bind_param("ssss", $sixMonthsAgo, $sixMonthsAgo, $twelveMonthsAgo, $twelveMonthsAgo);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $metrics['enrollment']['total_enrolled'] = (int)$row['total_enrolled'];
            $metrics['enrollment']['can_enroll'] = (int)$row['can_enroll'];
            $metrics['enrollment']['can_enroll_not_enrolled'] = (int)$row['can_enroll_not_enrolled'];
            $metrics['enrollment']['can_enroll_with_orders_6m'] = (int)$row['can_enroll_with_orders_6m'];
            $metrics['enrollment']['enrolled_with_orders_6m'] = (int)$row['enrolled_with_orders_6m'];
            $metrics['enrollment']['can_enroll_with_orders_12m'] = (int)$row['can_enroll_with_orders_12m'];
            $metrics['enrollment']['enrolled_with_orders_12m'] = (int)$row['enrolled_with_orders_12m'];
            
            // Calculate enrollment percentages
            if ($metrics['enrollment']['can_enroll'] > 0) {
                $metrics['enrollment']['enrollment_percent'] = round(($metrics['enrollment']['total_enrolled'] / $metrics['enrollment']['can_enroll']) * 100, 1);
            }
            
            // Calculate active purchaser enrollment % (6M) - using ALL enrolled in numerator
            $total6m = $metrics['enrollment']['total_enrolled'] + $metrics['enrollment']['can_enroll_with_orders_6m'];
            if ($total6m > 0) {
                $metrics['enrollment']['enrollment_percent_6m'] = round(($metrics['enrollment']['total_enrolled'] / $total6m) * 100, 1);
            }
            
            // Calculate active purchaser enrollment % (12M) - using ALL enrolled in numerator
            $total12m = $metrics['enrollment']['total_enrolled'] + $metrics['enrollment']['can_enroll_with_orders_12m'];
            if ($total12m > 0) {
                $metrics['enrollment']['enrollment_percent_12m'] = round(($metrics['enrollment']['total_enrolled'] / $total12m) * 100, 1);
            }
        }
        $stmt->close();
    }
    
    // 2. POINTS METRICS
    $pointsSql = "
        SELECT 
            COALESCE(SUM(CASE WHEN DATE(clp.created_at) >= ? AND DATE(clp.created_at) <= ? THEN clp.points_earned ELSE 0 END), 0) AS total_earned,
            COALESCE(SUM(CASE WHEN clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_available_status_id') THEN (clp.points_earned - clp.points_redeemed) ELSE 0 END), 0) AS available,
            COALESCE(SUM(CASE WHEN clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_pending_status_id') THEN clp.points_earned ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_canceled_status_id') THEN clp.points_earned ELSE 0 END), 0) AS canceled,
            COALESCE(SUM(CASE WHEN clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_expired_status_id') THEN clp.points_earned ELSE 0 END), 0) AS expired
        FROM companies c
        INNER JOIN branches b ON c.branch_id = b.id
        LEFT JOIN company_loyalty_points clp ON clp.company_id = c.id
        WHERE c.status = 1
          AND LOWER(b.name) NOT LIKE '%mass market%'
          AND LOWER(b.name) NOT LIKE '%twin cities%'
          AND LOWER(b.name) NOT LIKE '%accounts receivable%'
          $branchFilter
    ";
    
    $stmt = $conn->prepare($pointsSql);
    if ($stmt) {
        $stmt->bind_param("ss", $from_date, $to_date);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $metrics['points']['total_earned'] = (int)$row['total_earned'];
            $metrics['points']['available'] = (int)$row['available'];
            $metrics['points']['pending'] = (int)$row['pending'];
            $metrics['points']['canceled'] = (int)$row['canceled'];
            $metrics['points']['expired'] = (int)$row['expired'];
        }
        $stmt->close();
    }
    
    // 3. REDEMPTIONS (Points Redeemed)
    $redemptionsSql = "
        SELECT 
            COALESCE(SUM(crp.points), 0) AS points_redeemed,
            COALESCE(SUM(crp.dollars), 0) AS claimed_rewards
        FROM companies c
        INNER JOIN branches b ON c.branch_id = b.id
        INNER JOIN company_redeemed_points crp ON crp.company_id = c.id
        WHERE c.status = 1
          AND LOWER(b.name) NOT LIKE '%mass market%'
          AND LOWER(b.name) NOT LIKE '%twin cities%'
          AND LOWER(b.name) NOT LIKE '%accounts receivable%'
          AND DATE(crp.created_at) >= ?
          AND DATE(crp.created_at) <= ?
          $branchFilter
    ";
    
    $stmt = $conn->prepare($redemptionsSql);
    if ($stmt) {
        $stmt->bind_param("ss", $from_date, $to_date);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $metrics['redemptions']['points_redeemed'] = (int)$row['points_redeemed'];
            $metrics['redemptions']['claimed_rewards'] = (float)$row['claimed_rewards'];
        }
        $stmt->close();
    }
    
    // Calculate redemption rate (points redeemed / points earned)
    if ($metrics['points']['total_earned'] > 0) {
        $metrics['redemptions']['redemption_rate'] = round(($metrics['redemptions']['points_redeemed'] / $metrics['points']['total_earned']) * 100, 1);
    }
    
    // 4. ORDER METRICS
    // Use INNER JOIN when filtering by customer type to ensure proper filtering
    if ($customer_filter === 'enrolled' || $customer_filter === 'can_enroll_not_enrolled') {
        $ordersSql = "
            SELECT 
                COUNT(DISTINCT o.id) AS total_orders,
                COALESCE(SUM(o.total), 0) AS total_revenue,
                COUNT(DISTINCT CASE WHEN o.total >= 350 THEN o.id END) AS orders_high,
                COUNT(DISTINCT CASE WHEN o.total < 350 THEN o.id END) AS orders_low,
                COALESCE(SUM(CASE WHEN c.is_enrolled_loyalty = 1 THEN o.total ELSE 0 END), 0) AS revenue_enrolled,
                0 AS orders_enrolled,
                0 AS orders_enrolled_high,
                0 AS orders_enrolled_low
            FROM orders o
            INNER JOIN branches b ON o.branch_id = b.id
            INNER JOIN companies c ON o.company_id = c.id
            WHERE o.order_status_id = 2
              AND LOWER(b.name) NOT LIKE '%mass market%'
              AND LOWER(b.name) NOT LIKE '%twin cities%'
              AND LOWER(b.name) NOT LIKE '%accounts receivable%'
              AND DATE(o.invoice_date) >= ?
              AND DATE(o.invoice_date) <= ?
              $branchFilter
              $customerFilter
        ";
    } else {
        $ordersSql = "
            SELECT 
                COUNT(DISTINCT o.id) AS total_orders,
                COALESCE(SUM(o.total), 0) AS total_revenue,
                COUNT(DISTINCT CASE WHEN o.total >= 350 THEN o.id END) AS orders_high,
                COUNT(DISTINCT CASE WHEN o.total < 350 THEN o.id END) AS orders_low,
                COALESCE(SUM(CASE WHEN c.is_enrolled_loyalty = 1 THEN o.total ELSE 0 END), 0) AS revenue_enrolled,
                COUNT(DISTINCT CASE WHEN c.is_enrolled_loyalty = 1 THEN o.id END) AS orders_enrolled,
                COUNT(DISTINCT CASE WHEN c.is_enrolled_loyalty = 1 AND o.total >= 350 THEN o.id END) AS orders_enrolled_high,
                COUNT(DISTINCT CASE WHEN c.is_enrolled_loyalty = 1 AND o.total < 350 THEN o.id END) AS orders_enrolled_low
            FROM orders o
            INNER JOIN branches b ON o.branch_id = b.id
            LEFT JOIN companies c ON o.company_id = c.id
            WHERE o.order_status_id = 2
              AND LOWER(b.name) NOT LIKE '%mass market%'
              AND LOWER(b.name) NOT LIKE '%twin cities%'
              AND LOWER(b.name) NOT LIKE '%accounts receivable%'
              AND DATE(o.invoice_date) >= ?
              AND DATE(o.invoice_date) <= ?
              $branchFilter
        ";
    }
    
    $stmt = $conn->prepare($ordersSql);
    if ($stmt) {
        $stmt->bind_param("ss", $from_date, $to_date);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $metrics['orders']['total_orders'] = (int)$row['total_orders'];
            $metrics['orders']['total_revenue'] = (float)$row['total_revenue'];
            $metrics['orders']['orders_high'] = (int)$row['orders_high'];
            $metrics['orders']['orders_low'] = (int)$row['orders_low'];
            $metrics['orders']['revenue_enrolled'] = (float)$row['revenue_enrolled'];
            $metrics['orders']['orders_enrolled'] = (int)$row['orders_enrolled'];
            $metrics['orders']['orders_enrolled_high'] = (int)$row['orders_enrolled_high'];
            $metrics['orders']['orders_enrolled_low'] = (int)$row['orders_enrolled_low'];
            
            // Calculate AOV
            if ($metrics['orders']['total_orders'] > 0) {
                $metrics['orders']['aov'] = round($metrics['orders']['total_revenue'] / $metrics['orders']['total_orders'], 2);
            }
        }
        $stmt->close();
    }
    
    // Get metrics for all branches for Excel export
    $allBranchMetrics = [];
    foreach ($branches as $branch) {
        $branchId = (int)$branch['id'];
        $branchMetrics = [
            'branch_id' => $branchId,
            'branch_name' => $branch['name'],
            'enrollment' => [],
            'points' => [],
            'orders' => [],
            'redemptions' => []
        ];
        
        // Enrollment metrics for this branch
        $branchEnrollmentSql = "
            SELECT 
                COUNT(DISTINCT CASE WHEN c.is_enrolled_loyalty = 1 THEN c.id END) AS total_enrolled,
                COUNT(DISTINCT CASE WHEN c.can_enroll_loyalty = 1 THEN c.id END) AS can_enroll,
                COUNT(DISTINCT CASE WHEN c.can_enroll_loyalty = 1 AND c.is_enrolled_loyalty = 0 THEN c.id END) AS can_enroll_not_enrolled,
                COUNT(DISTINCT CASE 
                    WHEN c.can_enroll_loyalty = 1 
                    AND c.is_enrolled_loyalty = 0
                    AND EXISTS (
                        SELECT 1 FROM orders o 
                        WHERE o.company_id = c.id 
                        AND o.invoice_date >= ?
                        AND o.order_status_id = 2
                    )
                    THEN c.id 
                END) AS can_enroll_with_orders_6m,
                COUNT(DISTINCT CASE 
                    WHEN c.is_enrolled_loyalty = 1
                    AND EXISTS (
                        SELECT 1 FROM orders o 
                        WHERE o.company_id = c.id 
                        AND o.invoice_date >= ?
                        AND o.order_status_id = 2
                    )
                    THEN c.id 
                END) AS enrolled_with_orders_6m,
                COUNT(DISTINCT CASE 
                    WHEN c.can_enroll_loyalty = 1 
                    AND c.is_enrolled_loyalty = 0
                    AND EXISTS (
                        SELECT 1 FROM orders o 
                        WHERE o.company_id = c.id 
                        AND o.invoice_date >= ?
                        AND o.order_status_id = 2
                    )
                    THEN c.id 
                END) AS can_enroll_with_orders_12m,
                COUNT(DISTINCT CASE 
                    WHEN c.is_enrolled_loyalty = 1
                    AND EXISTS (
                        SELECT 1 FROM orders o 
                        WHERE o.company_id = c.id 
                        AND o.invoice_date >= ?
                        AND o.order_status_id = 2
                    )
                    THEN c.id 
                END) AS enrolled_with_orders_12m
            FROM companies c
            INNER JOIN branches b ON c.branch_id = b.id
            WHERE c.status = 1
              AND b.id = ?
        ";
        $stmt = $conn->prepare($branchEnrollmentSql);
        if ($stmt) {
            $stmt->bind_param("ssssi", $sixMonthsAgo, $sixMonthsAgo, $twelveMonthsAgo, $twelveMonthsAgo, $branchId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $branchMetrics['enrollment']['total_enrolled'] = (int)$row['total_enrolled'];
                $branchMetrics['enrollment']['can_enroll'] = (int)$row['can_enroll'];
                $branchMetrics['enrollment']['can_enroll_not_enrolled'] = (int)$row['can_enroll_not_enrolled'];
                $branchMetrics['enrollment']['can_enroll_with_orders_6m'] = (int)$row['can_enroll_with_orders_6m'];
                $branchMetrics['enrollment']['enrolled_with_orders_6m'] = (int)$row['enrolled_with_orders_6m'];
                $branchMetrics['enrollment']['can_enroll_with_orders_12m'] = (int)$row['can_enroll_with_orders_12m'];
                $branchMetrics['enrollment']['enrolled_with_orders_12m'] = (int)$row['enrolled_with_orders_12m'];
                
                if ($branchMetrics['enrollment']['can_enroll'] > 0) {
                    $branchMetrics['enrollment']['enrollment_percent'] = round(($branchMetrics['enrollment']['total_enrolled'] / $branchMetrics['enrollment']['can_enroll']) * 100, 1);
                } else {
                    $branchMetrics['enrollment']['enrollment_percent'] = 0;
                }
                
                // Calculate active purchaser enrollment % (6M) - using ALL enrolled in numerator
                $total6m = $branchMetrics['enrollment']['total_enrolled'] + $branchMetrics['enrollment']['can_enroll_with_orders_6m'];
                if ($total6m > 0) {
                    $branchMetrics['enrollment']['enrollment_percent_6m'] = round(($branchMetrics['enrollment']['total_enrolled'] / $total6m) * 100, 1);
                } else {
                    $branchMetrics['enrollment']['enrollment_percent_6m'] = 0;
                }
                
                // Calculate active purchaser enrollment % (12M) - using ALL enrolled in numerator
                $total12m = $branchMetrics['enrollment']['total_enrolled'] + $branchMetrics['enrollment']['can_enroll_with_orders_12m'];
                if ($total12m > 0) {
                    $branchMetrics['enrollment']['enrollment_percent_12m'] = round(($branchMetrics['enrollment']['total_enrolled'] / $total12m) * 100, 1);
                } else {
                    $branchMetrics['enrollment']['enrollment_percent_12m'] = 0;
                }
            }
            $stmt->close();
        }
        
        // Points metrics for this branch
        $branchPointsSql = "
            SELECT 
                COALESCE(SUM(CASE WHEN DATE(clp.created_at) >= ? AND DATE(clp.created_at) <= ? THEN clp.points_earned ELSE 0 END), 0) AS total_earned,
                COALESCE(SUM(CASE WHEN clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_available_status_id') THEN (clp.points_earned - clp.points_redeemed) ELSE 0 END), 0) AS available,
                COALESCE(SUM(CASE WHEN clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_pending_status_id') THEN clp.points_earned ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_canceled_status_id') THEN clp.points_earned ELSE 0 END), 0) AS canceled,
                COALESCE(SUM(CASE WHEN clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_expired_status_id') THEN clp.points_earned ELSE 0 END), 0) AS expired
            FROM companies c
            INNER JOIN branches b ON c.branch_id = b.id
            LEFT JOIN company_loyalty_points clp ON clp.company_id = c.id
            WHERE c.status = 1
              AND b.id = ?
        ";
        $stmt = $conn->prepare($branchPointsSql);
        if ($stmt) {
            $stmt->bind_param("ssi", $from_date, $to_date, $branchId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $branchMetrics['points']['total_earned'] = (int)$row['total_earned'];
                $branchMetrics['points']['available'] = (int)$row['available'];
                $branchMetrics['points']['pending'] = (int)$row['pending'];
                $branchMetrics['points']['canceled'] = (int)$row['canceled'];
                $branchMetrics['points']['expired'] = (int)$row['expired'];
            }
            $stmt->close();
        }
        
        // Redemptions metrics for this branch
        $branchRedemptionsSql = "
            SELECT 
                COALESCE(SUM(crp.points), 0) AS points_redeemed,
                COALESCE(SUM(crp.dollars), 0) AS claimed_rewards
            FROM companies c
            INNER JOIN branches b ON c.branch_id = b.id
            INNER JOIN company_redeemed_points crp ON crp.company_id = c.id
            WHERE c.status = 1
              AND b.id = ?
              AND DATE(crp.created_at) >= ?
              AND DATE(crp.created_at) <= ?
        ";
        $stmt = $conn->prepare($branchRedemptionsSql);
        if ($stmt) {
            $stmt->bind_param("iss", $branchId, $from_date, $to_date);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $branchMetrics['redemptions']['points_redeemed'] = (int)$row['points_redeemed'];
                $branchMetrics['redemptions']['claimed_rewards'] = (float)$row['claimed_rewards'];
            }
            $stmt->close();
        }
        
        // Orders metrics for this branch
        $branchCustomerFilter = "";
        if ($customer_filter === 'enrolled') {
            $branchCustomerFilter = " AND c.is_enrolled_loyalty = 1 AND c.status = 1";
        } elseif ($customer_filter === 'can_enroll_not_enrolled') {
            $branchCustomerFilter = " AND c.can_enroll_loyalty = 1 AND c.is_enrolled_loyalty = 0 AND c.status = 1";
        }
        
        // Use INNER JOIN when filtering by customer type
        if ($customer_filter === 'enrolled' || $customer_filter === 'can_enroll_not_enrolled') {
            $branchOrdersSql = "
                SELECT 
                    COUNT(DISTINCT o.id) AS total_orders,
                    COALESCE(SUM(o.total), 0) AS total_revenue,
                    COUNT(DISTINCT CASE WHEN o.total >= 350 THEN o.id END) AS orders_high,
                    COUNT(DISTINCT CASE WHEN o.total < 350 THEN o.id END) AS orders_low,
                    COALESCE(SUM(CASE WHEN c.is_enrolled_loyalty = 1 THEN o.total ELSE 0 END), 0) AS revenue_enrolled,
                    0 AS orders_enrolled,
                    0 AS orders_enrolled_high,
                    0 AS orders_enrolled_low
                FROM orders o
                INNER JOIN branches b ON o.branch_id = b.id
                INNER JOIN companies c ON o.company_id = c.id
                WHERE o.order_status_id = 2
                  AND b.id = ?
                  AND DATE(o.invoice_date) >= ?
                  AND DATE(o.invoice_date) <= ?
                  $branchCustomerFilter
            ";
        } else {
            $branchOrdersSql = "
                SELECT 
                    COUNT(DISTINCT o.id) AS total_orders,
                    COALESCE(SUM(o.total), 0) AS total_revenue,
                    COUNT(DISTINCT CASE WHEN o.total >= 350 THEN o.id END) AS orders_high,
                    COUNT(DISTINCT CASE WHEN o.total < 350 THEN o.id END) AS orders_low,
                    COALESCE(SUM(CASE WHEN c.is_enrolled_loyalty = 1 THEN o.total ELSE 0 END), 0) AS revenue_enrolled,
                    COUNT(DISTINCT CASE WHEN c.is_enrolled_loyalty = 1 THEN o.id END) AS orders_enrolled,
                    COUNT(DISTINCT CASE WHEN c.is_enrolled_loyalty = 1 AND o.total >= 350 THEN o.id END) AS orders_enrolled_high,
                    COUNT(DISTINCT CASE WHEN c.is_enrolled_loyalty = 1 AND o.total < 350 THEN o.id END) AS orders_enrolled_low
                FROM orders o
                INNER JOIN branches b ON o.branch_id = b.id
                LEFT JOIN companies c ON o.company_id = c.id
                WHERE o.order_status_id = 2
                  AND b.id = ?
                  AND DATE(o.invoice_date) >= ?
                  AND DATE(o.invoice_date) <= ?
            ";
        }
        $stmt = $conn->prepare($branchOrdersSql);
        if ($stmt) {
            $stmt->bind_param("iss", $branchId, $from_date, $to_date);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $branchMetrics['orders']['total_orders'] = (int)$row['total_orders'];
                $branchMetrics['orders']['total_revenue'] = (float)$row['total_revenue'];
                $branchMetrics['orders']['orders_high'] = (int)$row['orders_high'];
                $branchMetrics['orders']['orders_low'] = (int)$row['orders_low'];
                $branchMetrics['orders']['revenue_enrolled'] = (float)$row['revenue_enrolled'];
                $branchMetrics['orders']['orders_enrolled'] = (int)$row['orders_enrolled'];
                $branchMetrics['orders']['orders_enrolled_high'] = (int)$row['orders_enrolled_high'];
                $branchMetrics['orders']['orders_enrolled_low'] = (int)$row['orders_enrolled_low'];
                
                if ($branchMetrics['orders']['total_orders'] > 0) {
                    $branchMetrics['orders']['aov'] = round($branchMetrics['orders']['total_revenue'] / $branchMetrics['orders']['total_orders'], 2);
                } else {
                    $branchMetrics['orders']['aov'] = 0;
                }
            }
            $stmt->close();
        }
        
        $allBranchMetrics[] = $branchMetrics;
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
    error_log("Summary page error: " . $error);
} finally {
    if ($conn) {
        $conn->close();
    }
}

?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">Key Metrics Summary</h2>
                <button type="button" class="btn btn-success" onclick="exportToExcel()" id="exportBtn">
                    <i class="bi bi-file-earmark-excel me-2"></i>Export to Excel
                </button>
            </div>
            
            <!-- Date and Branch Filter Form -->
            <div class="card border mb-4">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-2">
                            <label for="from_date" class="form-label small text-muted">From Date</label>
                            <input type="date" class="form-control" id="from_date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label for="to_date" class="form-label small text-muted">To Date</label>
                            <input type="date" class="form-control" id="to_date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label for="branch" class="form-label small text-muted">Branch/Location</label>
                            <select class="form-select" id="branch" name="branch">
                                <option value="all" <?php echo $selected_branch === 'all' ? 'selected' : ''; ?>>All Branches</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>" <?php echo $selected_branch == $branch['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($branch['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="customer_filter" class="form-label small text-muted">Customer Filter</label>
                            <select class="form-select" id="customer_filter" name="customer_filter">
                                <option value="all" <?php echo $customer_filter === 'all' ? 'selected' : ''; ?>>All Customers</option>
                                <option value="enrolled" <?php echo $customer_filter === 'enrolled' ? 'selected' : ''; ?>>Enrolled Only</option>
                                <option value="can_enroll_not_enrolled" <?php echo $customer_filter === 'can_enroll_not_enrolled' ? 'selected' : ''; ?>>Can Enroll (Not Enrolled)</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-secondary w-100">
                                Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- Orders & Revenue Metrics - Most Important First -->
            <div class="row mb-4">
                <div class="col-12">
                    <h5 class="mb-3 fw-bold">Orders & Revenue</h5>
                    <div class="row g-3">
                        <div class="col-md-2">
                            <div class="card border-top border-top-4 border-top-primary h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="orders_total" data-title="Total Orders">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Total Orders</div>
                                    <div class="h3 mb-0 text-primary"><?php echo number_format($metrics['orders']['total_orders']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-top border-top-4 border-top-success h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="orders_revenue" data-title="Total Revenue">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Total Revenue</div>
                                    <div class="h3 mb-0 text-success">$<?php echo number_format($metrics['orders']['total_revenue'], 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-top border-top-4 border-top-info h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="orders_aov" data-title="Average Order Value">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Average Order Value</div>
                                    <div class="h3 mb-0 text-info">$<?php echo number_format($metrics['orders']['aov'], 2); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-top border-top-4 border-top-warning h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="orders_high" data-title="Orders ≥ $350">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Orders ≥ $350</div>
                                    <div class="h4 mb-0 text-warning"><?php echo number_format($metrics['orders']['orders_high']); ?></div>
                                    <div class="text-muted small mt-1">Orders < $350: <?php echo number_format($metrics['orders']['orders_low']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-top border-top-4 border-top-secondary h-100 shadow-sm">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Revenue from Enrolled</div>
                                    <div class="h3 mb-0">$<?php echo number_format($metrics['orders']['revenue_enrolled'], 0); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php if ($customer_filter === 'all'): ?>
                    <div class="row g-3 mt-2">
                        <div class="col-md-4">
                            <div class="card border-top border-top-4 border-top-success h-100 shadow-sm">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Orders from Enrolled</div>
                                    <div class="h3 mb-0 text-success"><?php echo number_format($metrics['orders']['orders_enrolled']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-top border-top-4 border-top-warning h-100 shadow-sm">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Enrolled Orders ≥ $350</div>
                                    <div class="h4 mb-0 text-warning"><?php echo number_format($metrics['orders']['orders_enrolled_high']); ?></div>
                                    <div class="text-muted small mt-1">Enrolled Orders < $350: <?php echo number_format($metrics['orders']['orders_enrolled_low']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Enrollment Metrics -->
            <div class="row mb-4">
                <div class="col-12">
                    <h5 class="mb-3 fw-bold">Enrollment</h5>
                    <div class="row g-3">
                        <div class="col-md-2">
                            <div class="card border-top border-top-4 border-top-success h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="enrollment_total" data-title="Total Enrolled">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Total Enrolled</div>
                                    <div class="h4 mb-0 text-success"><?php echo number_format($metrics['enrollment']['total_enrolled']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-top border-top-4 border-top-warning h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="enrollment_not_enrolled" data-title="Can Enroll (Not Enrolled)">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Can Enroll (Not Enrolled)</div>
                                    <div class="h4 mb-0 text-warning"><?php echo number_format($metrics['enrollment']['can_enroll_not_enrolled']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-top border-top-4 border-top-info h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="enrollment_percent" data-title="Enrollment %">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Enrollment %</div>
                                    <div class="h4 mb-0 text-info"><?php echo number_format($metrics['enrollment']['enrollment_percent'], 1); ?>%</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-top border-top-4 border-top-secondary h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="enrollment_6m" data-title="Can Enroll w/ Orders (6M)">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Can Enroll w/ Orders (6M)</div>
                                    <div class="h4 mb-0"><?php echo number_format($metrics['enrollment']['can_enroll_with_orders_6m']); ?></div>
                                    <div class="text-muted small mt-1">Enrollment % (6M): <?php echo number_format($metrics['enrollment']['enrollment_percent_6m'], 1); ?>%</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-top border-top-4 border-top-primary h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="enrollment_orders_6m" data-title="Enrolled w/ Orders (6M)">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Enrolled w/ Orders (6M)</div>
                                    <div class="h4 mb-0 text-primary"><?php echo number_format($metrics['enrollment']['enrolled_with_orders_6m']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-top border-top-4 border-top-secondary h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="enrollment_12m" data-title="Can Enroll w/ Orders (12M)">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Can Enroll w/ Orders (12M)</div>
                                    <div class="h4 mb-0"><?php echo number_format($metrics['enrollment']['can_enroll_with_orders_12m']); ?></div>
                                    <div class="text-muted small mt-1">Enrollment % (12M): <?php echo number_format($metrics['enrollment']['enrollment_percent_12m'], 1); ?>%</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-top border-top-4 border-top-primary h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="enrollment_orders_12m" data-title="Enrolled w/ Orders (12M)">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Enrolled w/ Orders (12M)</div>
                                    <div class="h4 mb-0 text-primary"><?php echo number_format($metrics['enrollment']['enrolled_with_orders_12m']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Points Metrics - Hide when filtering by non-enrolled customers -->
            <?php if ($customer_filter !== 'can_enroll_not_enrolled'): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <h5 class="mb-3 fw-bold">Points</h5>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="card border-top border-top-4 border-top-primary h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="points_earned" data-title="Total Points Earned">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Total Earned</div>
                                    <div class="h4 mb-0 text-primary"><?php echo number_format($metrics['points']['total_earned']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-top border-top-4 border-top-success h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="points_available" data-title="Available Points">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Available</div>
                                    <div class="h4 mb-0 text-success"><?php echo number_format($metrics['points']['available']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-top border-top-4 border-top-warning h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="points_pending" data-title="Pending Points">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Pending</div>
                                    <div class="h4 mb-0 text-warning"><?php echo number_format($metrics['points']['pending']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-top border-top-4 border-top-info h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="points_redeemed" data-title="Points Redeemed">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Redeemed</div>
                                    <div class="h4 mb-0 text-info"><?php echo number_format($metrics['redemptions']['points_redeemed']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Redemptions Metrics - Hide when filtering by non-enrolled customers -->
            <?php if ($customer_filter !== 'can_enroll_not_enrolled'): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <h5 class="mb-3 fw-bold">Redemptions</h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card border-top border-top-4 border-top-info h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="redemptions_points" data-title="Points Redeemed">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Points Redeemed</div>
                                    <div class="h3 mb-0 text-info"><?php echo number_format($metrics['redemptions']['points_redeemed']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-top border-top-4 border-top-success h-100 shadow-sm metric-card" style="cursor: pointer;" data-metric="redemptions_value" data-title="Claimed Rewards Value">
                                <div class="card-body">
                                    <div class="text-muted small mb-1">Claimed Rewards Value</div>
                                    <div class="h3 mb-0 text-success">$<?php echo number_format($metrics['redemptions']['claimed_rewards'], 2); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Quick Links -->
            <div class="row">
                <div class="col-12">
                    <h5 class="mb-3 fw-bold">Quick Links</h5>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <a href="rewards_enrollment.php" class="btn btn-outline-primary w-100">
                                <i class="bi bi-gift me-2"></i>Enrollment Report
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="branch_redemptions.php" class="btn btn-outline-success w-100">
                                <i class="bi bi-gift-fill me-2"></i>Branch Redemptions
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="customer_redemptions.php" class="btn btn-outline-warning w-100">
                                <i class="bi bi-people-fill me-2"></i>Customer Redemptions
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="order_summary.php" class="btn btn-outline-info w-100">
                                <i class="bi bi-clipboard-data me-2"></i>Order Summary
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Branch Breakdown Modal -->
<div class="modal fade" id="branchBreakdownModal" tabindex="-1" aria-labelledby="branchBreakdownModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="branchBreakdownModalLabel">Branch Breakdown</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="branchBreakdownContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.metric-card {
    transition: all 0.2s ease;
}
.metric-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const metricCards = document.querySelectorAll('.metric-card');
    const modal = new bootstrap.Modal(document.getElementById('branchBreakdownModal'));
    const modalTitle = document.getElementById('branchBreakdownModalLabel');
    const modalContent = document.getElementById('branchBreakdownContent');
    
    // Handle card clicks
    metricCards.forEach(card => {
        card.addEventListener('click', function() {
            const metric = this.getAttribute('data-metric');
            const title = this.getAttribute('data-title');
            
            modalTitle.textContent = title + ' - Branch Breakdown';
            modalContent.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
            modal.show();
            
            // Fetch branch breakdown
            fetchBranchBreakdown(metric, title);
        });
    });
    
    function fetchBranchBreakdown(metric, title) {
        const params = new URLSearchParams({
            action: 'branch_breakdown',
            metric: metric,
            from_date: '<?php echo $from_date; ?>',
            to_date: '<?php echo $to_date; ?>',
            branch: '<?php echo $selected_branch; ?>'
        });
        
        fetch('summary_breakdown.php?' + params.toString())
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    modalContent.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
                    return;
                }
                
                let html = '<div class="table-responsive"><table class="table table-striped table-hover">';
                html += '<thead><tr><th>Branch</th><th class="text-end">Value</th></tr></thead>';
                html += '<tbody>';
                
                data.breakdown.forEach(row => {
                    html += '<tr>';
                    html += '<td>' + escapeHtml(row.branch_name) + '</td>';
                    html += '<td class="text-end">' + formatValue(row.value, metric) + '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody>';
                html += '<tfoot><tr class="table-primary"><th>Total</th><th class="text-end">' + formatValue(data.total, metric) + '</th></tr></tfoot>';
                html += '</table></div>';
                
                modalContent.innerHTML = html;
            })
            .catch(error => {
                modalContent.innerHTML = '<div class="alert alert-danger">Error loading branch breakdown: ' + error.message + '</div>';
            });
    }
    
    function formatValue(value, metric) {
        if (metric.includes('revenue') || metric.includes('value') || metric.includes('aov')) {
            return '$' + parseFloat(value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        } else if (metric.includes('percent') || metric.includes('rate')) {
            return parseFloat(value).toFixed(1) + '%';
        } else {
            return parseFloat(value).toLocaleString('en-US');
        }
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
</script>

<!-- SheetJS Library for Excel Export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
// Excel Export Function - Creates a sheet for each branch
function exportToExcel() {
    const exportBtn = document.getElementById('exportBtn');
    const originalText = exportBtn.innerHTML;
    
    // Show loading state
    exportBtn.disabled = true;
    exportBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Generating Excel...';
    
    try {
        // Get branch metrics data from PHP
        const allBranchMetrics = <?php echo json_encode($allBranchMetrics); ?>;
        const dateRange = '<?php echo date('M j, Y', strtotime($from_date)); ?> - <?php echo date('M j, Y', strtotime($to_date)); ?>';
        
        // Create workbook
        const wb = XLSX.utils.book_new();
        
        // Get customer filter value once
        const customerFilter = '<?php echo $customer_filter; ?>';
        
        // Create a sheet for each branch
        allBranchMetrics.forEach(branchData => {
            const exportData = [];
            const branchName = branchData.branch_name;
            
            // Header row
            exportData.push([branchName]);
            exportData.push(['Date Range:', dateRange]);
            exportData.push([]); // Empty row
            
            // Orders & Revenue Section
            exportData.push(['ORDERS & REVENUE']);
            exportData.push(['Metric', 'Value']);
            exportData.push(['Total Orders', branchData.orders.total_orders || 0]);
            exportData.push(['Total Revenue', branchData.orders.total_revenue || 0]);
            exportData.push(['Average Order Value', branchData.orders.aov || 0]);
            exportData.push(['Orders ≥ $350', branchData.orders.orders_high || 0]);
            exportData.push(['Orders < $350', branchData.orders.orders_low || 0]);
            exportData.push(['Revenue from Enrolled Customers', branchData.orders.revenue_enrolled || 0]);
            if (customerFilter === 'all') {
                exportData.push(['Orders from Enrolled Customers', branchData.orders.orders_enrolled || 0]);
                exportData.push(['Enrolled Orders ≥ $350', branchData.orders.orders_enrolled_high || 0]);
                exportData.push(['Enrolled Orders < $350', branchData.orders.orders_enrolled_low || 0]);
            }
            exportData.push([]); // Empty row
            
            // Enrollment Section
            exportData.push(['ENROLLMENT']);
            exportData.push(['Metric', 'Value']);
            exportData.push(['Total Enrolled', branchData.enrollment.total_enrolled || 0]);
            exportData.push(['Enrolled w/ Orders (6M)', branchData.enrollment.enrolled_with_orders_6m || 0]);
            exportData.push(['Can Enroll (Not Enrolled)', branchData.enrollment.can_enroll_not_enrolled || 0]);
            exportData.push(['Enrollment %', branchData.enrollment.enrollment_percent || 0]);
            exportData.push(['Can Enroll w/ Orders (6M)', branchData.enrollment.can_enroll_with_orders_6m || 0]);
            exportData.push(['Enrollment % (6M)', branchData.enrollment.enrollment_percent_6m || 0]);
            exportData.push(['Can Enroll w/ Orders (12M)', branchData.enrollment.can_enroll_with_orders_12m || 0]);
            exportData.push(['Enrollment % (12M)', branchData.enrollment.enrollment_percent_12m || 0]);
            exportData.push(['Enrolled w/ Orders (12M)', branchData.enrollment.enrolled_with_orders_12m || 0]);
            exportData.push([]); // Empty row
            
            // Points Section - Skip if filtering by non-enrolled customers
            if (customerFilter !== 'can_enroll_not_enrolled') {
                exportData.push(['POINTS']);
                exportData.push(['Metric', 'Value']);
                exportData.push(['Total Earned', branchData.points.total_earned || 0]);
                exportData.push(['Available', branchData.points.available || 0]);
                exportData.push(['Pending', branchData.points.pending || 0]);
                exportData.push(['Redeemed', branchData.redemptions.points_redeemed || 0]);
                exportData.push([]); // Empty row
            }
            
            // Redemptions Section - Skip if filtering by non-enrolled customers
            if (customerFilter !== 'can_enroll_not_enrolled') {
                exportData.push(['REDEMPTIONS']);
                exportData.push(['Metric', 'Value']);
                exportData.push(['Points Redeemed', branchData.redemptions.points_redeemed || 0]);
                exportData.push(['Claimed Rewards Value', branchData.redemptions.claimed_rewards || 0]);
            }
            
            // Create worksheet
            const ws = XLSX.utils.aoa_to_sheet(exportData);
            
            // Set column widths
            ws['!cols'] = [
                { wch: 30 },  // Metric column
                { wch: 20 }   // Value column
            ];
            
            // Merge header row
            if (!ws['!merges']) ws['!merges'] = [];
            ws['!merges'].push({ s: { r: 0, c: 0 }, e: { r: 0, c: 1 } }); // Merge header
            
            // Excel sheet names are limited to 31 characters
            let sheetName = branchName;
            if (sheetName.length > 31) {
                sheetName = sheetName.substring(0, 31);
            }
            // Remove invalid characters for sheet names
            sheetName = sheetName.replace(/[\\\/\?\*\[\]:]/g, '_');
            
            // Add worksheet to workbook
            XLSX.utils.book_append_sheet(wb, ws, sheetName);
        });
        
        // Generate filename with date range
        const dateStr = '<?php echo date('Ymd', strtotime($from_date)); ?>_<?php echo date('Ymd', strtotime($to_date)); ?>';
        const filename = `Key_Metrics_Summary_All_Branches_${dateStr}.xlsx`;
        
        // Save file
        XLSX.writeFile(wb, filename);
        
        setTimeout(() => {
            exportBtn.disabled = false;
            exportBtn.innerHTML = originalText;
        }, 500);
        
    } catch (error) {
        alert('Error exporting to Excel: ' + error.message);
        exportBtn.disabled = false;
        exportBtn.innerHTML = originalText;
    }
}
</script>

<?php require(__DIR__ . '/../../includes/footer.inc.php'); ?>

