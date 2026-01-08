<?php
$header['pageTitle'] = "Enrollment Details - Petals Data";
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

// Connect to MySQL database
$conn = null;
$results = [];
$branches = [];
$error = null;
$totals = [
    'enrolled' => 0,
    'not_enrolled_with_orders' => 0,
    'total' => 0,
    'orders_over_350_6m' => 0,
    'orders_over_350_12m' => 0,
    'total_orders_6m' => 0,
    'total_orders_12m' => 0,
    'revenue_over_350_6m' => 0,
    'revenue_over_350_12m' => 0
];

// Get filter parameters
$selectedBranch = isset($_GET['branch_id']) ? $_GET['branch_id'] : 'all';
$customerFilter = isset($_GET['customer_filter']) ? $_GET['customer_filter'] : 'all';

try {
    $conn = new mysqli($config['dbServer'], $config['dbUser'], $config['dbPassword'], $config['dbName']);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // First, get list of branches for the dropdown
    $branchSql = "
        SELECT id, name 
        FROM branches 
        WHERE LOWER(name) NOT LIKE '%mass market%'
          AND LOWER(name) NOT LIKE '%twin cities%'
          AND LOWER(name) NOT LIKE '%accounts receivable%'
        ORDER BY name ASC
    ";
    $branchResult = $conn->query($branchSql);
    while ($row = $branchResult->fetch_assoc()) {
        $branches[] = $row;
    }
    
    // Six months ago date
    $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
    // Twelve months ago date
    $twelveMonthsAgo = date('Y-m-d', strtotime('-12 months'));
    
    // Build the main query for customer details
    // This shows:
    // 1. All enrolled customers
    // 2. All non-enrolled customers who have had an order in last 6 months
    
    $branchFilter = "";
    
    // Base customer filter condition
    $customerCondition = "";
    if ($customerFilter === 'enrolled') {
        $customerCondition = " AND c.is_enrolled_loyalty = 1 ";
    } elseif ($customerFilter === 'not_enrolled_with_orders') {
        $customerCondition = " AND c.is_enrolled_loyalty = 0 AND c.can_enroll_loyalty = 1 ";
    }
    // 'all' shows both enrolled and non-enrolled with recent orders
    
    // Build params in correct order:
    // date (6m orders), date (6m revenue), date (12m orders), date (12m revenue),
    // date (6m orders >= 350), date (12m orders >= 350),
    // date (6m revenue >= 350), date (12m revenue >= 350),
    // [branch if selected], date (EXISTS)
    $params = [
        $sixMonthsAgo,
        $sixMonthsAgo,
        $twelveMonthsAgo,
        $twelveMonthsAgo,
        $sixMonthsAgo,
        $twelveMonthsAgo,
        $sixMonthsAgo,
        $twelveMonthsAgo
    ];
    $types = "ssssssss";
    
    if ($selectedBranch !== 'all') {
        $branchFilter = " AND c.branch_id = ? ";
        $params[] = $selectedBranch;
        $types .= "i";
    }
    
    // Add final date param for EXISTS clause
    $params[] = $sixMonthsAgo;
    $types .= "s";
    
    $sql = "
        SELECT 
            c.id AS customer_id,
            c.code AS customer_code,
            c.name AS customer_name,
            b.name AS branch_name,
            c.can_enroll_loyalty,
            c.is_enrolled_loyalty,
            c.status AS customer_status,
            (
                SELECT MAX(o.invoice_date) 
                FROM orders o 
                WHERE o.company_id = c.id
                AND o.order_status_id = 2
            ) AS last_order_date,
            (
                SELECT COUNT(*) 
                FROM orders o 
                WHERE o.company_id = c.id 
                AND o.invoice_date >= ?
            ) AS orders_last_6m,
            (
                SELECT SUM(o.total) 
                FROM orders o 
                WHERE o.company_id = c.id 
                AND o.invoice_date >= ?
            ) AS revenue_last_6m,
            (
                SELECT COUNT(*) 
                FROM orders o 
                WHERE o.company_id = c.id 
                AND o.invoice_date >= ?
            ) AS orders_last_12m,
            (
                SELECT SUM(o.total) 
                FROM orders o 
                WHERE o.company_id = c.id 
                AND o.invoice_date >= ?
            ) AS revenue_last_12m,
            (
                SELECT COUNT(*) 
                FROM orders o 
                WHERE o.company_id = c.id 
                AND o.invoice_date >= ?
                AND o.total >= 350
            ) AS orders_over_350_6m,
            (
                SELECT COUNT(*) 
                FROM orders o 
                WHERE o.company_id = c.id 
                AND o.invoice_date >= ?
                AND o.total >= 350
            ) AS orders_over_350_12m,
            (
                SELECT SUM(o.total) 
                FROM orders o 
                WHERE o.company_id = c.id 
                AND o.invoice_date >= ?
                AND o.total >= 350
            ) AS revenue_over_350_6m,
            (
                SELECT SUM(o.total) 
                FROM orders o 
                WHERE o.company_id = c.id 
                AND o.invoice_date >= ?
                AND o.total >= 350
            ) AS revenue_over_350_12m
        FROM companies c
        INNER JOIN branches b ON b.id = c.branch_id
        WHERE c.status = 1
          AND LOWER(b.name) NOT LIKE '%mass market%'
          AND LOWER(b.name) NOT LIKE '%twin cities%'
          AND LOWER(b.name) NOT LIKE '%accounts receivable%'
          {$branchFilter}
          {$customerCondition}
          AND (
              c.is_enrolled_loyalty = 1
              OR (
                  c.can_enroll_loyalty = 1 
                  AND c.is_enrolled_loyalty = 0
                  AND EXISTS (
                      SELECT 1 FROM orders o 
                      WHERE o.company_id = c.id 
                      AND o.invoice_date >= ?
                      AND o.order_status_id = 2
                  )
              )
          )
        ORDER BY b.name ASC, c.name ASC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result) {
        throw new Exception("Query failed: " . $stmt->error);
    }
    
    // Process results
    while ($row = $result->fetch_assoc()) {
        $ordersOver350_6m = (int)$row['orders_over_350_6m'];
        $ordersOver350_12m = (int)$row['orders_over_350_12m'];
        $orders_6m = (int)$row['orders_last_6m'];
        $orders_12m = (int)$row['orders_last_12m'];
        $revenueOver350_6m = (float)($row['revenue_over_350_6m'] ?? 0);
        $revenueOver350_12m = (float)($row['revenue_over_350_12m'] ?? 0);
        
        $results[] = [
            'customer_id' => $row['customer_id'],
            'customer_code' => $row['customer_code'],
            'customer_name' => $row['customer_name'],
            'branch_name' => $row['branch_name'],
            'can_enroll' => (int)$row['can_enroll_loyalty'],
            'is_enrolled' => (int)$row['is_enrolled_loyalty'],
            'customer_status' => (int)$row['customer_status'],
            'last_order_date' => $row['last_order_date'],
            'orders_last_6m' => $orders_6m,
            'revenue_last_6m' => (float)$row['revenue_last_6m'],
            'orders_last_12m' => $orders_12m,
            'revenue_last_12m' => (float)$row['revenue_last_12m'],
            'orders_over_350_6m' => $ordersOver350_6m,
            'orders_over_350_12m' => $ordersOver350_12m,
            'revenue_over_350_6m' => $revenueOver350_6m,
            'revenue_over_350_12m' => $revenueOver350_12m,
            'pct_orders_over_350_6m' => $orders_6m > 0 ? round(($ordersOver350_6m / $orders_6m) * 100, 1) : 0,
            'pct_orders_over_350_12m' => $orders_12m > 0 ? round(($ordersOver350_12m / $orders_12m) * 100, 1) : 0
        ];
        
        // Count totals
        if ($row['is_enrolled_loyalty'] == 1) {
            $totals['enrolled']++;
        } else {
            $totals['not_enrolled_with_orders']++;
        }
        $totals['total']++;
        $totals['orders_over_350_6m'] += $ordersOver350_6m;
        $totals['orders_over_350_12m'] += $ordersOver350_12m;
        $totals['total_orders_6m'] += $orders_6m;
        $totals['total_orders_12m'] += $orders_12m;
        $totals['revenue_over_350_6m'] += $revenueOver350_6m;
        $totals['revenue_over_350_12m'] += $revenueOver350_12m;
    }
    
    $stmt->close();
    
    // Get summary by status for cards
    $summaryParams = [];
    $summaryTypes = "";
    $summaryBranchFilter = "";
    
    if ($selectedBranch !== 'all') {
        $summaryBranchFilter = " AND c.branch_id = ? ";
        $summaryParams[] = $selectedBranch;
        $summaryTypes .= "i";
    }
    
    // Count enrolled customers
    $enrolledSql = "
        SELECT COUNT(*) AS count
        FROM companies c
        INNER JOIN branches b ON b.id = c.branch_id
        WHERE c.status = 1
          AND c.is_enrolled_loyalty = 1
          AND LOWER(b.name) NOT LIKE '%mass market%'
          AND LOWER(b.name) NOT LIKE '%twin cities%'
          AND LOWER(b.name) NOT LIKE '%accounts receivable%'
          {$summaryBranchFilter}
    ";
    
    $stmtEnrolled = $conn->prepare($enrolledSql);
    if (!empty($summaryParams)) {
        $stmtEnrolled->bind_param($summaryTypes, ...$summaryParams);
    }
    $stmtEnrolled->execute();
    $enrolledResult = $stmtEnrolled->get_result()->fetch_assoc();
    $totalEnrolled = (int)$enrolledResult['count'];
    $stmtEnrolled->close();
    
    // Count not enrolled with orders in last 6 months
    $notEnrolledParams = [];
    $notEnrolledTypes = "";
    if ($selectedBranch !== 'all') {
        $notEnrolledParams[] = $selectedBranch;
        $notEnrolledTypes .= "i";
    }
    $notEnrolledParams[] = $sixMonthsAgo;
    $notEnrolledTypes .= "s";
    
    $notEnrolledSql = "
        SELECT COUNT(*) AS count
        FROM companies c
        INNER JOIN branches b ON b.id = c.branch_id
        WHERE c.status = 1
          AND c.is_enrolled_loyalty = 0
          AND c.can_enroll_loyalty = 1
          AND LOWER(b.name) NOT LIKE '%mass market%'
          AND LOWER(b.name) NOT LIKE '%twin cities%'
          AND LOWER(b.name) NOT LIKE '%accounts receivable%'
          {$summaryBranchFilter}
          AND EXISTS (
              SELECT 1 FROM orders o 
              WHERE o.company_id = c.id 
              AND o.invoice_date >= ?
              AND o.order_status_id = 2
          )
    ";
    
    $stmtNotEnrolled = $conn->prepare($notEnrolledSql);
    $stmtNotEnrolled->bind_param($notEnrolledTypes, ...$notEnrolledParams);
    $stmtNotEnrolled->execute();
    $notEnrolledResult = $stmtNotEnrolled->get_result()->fetch_assoc();
    $totalNotEnrolledWithOrders = (int)$notEnrolledResult['count'];
    $stmtNotEnrolled->close();
    
    // Calculate percentages for orders over $350
    $totals['pct_orders_over_350_6m'] = $totals['total_orders_6m'] > 0 
        ? round(($totals['orders_over_350_6m'] / $totals['total_orders_6m']) * 100, 1) 
        : 0;
    $totals['pct_orders_over_350_12m'] = $totals['total_orders_12m'] > 0 
        ? round(($totals['orders_over_350_12m'] / $totals['total_orders_12m']) * 100, 1) 
        : 0;
    
} catch (Exception $e) {
    $error = $e->getMessage();
} finally {
    if ($conn) {
        $conn->close();
    }
}

?>

<div class="container-fluid mt-4">
    <!-- Breadcrumb Navigation -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php"><i class="bi bi-flower1 me-1"></i>Petals Data</a></li>
            <li class="breadcrumb-item"><a href="rewards_enrollment.php">Rewards Enrollment</a></li>
            <li class="breadcrumb-item active" aria-current="page">Enrollment Details</li>
        </ol>
    </nav>
    
    <div class="row mb-3">
        <div class="col-12">
            <h2><i class="bi bi-person-lines-fill me-2"></i>Enrollment Details</h2>
            <p class="text-muted">View enrolled customers and eligible customers with recent orders (last 6 months)</p>
        </div>
    </div>
    
    <!-- Filter Form -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="branch_id" class="form-label">Branch</label>
                    <select name="branch_id" id="branch_id" class="form-select">
                        <option value="all" <?php echo $selectedBranch === 'all' ? 'selected' : ''; ?>>All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch['id']; ?>" <?php echo $selectedBranch == $branch['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($branch['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="customer_filter" class="form-label">Customer Status</label>
                    <select name="customer_filter" id="customer_filter" class="form-select">
                        <option value="all" <?php echo $customerFilter === 'all' ? 'selected' : ''; ?>>All (Enrolled + Not Enrolled w/ Orders)</option>
                        <option value="enrolled" <?php echo $customerFilter === 'enrolled' ? 'selected' : ''; ?>>Enrolled Only</option>
                        <option value="not_enrolled_with_orders" <?php echo $customerFilter === 'not_enrolled_with_orders' ? 'selected' : ''; ?>>Not Enrolled (With Orders in 6M)</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel me-2"></i>Apply Filter
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-danger" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php else: ?>
    
    <!-- Enrollment Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Enrolled</h6>
                            <h3 class="mb-0 text-success"><?php echo number_format($totalEnrolled); ?></h3>
                        </div>
                        <div class="text-success" style="font-size: 2rem;">
                            <i class="bi bi-check-circle-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Not Enrolled (w/ Orders 6M)</h6>
                            <h3 class="mb-0 text-warning"><?php echo number_format($totalNotEnrolledWithOrders); ?></h3>
                        </div>
                        <div class="text-warning" style="font-size: 2rem;">
                            <i class="bi bi-exclamation-circle-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Total Orders Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Orders (6M)</h6>
                            <h3 class="mb-0 text-secondary"><?php echo number_format($totals['total_orders_6m']); ?></h3>
                        </div>
                        <div class="text-secondary" style="font-size: 2rem;">
                            <i class="bi bi-cart"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Orders < $350 (6M)</h6>
                            <h3 class="mb-0 text-warning"><?php echo number_format($totals['total_orders_6m'] - $totals['orders_over_350_6m']); ?></h3>
                        </div>
                        <div class="text-warning" style="font-size: 2rem;">
                            <i class="bi bi-cart-dash"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Orders (12M)</h6>
                            <h3 class="mb-0 text-secondary"><?php echo number_format($totals['total_orders_12m']); ?></h3>
                        </div>
                        <div class="text-secondary" style="font-size: 2rem;">
                            <i class="bi bi-cart"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Orders < $350 (12M)</h6>
                            <h3 class="mb-0 text-warning"><?php echo number_format($totals['total_orders_12m'] - $totals['orders_over_350_12m']); ?></h3>
                        </div>
                        <div class="text-warning" style="font-size: 2rem;">
                            <i class="bi bi-cart-dash"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Orders Over $350 Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Orders ≥ $350 (6M)</h6>
                            <h3 class="mb-0 text-primary"><?php echo number_format($totals['orders_over_350_6m']); ?></h3>
                        </div>
                        <div class="text-primary" style="font-size: 2rem;">
                            <i class="bi bi-cart-check"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">% Orders ≥ $350 (6M)</h6>
                            <h3 class="mb-0 text-success"><?php echo number_format($totals['pct_orders_over_350_6m'], 1); ?>%</h3>
                        </div>
                        <div class="text-success" style="font-size: 2rem;">
                            <i class="bi bi-percent"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Orders ≥ $350 (12M)</h6>
                            <h3 class="mb-0 text-primary"><?php echo number_format($totals['orders_over_350_12m']); ?></h3>
                        </div>
                        <div class="text-primary" style="font-size: 2rem;">
                            <i class="bi bi-cart-check"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">% Orders ≥ $350 (12M)</h6>
                            <h3 class="mb-0 text-success"><?php echo number_format($totals['pct_orders_over_350_12m'], 1); ?>%</h3>
                        </div>
                        <div class="text-success" style="font-size: 2rem;">
                            <i class="bi bi-percent"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Revenue from Orders ≥ $350 Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Revenue from Orders ≥ $350 (6M)</h6>
                            <h3 class="mb-0 text-info">$<?php echo number_format($totals['revenue_over_350_6m'], 2); ?></h3>
                        </div>
                        <div class="text-info" style="font-size: 2rem;">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Revenue from Orders ≥ $350 (12M)</h6>
                            <h3 class="mb-0 text-info">$<?php echo number_format($totals['revenue_over_350_12m'], 2); ?></h3>
                        </div>
                        <div class="text-info" style="font-size: 2rem;">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Customer Details Table -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header text-white d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <h5 class="mb-0"><i class="bi bi-table me-2"></i>Customer Details</h5>
                    <button type="button" class="btn btn-light btn-sm" onclick="exportToExcel()" id="exportBtn">
                        <i class="bi bi-file-earmark-excel me-2"></i>Export to Excel
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="customerTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Customer Code</th>
                                    <th>Customer Name</th>
                                    <th>Branch</th>
                                    <th class="text-center">Enrollment Status</th>
                                    <th class="text-center">Can Enroll</th>
                                    <th class="text-end">Orders (6M)</th>
                                    <th class="text-end">Orders ≥ $350 (6M)</th>
                                    <th class="text-end">% ≥ $350 (6M)</th>
                                    <th class="text-end">Revenue (6M)</th>
                                    <th class="text-end">Revenue ≥ $350 (6M)</th>
                                    <th class="text-end">Orders (12M)</th>
                                    <th class="text-end">Orders ≥ $350 (12M)</th>
                                    <th class="text-end">% ≥ $350 (12M)</th>
                                    <th class="text-end">Revenue (12M)</th>
                                    <th class="text-end">Revenue ≥ $350 (12M)</th>
                                    <th>Last Order</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($results)): ?>
                                <tr>
                                    <td colspan="16" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox me-2"></i>No customers found matching the criteria
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($results as $row): ?>
                                <tr>
                                    <td data-order="<?php echo htmlspecialchars($row['customer_code']); ?>"><code><?php echo htmlspecialchars($row['customer_code']); ?></code></td>
                                    <td data-order="<?php echo htmlspecialchars($row['customer_name']); ?>"><strong><?php echo htmlspecialchars($row['customer_name']); ?></strong></td>
                                    <td data-order="<?php echo htmlspecialchars($row['branch_name']); ?>"><?php echo htmlspecialchars($row['branch_name']); ?></td>
                                    <td class="text-center" data-order="<?php echo $row['is_enrolled'] ? 'Enrolled' : 'Not Enrolled'; ?>">
                                        <?php if ($row['is_enrolled']): ?>
                                        <span class="badge bg-success">Enrolled</span>
                                        <?php else: ?>
                                        <span class="badge bg-warning text-dark">Not Enrolled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center" data-order="<?php echo $row['can_enroll'] ? 'Yes' : 'No'; ?>">
                                        <?php if ($row['can_enroll']): ?>
                                        <span class="badge bg-info">Yes</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['orders_last_6m']; ?>"><?php echo number_format($row['orders_last_6m']); ?></td>
                                    <td class="text-end" data-order="<?php echo $row['orders_over_350_6m']; ?>">
                                        <span class="badge bg-primary"><?php echo number_format($row['orders_over_350_6m']); ?></span>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['pct_orders_over_350_6m']; ?>">
                                        <strong class="text-primary"><?php echo number_format($row['pct_orders_over_350_6m'], 1); ?>%</strong>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['revenue_last_6m']; ?>">$<?php echo number_format($row['revenue_last_6m'], 2); ?></td>
                                    <td class="text-end" data-order="<?php echo $row['revenue_over_350_6m']; ?>">
                                        <strong class="text-info">$<?php echo number_format($row['revenue_over_350_6m'], 2); ?></strong>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['orders_last_12m']; ?>"><?php echo number_format($row['orders_last_12m']); ?></td>
                                    <td class="text-end" data-order="<?php echo $row['orders_over_350_12m']; ?>">
                                        <span class="badge bg-primary"><?php echo number_format($row['orders_over_350_12m']); ?></span>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['pct_orders_over_350_12m']; ?>">
                                        <strong class="text-primary"><?php echo number_format($row['pct_orders_over_350_12m'], 1); ?>%</strong>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['revenue_last_12m']; ?>">$<?php echo number_format($row['revenue_last_12m'], 2); ?></td>
                                    <td class="text-end" data-order="<?php echo $row['revenue_over_350_12m']; ?>">
                                        <strong class="text-info">$<?php echo number_format($row['revenue_over_350_12m'], 2); ?></strong>
                                    </td>
                                    <td data-order="<?php echo $row['last_order_date'] ? strtotime($row['last_order_date']) : 0; ?>">
                                        <?php 
                                        if ($row['last_order_date']) {
                                            echo date('M j, Y', strtotime($row['last_order_date']));
                                        } else {
                                            echo '<span class="text-muted">N/A</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if (!empty($results)): ?>
                <div class="card-footer text-muted">
                    <small>Showing <?php echo number_format(count($results)); ?> customers</small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php endif; ?>
</div>

<!-- SheetJS Library for Excel Export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
// Initialize DataTables for sorting
(function() {
    function initDataTable() {
        if (typeof jQuery !== 'undefined' && jQuery.fn.DataTable && jQuery('#customerTable').length) {
            // Check if already initialized
            if (jQuery.fn.DataTable.isDataTable('#customerTable')) {
                return; // Already initialized
            }
            
            jQuery('#customerTable').DataTable({
                order: [[1, 'asc']], // Default sort by Customer Name
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                columnDefs: [
                    { 
                        targets: [5, 6, 7, 8, 9, 10, 11, 12, 13, 14], // Numeric columns (Orders, Revenue, Percentages)
                        type: 'num',
                        orderable: true
                    },
                    {
                        targets: [15], // Last Order date column
                        type: 'num', // Using timestamp for sorting
                        orderable: true
                    },
                    {
                        targets: [0, 1, 2, 3, 4], // Text columns (Code, Name, Branch, Status, Can Enroll)
                        orderable: true,
                        searchable: true
                    }
                ],
                dom: 'lrtip', // Show length menu, processing, table, info, pagination
                language: {
                    search: "Search customers:",
                    lengthMenu: "Show _MENU_ customers per page"
                }
            });
        } else {
            // Retry if jQuery/DataTables not loaded yet
            setTimeout(initDataTable, 100);
        }
    }
    
    // Start initialization when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDataTable);
    } else {
        initDataTable();
    }
})();

// Excel Export Function
function exportToExcel() {
    const table = document.getElementById('customerTable');
    const exportBtn = document.getElementById('exportBtn');
    
    if (!table) {
        alert('No data to export');
        return;
    }
    
    // Always get all rows from the table, ignoring DataTables sorting/filtering
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    if (rows.length === 0 || rows[0].querySelectorAll('td').length === 0) {
        alert('No data to export');
        return;
    }
    
    // Show loading state
    const originalText = exportBtn.innerHTML;
    exportBtn.disabled = true;
    exportBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Generating Excel...';
    
    try {
        const exportData = [];
        
        // Add headers
        const headers = ['Customer Code', 'Customer Name', 'Branch', 'Enrollment Status', 'Can Enroll', 'Orders (6M)', 'Orders ≥ $350 (6M)', '% ≥ $350 (6M)', 'Revenue (6M)', 'Revenue ≥ $350 (6M)', 'Orders (12M)', 'Orders ≥ $350 (12M)', '% ≥ $350 (12M)', 'Revenue (12M)', 'Revenue ≥ $350 (12M)', 'Last Order'];
        exportData.push(headers);
        
        // Add data rows
        const rowArray = Array.isArray(rows) ? rows : Array.from(rows);
        rowArray.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length === 0) return;
            
            const rowData = [];
            cells.forEach((cell, index) => {
                let cellText = cell.textContent.trim();
                
                // Get badge text if present
                const badge = cell.querySelector('.badge');
                if (badge) {
                    cellText = badge.textContent.trim();
                }
                
                // Clean revenue format
                if (index === 8 || index === 9 || index === 13 || index === 14) {
                    cellText = cellText.replace('$', '').replace(/,/g, '');
                }
                
                // Clean percentage format
                if ((index === 7 || index === 12) && cellText.includes('%')) {
                    cellText = cellText.replace('%', '').trim();
                }
                
                rowData.push(cellText);
            });
            
            exportData.push(rowData);
        });
        
        // Create workbook and worksheet
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(exportData);
        
        // Set column widths
        const colWidths = [
            { wch: 15 },  // Customer Code
            { wch: 35 },  // Customer Name
            { wch: 20 },  // Branch
            { wch: 18 },  // Enrollment Status
            { wch: 12 },  // Can Enroll
            { wch: 12 },  // Orders (6M)
            { wch: 18 },  // Orders ≥ $350 (6M)
            { wch: 15 },  // % ≥ $350 (6M)
            { wch: 15 },  // Revenue (6M)
            { wch: 20 },  // Revenue ≥ $350 (6M)
            { wch: 12 },  // Orders (12M)
            { wch: 18 },  // Orders ≥ $350 (12M)
            { wch: 15 },  // % ≥ $350 (12M)
            { wch: 15 },  // Revenue (12M)
            { wch: 20 },  // Revenue ≥ $350 (12M)
            { wch: 15 }   // Last Order
        ];
        ws['!cols'] = colWidths;
        
        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'Enrollment Details');
        
        // Generate filename with current date
        const dateStr = new Date().toISOString().split('T')[0].replace(/-/g, '');
        const filename = `Enrollment_Details_${dateStr}.xlsx`;
        
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

<?php
require(__DIR__ . '/../../includes/footer.inc.php');
?>

