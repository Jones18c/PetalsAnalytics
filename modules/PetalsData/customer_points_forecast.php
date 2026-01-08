<?php
$header['pageTitle'] = "Customer Points Forecast - Petals Data";
require(__DIR__ . '/../../includes/header.inc.php');
require(__DIR__ . '/../../includes/config.inc.php');
require_once(__DIR__ . '/../../includes/functions.inc.php');

// Connect to MySQL database
$conn = null;
$customers = [];
$branches = [];
$error = null;
$forecastData = null;

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
    while ($row = $branchResult->fetch_assoc()) {
        $branches[] = $row;
    }
    
    // Get list of customers (with points activity)
    $customerSql = "
        SELECT DISTINCT
            c.id AS company_id,
            c.name AS company_name,
            c.code AS company_code,
            b.name AS branch_name
        FROM companies c
        INNER JOIN branches b ON c.branch_id = b.id
        WHERE c.status = 1
          AND LOWER(b.name) NOT LIKE '%mass market%'
          AND LOWER(b.name) NOT LIKE '%twin cities%'
          AND LOWER(b.name) NOT LIKE '%accounts receivable%'
          AND EXISTS (
              SELECT 1 FROM company_loyalty_points clp WHERE clp.company_id = c.id
              UNION
              SELECT 1 FROM company_redeemed_points crp WHERE crp.company_id = c.id
          )
        ORDER BY b.name ASC, c.name ASC
        LIMIT 1000
    ";
    $customerResult = $conn->query($customerSql);
    while ($row = $customerResult->fetch_assoc()) {
        $customers[] = $row;
    }
    
    // Get parameters
    $selectedCustomer = $_GET['customer'] ?? 'all';
    $selectedBranch = $_GET['branch'] ?? 'all';
    $forecastMonths = isset($_GET['forecast_months']) ? (int)$_GET['forecast_months'] : 12;
    
    // Run Python script when generate is clicked
    if (isset($_GET['generate'])) {
        $scriptPath = __DIR__ . '/customer_points_forecast.py';
        
        // Determine company ID
        $companyId = ($selectedCustomer !== 'all') ? (int)$selectedCustomer : null;
        
        // Check if Python script exists
        if (!file_exists($scriptPath)) {
            throw new Exception("Python script not found: $scriptPath");
        }
        
        // Execute Python script
        $pythonPath = "/usr/bin/python3";
        
        // Check if shell_exec is available
        if (!function_exists('shell_exec')) {
            throw new Exception("shell_exec() is disabled on this server. Please enable it or use a different execution method.");
        }
        
        // Check if Python script exists and is readable
        if (!is_readable($scriptPath)) {
            throw new Exception("Python script not readable: $scriptPath");
        }
        
        // Build command
        if ($companyId !== null) {
            $command = escapeshellarg($pythonPath) . " " . escapeshellarg($scriptPath) . " " . escapeshellarg($companyId) . " " . escapeshellarg($forecastMonths) . " 2>&1";
        } else {
            // For "all customers", pass 0
            $command = escapeshellarg($pythonPath) . " " . escapeshellarg($scriptPath) . " 0 " . escapeshellarg($forecastMonths) . " 2>&1";
        }
        
        // Execute using exec() to get return code
        $outputLines = [];
        $returnVar = 0;
        exec($command, $outputLines, $returnVar);
        $output = implode("\n", $outputLines);
        
        if (empty($output) && $returnVar !== 0) {
            $testCommand = escapeshellarg($pythonPath) . " --version 2>&1";
            $pythonVersion = shell_exec($testCommand);
            
            $errorDetails = "Failed to execute Python script. ";
            $errorDetails .= "Return code: $returnVar. ";
            $errorDetails .= "Python version: " . ($pythonVersion ? trim($pythonVersion) : "not found") . ". ";
            $errorDetails .= "Script: " . basename($scriptPath);
            
            throw new Exception($errorDetails);
        }
        
        // Filter out matplotlib/plotly warnings - extract JSON only
        $jsonStart = strpos($output, '{');
        if ($jsonStart !== false) {
            $jsonEnd = strrpos($output, '}');
            if ($jsonEnd !== false && $jsonEnd > $jsonStart) {
                $output = substr($output, $jsonStart, $jsonEnd - $jsonStart + 1);
            }
        }
        
        // If no JSON found, output might be an error message
        if (empty($output) || $jsonStart === false) {
            throw new Exception("Python script returned no valid output. Raw output: " . substr(implode("\n", $outputLines), 0, 500));
        }
        
        // Parse JSON output
        $forecastData = json_decode($output, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from Python script: " . json_last_error_msg() . "\nOutput: " . substr($output, 0, 500));
        }
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
} finally {
    if ($conn) {
        $conn->close();
    }
}
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="bi bi-graph-up me-2"></i>Customer Points Forecast (Prophet)</h4>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="GET" action="" class="mb-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="customer" class="form-label">Customer</label>
                                <input type="text" class="form-control mb-2" id="customerSearch" placeholder="Type to filter customers..." autocomplete="off" onkeyup="filterCustomers()">
                                <select class="form-select" id="customer" name="customer" size="10" style="max-height: 300px; overflow-y: auto;">
                                    <option value="all" <?php echo $selectedCustomer === 'all' ? 'selected' : ''; ?>>All Customers</option>
                                    <?php foreach ($customers as $customer): 
                                        $displayText = $customer['company_name'] . ' (' . $customer['company_code'] . ') - ' . $customer['branch_name'];
                                        $searchText = strtolower($displayText);
                                    ?>
                                        <option value="<?php echo $customer['company_id']; ?>" 
                                                data-search="<?php echo htmlspecialchars($searchText); ?>"
                                                <?php echo $selectedCustomer == $customer['company_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($displayText); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted d-block mt-1">Type above to filter, then select from dropdown</small>
                            </div>
                            <div class="col-md-3">
                                <label for="forecast_months" class="form-label">Forecast Months</label>
                                <input type="number" class="form-control" id="forecast_months" name="forecast_months" 
                                       value="<?php echo $forecastMonths; ?>" min="1" max="24">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" name="generate" value="1" class="btn btn-primary w-100">
                                    <i class="bi bi-play-circle me-2"></i>Generate Forecast
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <?php if ($forecastData): ?>
                        <div class="alert alert-info">
                            <strong>Forecast Scope:</strong> <?php echo htmlspecialchars($forecastData['scope']); ?><br>
                            <strong>Forecast Period:</strong> <?php echo $forecastData['forecast_months']; ?> months
                        </div>
                        
                        <!-- Summary Cards (same as points_forecast.php) -->
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="card bg-success text-white">
                                    <div class="card-body">
                                        <h5 class="card-title">Points Earned</h5>
                                        <?php 
                                        $lastEarned = end($forecastData['historical_data']);
                                        $forecastEarned = end($forecastData['forecast']['earned']);
                                        $forecastEarnedValue = max($forecastEarned['yhat'] ?? 0, 0);
                                        $earnedChange = $lastEarned && $lastEarned['earned'] > 0 ? (($forecastEarnedValue - $lastEarned['earned']) / $lastEarned['earned']) * 100 : 0;
                                        $earnedConfidence = $forecastEarnedValue > 0 ? (abs($forecastEarned['yhat_upper'] - $forecastEarned['yhat_lower']) / $forecastEarnedValue) * 100 : 999;
                                        ?>
                                        <p class="mb-0">Last Month: <?php echo number_format($lastEarned['earned'] ?? 0); ?></p>
                                        <p class="mb-0">Forecast (<?php echo date('M Y', strtotime($forecastEarned['ds'])); ?>): <?php echo number_format($forecastEarnedValue); ?></p>
                                        <p class="mb-0 small">Change: <?php echo number_format($earnedChange, 1); ?>%</p>
                                        <?php if ($forecastEarnedValue > 0 && $earnedConfidence < 999): ?>
                                            <p class="mb-0 small">Confidence Range: ±<?php echo number_format($earnedConfidence / 2, 1); ?>%</p>
                                        <?php else: ?>
                                            <p class="mb-0 small text-warning">High Uncertainty</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-info text-white">
                                    <div class="card-body">
                                        <h5 class="card-title">Available Points</h5>
                                        <?php 
                                        $lastAvailable = end($forecastData['historical_data']);
                                        $forecastAvailable = end($forecastData['forecast']['available']);
                                        $forecastAvailableValue = max($forecastAvailable['yhat'] ?? 0, 0);
                                        $availableChange = $lastAvailable && $lastAvailable['available'] > 0 ? (($forecastAvailableValue - $lastAvailable['available']) / $lastAvailable['available']) * 100 : 0;
                                        $availableConfidence = $forecastAvailableValue > 0 ? (abs($forecastAvailable['yhat_upper'] - $forecastAvailable['yhat_lower']) / $forecastAvailableValue) * 100 : 999;
                                        ?>
                                        <p class="mb-0">Last Month: <?php echo number_format($lastAvailable['available'] ?? 0); ?></p>
                                        <p class="mb-0">Forecast (<?php echo date('M Y', strtotime($forecastAvailable['ds'])); ?>): <?php echo number_format($forecastAvailableValue); ?></p>
                                        <p class="mb-0 small">Change: <?php echo number_format($availableChange, 1); ?>%</p>
                                        <?php if ($forecastAvailableValue > 0 && $availableConfidence < 999): ?>
                                            <p class="mb-0 small">Confidence Range: ±<?php echo number_format($availableConfidence / 2, 1); ?>%</p>
                                        <?php else: ?>
                                            <p class="mb-0 small text-warning">High Uncertainty</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-warning text-white">
                                    <div class="card-body">
                                        <h5 class="card-title">Points Redeemed</h5>
                                        <?php 
                                        $lastRedeemed = end($forecastData['historical_data']);
                                        $forecastRedeemed = end($forecastData['forecast']['redeemed']);
                                        $forecastRedeemedValue = max($forecastRedeemed['yhat'] ?? 0, 0);
                                        $redeemedChange = $lastRedeemed && $lastRedeemed['redeemed'] > 0 ? (($forecastRedeemedValue - $lastRedeemed['redeemed']) / $lastRedeemed['redeemed']) * 100 : 0;
                                        $redeemedConfidence = $forecastRedeemedValue > 0 ? (abs($forecastRedeemed['yhat_upper'] - $forecastRedeemed['yhat_lower']) / $forecastRedeemedValue) * 100 : 999;
                                        ?>
                                        <p class="mb-0">Last Month: <?php echo number_format($lastRedeemed['redeemed'] ?? 0); ?></p>
                                        <p class="mb-0">Forecast (<?php echo date('M Y', strtotime($forecastRedeemed['ds'])); ?>): <?php echo number_format($forecastRedeemedValue); ?></p>
                                        <p class="mb-0 small">Change: <?php echo number_format($redeemedChange, 1); ?>%</p>
                                        <?php if ($forecastRedeemedValue > 0 && $redeemedConfidence < 999): ?>
                                            <p class="mb-0 small">Confidence Range: ±<?php echo number_format($redeemedConfidence / 2, 1); ?>%</p>
                                        <?php else: ?>
                                            <p class="mb-0 small text-warning">High Uncertainty</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Chart visualization (same structure as points_forecast.php) -->
                        <div class="row">
                            <div class="col-12">
                                <h5>Forecast Visualization</h5>
                                <canvas id="forecastChart" height="80"></canvas>
                            </div>
                        </div>
                        
                        <script>
                            // Simple, reliable customer filter function
                            function filterCustomers() {
                                const searchInput = document.getElementById('customerSearch');
                                const customerSelect = document.getElementById('customer');
                                
                                if (!searchInput || !customerSelect) {
                                    return;
                                }
                                
                                const searchTerm = searchInput.value.toLowerCase().trim();
                                const options = customerSelect.getElementsByTagName('option');
                                
                                for (let i = 0; i < options.length; i++) {
                                    const option = options[i];
                                    
                                    if (option.value === 'all') {
                                        // Always show "All Customers" when search is empty
                                        option.style.display = (searchTerm === '') ? '' : 'none';
                                        continue;
                                    }
                                    
                                    const searchText = option.getAttribute('data-search') || option.textContent.toLowerCase();
                                    
                                    if (searchTerm === '' || searchText.indexOf(searchTerm) !== -1) {
                                        option.style.display = '';
                                    } else {
                                        option.style.display = 'none';
                                    }
                                }
                            }
                            
                            // Initialize on page load
                            document.addEventListener('DOMContentLoaded', function() {
                                filterCustomers();
                            });
                        </script>
                        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
                        <script>
                            const historicalData = <?php echo json_encode($forecastData['historical_data']); ?>;
                            const forecastEarned = <?php echo json_encode($forecastData['forecast']['earned'] ?? []); ?>;
                            const forecastAvailable = <?php echo json_encode($forecastData['forecast']['available'] ?? []); ?>;
                            const forecastRedeemed = <?php echo json_encode($forecastData['forecast']['redeemed'] ?? []); ?>;
                            
                            // Prepare labels
                            const historicalLabels = historicalData.map(d => new Date(d.ds).toLocaleDateString('en-US', { month: 'short', year: 'numeric' }));
                            const forecastLabels = forecastEarned.map(d => new Date(d.ds).toLocaleDateString('en-US', { month: 'short', year: 'numeric' }));
                            const allLabels = [...historicalLabels, ...forecastLabels];
                            
                            const ctx = document.getElementById('forecastChart').getContext('2d');
                            const chart = new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: allLabels,
                                    datasets: [
                                        {
                                            label: 'Earned (Historical)',
                                            data: [
                                                ...historicalData.map(d => d.earned),
                                                ...Array(forecastEarned.length).fill(null)
                                            ],
                                            borderColor: 'rgb(40, 167, 69)',
                                            backgroundColor: 'rgba(40, 167, 69, 0.2)',
                                            tension: 0.1
                                        },
                                        {
                                            label: 'Earned (Forecast)',
                                            data: [
                                                ...Array(historicalData.length).fill(null),
                                                ...forecastEarned.map(d => d.yhat)
                                            ],
                                            borderColor: 'rgb(40, 167, 69)',
                                            backgroundColor: 'rgba(40, 167, 69, 0.2)',
                                            borderDash: [5, 5],
                                            tension: 0.1
                                        },
                                        {
                                            label: 'Available (Historical)',
                                            data: [
                                                ...historicalData.map(d => d.available),
                                                ...Array(forecastAvailable.length).fill(null)
                                            ],
                                            borderColor: 'rgb(23, 162, 184)',
                                            backgroundColor: 'rgba(23, 162, 184, 0.2)',
                                            tension: 0.1
                                        },
                                        {
                                            label: 'Available (Forecast)',
                                            data: [
                                                ...Array(historicalData.length).fill(null),
                                                ...forecastAvailable.map(d => d.yhat)
                                            ],
                                            borderColor: 'rgb(23, 162, 184)',
                                            backgroundColor: 'rgba(23, 162, 184, 0.2)',
                                            borderDash: [5, 5],
                                            tension: 0.1
                                        },
                                        {
                                            label: 'Redeemed (Historical)',
                                            data: [
                                                ...historicalData.map(d => d.redeemed),
                                                ...Array(forecastRedeemed.length).fill(null)
                                            ],
                                            borderColor: 'rgb(255, 193, 7)',
                                            backgroundColor: 'rgba(255, 193, 7, 0.2)',
                                            tension: 0.1
                                        },
                                        {
                                            label: 'Redeemed (Forecast)',
                                            data: [
                                                ...Array(historicalData.length).fill(null),
                                                ...forecastRedeemed.map(d => d.yhat)
                                            ],
                                            borderColor: 'rgb(255, 193, 7)',
                                            backgroundColor: 'rgba(255, 193, 7, 0.2)',
                                            borderDash: [5, 5],
                                            tension: 0.1
                                        }
                                    ]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: true,
                                    scales: {
                                        y: {
                                            beginAtZero: true,
                                            title: {
                                                display: true,
                                                text: 'Points'
                                            }
                                        },
                                        x: {
                                            title: {
                                                display: true,
                                                text: 'Month'
                                            }
                                        }
                                    },
                                    plugins: {
                                        legend: {
                                            display: true,
                                            position: 'top'
                                        },
                                        tooltip: {
                                            mode: 'index',
                                            intersect: false
                                        }
                                    }
                                }
                            });
                        </script>
                    <?php else: ?>
                        <div class="alert alert-secondary">
                            Select a customer and click "Generate Forecast" to see predictions for points earned, available, and redeemed.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require(__DIR__ . '/../../includes/footer.inc.php'); ?>

