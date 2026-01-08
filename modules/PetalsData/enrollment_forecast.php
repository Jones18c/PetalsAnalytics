<?php
$header['pageTitle'] = "Enrollment Forecast - Petals Data";
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
    
    // Run Python script if branch is selected
    if ($selectedBranch !== 'all' && isset($_GET['generate'])) {
        $branchId = (int)$selectedBranch;
        $scriptPath = __DIR__ . '/enrollment_forecast.py';
        
        // Check if Python script exists
        if (!file_exists($scriptPath)) {
            throw new Exception("Python script not found: $scriptPath");
        }
        
        // Execute Python script
        // Use full path to python3 - the Python script will handle adding the user's site-packages to sys.path
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
        $command = escapeshellarg($pythonPath) . " " . escapeshellarg($scriptPath) . " " . escapeshellarg($branchId) . " " . escapeshellarg($forecastMonths) . " 2>&1";
        
        // Execute using exec() to get return code
        $outputLines = [];
        $returnVar = 0;
        exec($command, $outputLines, $returnVar);
        $output = implode("\n", $outputLines);
        
        if (empty($output) && $returnVar !== 0) {
            // Try to get more info about what went wrong
            $testCommand = escapeshellarg($pythonPath) . " --version 2>&1";
            $pythonVersion = shell_exec($testCommand);
            
            $errorDetails = "Failed to execute Python script. ";
            $errorDetails .= "Return code: $returnVar. ";
            $errorDetails .= "Python version: " . ($pythonVersion ? trim($pythonVersion) : "not found") . ". ";
            $errorDetails .= "Script: " . basename($scriptPath);
            
            throw new Exception($errorDetails);
        }
        
        // Filter out matplotlib/plotly warnings - extract JSON only
        // Look for JSON object (starts with { and ends with })
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
                    <h4 class="mb-0"><i class="bi bi-graph-up me-2"></i>Enrollment Forecast (Prophet)</h4>
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
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5>Historical Data</h5>
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th>Month</th>
                                                <th>Can Enroll (Not Enrolled)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($forecastData['historical_data'] as $row): ?>
                                                <tr>
                                                    <td><?php echo date('M Y', strtotime($row['ds'])); ?></td>
                                                    <td><?php echo number_format($row['y']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5>Forecast</h5>
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th>Month</th>
                                                <th>Forecast</th>
                                                <th>Lower Bound</th>
                                                <th>Upper Bound</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($forecastData['forecast'] as $row): ?>
                                                <tr>
                                                    <td><?php echo date('M Y', strtotime($row['ds'])); ?></td>
                                                    <td><strong><?php echo number_format($row['yhat'], 0); ?></strong></td>
                                                    <td><?php echo number_format($row['yhat_lower'], 0); ?></td>
                                                    <td><?php echo number_format($row['yhat_upper'], 0); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
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
                            const forecastData = <?php echo json_encode($forecastData['forecast']); ?>;
                            
                            const ctx = document.getElementById('forecastChart').getContext('2d');
                            const chart = new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: [
                                        ...historicalData.map(d => new Date(d.ds).toLocaleDateString('en-US', { month: 'short', year: 'numeric' })),
                                        ...forecastData.map(d => new Date(d.ds).toLocaleDateString('en-US', { month: 'short', year: 'numeric' }))
                                    ],
                                    datasets: [
                                        {
                                            label: 'Historical',
                                            data: historicalData.map(d => d.y),
                                            borderColor: 'rgb(75, 192, 192)',
                                            backgroundColor: 'rgba(75, 192, 192, 0.2)',
                                            tension: 0.1
                                        },
                                        {
                                            label: 'Forecast',
                                            data: [
                                                ...Array(historicalData.length).fill(null),
                                                ...forecastData.map(d => d.yhat)
                                            ],
                                            borderColor: 'rgb(255, 99, 132)',
                                            backgroundColor: 'rgba(255, 99, 132, 0.2)',
                                            borderDash: [5, 5],
                                            tension: 0.1
                                        },
                                        {
                                            label: 'Upper Bound',
                                            data: [
                                                ...Array(historicalData.length).fill(null),
                                                ...forecastData.map(d => d.yhat_upper)
                                            ],
                                            borderColor: 'rgba(255, 99, 132, 0.3)',
                                            backgroundColor: 'rgba(255, 99, 132, 0.1)',
                                            borderDash: [2, 2],
                                            tension: 0.1,
                                            fill: '+1'
                                        },
                                        {
                                            label: 'Lower Bound',
                                            data: [
                                                ...Array(historicalData.length).fill(null),
                                                ...forecastData.map(d => d.yhat_lower)
                                            ],
                                            borderColor: 'rgba(255, 99, 132, 0.3)',
                                            backgroundColor: 'rgba(255, 99, 132, 0.1)',
                                            borderDash: [2, 2],
                                            tension: 0.1,
                                            fill: false
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
                                                text: 'Customers (Can Enroll, Not Enrolled)'
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
                            Select a branch and click "Generate Forecast" to see predictions.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require(__DIR__ . '/../../includes/footer.inc.php'); ?>

