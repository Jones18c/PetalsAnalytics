<?php
$header['pageTitle'] = "Rewards Program Enrollment - Petals Data";
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
$error = null;

try {
    $conn = new mysqli($config['dbServer'], $config['dbUser'], $config['dbPassword'], $config['dbName']);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Query to get enrollment metrics per branch
    // We're looking at companies table with Status = 1 (active customers only)
    // Linking to branches via branch_id
    // Excluding Mass Market and Twin Cities branches
    // Also counting can_enroll customers with orders in last 6 and 12 months
    $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
    $twelveMonthsAgo = date('Y-m-d', strtotime('-12 months'));
    
    $sql = "
        SELECT 
            b.id AS branch_id,
            b.name AS branch_name,
            COUNT(DISTINCT CASE WHEN c.can_enroll_loyalty = 1 THEN c.id END) AS can_enroll_count,
            COUNT(DISTINCT CASE WHEN c.is_enrolled_loyalty = 1 THEN c.id END) AS enrolled_count,
            COUNT(DISTINCT CASE WHEN c.can_enroll_loyalty = 1 AND c.is_enrolled_loyalty = 0 THEN c.id END) AS can_enroll_not_enrolled_count,
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
                WHEN c.can_enroll_loyalty = 1 
                AND c.is_enrolled_loyalty = 0
                AND EXISTS (
                    SELECT 1 FROM orders o 
                    WHERE o.company_id = c.id 
                    AND o.invoice_date >= ?
                    AND o.order_status_id = 2
                )
                THEN c.id 
            END) AS can_enroll_with_orders_12m
        FROM branches b
        LEFT JOIN companies c ON b.id = c.branch_id AND c.status = 1
        WHERE LOWER(b.name) NOT LIKE '%mass market%'
          AND LOWER(b.name) NOT LIKE '%twin cities%'
          AND LOWER(b.name) NOT LIKE '%accounts receivable%'
        GROUP BY b.id, b.name
        HAVING can_enroll_count > 0 OR enrolled_count > 0
        ORDER BY b.name ASC
    ";
    
    // Prepare statement to safely pass the date parameters
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("ss", $sixMonthsAgo, $twelveMonthsAgo);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result) {
        throw new Exception("Query failed: " . $stmt->error);
    }
    
    // Process results
    while ($row = $result->fetch_assoc()) {
        $canEnroll = (int)$row['can_enroll_count'];
        $enrolled = (int)$row['enrolled_count'];
        $canEnrollNotEnrolled = (int)$row['can_enroll_not_enrolled_count'];
        $canEnrollWithOrders6m = (int)$row['can_enroll_with_orders_6m'];
        $canEnrollWithOrders12m = (int)$row['can_enroll_with_orders_12m'];
        
        // Calculate enrollment percentage
        // Enrollment % = (enrolled / can_enroll) * 100
        $enrollmentPercent = $canEnroll > 0 ? round(($enrolled / $canEnroll) * 100, 2) : 0;
        
        // Calculate active purchaser enrollment % (6M)
        // (total enrolled / (total enrolled + not enrolled with purchases in 6M)) * 100
        $total6m = $enrolled + $canEnrollWithOrders6m;
        $activePurchaserEnrollmentPercent6m = $total6m > 0 
            ? round(($enrolled / $total6m) * 100, 1) 
            : 0;
        
        // Calculate active purchaser enrollment % (12M)
        $total12m = $enrolled + $canEnrollWithOrders12m;
        $activePurchaserEnrollmentPercent12m = $total12m > 0 
            ? round(($enrolled / $total12m) * 100, 1) 
            : 0;
        
        $results[] = [
            'branch_id' => $row['branch_id'],
            'branch_name' => $row['branch_name'],
            'can_enroll_count' => $canEnroll,
            'enrolled_count' => $enrolled,
            'can_enroll_not_enrolled_count' => $canEnrollNotEnrolled,
            'can_enroll_with_orders_6m' => $canEnrollWithOrders6m,
            'can_enroll_with_orders_12m' => $canEnrollWithOrders12m,
            'enrollment_percent' => $enrollmentPercent,
            'active_purchaser_enrollment_percent_6m' => $activePurchaserEnrollmentPercent6m,
            'active_purchaser_enrollment_percent_12m' => $activePurchaserEnrollmentPercent12m
        ];
    }
    
    // Close prepared statement
    $stmt->close();
    
    // Calculate totals
    $totals = [
        'can_enroll_count' => array_sum(array_column($results, 'can_enroll_count')),
        'enrolled_count' => array_sum(array_column($results, 'enrolled_count')),
        'can_enroll_not_enrolled_count' => array_sum(array_column($results, 'can_enroll_not_enrolled_count')),
        'can_enroll_with_orders_6m' => array_sum(array_column($results, 'can_enroll_with_orders_6m')),
        'can_enroll_with_orders_12m' => array_sum(array_column($results, 'can_enroll_with_orders_12m'))
    ];
    // Calculate enrollment percentage for totals
    // Total Can Enroll = Total Enrolled + Total Can Enroll (Not Enrolled)
    $totalCanEnroll = $totals['enrolled_count'] + $totals['can_enroll_not_enrolled_count'];
    $totals['enrollment_percent'] = $totalCanEnroll > 0 
        ? round(($totals['enrolled_count'] / $totalCanEnroll) * 100, 1) 
        : 0;
    
    // Calculate active purchaser enrollment % for totals (6M)
    $total6m = $totals['enrolled_count'] + $totals['can_enroll_with_orders_6m'];
    $totals['active_purchaser_enrollment_percent_6m'] = $total6m > 0 
        ? round(($totals['enrolled_count'] / $total6m) * 100, 1) 
        : 0;
    
    // Calculate active purchaser enrollment % for totals (12M)
    $total12m = $totals['enrolled_count'] + $totals['can_enroll_with_orders_12m'];
    $totals['active_purchaser_enrollment_percent_12m'] = $total12m > 0 
        ? round(($totals['enrolled_count'] / $total12m) * 100, 1) 
        : 0;
    
} catch (Exception $e) {
    $error = $e->getMessage();
} finally {
    if ($conn) {
        $conn->close();
    }
}

?>

<div class="container mt-4">
    <!-- Breadcrumb Navigation -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php"><i class="bi bi-flower1 me-1"></i>Petals Data</a></li>
            <li class="breadcrumb-item active" aria-current="page">Rewards Program Enrollment</li>
        </ol>
    </nav>
    
    <div class="row mb-3">
        <div class="col-12">
            <h2><i class="bi bi-gift me-2"></i>Rewards Program Enrollment</h2>
            <p class="text-muted">Enrollment metrics by branch for active customers (Status = 1)</p>
        </div>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-danger" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php else: ?>
    
    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-2 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Can Enroll</h6>
                            <h3 class="mb-0"><?php echo number_format($totals['can_enroll_count']); ?></h3>
                        </div>
                        <div class="text-primary" style="font-size: 2rem;">
                            <i class="bi bi-people"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-2 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Enrolled</h6>
                            <h3 class="mb-0 text-success"><?php echo number_format($totals['enrolled_count']); ?></h3>
                        </div>
                        <div class="text-success" style="font-size: 2rem;">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-2 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Can Enroll (Not Enrolled)</h6>
                            <h3 class="mb-0 text-warning"><?php echo number_format($totals['can_enroll_not_enrolled_count']); ?></h3>
                        </div>
                        <div class="text-warning" style="font-size: 2rem;">
                            <i class="bi bi-exclamation-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-2 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Can Enroll w/ Purchases (6M)</h6>
                            <h3 class="mb-0 text-info"><?php echo number_format($totals['can_enroll_with_orders_6m']); ?></h3>
                        </div>
                        <div class="text-info" style="font-size: 2rem;">
                            <i class="bi bi-cart-x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-2 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Can Enroll w/ Purchases (12M)</h6>
                            <h3 class="mb-0 text-info"><?php echo number_format($totals['can_enroll_with_orders_12m']); ?></h3>
                        </div>
                        <div class="text-info" style="font-size: 2rem;">
                            <i class="bi bi-cart-x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-2 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Overall Enrollment %</h6>
                            <h3 class="mb-0 text-secondary"><?php echo number_format($totals['enrollment_percent'], 1); ?>%</h3>
                        </div>
                        <div class="text-secondary" style="font-size: 2rem;">
                            <i class="bi bi-percent"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-2 mb-3">
            <div class="card border-0 shadow-sm h-100 border-primary" style="border-width: 2px !important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Enrollment % (6M Purchasers)</h6>
                            <h3 class="mb-0 text-primary"><?php echo number_format($totals['active_purchaser_enrollment_percent_6m'], 1); ?>%</h3>
                        </div>
                        <div class="text-primary" style="font-size: 2rem;">
                            <i class="bi bi-percent"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-2 mb-3">
            <div class="card border-0 shadow-sm h-100 border-success" style="border-width: 2px !important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Enrollment % (12M Purchasers)</h6>
                            <h3 class="mb-0 text-success"><?php echo number_format($totals['active_purchaser_enrollment_percent_12m'], 1); ?>%</h3>
                        </div>
                        <div class="text-success" style="font-size: 2rem;">
                            <i class="bi bi-percent"></i>
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
                    <h5 class="mb-0"><i class="bi bi-table me-2"></i>Enrollment by Branch</h5>
                    <button type="button" class="btn btn-light btn-sm" onclick="exportToExcel()" id="exportBtn">
                        <i class="bi bi-file-earmark-excel me-2"></i>Export to Excel
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="enrollmentTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Branch Name</th>
                                    <th class="text-end">Enrolled</th>
                                    <th class="text-end">Can Enroll (Not Enrolled)</th>
                                    <th class="text-end">Enrollment %</th>
                                    <th class="text-end">Can Enroll w/ Purchases (6M)</th>
                                    <th class="text-end">Enrollment % (6M)</th>
                                    <th class="text-end">Can Enroll w/ Purchases (12M)</th>
                                    <th class="text-end">Enrollment % (12M)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($results)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox me-2"></i>No data found
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($results as $row): ?>
                                <tr>
                                    <td data-order="<?php echo htmlspecialchars($row['branch_name']); ?>"><strong><?php echo htmlspecialchars($row['branch_name']); ?></strong></td>
                                    <td class="text-end" data-order="<?php echo $row['enrolled_count']; ?>">
                                        <span class="badge bg-success"><?php echo number_format($row['enrolled_count']); ?></span>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['can_enroll_not_enrolled_count']; ?>">
                                        <?php if ($row['can_enroll_not_enrolled_count'] > 0): ?>
                                        <span class="badge bg-warning text-dark"><?php echo number_format($row['can_enroll_not_enrolled_count']); ?></span>
                                        <?php else: ?>
                                        <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['enrollment_percent']; ?>">
                                        <strong class="<?php 
                                            echo $row['enrollment_percent'] >= 80 ? 'text-success' : 
                                                ($row['enrollment_percent'] >= 50 ? 'text-warning' : 'text-danger'); 
                                        ?>">
                                            <?php echo number_format($row['enrollment_percent'], 1); ?>%
                                        </strong>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['can_enroll_with_orders_6m']; ?>">
                                        <span class="badge bg-info"><?php echo number_format($row['can_enroll_with_orders_6m']); ?></span>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['active_purchaser_enrollment_percent_6m']; ?>">
                                        <strong class="<?php 
                                            echo $row['active_purchaser_enrollment_percent_6m'] >= 80 ? 'text-success' : 
                                                ($row['active_purchaser_enrollment_percent_6m'] >= 50 ? 'text-warning' : 'text-danger'); 
                                        ?>">
                                            <?php echo number_format($row['active_purchaser_enrollment_percent_6m'], 1); ?>%
                                        </strong>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['can_enroll_with_orders_12m']; ?>">
                                        <span class="badge bg-info"><?php echo number_format($row['can_enroll_with_orders_12m']); ?></span>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['active_purchaser_enrollment_percent_12m']; ?>">
                                        <strong class="<?php 
                                            echo $row['active_purchaser_enrollment_percent_12m'] >= 80 ? 'text-success' : 
                                                ($row['active_purchaser_enrollment_percent_12m'] >= 50 ? 'text-warning' : 'text-danger'); 
                                        ?>">
                                            <?php echo number_format($row['active_purchaser_enrollment_percent_12m'], 1); ?>%
                                        </strong>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th>Total</th>
                                    <th class="text-end"><?php echo number_format($totals['enrolled_count']); ?></th>
                                    <th class="text-end"><?php echo number_format($totals['can_enroll_not_enrolled_count']); ?></th>
                                    <th class="text-end">
                                        <strong><?php echo number_format($totals['enrollment_percent'], 1); ?>%</strong>
                                    </th>
                                    <th class="text-end"><?php echo number_format($totals['can_enroll_with_orders_6m']); ?></th>
                                    <th class="text-end">
                                        <strong><?php echo number_format($totals['active_purchaser_enrollment_percent_6m'], 1); ?>%</strong>
                                    </th>
                                    <th class="text-end"><?php echo number_format($totals['can_enroll_with_orders_12m']); ?></th>
                                    <th class="text-end">
                                        <strong><?php echo number_format($totals['active_purchaser_enrollment_percent_12m'], 1); ?>%</strong>
                                    </th>
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
        if (typeof jQuery !== 'undefined' && jQuery.fn.DataTable && jQuery('#enrollmentTable').length) {
            // Check if already initialized
            if (jQuery.fn.DataTable.isDataTable('#enrollmentTable')) {
                return; // Already initialized
            }
            
            jQuery('#enrollmentTable').DataTable({
                order: [[0, 'asc']], // Default sort by Branch Name
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                columnDefs: [
                    { 
                        targets: [1, 2, 3, 4, 5, 6, 7], // Numeric columns
                        type: 'num',
                        orderable: true
                    },
                    {
                        targets: [0], // Branch Name
                        orderable: true,
                        searchable: true
                    }
                ],
                dom: 'lrtip', // Show length menu, processing, table, info, pagination
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

// Excel Export Function
function exportToExcel() {
    const table = document.getElementById('enrollmentTable');
    const exportBtn = document.getElementById('exportBtn');
    
    if (!table) {
        alert('No data to export');
        return;
    }
    
    // Always get all rows from the table, ignoring DataTables sorting/filtering
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    if (rows.length === 0) {
        alert('No data to export');
        return;
    }
    
    // Show loading state
    const originalText = exportBtn.innerHTML;
    exportBtn.disabled = true;
    exportBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Generating Excel...';
    
    try {
        // Prepare data for export
        const exportData = [];
        
        // Add headers
        const headers = ['Branch Name', 'Enrolled', 'Can Enroll (Not Enrolled)', 'Enrollment %', 'Can Enroll w/ Purchases (6M)', 'Enrollment % (6M)', 'Can Enroll w/ Purchases (12M)', 'Enrollment % (12M)'];
        exportData.push(headers);
        
        // Add data rows (exclude the footer row)
        const rowArray = Array.isArray(rows) ? rows : Array.from(rows);
        rowArray.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length === 0) return; // Skip empty rows
            
            const rowData = [];
            cells.forEach((cell, index) => {
                let cellText = cell.textContent.trim();
                
                // Clean up formatting - remove badges and extra formatting
                // Remove badge spans but keep the text
                const badge = cell.querySelector('.badge');
                if (badge) {
                    cellText = badge.textContent.trim();
                }
                
                // Remove percentage sign and convert to number for proper Excel formatting
                if ((index === 3 || index === 5 || index === 7) && cellText.includes('%')) {
                    cellText = cellText.replace('%', '').trim();
                }
                
                rowData.push(cellText);
            });
            
            exportData.push(rowData);
        });
        
        // Add totals row
        const footerRow = table.querySelector('tfoot tr');
        if (footerRow) {
            const footerCells = footerRow.querySelectorAll('th, td');
            const footerData = [];
            footerCells.forEach((cell, index) => {
                let cellText = cell.textContent.trim();
                if ((index === 3 || index === 5 || index === 7) && cellText.includes('%')) {
                    cellText = cellText.replace('%', '').trim();
                }
                footerData.push(cellText);
            });
            exportData.push(footerData);
        }
        
        // Create workbook and worksheet
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(exportData);
        
        // Set column widths
        const colWidths = [
            { wch: 25 },  // Branch Name
            { wch: 10 },  // Enrolled
            { wch: 22 },  // Can Enroll (Not Enrolled)
            { wch: 14 },  // Enrollment %
            { wch: 26 },  // Can Enroll w/ Purchases (6M)
            { wch: 18 },  // Enrollment % (6M)
            { wch: 26 },  // Can Enroll w/ Purchases (12M)
            { wch: 18 }   // Enrollment % (12M)
        ];
        ws['!cols'] = colWidths;
        
        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'Rewards Enrollment');
        
        // Generate filename with current date
        const dateStr = new Date().toISOString().split('T')[0].replace(/-/g, '');
        const filename = `Rewards_Enrollment_${dateStr}.xlsx`;
        
        // Save file
        XLSX.writeFile(wb, filename);
        
        // Restore button state
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

