<?php
$header['pageTitle'] = "Online Orders by Branch";
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
$allResults = []; // For export: all customers
$enrolledResults = []; // For export: enrolled customers
$canEnrollResults = []; // For export: can enroll customers
$totals = [
    'orders_high' => 0,
    'orders_low' => 0,
    'total_orders' => 0
];
$error = '';
$from_date = $_GET['from_date'] ?? date('Y-01-01'); // Default to start of current year
$to_date = $_GET['to_date'] ?? date('Y-m-d'); // Default to today
$customer_filter = $_GET['customer_filter'] ?? 'all'; // all, enrolled, can_enroll_not_enrolled

// Connect to database
$conn = null;
try {
    $conn = new mysqli($config['dbServer'], $config['dbUser'], $config['dbPassword'], $config['dbName']);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Function to fetch data for a specific customer filter
    $fetchDataForFilter = function($filterType) use ($conn, $from_date, $to_date) {
        $sql = "
            SELECT 
                b.id AS branch_id,
                b.name AS branch_name,
                COUNT(CASE WHEN o.total >= 350 THEN 1 END) AS orders_high,
                COUNT(CASE WHEN o.total < 350 THEN 1 END) AS orders_low,
                COUNT(*) AS total_orders
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
        if ($filterType === 'enrolled') {
            $sql .= " AND c.is_enrolled_loyalty = 1 AND c.status = 1";
        } elseif ($filterType === 'can_enroll_not_enrolled') {
            $sql .= " AND c.can_enroll_loyalty = 1 AND c.is_enrolled_loyalty = 0 AND c.status = 1";
        }
        
        $sql .= "
            GROUP BY b.id, b.name
            HAVING total_orders > 0
            ORDER BY b.name ASC
        ";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("ss", $from_date, $to_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result) {
            throw new Exception("Query failed: " . $stmt->error);
        }
        
        $filterResults = [];
        while ($row = $result->fetch_assoc()) {
            $filterResults[] = [
                'branch_id' => $row['branch_id'],
                'branch_name' => $row['branch_name'],
                'orders_high' => (int)$row['orders_high'],
                'orders_low' => (int)$row['orders_low'],
                'total_orders' => (int)$row['total_orders']
            ];
        }
        
        $stmt->close();
        return $filterResults;
    };
    
    // Fetch data for the currently selected filter (for display)
    $results = $fetchDataForFilter($customer_filter);
    
    // Fetch data for all three filters (for export)
    $allResults = $fetchDataForFilter('all');
    $enrolledResults = $fetchDataForFilter('enrolled');
    $canEnrollResults = $fetchDataForFilter('can_enroll_not_enrolled');
    
    // Calculate totals for display
    $totals = [
        'orders_high' => array_sum(array_column($results, 'orders_high')),
        'orders_low' => array_sum(array_column($results, 'orders_low')),
        'total_orders' => array_sum(array_column($results, 'total_orders'))
    ];
    
} catch (Exception $e) {
    $error = $e->getMessage();
    // Initialize empty arrays for export if error occurred
    if (empty($allResults)) $allResults = [];
    if (empty($enrolledResults)) $enrolledResults = [];
    if (empty($canEnrollResults)) $canEnrollResults = [];
} finally {
    if ($conn) {
        $conn->close();
    }
}

?>

<div class="container mt-4">
    <div class="row mb-3">
        <div class="col-12">
            <h2><i class="bi bi-cart-check me-2"></i>Online Orders by Branch</h2>
            <p class="text-muted">Orders with status = 2, grouped by branch</p>
        </div>
    </div>
    
    <!-- Date Filter Form -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-4">
                            <label for="from_date" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="from_date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="to_date" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="to_date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="customer_filter" class="form-label">Customer Filter</label>
                            <select class="form-select" id="customer_filter" name="customer_filter">
                                <option value="all" <?php echo $customer_filter === 'all' ? 'selected' : ''; ?>>All Customers</option>
                                <option value="enrolled" <?php echo $customer_filter === 'enrolled' ? 'selected' : ''; ?>>Enrolled Customers Only</option>
                                <option value="can_enroll_not_enrolled" <?php echo $customer_filter === 'can_enroll_not_enrolled' ? 'selected' : ''; ?>>Can Enroll (Not Enrolled) Only</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-funnel me-2"></i>Filter
                                </button>
                                <a href="?" class="btn btn-secondary w-100 mt-2">
                                    <i class="bi bi-arrow-clockwise me-2"></i>Reset
                                </a>
                            </div>
                        </div>
                    </form>
                    <?php if ($customer_filter !== 'all'): ?>
                    <div class="mt-3">
                        <span class="badge bg-info">
                            <i class="bi bi-info-circle me-1"></i>
                            <?php 
                            if ($customer_filter === 'enrolled') {
                                echo 'Showing only orders from enrolled customers';
                            } elseif ($customer_filter === 'can_enroll_not_enrolled') {
                                echo 'Showing only orders from customers who can enroll but are not enrolled';
                            }
                            ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-danger" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php else: ?>
    
    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Orders >= $350</h6>
                            <h3 class="mb-0 text-success"><?php echo number_format($totals['orders_high']); ?></h3>
                        </div>
                        <div class="text-success" style="font-size: 2rem;">
                            <i class="bi bi-arrow-up-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Orders < $350</h6>
                            <h3 class="mb-0 text-warning"><?php echo number_format($totals['orders_low']); ?></h3>
                        </div>
                        <div class="text-warning" style="font-size: 2rem;">
                            <i class="bi bi-arrow-down-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Orders</h6>
                            <h3 class="mb-0 text-primary"><?php echo number_format($totals['total_orders']); ?></h3>
                        </div>
                        <div class="text-primary" style="font-size: 2rem;">
                            <i class="bi bi-cart"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Branch Details Table -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header text-white d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <h5 class="mb-0"><i class="bi bi-table me-2"></i>Online Orders by Branch</h5>
                    <button type="button" class="btn btn-light btn-sm" onclick="exportToExcel()" id="exportBtn">
                        <i class="bi bi-file-earmark-excel me-2"></i>Export to Excel
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="ordersTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Branch Name</th>
                                    <th class="text-end">Orders >= $350</th>
                                    <th class="text-end">Orders < $350</th>
                                    <th class="text-end">Total Orders</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($results)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox me-2"></i>No data found
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($results as $row): ?>
                                <tr>
                                    <td data-order="<?php echo htmlspecialchars($row['branch_name']); ?>"><strong><?php echo htmlspecialchars($row['branch_name']); ?></strong></td>
                                    <td class="text-end" data-order="<?php echo $row['orders_high']; ?>">
                                        <span class="badge bg-success"><?php echo number_format($row['orders_high']); ?></span>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['orders_low']; ?>">
                                        <span class="badge bg-warning text-dark"><?php echo number_format($row['orders_low']); ?></span>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['total_orders']; ?>">
                                        <span class="badge bg-primary"><?php echo number_format($row['total_orders']); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th>Total</th>
                                    <th class="text-end"><?php echo number_format($totals['orders_high']); ?></th>
                                    <th class="text-end"><?php echo number_format($totals['orders_low']); ?></th>
                                    <th class="text-end"><?php echo number_format($totals['total_orders']); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
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
        if (typeof jQuery !== 'undefined' && jQuery.fn.DataTable && jQuery('#ordersTable').length) {
            // Check if already initialized
            if (jQuery.fn.DataTable.isDataTable('#ordersTable')) {
                return; // Already initialized
            }
            
            jQuery('#ordersTable').DataTable({
                order: [[0, 'asc']], // Default sort by Branch Name
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                columnDefs: [
                    { 
                        targets: [1, 2, 3], // Numeric columns
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
                    search: "Search branches:",
                    lengthMenu: "Show _MENU_ branches per page"
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

// Pass PHP data to JavaScript for export
const exportData = {
    all: <?php echo json_encode($allResults); ?>,
    enrolled: <?php echo json_encode($enrolledResults); ?>,
    canEnroll: <?php echo json_encode($canEnrollResults); ?>
};

function exportToExcel() {
    const exportBtn = document.getElementById('exportBtn');
    
    // Show loading state
    const originalText = exportBtn.innerHTML;
    exportBtn.disabled = true;
    exportBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Generating Excel...';
    
    try {
        // Create workbook
        const wb = XLSX.utils.book_new();
        
        // Headers for all sheets
        const headers = ['Branch Name', 'Orders >= $350', 'Orders < $350', 'Total Orders'];
        
        // Column widths
        const colWidths = [
            { wch: 25 },  // Branch Name
            { wch: 18 },  // Orders >= $350
            { wch: 18 },  // Orders < $350
            { wch: 15 }   // Total Orders
        ];
        
        // Helper function to create worksheet from data
        const createWorksheet = (data, sheetName) => {
            const exportData = [];
            exportData.push(headers);
            
            let totalHigh = 0;
            let totalLow = 0;
            let totalOrders = 0;
            
            // Add data rows
            data.forEach(row => {
                exportData.push([
                    row.branch_name,
                    row.orders_high.toString(),
                    row.orders_low.toString(),
                    row.total_orders.toString()
                ]);
                
                totalHigh += row.orders_high;
                totalLow += row.orders_low;
                totalOrders += row.total_orders;
            });
            
            // Add totals row
            exportData.push([
                'Total',
                totalHigh.toString(),
                totalLow.toString(),
                totalOrders.toString()
            ]);
            
            // Create worksheet
            const ws = XLSX.utils.aoa_to_sheet(exportData);
            ws['!cols'] = colWidths;
            
            // Add worksheet to workbook
            XLSX.utils.book_append_sheet(wb, ws, sheetName);
        };
        
        // Create worksheets for each filter type
        createWorksheet(exportData.all, 'All Customers');
        createWorksheet(exportData.enrolled, 'Enrolled Customers');
        createWorksheet(exportData.canEnroll, 'Can Enroll Customers');
        
        // Generate filename with date range
        const fromDate = '<?php echo str_replace('-', '', $from_date); ?>';
        const toDate = '<?php echo str_replace('-', '', $to_date); ?>';
        const filename = `Online_Orders_${fromDate}_to_${toDate}.xlsx`;
        
        // Save file
        XLSX.writeFile(wb, filename);
        
    } catch (e) {
        alert('Error exporting to Excel: ' + e.message);
    } finally {
        // Restore button state
        setTimeout(() => {
            exportBtn.disabled = false;
            exportBtn.innerHTML = originalText;
        }, 500);
    }
}
</script>

<?php
require(__DIR__ . '/../../includes/footer.inc.php');
?>

