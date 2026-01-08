<?php
header('Content-Type: application/json');
require(__DIR__ . '/../../includes/config.inc.php');

// Get parameters
$action = $_GET['action'] ?? '';
$metric = $_GET['metric'] ?? '';
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$selected_branch = $_GET['branch'] ?? 'all';

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
    echo json_encode(['error' => 'Invalid date format']);
    exit;
}

if ($action !== 'branch_breakdown') {
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

$conn = null;
$breakdown = [];
$total = 0;

try {
    $conn = new mysqli($config['dbServer'], $config['dbUser'], $config['dbPassword'], $config['dbName']);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Build branch filter
    $branchFilter = "";
    if ($selected_branch !== 'all') {
        $branchId = (int)$selected_branch;
        $branchFilter = " AND b.id = $branchId";
    }
    
    $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
    $twelveMonthsAgo = date('Y-m-d', strtotime('-12 months'));
    
    switch ($metric) {
        case 'orders_total':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    COUNT(DISTINCT o.id) AS value
                FROM orders o
                INNER JOIN branches b ON o.branch_id = b.id
                WHERE o.order_status_id = 2
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  AND DATE(o.invoice_date) >= ?
                  AND DATE(o.invoice_date) <= ?
                  $branchFilter
                GROUP BY b.id, b.name
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $from_date, $to_date);
            break;
            
        case 'orders_revenue':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    SUM(o.total) AS value
                FROM orders o
                INNER JOIN branches b ON o.branch_id = b.id
                WHERE o.order_status_id = 2
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  AND DATE(o.invoice_date) >= ?
                  AND DATE(o.invoice_date) <= ?
                  $branchFilter
                GROUP BY b.id, b.name
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $from_date, $to_date);
            break;
            
        case 'orders_aov':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    CASE 
                        WHEN COUNT(DISTINCT o.id) > 0 
                        THEN SUM(o.total) / COUNT(DISTINCT o.id)
                        ELSE 0
                    END AS value
                FROM orders o
                INNER JOIN branches b ON o.branch_id = b.id
                WHERE o.order_status_id = 2
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  AND DATE(o.invoice_date) >= ?
                  AND DATE(o.invoice_date) <= ?
                  $branchFilter
                GROUP BY b.id, b.name
                HAVING COUNT(DISTINCT o.id) > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $from_date, $to_date);
            break;
            
        case 'orders_high':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    COUNT(DISTINCT CASE WHEN o.total >= 350 THEN o.id END) AS value
                FROM orders o
                INNER JOIN branches b ON o.branch_id = b.id
                WHERE o.order_status_id = 2
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  AND DATE(o.invoice_date) >= ?
                  AND DATE(o.invoice_date) <= ?
                  $branchFilter
                GROUP BY b.id, b.name
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $from_date, $to_date);
            break;
            
        case 'enrollment_total':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    COUNT(DISTINCT CASE WHEN c.is_enrolled_loyalty = 1 THEN c.id END) AS value
                FROM companies c
                INNER JOIN branches b ON c.branch_id = b.id
                WHERE c.status = 1
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  $branchFilter
                GROUP BY b.id, b.name
                HAVING value > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            break;
            
        case 'enrollment_not_enrolled':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    COUNT(DISTINCT CASE WHEN c.can_enroll_loyalty = 1 AND c.is_enrolled_loyalty = 0 THEN c.id END) AS value
                FROM companies c
                INNER JOIN branches b ON c.branch_id = b.id
                WHERE c.status = 1
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  $branchFilter
                GROUP BY b.id, b.name
                HAVING value > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            break;
            
        case 'enrollment_percent':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    CASE 
                        WHEN COUNT(DISTINCT CASE WHEN c.can_enroll_loyalty = 1 THEN c.id END) > 0
                        THEN (COUNT(DISTINCT CASE WHEN c.is_enrolled_loyalty = 1 THEN c.id END) / COUNT(DISTINCT CASE WHEN c.can_enroll_loyalty = 1 THEN c.id END)) * 100
                        ELSE 0
                    END AS value
                FROM companies c
                INNER JOIN branches b ON c.branch_id = b.id
                WHERE c.status = 1
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  $branchFilter
                GROUP BY b.id, b.name
                HAVING COUNT(DISTINCT CASE WHEN c.can_enroll_loyalty = 1 THEN c.id END) > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            break;
            
        case 'enrollment_orders_6m':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    COUNT(DISTINCT CASE 
                        WHEN c.is_enrolled_loyalty = 1
                        AND EXISTS (
                            SELECT 1 FROM orders o 
                            WHERE o.company_id = c.id 
                            AND o.invoice_date >= ?
                            AND o.order_status_id = 2
                        )
                        THEN c.id 
                    END) AS value
                FROM companies c
                INNER JOIN branches b ON c.branch_id = b.id
                WHERE c.status = 1
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  $branchFilter
                GROUP BY b.id, b.name
                HAVING value > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $sixMonthsAgo);
            break;
            
        case 'enrollment_6m':
            $sql = "
                SELECT 
                    b.name AS branch_name,
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
                    END) AS value
                FROM companies c
                INNER JOIN branches b ON c.branch_id = b.id
                WHERE c.status = 1
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  $branchFilter
                GROUP BY b.id, b.name
                HAVING value > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $sixMonthsAgo);
            break;
            
        case 'enrollment_12m':
            $sql = "
                SELECT 
                    b.name AS branch_name,
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
                    END) AS value
                FROM companies c
                INNER JOIN branches b ON c.branch_id = b.id
                WHERE c.status = 1
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  $branchFilter
                GROUP BY b.id, b.name
                HAVING value > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $twelveMonthsAgo);
            break;
            
        case 'enrollment_orders_12m':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    COUNT(DISTINCT CASE 
                        WHEN c.is_enrolled_loyalty = 1
                        AND EXISTS (
                            SELECT 1 FROM orders o 
                            WHERE o.company_id = c.id 
                            AND o.invoice_date >= ?
                            AND o.order_status_id = 2
                        )
                        THEN c.id 
                    END) AS value
                FROM companies c
                INNER JOIN branches b ON c.branch_id = b.id
                WHERE c.status = 1
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  $branchFilter
                GROUP BY b.id, b.name
                HAVING value > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $twelveMonthsAgo);
            break;
            
        case 'points_earned':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    COALESCE(SUM(CASE WHEN DATE(clp.created_at) >= ? AND DATE(clp.created_at) <= ? THEN clp.points_earned ELSE 0 END), 0) AS value
                FROM companies c
                INNER JOIN branches b ON c.branch_id = b.id
                LEFT JOIN company_loyalty_points clp ON clp.company_id = c.id
                WHERE c.status = 1
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  $branchFilter
                GROUP BY b.id, b.name
                HAVING value > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $from_date, $to_date);
            break;
            
        case 'points_available':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    COALESCE(SUM(CASE WHEN clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_available_status_id') THEN (clp.points_earned - clp.points_redeemed) ELSE 0 END), 0) AS value
                FROM companies c
                INNER JOIN branches b ON c.branch_id = b.id
                LEFT JOIN company_loyalty_points clp ON clp.company_id = c.id
                WHERE c.status = 1
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  $branchFilter
                GROUP BY b.id, b.name
                HAVING value > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            break;
            
        case 'points_pending':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    COALESCE(SUM(CASE WHEN clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_pending_status_id') THEN clp.points_earned ELSE 0 END), 0) AS value
                FROM companies c
                INNER JOIN branches b ON c.branch_id = b.id
                LEFT JOIN company_loyalty_points clp ON clp.company_id = c.id
                WHERE c.status = 1
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  $branchFilter
                GROUP BY b.id, b.name
                HAVING value > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            break;
            
        case 'points_canceled':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    COALESCE(SUM(CASE WHEN clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_canceled_status_id') THEN clp.points_earned ELSE 0 END), 0) AS value
                FROM companies c
                INNER JOIN branches b ON c.branch_id = b.id
                LEFT JOIN company_loyalty_points clp ON clp.company_id = c.id
                WHERE c.status = 1
                  AND LOWER(b.name) NOT LIKE '%mass market%'
                  AND LOWER(b.name) NOT LIKE '%twin cities%'
                  AND LOWER(b.name) NOT LIKE '%accounts receivable%'
                  $branchFilter
                GROUP BY b.id, b.name
                HAVING value > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            break;
            
        case 'points_redeemed':
        case 'redemptions_points':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    COALESCE(SUM(crp.points), 0) AS value
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
                GROUP BY b.id, b.name
                HAVING value > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $from_date, $to_date);
            break;
            
        case 'redemptions_value':
            $sql = "
                SELECT 
                    b.name AS branch_name,
                    COALESCE(SUM(crp.dollars), 0) AS value
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
                GROUP BY b.id, b.name
                HAVING value > 0
                ORDER BY value DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $from_date, $to_date);
            break;
            
        default:
            echo json_encode(['error' => 'Invalid metric']);
            exit;
    }
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $breakdown[] = [
            'branch_name' => $row['branch_name'],
            'value' => (float)$row['value']
        ];
        $total += (float)$row['value'];
    }
    
    $stmt->close();
    
    echo json_encode([
        'breakdown' => $breakdown,
        'total' => $total
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if ($conn) {
        $conn->close();
    }
}
?>

