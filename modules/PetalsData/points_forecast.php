<?php
$header['pageTitle'] = "Points Forecast - Petals Data";
require(__DIR__ . '/../../includes/header.inc.php');
require(__DIR__ . '/../../includes/config.inc.php');
require_once(__DIR__ . '/../../includes/functions.inc.php');

// Connect to MySQL database
$conn = null;
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
    
    // Get parameters
    $selectedBranch = $_GET['branch'] ?? 'all';
    $forecastMonths = isset($_GET['forecast_months']) ? (int)$_GET['forecast_months'] : 12;
    
    // Run Python script when generate is clicked
    if (isset($_GET['generate'])) {
        $scriptPath = __DIR__ . '/points_forecast.py';
        
        // Determine branch ID - use 0 or empty for "all branches"
        $branchId = ($selectedBranch !== 'all') ? (int)$selectedBranch : null;
        
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
        
        // Build command - use exec() to get return code and output
        // If branchId is null (all branches), don't pass it as an argument
        if ($branchId !== null) {
            $command = escapeshellarg($pythonPath) . " " . escapeshellarg($scriptPath) . " " . escapeshellarg($branchId) . " " . escapeshellarg($forecastMonths) . " 2>&1";
        } else {
            // For "all branches", pass 0 or skip branch_id argument
            // The Python script should handle 0 or missing argument as "all branches"
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
                    <h4 class="mb-0"><i class="bi bi-graph-up me-2"></i>Points Forecast (Prophet)</h4>
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
                                <label for="branch" class="form-label">Branch</label>
                                <select class="form-select" id="branch" name="branch">
                                    <option value="all" <?php echo $selectedBranch === 'all' ? 'selected' : ''; ?>>All Branches</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo $branch['id']; ?>" <?php echo $selectedBranch == $branch['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($branch['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
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
                        
                        <!-- Summary Cards -->
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
                                        <p class="mb-0">Forecast (<?php echo date('M Y', strtotime($forecastEarned['ds'])); ?>): <?php echo number_format(max($forecastEarned['yhat'] ?? 0, 0)); ?></p>
                                        <p class="mb-0 small">Change: <?php echo number_format($earnedChange, 1); ?>%</p>
                                        <?php if ($forecastEarned['yhat'] > 0 && $earnedConfidence < 999): ?>
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
                                        <p class="mb-0">Forecast (<?php echo date('M Y', strtotime($forecastAvailable['ds'])); ?>): <?php echo number_format(max($forecastAvailable['yhat'] ?? 0, 0)); ?></p>
                                        <p class="mb-0 small">Change: <?php echo number_format($availableChange, 1); ?>%</p>
                                        <?php if ($forecastAvailable['yhat'] > 0 && $availableConfidence < 999): ?>
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
                                        <p class="mb-0">Forecast (<?php echo date('M Y', strtotime($forecastRedeemed['ds'])); ?>): <?php echo number_format(max($forecastRedeemed['yhat'] ?? 0, 0)); ?></p>
                                        <p class="mb-0 small">Change: <?php echo number_format($redeemedChange, 1); ?>%</p>
                                        <?php if ($forecastRedeemed['yhat'] > 0 && $redeemedConfidence < 999): ?>
                                            <p class="mb-0 small">Confidence Range: ±<?php echo number_format($redeemedConfidence / 2, 1); ?>%</p>
                                        <?php else: ?>
                                            <p class="mb-0 small text-warning">High Uncertainty</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Forecast Explanation -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <div class="card border-info">
                                    <div class="card-header bg-info text-white">
                                        <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Understanding the Forecast</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <h6><strong>How the Forecast Works</strong></h6>
                                                <p class="small">
                                                    This forecast uses <strong>Facebook Prophet</strong>, a time series forecasting tool that:
                                                </p>
                                                <ul class="small">
                                                    <li>Analyzes historical patterns in your points data</li>
                                                    <li>Identifies trends, seasonality, and recurring patterns</li>
                                                    <li>Projects future values based on these patterns</li>
                                                    <li>Accounts for yearly seasonality (e.g., holiday periods, seasonal trends)</li>
                                                </ul>
                                                
                                                <h6 class="mt-3"><strong>Confidence Intervals</strong></h6>
                                                <p class="small">
                                                    Each forecast includes an <strong>80% confidence interval</strong> (the shaded area on the chart):
                                                </p>
                                                <ul class="small">
                                                    <li><strong>Upper Bound</strong>: There's an 80% chance the actual value will be below this</li>
                                                    <li><strong>Forecast</strong>: The most likely value (median prediction)</li>
                                                    <li><strong>Lower Bound</strong>: There's an 80% chance the actual value will be above this</li>
                                                </ul>
                                                <p class="small text-muted">
                                                    <em>A narrower confidence interval indicates higher confidence in the prediction. Wider intervals suggest more uncertainty.</em>
                                                </p>
                                            </div>
                                            <div class="col-md-6">
                                                <h6><strong>Interpreting the Results</strong></h6>
                                                
                                                <h6 class="mt-2"><strong>Points Earned</strong></h6>
                                                <p class="small">
                                                    Forecasts the total points customers will earn in future months. This helps predict:
                                                </p>
                                                <ul class="small">
                                                    <li>Customer engagement levels</li>
                                                    <li>Program growth trends</li>
                                                    <li>Resource planning needs</li>
                                                </ul>
                                                
                                                <h6 class="mt-2"><strong>Available Points</strong></h6>
                                                <p class="small">
                                                    Forecasts the total points balance available for redemption. This helps:
                                                </p>
                                                <ul class="small">
                                                    <li>Plan for redemption capacity</li>
                                                    <li>Understand liability trends</li>
                                                    <li>Manage program costs</li>
                                                </ul>
                                                
                                                <h6 class="mt-2"><strong>Points Redeemed</strong></h6>
                                                <p class="small">
                                                    Forecasts how many points will be redeemed. This helps:
                                                </p>
                                                <ul class="small">
                                                    <li>Budget for rewards costs</li>
                                                    <li>Plan inventory needs</li>
                                                    <li>Understand redemption patterns</li>
                                                </ul>
                                                
                                                <div class="alert alert-warning mt-3 small">
                                                    <strong>Note:</strong> Forecasts become less accurate the further into the future you look. 
                                                    Use near-term forecasts (1-3 months) for planning, and longer-term forecasts (6+ months) for trend analysis.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Detailed Forecast Table -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="bi bi-table me-2"></i>Detailed Forecast with Confidence Intervals</h5>
                                    </div>
                                    <div class="card-body">
                                        <ul class="nav nav-tabs" id="forecastTabs" role="tablist">
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link active" id="earned-tab" data-bs-toggle="tab" data-bs-target="#earned" type="button" role="tab">
                                                    Points Earned
                                                </button>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link" id="available-tab" data-bs-toggle="tab" data-bs-target="#available" type="button" role="tab">
                                                    Available Points
                                                </button>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link" id="redeemed-tab" data-bs-toggle="tab" data-bs-target="#redeemed" type="button" role="tab">
                                                    Points Redeemed
                                                </button>
                                            </li>
                                        </ul>
                                        <div class="tab-content" id="forecastTabContent">
                                            <div class="tab-pane fade show active" id="earned" role="tabpanel">
                                                <div class="table-responsive mt-3">
                                                    <table class="table table-sm table-striped">
                                                        <thead>
                                                            <tr>
                                                                <th>Month</th>
                                                                <th>Forecast</th>
                                                                <th>Lower Bound (80%)</th>
                                                                <th>Upper Bound (80%)</th>
                                                                <th>Confidence Range</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($forecastData['forecast']['earned'] ?? [] as $row): ?>
                                                                <?php 
                                                                $forecastValue = max($row['yhat'], 0); // Ensure non-negative
                                                                $range = abs($row['yhat_upper'] - $row['yhat_lower']);
                                                                // Only calculate percentage if forecast is meaningful (> 0)
                                                                if ($forecastValue > 0) {
                                                                    $rangePercent = ($range / $forecastValue) * 100;
                                                                } else {
                                                                    // If forecast is 0 or negative, show absolute range
                                                                    $rangePercent = $range > 0 ? 999 : 0; // Flag as high uncertainty
                                                                }
                                                                ?>
                                                                <tr>
                                                                    <td><?php echo date('M Y', strtotime($row['ds'])); ?></td>
                                                                    <td><strong><?php echo number_format($row['yhat'], 0); ?></strong></td>
                                                                    <td><?php echo number_format($row['yhat_lower'], 0); ?></td>
                                                                    <td><?php echo number_format($row['yhat_upper'], 0); ?></td>
                                                                    <td>
                                                                        <span class="badge <?php echo $rangePercent < 20 ? 'bg-success' : ($rangePercent < 40 ? 'bg-warning' : 'bg-danger'); ?>">
                                                                            ±<?php echo number_format($rangePercent / 2, 1); ?>%
                                                                        </span>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div class="tab-pane fade" id="available" role="tabpanel">
                                                <div class="table-responsive mt-3">
                                                    <table class="table table-sm table-striped">
                                                        <thead>
                                                            <tr>
                                                                <th>Month</th>
                                                                <th>Forecast</th>
                                                                <th>Lower Bound (80%)</th>
                                                                <th>Upper Bound (80%)</th>
                                                                <th>Confidence Range</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($forecastData['forecast']['available'] ?? [] as $row): ?>
                                                                <?php 
                                                                $forecastValue = max($row['yhat'], 0);
                                                                $range = abs($row['yhat_upper'] - $row['yhat_lower']);
                                                                if ($forecastValue > 0) {
                                                                    $rangePercent = ($range / $forecastValue) * 100;
                                                                } else {
                                                                    $rangePercent = $range > 0 ? 999 : 0;
                                                                }
                                                                ?>
                                                                <tr>
                                                                    <td><?php echo date('M Y', strtotime($row['ds'])); ?></td>
                                                                    <td><strong><?php echo number_format($row['yhat'], 0); ?></strong></td>
                                                                    <td><?php echo number_format($row['yhat_lower'], 0); ?></td>
                                                                    <td><?php echo number_format($row['yhat_upper'], 0); ?></td>
                                                                    <td>
                                                                        <?php if ($forecastValue > 0 && $rangePercent < 999): ?>
                                                                            <span class="badge <?php echo $rangePercent < 20 ? 'bg-success' : ($rangePercent < 40 ? 'bg-warning' : 'bg-danger'); ?>">
                                                                                ±<?php echo number_format($rangePercent / 2, 1); ?>%
                                                                            </span>
                                                                        <?php else: ?>
                                                                            <span class="badge bg-danger" title="High uncertainty or invalid forecast">
                                                                                High Uncertainty
                                                                            </span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div class="tab-pane fade" id="redeemed" role="tabpanel">
                                                <div class="table-responsive mt-3">
                                                    <table class="table table-sm table-striped">
                                                        <thead>
                                                            <tr>
                                                                <th>Month</th>
                                                                <th>Forecast</th>
                                                                <th>Lower Bound (80%)</th>
                                                                <th>Upper Bound (80%)</th>
                                                                <th>Confidence Range</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($forecastData['forecast']['redeemed'] ?? [] as $row): ?>
                                                                <?php 
                                                                $forecastValue = max($row['yhat'], 0);
                                                                $range = abs($row['yhat_upper'] - $row['yhat_lower']);
                                                                if ($forecastValue > 0) {
                                                                    $rangePercent = ($range / $forecastValue) * 100;
                                                                } else {
                                                                    $rangePercent = $range > 0 ? 999 : 0;
                                                                }
                                                                ?>
                                                                <tr>
                                                                    <td><?php echo date('M Y', strtotime($row['ds'])); ?></td>
                                                                    <td><strong><?php echo number_format($row['yhat'], 0); ?></strong></td>
                                                                    <td><?php echo number_format($row['yhat_lower'], 0); ?></td>
                                                                    <td><?php echo number_format($row['yhat_upper'], 0); ?></td>
                                                                    <td>
                                                                        <?php if ($forecastValue > 0 && $rangePercent < 999): ?>
                                                                            <span class="badge <?php echo $rangePercent < 20 ? 'bg-success' : ($rangePercent < 40 ? 'bg-warning' : 'bg-danger'); ?>">
                                                                                ±<?php echo number_format($rangePercent / 2, 1); ?>%
                                                                            </span>
                                                                        <?php else: ?>
                                                                            <span class="badge bg-danger" title="High uncertainty or invalid forecast">
                                                                                High Uncertainty
                                                                            </span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Chart visualization -->
                        <div class="row">
                            <div class="col-12">
                                <h5>Forecast Visualization</h5>
                                <canvas id="forecastChart" height="80"></canvas>
                            </div>
                        </div>
                        
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
                                        },
                                        // Confidence intervals for Earned
                                        {
                                            label: 'Earned Upper Bound (80%)',
                                            data: [
                                                ...Array(historicalData.length).fill(null),
                                                ...forecastEarned.map(d => d.yhat_upper)
                                            ],
                                            borderColor: 'rgba(40, 167, 69, 0.3)',
                                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                                            borderDash: [2, 2],
                                            tension: 0.1,
                                            fill: '+1',
                                            pointRadius: 0
                                        },
                                        {
                                            label: 'Earned Lower Bound (80%)',
                                            data: [
                                                ...Array(historicalData.length).fill(null),
                                                ...forecastEarned.map(d => d.yhat_lower)
                                            ],
                                            borderColor: 'rgba(40, 167, 69, 0.3)',
                                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                                            borderDash: [2, 2],
                                            tension: 0.1,
                                            fill: false,
                                            pointRadius: 0
                                        },
                                        // Confidence intervals for Available
                                        {
                                            label: 'Available Upper Bound (80%)',
                                            data: [
                                                ...Array(historicalData.length).fill(null),
                                                ...forecastAvailable.map(d => d.yhat_upper)
                                            ],
                                            borderColor: 'rgba(23, 162, 184, 0.3)',
                                            backgroundColor: 'rgba(23, 162, 184, 0.1)',
                                            borderDash: [2, 2],
                                            tension: 0.1,
                                            fill: '+1',
                                            pointRadius: 0
                                        },
                                        {
                                            label: 'Available Lower Bound (80%)',
                                            data: [
                                                ...Array(historicalData.length).fill(null),
                                                ...forecastAvailable.map(d => d.yhat_lower)
                                            ],
                                            borderColor: 'rgba(23, 162, 184, 0.3)',
                                            backgroundColor: 'rgba(23, 162, 184, 0.1)',
                                            borderDash: [2, 2],
                                            tension: 0.1,
                                            fill: false,
                                            pointRadius: 0
                                        },
                                        // Confidence intervals for Redeemed
                                        {
                                            label: 'Redeemed Upper Bound (80%)',
                                            data: [
                                                ...Array(historicalData.length).fill(null),
                                                ...forecastRedeemed.map(d => d.yhat_upper)
                                            ],
                                            borderColor: 'rgba(255, 193, 7, 0.3)',
                                            backgroundColor: 'rgba(255, 193, 7, 0.1)',
                                            borderDash: [2, 2],
                                            tension: 0.1,
                                            fill: '+1',
                                            pointRadius: 0
                                        },
                                        {
                                            label: 'Redeemed Lower Bound (80%)',
                                            data: [
                                                ...Array(historicalData.length).fill(null),
                                                ...forecastRedeemed.map(d => d.yhat_lower)
                                            ],
                                            borderColor: 'rgba(255, 193, 7, 0.3)',
                                            backgroundColor: 'rgba(255, 193, 7, 0.1)',
                                            borderDash: [2, 2],
                                            tension: 0.1,
                                            fill: false,
                                            pointRadius: 0
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
                                            position: 'top',
                                            onClick: function(e, legendItem) {
                                                // Hide/show confidence intervals together
                                                const chart = this.chart;
                                                const ci = legendItem.text;
                                                if (ci.includes('Upper Bound') || ci.includes('Lower Bound')) {
                                                    const baseName = ci.replace(' Upper Bound (80%)', '').replace(' Lower Bound (80%)', '');
                                                    const datasets = chart.data.datasets;
                                                    datasets.forEach((dataset, index) => {
                                                        if (dataset.label.includes(baseName) && dataset.label.includes('Bound')) {
                                                            const meta = chart.getDatasetMeta(index);
                                                            meta.hidden = !meta.hidden;
                                                        }
                                                    });
                                                } else {
                                                    const meta = chart.getDatasetMeta(legendItem.datasetIndex);
                                                    meta.hidden = !meta.hidden;
                                                }
                                                chart.update();
                                            }
                                        },
                                        tooltip: {
                                            mode: 'index',
                                            intersect: false,
                                            callbacks: {
                                                label: function(context) {
                                                    let label = context.dataset.label || '';
                                                    if (label) {
                                                        label += ': ';
                                                    }
                                                    if (context.parsed.y !== null) {
                                                        label += new Intl.NumberFormat().format(context.parsed.y);
                                                    }
                                                    return label;
                                                }
                                            }
                                        }
                                    }
                                }
                            });
                        </script>
                    <?php else: ?>
                        <div class="alert alert-secondary">
                            Select a branch and click "Generate Forecast" to see predictions for points earned, available, and redeemed.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require(__DIR__ . '/../../includes/footer.inc.php'); ?>

