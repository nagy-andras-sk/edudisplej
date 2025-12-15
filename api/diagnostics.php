<?php
/**
 * Diagnostics API
 * Provides system health and diagnostic information
 */

// Include shared configuration (contains loadEnv function)
require_once __DIR__ . '/config.php';

/**
 * Check UPnP availability
 * @return array Diagnostic result
 */
function checkUpnp() {
    global $config;
    
    $issues = [];
    
    // Check if running in Docker
    if (file_exists('/.dockerenv')) {
        $issues[] = [
            'level' => 'high',
            'message' => 'UPnP not accessible from Docker container',
            'suggestion' => 'Use API Poll mode instead (set TR2_UPNP_ENABLED=false)'
        ];
        
        $issues[] = [
            'level' => 'medium',
            'message' => 'Docker bridge network incompatible with UPnP',
            'suggestion' => 'File server will connect to main server every ' . $config['heartbeat_interval'] . ' seconds'
        ];
    }
    
    // Check UPnP status
    if ($config['upnp_enabled']) {
        // Try to detect UPnP gateway
        $upnpAvailable = false;
        
        // Simple check - try to ping common router IPs
        $commonGateways = ['192.168.1.1', '192.168.0.1', '10.0.0.1'];
        foreach ($commonGateways as $gateway) {
            exec("ping -c 1 -W 1 $gateway 2>/dev/null", $output, $returnCode);
            if ($returnCode === 0) {
                $upnpAvailable = true;
                break;
            }
        }
        
        if (!$upnpAvailable && !file_exists('/.dockerenv')) {
            $issues[] = [
                'level' => 'medium',
                'message' => 'Cannot reach router gateway for UPnP',
                'suggestion' => 'Check network configuration or disable UPnP'
            ];
        }
    }
    
    return [
        'service' => 'UPnP',
        'enabled' => $config['upnp_enabled'],
        'issues' => $issues
    ];
}

/**
 * Check network connectivity
 * @return array Diagnostic result
 */
function checkNetwork() {
    $issues = [];
    
    // Check internet connectivity
    $ch = curl_init('https://www.google.com');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        $issues[] = [
            'level' => 'critical',
            'message' => 'No internet connectivity',
            'suggestion' => 'Check network settings and firewall'
        ];
    }
    
    // Check main server connectivity
    global $config;
    $mainServer = $config['main_server'];
    $ch = curl_init($mainServer);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200 && $httpCode !== 404) {
        $issues[] = [
            'level' => 'high',
            'message' => "Cannot reach main server: $mainServer",
            'suggestion' => 'Check TR2_MAIN_SERVER configuration',
            'details' => $error
        ];
    }
    
    return [
        'service' => 'Network',
        'main_server' => $mainServer,
        'issues' => $issues
    ];
}

/**
 * Check configuration
 * @return array Diagnostic result
 */
function checkConfiguration() {
    global $config;
    $issues = [];
    
    if (empty($config['pairing_id'])) {
        $issues[] = [
            'level' => 'critical',
            'message' => 'Pairing ID not configured',
            'suggestion' => 'Set TR2_PAIRING_ID in .env file'
        ];
    }
    
    if (!file_exists($config['env_file'])) {
        $issues[] = [
            'level' => 'high',
            'message' => '.env file not found',
            'suggestion' => 'Create .env file at ' . $config['env_file']
        ];
    }
    
    return [
        'service' => 'Configuration',
        'pairing_id_set' => !empty($config['pairing_id']),
        'issues' => $issues
    ];
}

/**
 * Run all diagnostics
 * @return array Complete diagnostic results
 */
function runDiagnostics() {
    $diagnostics = [
        'upnp' => checkUpnp(),
        'network' => checkNetwork(),
        'config' => checkConfiguration()
    ];
    
    // Calculate overall health
    $criticalCount = 0;
    $highCount = 0;
    $mediumCount = 0;
    
    foreach ($diagnostics as $diagnostic) {
        foreach ($diagnostic['issues'] as $issue) {
            if ($issue['level'] === 'critical') $criticalCount++;
            elseif ($issue['level'] === 'high') $highCount++;
            elseif ($issue['level'] === 'medium') $mediumCount++;
        }
    }
    
    $totalIssues = $criticalCount + $highCount + $mediumCount;
    
    if ($criticalCount > 0) {
        $health = 'critical';
    } elseif ($highCount > 0) {
        $health = 'error';
    } elseif ($mediumCount > 0) {
        $health = 'warning';
    } else {
        $health = 'ok';
    }
    
    return [
        'health' => $health,
        'summary' => [
            'total_issues' => $totalIssues,
            'critical' => $criticalCount,
            'high' => $highCount,
            'medium' => $mediumCount
        ],
        'diagnostics' => $diagnostics,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

// Main execution
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    $results = runDiagnostics();
    
    // Log diagnostic summary
    logMessage(
        "Diagnostics completed: Health = {$results['health']}, " .
        "⚠️ Found {$results['summary']['total_issues']} issue(s) " .
        "({$results['summary']['critical']} critical)",
        $results['health'] === 'ok' ? 'INFO' : 'WARNING'
    );
    
    // Print issues to stdout for Docker logs
    foreach ($results['diagnostics'] as $diagnostic) {
        foreach ($diagnostic['issues'] as $issue) {
            echo "  - [{$issue['level']}] {$issue['message']}" . PHP_EOL;
        }
    }
    
    sendResponse($results);
}
