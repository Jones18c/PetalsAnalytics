<?php
$header['pageTitle'] = "Branch Redemptions - Petals Data";
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

// Connect to MySQL database
$conn = null;
$results = [];
$error = null;

try {
    $conn = new mysqli($config['dbServer'], $config['dbUser'], $config['dbPassword'], $config['dbName']);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Query to get redemption metrics per branch using the same logic as the website
    // Uses settings table for status IDs and proper point calculations
    // Based on the website query logic
    $sql = "
        SELECT 
            b.id AS branch_id,
            b.name AS branch_name,
            COALESCE(lp_stats.total_points_earned, 0) AS total_points_earned,
            COALESCE(ap_stats.available_points, 0) AS available_points,
            COALESCE(pp_stats.pending_points, 0) AS pending_points,
            COALESCE(cp_stats.canceled_points, 0) AS canceled_points,
            COALESCE(ep_stats.expired_points, 0) AS expired_points,
            COALESCE(cr_stats.claimed_rewards, 0) AS claimed_rewards,
            COALESCE(cr_stats.points_redeemed, 0) AS points_redeemed
        FROM branches b
        LEFT JOIN (
            SELECT 
                c.branch_id,
                SUM(CASE WHEN DATE(clp.created_at) >= ? AND DATE(clp.created_at) <= ? THEN clp.points_earned ELSE 0 END) AS total_points_earned
            FROM companies c
            INNER JOIN company_loyalty_points clp ON clp.company_id = c.id
            WHERE c.status = 1
            GROUP BY c.branch_id
        ) lp_stats ON b.id = lp_stats.branch_id
        LEFT JOIN (
            SELECT
                c.branch_id,
                SUM(clp.points_earned - clp.points_redeemed) AS available_points
            FROM companies c
            INNER JOIN company_loyalty_points clp ON clp.company_id = c.id
            WHERE c.status = 1
              AND clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_available_status_id')
            GROUP BY c.branch_id
        ) ap_stats ON b.id = ap_stats.branch_id
        LEFT JOIN (
            SELECT
                c.branch_id,
                SUM(clp.points_earned) AS pending_points
            FROM companies c
            INNER JOIN company_loyalty_points clp ON clp.company_id = c.id
            WHERE c.status = 1
              AND clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_pending_status_id')
            GROUP BY c.branch_id
        ) pp_stats ON b.id = pp_stats.branch_id
        LEFT JOIN (
            SELECT
                c.branch_id,
                SUM(clp.points_earned) AS canceled_points
            FROM companies c
            INNER JOIN company_loyalty_points clp ON clp.company_id = c.id
            WHERE c.status = 1
              AND clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_canceled_status_id')
            GROUP BY c.branch_id
        ) cp_stats ON b.id = cp_stats.branch_id
        LEFT JOIN (
            SELECT
                c.branch_id,
                SUM(clp.points_earned) AS expired_points
            FROM companies c
            INNER JOIN company_loyalty_points clp ON clp.company_id = c.id
            WHERE c.status = 1
              AND clp.point_status_id = (SELECT config_value FROM settings WHERE config_key = 'loyalty_expired_status_id')
            GROUP BY c.branch_id
        ) ep_stats ON b.id = ep_stats.branch_id
        LEFT JOIN (
            SELECT
                c.branch_id,
                SUM(crp.dollars) AS claimed_rewards,
                SUM(crp.points) AS points_redeemed
            FROM companies c
            INNER JOIN company_redeemed_points crp ON crp.company_id = c.id
            WHERE c.status = 1
              AND DATE(crp.created_at) >= ?
              AND DATE(crp.created_at) <= ?
            GROUP BY c.branch_id
        ) cr_stats ON b.id = cr_stats.branch_id
        WHERE LOWER(b.name) NOT LIKE '%mass market%'
          AND LOWER(b.name) NOT LIKE '%twin cities%'
          AND LOWER(b.name) NOT LIKE '%accounts receivable%'
        HAVING total_points_earned > 0 OR available_points > 0 OR pending_points > 0 OR canceled_points > 0 OR expired_points > 0 OR claimed_rewards > 0 OR points_redeemed > 0
        ORDER BY b.name ASC
    ";
    
    // Prepare statement to safely pass the date parameters 
    // 4 dates: 2 for total_points_earned, 2 for redemptions
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("ssss", $from_date, $to_date, $from_date, $to_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result) {
        throw new Exception("Query failed: " . $stmt->error);
    }
    
    // Process results and calculate costs
    while ($row = $result->fetch_assoc()) {
        $totalPointsEarned = (int)$row['total_points_earned'];
        $availablePoints = (int)$row['available_points'];
        $pendingPoints = (int)$row['pending_points'];
        $canceledPoints = (int)$row['canceled_points'];
        $expiredPoints = (int)$row['expired_points'];
        $claimedRewards = (float)$row['claimed_rewards']; // This is already in dollars
        $pointsRedeemed = (int)$row['points_redeemed'];
        
        $results[] = [
            'branch_id' => $row['branch_id'],
            'branch_name' => $row['branch_name'],
            'total_points_earned' => $totalPointsEarned,
            'available_points' => $availablePoints,
            'pending_points' => $pendingPoints,
            'canceled_points' => $canceledPoints,
            'expired_points' => $expiredPoints,
            'claimed_rewards' => $claimedRewards,
            'points_redeemed' => $pointsRedeemed
        ];
    }
    
    // Calculate totals
    $totals = [
        'total_points_earned' => array_sum(array_column($results, 'total_points_earned')),
        'available_points' => array_sum(array_column($results, 'available_points')),
        'pending_points' => array_sum(array_column($results, 'pending_points')),
        'canceled_points' => array_sum(array_column($results, 'canceled_points')),
        'expired_points' => array_sum(array_column($results, 'expired_points')),
        'claimed_rewards' => array_sum(array_column($results, 'claimed_rewards')),
        'points_redeemed' => array_sum(array_column($results, 'points_redeemed'))
    ];
    
    // Close prepared statement
    $stmt->close();
    
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
            <li class="breadcrumb-item active" aria-current="page">Branch Redemptions</li>
        </ol>
    </nav>
    
    <div class="row mb-3">
        <div class="col-12">
            <h2><i class="bi bi-gift-fill me-2"></i>Branch Redemptions</h2>
            <p class="text-muted">
                Loyalty points breakdown by branch (1000 petals = $25, earn 1 petal per dollar spent)
                <?php if ($from_date && $to_date): ?>
                <br><strong>Date Range:</strong> <?php echo date('M d, Y', strtotime($from_date)); ?> - <?php echo date('M d, Y', strtotime($to_date)); ?>
                <?php endif; ?>
            </p>
        </div>
    </div>
    
    <!-- Date Filter Form -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="from_date" class="form-label"><strong>From Date</strong></label>
                            <input type="date" class="form-control" id="from_date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="to_date" class="form-label"><strong>To Date</strong></label>
                            <input type="date" class="form-control" id="to_date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-funnel me-2"></i>Apply Filter
                            </button>
                            <a href="?" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle me-2"></i>Clear
                            </a>
                        </div>
                    </form>
                </div>
            </div>
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
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Points Earned</h6>
                            <h3 class="mb-0"><?php echo number_format($totals['total_points_earned']); ?></h3>
                        </div>
                        <div class="text-primary" style="font-size: 2rem;">
                            <i class="bi bi-star-fill"></i>
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
                            <h6 class="text-muted mb-1">Available Points</h6>
                            <h3 class="mb-0 text-success"><?php echo number_format($totals['available_points']); ?></h3>
                        </div>
                        <div class="text-success" style="font-size: 2rem;">
                            <i class="bi bi-check-circle-fill"></i>
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
                            <h6 class="text-muted mb-1">Points Redeemed</h6>
                            <h3 class="mb-0 text-warning"><?php echo number_format($totals['points_redeemed']); ?></h3>
                        </div>
                        <div class="text-warning" style="font-size: 2rem;">
                            <i class="bi bi-arrow-down-circle-fill"></i>
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
                            <h6 class="text-muted mb-1">Claimed Rewards</h6>
                            <h3 class="mb-0 text-info">$<?php echo number_format($totals['claimed_rewards'], 2); ?></h3>
                        </div>
                        <div class="text-info" style="font-size: 2rem;">
                            <i class="bi bi-gift-fill"></i>
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
                    <h5 class="mb-0"><i class="bi bi-table me-2"></i>Redemptions by Branch</h5>
                    <button type="button" class="btn btn-light btn-sm" onclick="exportToExcel()" id="exportBtn">
                        <i class="bi bi-file-earmark-excel me-2"></i>Export to Excel
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="redemptionsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Branch Name</th>
                                    <th class="text-end">Total Points Earned</th>
                                    <th class="text-end">Available</th>
                                    <th class="text-end">Points Redeemed</th>
                                    <th class="text-end">Claimed Rewards</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($results)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox me-2"></i>No data found
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($results as $row): ?>
                                <tr>
                                    <td data-order="<?php echo htmlspecialchars($row['branch_name']); ?>"><strong><?php echo htmlspecialchars($row['branch_name']); ?></strong></td>
                                    <td class="text-end" data-order="<?php echo $row['total_points_earned']; ?>"><?php echo number_format($row['total_points_earned']); ?></td>
                                    <td class="text-end" data-order="<?php echo $row['available_points']; ?>">
                                        <span class="badge bg-success"><?php echo number_format($row['available_points']); ?></span>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['points_redeemed']; ?>">
                                        <span class="badge bg-warning"><?php echo number_format($row['points_redeemed']); ?></span>
                                    </td>
                                    <td class="text-end" data-order="<?php echo $row['claimed_rewards']; ?>">$<?php echo number_format($row['claimed_rewards'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th>Total</th>
                                    <th class="text-end"><?php echo number_format($totals['total_points_earned']); ?></th>
                                    <th class="text-end"><?php echo number_format($totals['available_points']); ?></th>
                                    <th class="text-end"><?php echo number_format($totals['points_redeemed']); ?></th>
                                    <th class="text-end">$<?php echo number_format($totals['claimed_rewards'], 2); ?></th>
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
        if (typeof jQuery !== 'undefined' && jQuery.fn.DataTable && jQuery('#redemptionsTable').length) {
            // Check if already initialized
            if (jQuery.fn.DataTable.isDataTable('#redemptionsTable')) {
                return; // Already initialized
            }
            
            jQuery('#redemptionsTable').DataTable({
                order: [[0, 'asc']], // Default sort by Branch Name
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                columnDefs: [
                    { 
                        targets: [1, 2, 3, 4], // Numeric columns
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

// Excel Export Function
function exportToExcel() {
    const table = document.getElementById('redemptionsTable');
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
        const headers = ['Branch Name', 'Total Points Earned', 'Available Points', 'Points Redeemed', 'Claimed Rewards'];
        exportData.push(headers);
        
        // Add data rows (exclude the footer row)
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length === 0) return; // Skip empty rows
            
            const rowData = [];
            cells.forEach((cell, index) => {
                let cellText = cell.textContent.trim();
                
                // Clean up formatting - remove badges and extra formatting
                const badge = cell.querySelector('.badge');
                if (badge) {
                    cellText = badge.textContent.trim();
                }
                
                // Remove dollar sign for claimed rewards column
                if (index === 4 && cellText.includes('$')) {
                    cellText = cellText.replace('$', '').trim();
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
                if (index === 3 && cellText.includes('$')) {
                    cellText = cellText.replace('$', '').trim();
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
            { wch: 18 },  // Total Points Earned
            { wch: 18 },  // Available Points
            { wch: 18 },  // Points Redeemed
            { wch: 18 }   // Claimed Rewards
        ];
        ws['!cols'] = colWidths;
        
        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'Branch Redemptions');
        
        // Generate filename with date range
        const fromDate = '<?php echo str_replace('-', '', $from_date); ?>';
        const toDate = '<?php echo str_replace('-', '', $to_date); ?>';
        const filename = `Branch_Redemptions_${fromDate}_to_${toDate}.xlsx`;
        
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

