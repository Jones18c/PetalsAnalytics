<?php
$header['pageTitle'] = "Order Summary Test 2";
require(__DIR__ . '/../../includes/header.inc.php');
require(__DIR__ . '/../../includes/config.inc.php');
require_once(__DIR__ . '/../../includes/functions.inc.php');

/* MODULE ACCESS CONTROL TEMPORARILY DISABLED
// Module Access Control
$moduleCode = 'petals_data';
$userEmail = getUserEmail();
if (!hasModuleAccess($userEmail, $moduleCode)) {
    logModuleAccess($userEmail, $moduleCode, 'denied');
    denyAccess();
}
logModuleAccess($userEmail, $moduleCode, 'view');
*/

// Initialize variables
$results = [];
$totals = [
    'online_orders' => 0,
    'local_orders' => 0,
    'total_orders' => 0,
    'online_revenue' => 0,
    'local_revenue' => 0,
    'total_revenue' => 0,
    'online_aov' => 0,
    'local_aov' => 0,
    'online_percent' => 0
];
$error = '';
$from_date = $_GET['from_date'] ?? date('Y-01-01'); // Default to start of current year
$to_date = $_GET['to_date'] ?? date('Y-m-d'); // Default to today
$customer_filter = $_GET['customer_filter'] ?? 'all'; // all, enrolled, can_enroll_not_enrolled, cant_enroll

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
    $from_date = date('Y-01-01');
    $to_date = date('Y-m-d');
}

// Ensure start date is not after end date
if ($from_date > $to_date) {
    $temp = $from_date;
    $from_date = $to_date;
    $to_date = $temp;
}

// Get customer codes that match enrollment filter (for filtering Snowflake)
$enrolledCustomerCodes = [];
if ($customer_filter !== 'all') {
    try {
        $temp_conn = new mysqli($config['dbServer'], $config['dbUser'], $config['dbPassword'], $config['dbName']);
        if (!$temp_conn->connect_error) {
            if ($customer_filter === 'enrolled') {
                $customerSql = "SELECT DISTINCT c.code AS customer_code
                    FROM companies c
                    WHERE c.status = 1 AND c.is_enrolled_loyalty = 1";
            } elseif ($customer_filter === 'can_enroll_not_enrolled') {
                $customerSql = "SELECT DISTINCT c.code AS customer_code
                    FROM companies c
                    WHERE c.status = 1 AND c.can_enroll_loyalty = 1 AND c.is_enrolled_loyalty = 0";
            } elseif ($customer_filter === 'cant_enroll') {
                // NEW: Customers who CANNOT enroll (can_enroll_loyalty = 0)
                $customerSql = "SELECT DISTINCT c.code AS customer_code
                    FROM companies c
                    WHERE c.status = 1 AND c.can_enroll_loyalty = 0";
            } else {
                $customerSql = "SELECT DISTINCT c.code AS customer_code
                    FROM companies c
                    WHERE c.status = 1";
            }
            
            $customerResult = $temp_conn->query($customerSql);
            if ($customerResult) {
                while ($customerRow = $customerResult->fetch_assoc()) {
                    $code = $customerRow['customer_code'];
                    if (!empty($code)) {
                        $enrolledCustomerCodes[] = $temp_conn->real_escape_string($code);
                    }
                }
            }
            $temp_conn->close();
        }
    } catch (Exception $e) {
        error_log("Customer code lookup error: " . $e->getMessage());
    }
}

// Get online orders from MySQL
$onlineOrders = [];
$onlineRevenue = [];
$mysql_conn = null;
try {
    $mysql_conn = new mysqli($config['dbServer'], $config['dbUser'], $config['dbPassword'], $config['dbName']);
    
    if ($mysql_conn->connect_error) {
        throw new Exception("MySQL connection failed: " . $mysql_conn->connect_error);
    }
    
    // Query to get online orders and revenue by branch from MySQL
    $sql = "
        SELECT 
            b.name AS branch_name,
            COUNT(DISTINCT o.id) AS order_count,
            SUM(o.total) AS total_revenue
        FROM orders o
        INNER JOIN branches b ON o.branch_id = b.id
        LEFT JOIN companies c ON o.company_id = c.id
        WHERE o.order_status_id = 2
          AND LOWER(b.name) NOT LIKE '%mass market%'
          AND LOWER(b.name) NOT LIKE '%twin cities%'
          AND LOWER(b.name) NOT LIKE '%accounts receivable%'
          AND DATE(o.invoice_date) >= ?
          AND DATE(o.invoice_date) <= ?
    ";
    
    // Add customer filter condition
    if ($customer_filter === 'enrolled') {
        $sql .= " AND c.is_enrolled_loyalty = 1 AND c.status = 1";
    } elseif ($customer_filter === 'can_enroll_not_enrolled') {
        $sql .= " AND c.can_enroll_loyalty = 1 AND c.is_enrolled_loyalty = 0 AND c.status = 1";
    } elseif ($customer_filter === 'cant_enroll') {
        // NEW: Customers who CANNOT enroll (can_enroll_loyalty = 0)
        $sql .= " AND c.can_enroll_loyalty = 0 AND c.status = 1";
    } else {
        $sql .= " AND c.status = 1";
    }
    
    $sql .= "
        GROUP BY b.name
        ORDER BY b.name ASC
    ";
    
    $stmt = $mysql_conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("MySQL prepare failed: " . $mysql_conn->error);
    }
    
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Process online orders and normalize branch names
    while ($row = $result->fetch_assoc()) {
        $normalizedName = normalizeBranchName($row['branch_name']);
        $onlineOrders[$normalizedName] = ($onlineOrders[$normalizedName] ?? 0) + (int)$row['order_count'];
        $onlineRevenue[$normalizedName] = ($onlineRevenue[$normalizedName] ?? 0) + (float)$row['total_revenue'];
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("MySQL Online Orders Error: " . $e->getMessage());
} finally {
    if ($mysql_conn) {
        $mysql_conn->close();
    }
}

// Function to normalize branch names (same as LocationsDashboard)
function normalizeBranchName($branchName) {
    // Remove "Mayesh" prefix and trim
    $normalized = trim(str_replace('Mayesh', '', $branchName));
    
    // Remove numbers in parentheses (e.g., "Atlanta (26)" -> "Atlanta")
    $normalized = preg_replace('/\s*\(\d+\)\s*$/', '', $normalized);
    
    // Map "Cut Flower" to "Atlanta"
    if ($normalized === 'Cut Flower') {
        return 'Atlanta';
    }
    
    // Map "LAX Shipping" to "LAX/Shipping" (handle both with and without parentheses)
    if (preg_match('/^LAX\s*Shipping/i', $normalized)) {
        return 'LAX/Shipping';
    }
    
    // Return trimmed name
    return trim($normalized);
}

// Get local orders from Snowflake using PURCHASE_ORDER logic
$localOrders = [];
$localRevenue = [];
try {
    // Connect using ODBC (same as Route-Data-Repo)
    // Support both flat and nested config structures
    $account = $config['snowflake']['account'] ?? $config['snowflake_account'] ?? '';
    $host = $config['snowflake']['host'] ?? $account ?? '';
    $database = $config['snowflake']['database'] ?? $config['snowflake_database'] ?? 'KOMET';
    $warehouse = $config['snowflake']['warehouse'] ?? $config['snowflake_warehouse'] ?? 'READER_WH';
    $user = $config['snowflake']['user'] ?? $config['snowflake_user'] ?? 'reader_admin';
    $password = $config['snowflake']['password'] ?? $config['snowflake_password'] ?? '';
    
    // Extract account ID from full hostname (e.g., "jn47684.us-central1.gcp" from "jn47684.us-central1.gcp.snowflakecomputing.com")
    // For the connection string, we need just the account part before ".snowflakecomputing.com"
    if (strpos($host, '.snowflakecomputing.com') !== false) {
        $host = str_replace('.snowflakecomputing.com', '', $host);
    }
    
    // Use the working SnowflakeDSIIDriver connection string format
    $connection_string = "Driver={SnowflakeDSIIDriver};Server={$host}.snowflakecomputing.com;Database={$database};Warehouse={$warehouse};UID={$user};PWD={$password}";
    
    $odbc_conn = @odbc_connect($connection_string, '', '');
    
    if (!$odbc_conn) {
        $odbc_error = odbc_errormsg();
        throw new Exception("Failed to connect to Snowflake via ODBC: " . ($odbc_error ?: "Unknown error"));
    }
    
    // Build enrolled customers filter using VALUES clause to avoid 50-item IN limit
    $enrolledCustomersCTE = "";
    if (!empty($enrolledCustomerCodes)) {
        $values = [];
        foreach ($enrolledCustomerCodes as $code) {
            $escaped = addslashes($code);
            $values[] = "('" . $escaped . "')";
        }
        $enrolledCustomersCTE = "
          enrolled_customers AS (
            SELECT customer_code AS customer_id
            FROM (VALUES " . implode(",", $values) . ") AS t(customer_code)
          ),";
    }
    
    // Query to get LOCAL orders only (using PURCHASE_ORDER logic to exclude programs)
    // Program logic: FDB, LAL, DBL, PCL, WSP, SBL, PRB, MDB are NOT local
    // Local = everything else
    $sql = "
        WITH " . $enrolledCustomersCTE . "
        deduped_customers AS (
          SELECT DISTINCT
            c.customer_id,
            c.customer_type
          FROM analytics.customers_external c
          " . (!empty($enrolledCustomerCodes) ? "
          INNER JOIN enrolled_customers ec ON CAST(c.customer_id AS VARCHAR) = CAST(ec.customer_id AS VARCHAR)" : "") . "
        ),
        orders_with_program AS (
          SELECT 
            i.invoice_number,
            i.customer_id,
            i.shipping_date,
            i.subtotal,
            i.company_location_name,
            i.company_location_id,
            i.company_location_code,
            i.customer_group,
            i.purchase_order,
            c.customer_type,
            -- Determine program based on PURCHASE_ORDER logic
            CASE
              WHEN (UPPER(COALESCE(i.purchase_order, '')) LIKE '%FDB%' OR UPPER(COALESCE(i.purchase_order, '')) LIKE '%FBD%')
                   AND UPPER(COALESCE(i.company_location_code, '')) = 'MIA'
                   THEN 'FDB'
              WHEN UPPER(COALESCE(i.purchase_order, '')) LIKE '%LAL%' THEN 'LAL'
              WHEN UPPER(COALESCE(i.purchase_order, '')) LIKE '%DBL%' THEN 'DBL'
              WHEN UPPER(COALESCE(i.purchase_order, '')) LIKE '%PCL%' THEN 'PCL'
              WHEN UPPER(COALESCE(i.purchase_order, '')) LIKE '%WSP%' THEN 'WSP'
              WHEN UPPER(COALESCE(i.purchase_order, '')) LIKE '%SBL%' THEN 'SBL'
              WHEN UPPER(COALESCE(i.purchase_order, '')) LIKE '%PRB%' THEN 'PRB'
              WHEN (UPPER(COALESCE(i.purchase_order, '')) NOT LIKE '%FDB%' AND UPPER(COALESCE(i.purchase_order, '')) NOT LIKE '%FBD%')
                   AND COALESCE(i.is_ecommerce, FALSE) = TRUE
                   AND UPPER(COALESCE(i.company_location_code, '')) = 'MIA'
                   AND COALESCE(NULLIF(TRIM(c.customer_type), ''), 'KEEP') <> 'Internal'
                   THEN 'MDB'
              ELSE 'Local'
            END AS program
          FROM analytics.invoices_external i
          JOIN deduped_customers c
            ON i.customer_id = c.customer_id
          WHERE COALESCE(NULLIF(TRIM(c.customer_type), ''), 'KEEP') <> 'Internal'
            AND i.invoice_status = 'Confirmed'
            AND CAST(i.shipping_date AS DATE) >= '" . date('Y-m-d', strtotime($from_date)) . "'
            AND CAST(i.shipping_date AS DATE) <= '" . date('Y-m-d', strtotime($to_date)) . "'
        ),
        regular_locations AS (
          SELECT 
              CASE 
                  WHEN TRIM(REPLACE(i.company_location_name, 'Mayesh', '')) = 'Cut Flower' 
                       THEN 'Atlanta'
                  ELSE TRIM(REPLACE(i.company_location_name, 'Mayesh', ''))
              END AS branch_name,
              COUNT(DISTINCT i.invoice_number) AS order_count,
              SUM(i.subtotal) AS total_revenue
          FROM orders_with_program i
          WHERE i.program = 'Local'
            AND i.company_location_id <> 223
          GROUP BY 
              CASE 
                  WHEN TRIM(REPLACE(i.company_location_name, 'Mayesh', '')) = 'Cut Flower' 
                       THEN 'Atlanta'
                  ELSE TRIM(REPLACE(i.company_location_name, 'Mayesh', ''))
              END
        ),
        miami_direct AS (
          SELECT 
              CASE 
                  WHEN TRIM(REGEXP_REPLACE(COALESCE(i.customer_group, ''), '[^\\x20-\\x7E]', '')) 
                       IN ('Miami Sales', 'Orlando', 'New Orleans') THEN 'Miami'
                  WHEN TRIM(REGEXP_REPLACE(COALESCE(i.customer_group, ''), '[^\\x20-\\x7E]', '')) = 'LAX'
                       THEN 'LAX/Shipping'
                  WHEN TRIM(REGEXP_REPLACE(COALESCE(i.customer_group, ''), '[^\\x20-\\x7E]', '')) = 'Riverside Sales'
                       THEN 'Riverside'
                  ELSE COALESCE(
                         NULLIF(
                           TRIM(REGEXP_REPLACE(COALESCE(i.customer_group, ''), '[^\\x20-\\x7E]', ''))
                         , ''), 
                         'Unassigned'
                       )
              END AS branch_name,
              COUNT(DISTINCT i.invoice_number) AS order_count,
              SUM(i.subtotal) AS total_revenue
          FROM orders_with_program i
          WHERE i.program = 'Local'
            AND i.company_location_id = 223
          GROUP BY 
              CASE 
                  WHEN TRIM(REGEXP_REPLACE(COALESCE(i.customer_group, ''), '[^\\x20-\\x7E]', '')) 
                       IN ('Miami Sales', 'Orlando', 'New Orleans') THEN 'Miami'
                  WHEN TRIM(REGEXP_REPLACE(COALESCE(i.customer_group, ''), '[^\\x20-\\x7E]', '')) = 'LAX'
                       THEN 'LAX/Shipping'
                  WHEN TRIM(REGEXP_REPLACE(COALESCE(i.customer_group, ''), '[^\\x20-\\x7E]', '')) = 'Riverside Sales'
                       THEN 'Riverside'
                  ELSE COALESCE(
                         NULLIF(
                           TRIM(REGEXP_REPLACE(COALESCE(i.customer_group, ''), '[^\\x20-\\x7E]', ''))
                         , ''), 
                         'Unassigned'
                       )
              END
        ),
        combined AS (
          SELECT branch_name, order_count, total_revenue FROM regular_locations
          UNION ALL
          SELECT branch_name, order_count, total_revenue FROM miami_direct
        )
        SELECT
            branch_name,
            SUM(order_count) AS order_count,
            SUM(total_revenue) AS total_revenue
        FROM combined
        GROUP BY branch_name
        ORDER BY branch_name ASC
    ";
    
    // Execute query using ODBC (same as Route-Data-Repo)
    $odbc_result = @odbc_exec($odbc_conn, $sql);
    if (!$odbc_result) {
        $odbc_error = odbc_errormsg($odbc_conn);
        throw new Exception("Query execution failed: " . ($odbc_error ?: "Unknown error"));
    }
    
    // Fetch results using odbc_fetch_array (same as Route-Data-Repo)
    while ($row = odbc_fetch_array($odbc_result)) {
        $orderCount = (int)($row['ORDER_COUNT'] ?? $row['order_count'] ?? 0);
        $revenue = (float)($row['TOTAL_REVENUE'] ?? $row['total_revenue'] ?? 0);
        $branchName = $row['BRANCH_NAME'] ?? $row['branch_name'] ?? 'Unknown';
        $localOrders[$branchName] = $orderCount;
        $localRevenue[$branchName] = $revenue;
    }
    
    odbc_free_result($odbc_result);
    odbc_close($odbc_conn);
    
} catch (Exception $e) {
    $error = "Snowflake Error: " . $e->getMessage();
    error_log("Snowflake Local Orders Error: " . $e->getMessage());
}

// Combine online and local orders by normalized branch name
$allBranches = array_unique(array_merge(array_keys($onlineOrders), array_keys($localOrders)));
sort($allBranches);

foreach ($allBranches as $branchName) {
    $onlineCount = $onlineOrders[$branchName] ?? 0;
    $onlineRev = $onlineRevenue[$branchName] ?? 0;
    $localCount = $localOrders[$branchName] ?? 0;
    $localRev = $localRevenue[$branchName] ?? 0;
    
    $totalCount = $onlineCount + $localCount;
    $totalRev = $onlineRev + $localRev;
    
    // Calculate AOVs
    $onlineAOV = $onlineCount > 0 ? $onlineRev / $onlineCount : 0;
    $localAOV = $localCount > 0 ? $localRev / $localCount : 0;
    
    // Calculate online order percentage
    $onlinePercent = $totalCount > 0 ? ($onlineCount / $totalCount) * 100 : 0;
    
    $results[] = [
        'branch_name' => $branchName,
        'online_orders' => $onlineCount,
        'local_orders' => $localCount,
        'total_orders' => $totalCount,
        'online_revenue' => $onlineRev,
        'local_revenue' => $localRev,
        'total_revenue' => $totalRev,
        'online_aov' => $onlineAOV,
        'local_aov' => $localAOV,
        'online_percent' => $onlinePercent
    ];
}

// Calculate totals
$totals['total_orders'] = array_sum(array_column($results, 'total_orders'));
$totals['online_orders'] = array_sum(array_column($results, 'online_orders'));
$totals['local_orders'] = array_sum(array_column($results, 'local_orders'));
$totals['online_revenue'] = array_sum(array_column($results, 'online_revenue'));
$totals['local_revenue'] = array_sum(array_column($results, 'local_revenue'));
$totals['total_revenue'] = array_sum(array_column($results, 'total_revenue'));
$totals['online_aov'] = $totals['online_orders'] > 0 ? $totals['online_revenue'] / $totals['online_orders'] : 0;
$totals['local_aov'] = $totals['local_orders'] > 0 ? $totals['local_revenue'] / $totals['local_orders'] : 0;
$totals['online_percent'] = $totals['total_orders'] > 0 ? ($totals['online_orders'] / $totals['total_orders']) * 100 : 0;
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <h1 class="mb-4">Order Summary Test 2</h1>
            
            <!-- Date Filter Form -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-3">
                            <label for="from_date" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="from_date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="to_date" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="to_date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="customer_filter" class="form-label">Customer Filter</label>
                            <select class="form-select" id="customer_filter" name="customer_filter">
                                <option value="all" <?php echo $customer_filter === 'all' ? 'selected' : ''; ?>>All Customers</option>
                                <option value="enrolled" <?php echo $customer_filter === 'enrolled' ? 'selected' : ''; ?>>Enrolled Customers Only</option>
                                <option value="can_enroll_not_enrolled" <?php echo $customer_filter === 'can_enroll_not_enrolled' ? 'selected' : ''; ?>>Can Enroll (Not Enrolled) Only</option>
                                <option value="cant_enroll" <?php echo $customer_filter === 'cant_enroll' ? 'selected' : ''; ?>>Can't Enroll</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                    <br><small>If this error persists, please check your Snowflake connection using the <a href="db_test.php">Database Connection Test</a> page.</small>
                </div>
            <?php endif; ?>
            
            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-muted">Total Orders</h5>
                            <h2 class="mb-0"><?php echo number_format($totals['total_orders']); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-muted">Online Orders</h5>
                            <h2 class="mb-0"><?php echo number_format($totals['online_orders']); ?></h2>
                            <small class="text-muted"><?php echo number_format($totals['online_percent'], 1); ?>%</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-muted">Local Orders</h5>
                            <h2 class="mb-0"><?php echo number_format($totals['local_orders']); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-muted">Total Revenue</h5>
                            <h2 class="mb-0">$<?php echo number_format($totals['total_revenue'], 0); ?></h2>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Results Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Orders by Branch</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Branch</th>
                                    <th class="text-end">Online Orders</th>
                                    <th class="text-end">Local Orders</th>
                                    <th class="text-end">Total Orders</th>
                                    <th class="text-end">Online Revenue</th>
                                    <th class="text-end">Local Revenue</th>
                                    <th class="text-end">Total Revenue</th>
                                    <th class="text-end">Online AOV</th>
                                    <th class="text-end">Local AOV</th>
                                    <th class="text-end">Online %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($results)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center">No data found for the selected date range.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($results as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['branch_name']); ?></td>
                                            <td class="text-end"><?php echo number_format($row['online_orders']); ?></td>
                                            <td class="text-end"><?php echo number_format($row['local_orders']); ?></td>
                                            <td class="text-end"><?php echo number_format($row['total_orders']); ?></td>
                                            <td class="text-end">$<?php echo number_format($row['online_revenue'], 0); ?></td>
                                            <td class="text-end">$<?php echo number_format($row['local_revenue'], 0); ?></td>
                                            <td class="text-end">$<?php echo number_format($row['total_revenue'], 0); ?></td>
                                            <td class="text-end">$<?php echo number_format($row['online_aov'], 2); ?></td>
                                            <td class="text-end">$<?php echo number_format($row['local_aov'], 2); ?></td>
                                            <td class="text-end"><?php echo number_format($row['online_percent'], 1); ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-info fw-bold">
                                        <td>Total</td>
                                        <td class="text-end"><?php echo number_format($totals['online_orders']); ?></td>
                                        <td class="text-end"><?php echo number_format($totals['local_orders']); ?></td>
                                        <td class="text-end"><?php echo number_format($totals['total_orders']); ?></td>
                                        <td class="text-end">$<?php echo number_format($totals['online_revenue'], 0); ?></td>
                                        <td class="text-end">$<?php echo number_format($totals['local_revenue'], 0); ?></td>
                                        <td class="text-end">$<?php echo number_format($totals['total_revenue'], 0); ?></td>
                                        <td class="text-end">$<?php echo number_format($totals['online_aov'], 2); ?></td>
                                        <td class="text-end">$<?php echo number_format($totals['local_aov'], 2); ?></td>
                                        <td class="text-end"><?php echo number_format($totals['online_percent'], 1); ?>%</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require(__DIR__ . '/../../includes/footer.inc.php'); ?>

