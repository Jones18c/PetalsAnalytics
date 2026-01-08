<?php
$header['pageTitle'] = "Order Summary";
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

// Initialize variables
$results = [];
$totals = [
    'online_orders' => 0,
    'local_orders' => 0,
    'total_orders' => 0,
    'orders_high' => 0,
    'orders_low' => 0,
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
$customer_filter = $_GET['customer_filter'] ?? 'enrolled'; // enrolled, can_enroll_not_enrolled, can_enroll_and_enrolled (default to enrolled)
$selected_branch = $_GET['branch'] ?? 'all'; // Branch filter (default to 'all')
// Checkbox: with hidden input, value will always be sent (0 or 1)
$show_programs = isset($_GET['show_programs']) ? (int)$_GET['show_programs'] === 1 : true; // Default to true on first load

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
            } elseif ($customer_filter === 'can_enroll_and_enrolled') {
                // Combined: Customers who can enroll OR are enrolled (or both)
                $customerSql = "SELECT DISTINCT c.code AS customer_code
                    FROM companies c
                    WHERE c.status = 1 AND (c.can_enroll_loyalty = 1 OR c.is_enrolled_loyalty = 1)";
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

// Get list of branches
$branches = [];
$mysql_conn = null;
try {
    $mysql_conn = new mysqli($config['dbServer'], $config['dbUser'], $config['dbPassword'], $config['dbName']);
    
    if ($mysql_conn->connect_error) {
        throw new Exception("MySQL connection failed: " . $mysql_conn->connect_error);
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
    $branchResult = $mysql_conn->query($branchSql);
    if ($branchResult) {
        while ($row = $branchResult->fetch_assoc()) {
            $branches[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Branch list error: " . $e->getMessage());
}

// Get online orders from MySQL grouped by branch and program
$results = [];
try {
    if (!$mysql_conn || $mysql_conn->connect_error) {
        $mysql_conn = new mysqli($config['dbServer'], $config['dbUser'], $config['dbPassword'], $config['dbName']);
        if ($mysql_conn->connect_error) {
            throw new Exception("MySQL connection failed: " . $mysql_conn->connect_error);
        }
    }
    
    // Query to get online orders grouped by branch and program
    // Count orders >= $350 and orders < $350
    $sql = "
        SELECT 
            b.name AS branch_name,
            COALESCE(o.program_name, 'Local Branch Sales') AS program_name,
            COUNT(DISTINCT o.id) AS order_count,
            COUNT(DISTINCT CASE WHEN o.total >= 350 THEN o.id END) AS orders_high,
            COUNT(DISTINCT CASE WHEN o.total < 350 THEN o.id END) AS orders_low,
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
    
    // Add branch filter condition
    if ($selected_branch !== 'all') {
        $sql .= " AND b.id = ?";
    }
    
    // Add customer filter condition
    if ($customer_filter === 'enrolled') {
        $sql .= " AND c.is_enrolled_loyalty = 1 AND c.status = 1";
    } elseif ($customer_filter === 'can_enroll_not_enrolled') {
        $sql .= " AND c.can_enroll_loyalty = 1 AND c.is_enrolled_loyalty = 0 AND c.status = 1";
    } elseif ($customer_filter === 'can_enroll_and_enrolled') {
        // Combined: Customers who can enroll OR are enrolled (or both)
        $sql .= " AND (c.can_enroll_loyalty = 1 OR c.is_enrolled_loyalty = 1) AND c.status = 1";
    } else {
        $sql .= " AND c.status = 1";
    }
    
    $sql .= "
        GROUP BY b.name, o.program_name
        ORDER BY b.name ASC, o.program_name ASC
    ";
    
    $stmt = $mysql_conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("MySQL prepare failed: " . $mysql_conn->error);
    }
    
    // Bind parameters based on whether branch filter is applied
    if ($selected_branch !== 'all') {
        $branch_id = (int)$selected_branch;
        $stmt->bind_param("ssi", $from_date, $to_date, $branch_id);
    } else {
        $stmt->bind_param("ss", $from_date, $to_date);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Process online orders grouped by branch and program
    while ($row = $result->fetch_assoc()) {
        $normalizedName = normalizeBranchName($row['branch_name']);
        $programName = $row['program_name'] ?: 'Local Branch Sales';
        $orderCount = (int)$row['order_count'];
        $ordersHigh = (int)$row['orders_high'];
        $ordersLow = (int)$row['orders_low'];
        $revenue = (float)$row['total_revenue'];
        $aov = $orderCount > 0 ? $revenue / $orderCount : 0;
        
        $results[] = [
            'branch_name' => $normalizedName,
            'program_name' => $programName,
            'order_count' => $orderCount,
            'orders_high' => $ordersHigh,
            'orders_low' => $ordersLow,
            'total_revenue' => $revenue,
            'aov' => $aov
        ];
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("MySQL Online Orders Error: " . $e->getMessage());
    $error = "Error loading online orders: " . $e->getMessage();
} finally {
    if ($mysql_conn) {
        $mysql_conn->close();
    }
}

// If not showing programs, aggregate by branch
if (!$show_programs) {
    $aggregatedResults = [];
    foreach ($results as $row) {
        $branchName = $row['branch_name'];
        if (!isset($aggregatedResults[$branchName])) {
            $aggregatedResults[$branchName] = [
                'branch_name' => $branchName,
                'order_count' => 0,
                'orders_high' => 0,
                'orders_low' => 0,
                'total_revenue' => 0,
                'aov' => 0
            ];
        }
        $aggregatedResults[$branchName]['order_count'] += $row['order_count'];
        $aggregatedResults[$branchName]['orders_high'] += $row['orders_high'];
        $aggregatedResults[$branchName]['orders_low'] += $row['orders_low'];
        $aggregatedResults[$branchName]['total_revenue'] += $row['total_revenue'];
    }
    // Recalculate AOV for aggregated results
    foreach ($aggregatedResults as &$agg) {
        $agg['aov'] = $agg['order_count'] > 0 ? $agg['total_revenue'] / $agg['order_count'] : 0;
    }
    $results = array_values($aggregatedResults);
    // Sort by branch name
    usort($results, function($a, $b) {
        return strcmp($a['branch_name'], $b['branch_name']);
    });
} else {
    // Sort results by branch name, then program name
    usort($results, function($a, $b) {
        $branchCompare = strcmp($a['branch_name'], $b['branch_name']);
        if ($branchCompare === 0) {
            return strcmp($a['program_name'], $b['program_name']);
        }
        return $branchCompare;
    });
}

// Calculate totals
$totals['total_orders'] = array_sum(array_column($results, 'order_count'));
$totals['orders_high'] = array_sum(array_column($results, 'orders_high'));
$totals['orders_low'] = array_sum(array_column($results, 'orders_low'));
$totals['total_revenue'] = array_sum(array_column($results, 'total_revenue'));
$totals['aov'] = $totals['total_orders'] > 0 ? $totals['total_revenue'] / $totals['total_orders'] : 0;

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

?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <h1 class="mb-4">Order Summary</h1>
            
            <!-- Date Filter Form -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-2">
                            <label for="from_date" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="from_date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label for="to_date" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="to_date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label for="branch" class="form-label">Branch/Location</label>
                            <select class="form-select" id="branch" name="branch">
                                <option value="all" <?php echo $selected_branch === 'all' ? 'selected' : ''; ?>>All Branches</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>" <?php echo $selected_branch == $branch['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($branch['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="customer_filter" class="form-label">Customer Filter</label>
                            <select class="form-select" id="customer_filter" name="customer_filter">
                                <option value="enrolled" <?php echo $customer_filter === 'enrolled' ? 'selected' : ''; ?>>Enrolled Customers Only</option>
                                <option value="can_enroll_not_enrolled" <?php echo $customer_filter === 'can_enroll_not_enrolled' ? 'selected' : ''; ?>>Can Enroll (Not Enrolled) Only</option>
                                <option value="can_enroll_and_enrolled" <?php echo $customer_filter === 'can_enroll_and_enrolled' ? 'selected' : ''; ?>>Can Enroll and Enrolled (Combined)</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input type="hidden" name="show_programs" value="0">
                                <input class="form-check-input" type="checkbox" id="show_programs" name="show_programs" value="1" <?php echo $show_programs ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="show_programs">Show Programs</label>
                            </div>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
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
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-muted">Total Orders</h5>
                            <h2 class="mb-0"><?php echo number_format($totals['total_orders']); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-muted">Total Revenue</h5>
                            <h2 class="mb-0">$<?php echo number_format($totals['total_revenue'], 0); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-muted">Average Order Value</h5>
                            <h2 class="mb-0">$<?php echo number_format($totals['aov'], 2); ?></h2>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Results Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?php echo $show_programs ? 'Online Orders by Branch and Program' : 'Online Orders by Branch'; ?></h5>
                    <button type="button" class="btn btn-light btn-sm" onclick="exportToExcel()" id="exportBtn">
                        <i class="bi bi-file-earmark-excel me-2"></i>Export to Excel
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="ordersTable">
                            <thead>
                                <tr>
                                    <th>Branch</th>
                                    <?php if ($show_programs): ?>
                                        <th>Program</th>
                                    <?php endif; ?>
                                    <th class="text-end">Orders</th>
                                    <th class="text-end">Orders ≥ $350</th>
                                    <th class="text-end">Orders < $350</th>
                                    <th class="text-end">Revenue</th>
                                    <th class="text-end">AOV</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($results)): ?>
                                    <tr>
                                        <td colspan="<?php echo $show_programs ? '7' : '6'; ?>" class="text-center">No data found for the selected date range.</td>
                                    </tr>
                                <?php else: 
                                    foreach ($results as $row): ?>
                                        <tr>
                                            <td data-order="<?php echo htmlspecialchars($row['branch_name']); ?>"><?php echo htmlspecialchars($row['branch_name']); ?></td>
                                            <?php if ($show_programs): ?>
                                                <td data-order="<?php echo htmlspecialchars($row['program_name']); ?>"><?php echo htmlspecialchars($row['program_name']); ?></td>
                                            <?php endif; ?>
                                            <td class="text-end" data-order="<?php echo $row['order_count']; ?>"><?php echo number_format($row['order_count']); ?></td>
                                            <td class="text-end" data-order="<?php echo $row['orders_high']; ?>"><?php echo number_format($row['orders_high']); ?></td>
                                            <td class="text-end" data-order="<?php echo $row['orders_low']; ?>"><?php echo number_format($row['orders_low']); ?></td>
                                            <td class="text-end" data-order="<?php echo $row['total_revenue']; ?>">$<?php echo number_format($row['total_revenue'], 0); ?></td>
                                            <td class="text-end" data-order="<?php echo $row['aov']; ?>">$<?php echo number_format($row['aov'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SheetJS Library for Excel Export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
// Initialize DataTables for sorting
(function() {
    function initDataTable() {
        if (typeof jQuery !== 'undefined' && jQuery.fn.DataTable && jQuery('#ordersTable').length) {
            // Check if already initialized
            if (jQuery.fn.DataTable.isDataTable('#ordersTable')) {
                return; // Already initialized
            }
            
            const showPrograms = <?php echo $show_programs ? 'true' : 'false'; ?>;
            const numericCols = showPrograms ? [2, 3, 4, 5, 6] : [1, 2, 3, 4, 5];
            
            jQuery('#ordersTable').DataTable({
                order: [[0, 'asc']], // Default sort by Branch
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                columnDefs: [
                    { 
                        targets: numericCols, // Numeric columns
                        type: 'num',
                        orderable: true
                    },
                    {
                        targets: [0], // Branch Name
                        orderable: true,
                        searchable: true
                    }
                ],
                dom: 'lrtip',
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ rows per page"
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

function exportToExcel() {
    const table = document.getElementById('ordersTable');
    const exportBtn = document.getElementById('exportBtn');
    
    if (!table) {
        alert('No data to export');
        return;
    }
    
    // Show loading state
    const originalText = exportBtn.innerHTML;
    exportBtn.disabled = true;
    exportBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Generating Excel...';
    
    try {
        // Determine if showing programs based on table structure
        const showPrograms = <?php echo $show_programs ? 'true' : 'false'; ?>;
        
        // Always get all rows from the table, ignoring DataTables sorting/filtering
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        
        // Group data by branch
        const branchData = {};
        
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length === 0) return; // Skip empty rows
            
            const rowData = [];
            let branchName = '';
            
            cells.forEach((cell, index) => {
                let cellText = cell.textContent.trim();
                
                // Get branch name from first column
                if (index === 0) {
                    branchName = cellText;
                }
                
                // Remove $ signs and clean up numbers for better Excel formatting
                const numIndex = showPrograms ? (index >= 2) : (index >= 1);
                if (numIndex && cellText.startsWith('$')) {
                    cellText = cellText.substring(1).replace(/,/g, '');
                } else if (numIndex) {
                    cellText = cellText.replace(/,/g, '');
                }
                
                rowData.push(cellText);
            });
            
            // Initialize branch array if it doesn't exist
            if (!branchData[branchName]) {
                branchData[branchName] = [];
            }
            
            branchData[branchName].push(rowData);
        });
        
        // Create workbook
        const wb = XLSX.utils.book_new();
        
        // Headers for all sheets
        const headers = showPrograms 
            ? ['Branch', 'Program', 'Orders', 'Orders ≥ $350', 'Orders < $350', 'Revenue', 'AOV'] 
            : ['Branch', 'Orders', 'Orders ≥ $350', 'Orders < $350', 'Revenue', 'AOV'];
        
        // Column widths
        const colWidths = showPrograms 
            ? [
                { wch: 20 },  // Branch
                { wch: 25 },  // Program
                { wch: 12 },  // Orders
                { wch: 15 },  // Orders ≥ $350
                { wch: 15 },  // Orders < $350
                { wch: 15 },  // Revenue
                { wch: 12 }   // AOV
            ]
            : [
                { wch: 20 },  // Branch
                { wch: 12 },  // Orders
                { wch: 15 },  // Orders ≥ $350
                { wch: 15 },  // Orders < $350
                { wch: 15 },  // Revenue
                { wch: 12 }   // AOV
            ];
        
        // Sort branch names alphabetically
        const sortedBranches = Object.keys(branchData).sort();
        
        // First, create "All Branches" sheet with all data combined
        const allBranchesData = [];
        allBranchesData.push(headers);
        
        // Collect all rows from all branches
        let grandTotalOrders = 0;
        let grandOrdersHigh = 0;
        let grandOrdersLow = 0;
        let grandTotalRevenue = 0;
        
        sortedBranches.forEach(branchName => {
            const branchRows = branchData[branchName];
            branchRows.forEach(rowData => {
                allBranchesData.push(rowData);
                
                // Calculate indices based on whether programs are shown
                const ordersIndex = showPrograms ? 2 : 1;
                const ordersHighIndex = showPrograms ? 3 : 2;
                const ordersLowIndex = showPrograms ? 4 : 3;
                const revenueIndex = showPrograms ? 5 : 4;
                
                grandTotalOrders += parseFloat((rowData[ordersIndex] || '0').toString().replace(/,/g, '')) || 0;
                grandOrdersHigh += parseFloat((rowData[ordersHighIndex] || '0').toString().replace(/,/g, '')) || 0;
                grandOrdersLow += parseFloat((rowData[ordersLowIndex] || '0').toString().replace(/,/g, '')) || 0;
                grandTotalRevenue += parseFloat((rowData[revenueIndex] || '0').toString().replace(/,/g, '')) || 0;
            });
        });
        
        // Add grand totals row
        allBranchesData.push([]); // Empty row for spacing
        const grandTotalsRow = showPrograms 
            ? ['Grand Total', '', 
                Math.round(grandTotalOrders).toString(),
                Math.round(grandOrdersHigh).toString(),
                Math.round(grandOrdersLow).toString(),
                grandTotalRevenue.toFixed(2),
                (grandTotalOrders > 0 ? grandTotalRevenue / grandTotalOrders : 0).toFixed(2)]
            : ['Grand Total', 
                Math.round(grandTotalOrders).toString(),
                Math.round(grandOrdersHigh).toString(),
                Math.round(grandOrdersLow).toString(),
                grandTotalRevenue.toFixed(2),
                (grandTotalOrders > 0 ? grandTotalRevenue / grandTotalOrders : 0).toFixed(2)];
        allBranchesData.push(grandTotalsRow);
        
        // Create "All Branches" worksheet
        const allBranchesWs = XLSX.utils.aoa_to_sheet(allBranchesData);
        allBranchesWs['!cols'] = colWidths;
        XLSX.utils.book_append_sheet(wb, allBranchesWs, 'All Branches');
        
        // Create a worksheet for each branch
        sortedBranches.forEach(branchName => {
            const branchRows = branchData[branchName];
            
            // Prepare export data for this branch
            const exportData = [];
            exportData.push(headers);
            
            // Add data rows
            branchRows.forEach(rowData => {
                exportData.push(rowData);
            });
            
            // Calculate totals for this branch
            let branchTotalOrders = 0;
            let branchOrdersHigh = 0;
            let branchOrdersLow = 0;
            let branchTotalRevenue = 0;
            
            branchRows.forEach(rowData => {
                // Calculate indices based on whether programs are shown
                const ordersIndex = showPrograms ? 2 : 1;
                const ordersHighIndex = showPrograms ? 3 : 2;
                const ordersLowIndex = showPrograms ? 4 : 3;
                const revenueIndex = showPrograms ? 5 : 4;
                
                branchTotalOrders += parseFloat((rowData[ordersIndex] || '0').toString().replace(/,/g, '')) || 0;
                branchOrdersHigh += parseFloat((rowData[ordersHighIndex] || '0').toString().replace(/,/g, '')) || 0;
                branchOrdersLow += parseFloat((rowData[ordersLowIndex] || '0').toString().replace(/,/g, '')) || 0;
                branchTotalRevenue += parseFloat((rowData[revenueIndex] || '0').toString().replace(/,/g, '')) || 0;
            });
            
            const branchAOV = branchTotalOrders > 0 ? branchTotalRevenue / branchTotalOrders : 0;
            
            // Add totals row
            exportData.push([]); // Empty row for spacing
            const totalsRow = showPrograms 
                ? ['Total', '', 
                    Math.round(branchTotalOrders).toString(),
                    Math.round(branchOrdersHigh).toString(),
                    Math.round(branchOrdersLow).toString(),
                    branchTotalRevenue.toFixed(2),
                    branchAOV.toFixed(2)]
                : ['Total', 
                    Math.round(branchTotalOrders).toString(),
                    Math.round(branchOrdersHigh).toString(),
                    Math.round(branchOrdersLow).toString(),
                    branchTotalRevenue.toFixed(2),
                    branchAOV.toFixed(2)];
            exportData.push(totalsRow);
            
            // Create worksheet
            const ws = XLSX.utils.aoa_to_sheet(exportData);
            ws['!cols'] = colWidths;
            
            // Clean branch name for sheet name (Excel sheet names have restrictions)
            // Max 31 characters, no special characters: \ / ? * [ ]
            let sheetName = branchName.replace(/[\\\/\?\*\[\]]/g, '_');
            if (sheetName.length > 31) {
                sheetName = sheetName.substring(0, 31);
            }
            if (sheetName.length === 0) {
                sheetName = 'Unknown';
            }
            
            // Add worksheet to workbook
            XLSX.utils.book_append_sheet(wb, ws, sheetName);
        });
        
        // Generate filename with date range
        const fromDate = '<?php echo str_replace('-', '', $from_date); ?>';
        const toDate = '<?php echo str_replace('-', '', $to_date); ?>';
        const filename = `Order_Summary_${fromDate}_to_${toDate}.xlsx`;
        
        // Export file
        XLSX.writeFile(wb, filename);
        
    } catch (error) {
        console.error('Export error:', error);
        alert('Error exporting to Excel: ' + error.message);
    } finally {
        // Restore button state
        exportBtn.disabled = false;
        exportBtn.innerHTML = originalText;
    }
}
</script>

<?php require(__DIR__ . '/../../includes/footer.inc.php'); ?>

