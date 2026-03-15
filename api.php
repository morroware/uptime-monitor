<?php
/**
 * Uptime Monitor API
 *
 * RESTful API for managing monitors, performing health checks,
 * tracking incidents, and sending alerts via multiple channels.
 *
 * @license MIT
 * @version 2.2.0
 */

// ============================================================================
// PERFORMANCE MONITORING & MEMORY MANAGEMENT
// ============================================================================

$startTime = microtime(true);
$startMemory = memory_get_usage();
ini_set('memory_limit', '256M'); // Increased for safety

// ============================================================================
// HEADERS & CORS CONFIGURATION
// ============================================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ============================================================================
// CONSTANTS & CONFIGURATION
// ============================================================================

define('DATA_FILE', __DIR__ . '/uptime_data/monitors.json');
define('CHECKS_DIR', __DIR__ . '/uptime_data/checks/');
define('CONFIG_FILE', __DIR__ . '/uptime_data/config.ini');
define('ALERTS_LOG', __DIR__ . '/uptime_data/alerts.log');
define('MAINTENANCE_FILE', __DIR__ . '/uptime_data/maintenance.json');
define('INCIDENTS_FILE', __DIR__ . '/uptime_data/incidents.json');
define('MAX_CHECKS_PER_MONITOR', 2880);
define('MAX_SPARKLINE_POINTS', 144);

// Global cache for performance
$dataCache = [
    'monitors' => null,
    'config' => null,
    'maintenance' => null,
    'checks' => [],
    'ssl_cache' => [],
    'cooldown_cache' => []
];

// ============================================================================
// INITIALIZATION
// ============================================================================

if (!file_exists(dirname(DATA_FILE))) {
    mkdir(dirname(DATA_FILE), 0755, true);
}
if (!file_exists(CHECKS_DIR)) {
    mkdir(CHECKS_DIR, 0755, true);
}

function initializeFile($file, $content) {
    if (!file_exists($file)) {
        if (file_put_contents($file, $content) === false) {
            throw new Exception("Failed to initialize: $file");
        }
    }
}

try {
    initializeFile(DATA_FILE, json_encode([]));
    initializeFile(MAINTENANCE_FILE, json_encode([
        'enabled' => false, 'message' => '', 'start_time' => null, 'end_time' => null
    ]));
    initializeFile(INCIDENTS_FILE, json_encode([]));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Initialization failed', 'message' => $e->getMessage()]);
    exit;
}

// Load configuration with caching
function getConfig() {
    global $dataCache;
    if ($dataCache['config'] === null) {
        $dataCache['config'] = file_exists(CONFIG_FILE) ? parse_ini_file(CONFIG_FILE, true) : [];
    }
    return $dataCache['config'];
}

$config = getConfig();

// ============================================================================
// SECURITY CHECKS
// ============================================================================

if (!empty($config['security']['api_key'])) {
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($providedKey !== $config['security']['api_key']) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'message' => 'Invalid or missing API key']);
        exit;
    }
}

if (!empty($config['security']['allowed_ips'])) {
    $allowedIps = array_map('trim', explode(',', $config['security']['allowed_ips']));
    $clientIp = $_SERVER['REMOTE_ADDR'];
    if (!in_array($clientIp, $allowedIps)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden', 'message' => 'IP address not allowed']);
        exit;
    }
}

// ============================================================================
// OPTIMIZED UTILITY FUNCTIONS
// ============================================================================

function isMaintenanceMode() {
    global $dataCache;
    if ($dataCache['maintenance'] === null) {
        if (!file_exists(MAINTENANCE_FILE)) {
            return false;
        }
        $dataCache['maintenance'] = json_decode(file_get_contents(MAINTENANCE_FILE), true);
    }
    
    $maintenance = $dataCache['maintenance'];
    if (!$maintenance['enabled']) {
        return false;
    }
    
    $now = time();
    if ($maintenance['start_time'] && $now < $maintenance['start_time']) {
        return false;
    }
    
    if ($maintenance['end_time'] && $now > $maintenance['end_time']) {
        $maintenance['enabled'] = false;
        file_put_contents(MAINTENANCE_FILE, json_encode($maintenance));
        $dataCache['maintenance'] = $maintenance;
        return false;
    }
    
    return true;
}

function loadMonitors($useCache = true) {
    global $dataCache;
    if ($useCache && $dataCache['monitors'] !== null) {
        return $dataCache['monitors'];
    }
    
    $data = json_decode(file_get_contents(DATA_FILE), true);
    $monitors = $data ?: [];
    
    if ($useCache) {
        $dataCache['monitors'] = $monitors;
    }
    
    return $monitors;
}

function saveMonitors($monitors) {
    global $dataCache;
    $result = file_put_contents(DATA_FILE, json_encode($monitors, JSON_PRETTY_PRINT));
    if ($result !== false) {
        $dataCache['monitors'] = $monitors;
    }
    return $result;
}

function loadChecks($monitorId, $maxChecks = null) {
    global $dataCache;
    
    if (isset($dataCache['checks'][$monitorId])) {
        $checks = $dataCache['checks'][$monitorId];
    } else {
        $file = CHECKS_DIR . $monitorId . '.json';
        if (!file_exists($file)) {
            return [];
        }
        
        $data = json_decode(file_get_contents($file), true);
        $checks = $data ?: [];
        
        // Cache recent checks only to save memory
        $dataCache['checks'][$monitorId] = array_slice($checks, -1000);
    }
    
    if ($maxChecks && count($checks) > $maxChecks) {
        return array_slice($checks, -$maxChecks);
    }
    
    return $checks;
}

function saveChecks($monitorId, $checks) {
    global $dataCache;
    
    $checks = array_slice($checks, -MAX_CHECKS_PER_MONITOR);
    $file = CHECKS_DIR . $monitorId . '.json';
    $result = file_put_contents($file, json_encode($checks, JSON_PRETTY_PRINT));
    
    if ($result !== false) {
        $dataCache['checks'][$monitorId] = array_slice($checks, -1000);
    }
    
    return $result;
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

// ============================================================================
// SSL CERTIFICATE CHECKING - OPTIMIZED
// ============================================================================

function checkSSLCertificate($url) {
    global $dataCache;
    
    $parsed = parse_url($url);
    if (!isset($parsed['scheme']) || $parsed['scheme'] !== 'https') {
        return null;
    }
    
    $host = $parsed['host'];
    $port = isset($parsed['port']) ? $parsed['port'] : 443;
    $cacheKey = "$host:$port";
    
    // Check cache (1 hour TTL)
    if (isset($dataCache['ssl_cache'][$cacheKey])) {
        $cached = $dataCache['ssl_cache'][$cacheKey];
        if (time() - $cached['timestamp'] < 3600) {
            return $cached['data'];
        }
    }
    
    $context = stream_context_create([
        "ssl" => [
            "capture_peer_cert" => true,
            "verify_peer" => false,
            "verify_peer_name" => false,
        ]
    ]);
    
    $stream = @stream_socket_client(
        "ssl://$host:$port", 
        $errno, 
        $errstr, 
        10, 
        STREAM_CLIENT_CONNECT, 
        $context
    );
    
    if (!$stream) {
        return ['error' => 'Could not connect to SSL'];
    }
    
    $params = stream_context_get_params($stream);
    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    
    fclose($stream);
    
    if (!$cert) {
        return ['error' => 'Could not parse certificate'];
    }
    
    $validFrom = $cert['validFrom_time_t'];
    $validTo = $cert['validTo_time_t'];
    $daysRemaining = floor(($validTo - time()) / 86400);
    
    $result = [
        'issuer' => $cert['issuer']['O'] ?? 'Unknown',
        'valid_from' => date('Y-m-d', $validFrom),
        'valid_to' => date('Y-m-d', $validTo),
        'days_remaining' => $daysRemaining,
        'is_valid' => $daysRemaining > 0,
        'is_expiring_soon' => $daysRemaining <= 30 && $daysRemaining > 0,
        'is_expired' => $daysRemaining <= 0
    ];
    
    // Cache result
    $dataCache['ssl_cache'][$cacheKey] = [
        'data' => $result,
        'timestamp' => time()
    ];
    
    return $result;
}

// ============================================================================
// CHART DATA GENERATION - OPTIMIZED
// ============================================================================

function generateChartData($checks, $hours = 24) {
    if (empty($checks)) {
        return [
            'labels' => [],
            'data' => [],
            'sparkline' => [],
            'check_count' => 0
        ];
    }
    
    $cutoffTime = time() - ($hours * 3600);
    
    $recentChecks = array_filter($checks, function($check) use ($cutoffTime) {
        return $check['timestamp'] >= $cutoffTime;
    });
    
    if (empty($recentChecks)) {
        $recentChecks = array_slice($checks, -50);
    }
    
    usort($recentChecks, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });
    
    $labels = [];
    $data = [];
    $sparkline = [];
    
    foreach ($recentChecks as $check) {
        $labels[] = $check['timestamp'] * 1000;
        
        if ($check['status'] === 'up' && isset($check['response_time'])) {
            $data[] = $check['response_time'];
            $sparkline[] = $check['response_time'];
        } else {
            $data[] = null;
            $sparkline[] = 0;
        }
    }
    
    if (count($sparkline) > MAX_SPARKLINE_POINTS) {
        $sparkline = downsampleData($sparkline, MAX_SPARKLINE_POINTS);
    }
    
    return [
        'labels' => $labels,
        'data' => $data,
        'sparkline' => $sparkline,
        'check_count' => count($recentChecks)
    ];
}

function downsampleData($data, $targetPoints) {
    $sourceCount = count($data);
    if ($sourceCount <= $targetPoints) {
        return $data;
    }
    
    $result = [];
    $bucketSize = $sourceCount / $targetPoints;
    
    for ($i = 0; $i < $targetPoints; $i++) {
        $start = (int)($i * $bucketSize);
        $end = (int)(($i + 1) * $bucketSize);
        
        $bucket = array_slice($data, $start, $end - $start);
        $validValues = array_filter($bucket, function($v) { return $v !== null; });
        
        if (!empty($validValues)) {
            $result[] = round(array_sum($validValues) / count($validValues));
        } else {
            $result[] = null;
        }
    }
    
    return $result;
}

// ============================================================================
// NETWORK MONITORING FUNCTIONS - OPTIMIZED
// ============================================================================

function performPingCheck($target, $config = []) {
    $startTime = microtime(true);
    
    $host = str_replace(['http://', 'https://', '/'], '', $target);
    
    $timeout = $config['ping_timeout'] ?? 3;
    $packets = $config['ping_packets'] ?? 3;
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $pingCommand = "ping -n $packets -w " . ($timeout * 1000) . " $host";
        $successPattern = '/TTL=/i';
        $timePattern = '/time[<=](\d+)ms/i';
    } else {
        $pingCommand = "ping -c $packets -W $timeout $host 2>&1";
        $successPattern = '/\d+ bytes from/i';
        $timePattern = '/time=(\d+\.?\d*) ms/i';
    }
    
    $output = shell_exec($pingCommand);
    $responseTime = round((microtime(true) - $startTime) * 1000);
    
    $status = 'down';
    $errorMessage = null;
    $avgPingTime = null;
    $packetLoss = 100;
    
    if ($output) {
        if (preg_match($successPattern, $output)) {
            $status = 'up';
            
            preg_match_all($timePattern, $output, $matches);
            if (!empty($matches[1])) {
                $avgPingTime = round(array_sum($matches[1]) / count($matches[1]), 2);
            }
            
            $receivedPackets = substr_count($output, 'bytes from');
            if ($receivedPackets > 0) {
                $packetLoss = round((($packets - $receivedPackets) / $packets) * 100, 2);
            } else {
                $packetLoss = 0;
            }
            
            if ($packetLoss >= ($config['max_packet_loss'] ?? 50)) {
                $status = 'down';
                $errorMessage = "High packet loss: {$packetLoss}%";
            }
        } else {
            $errorMessage = "Host unreachable";
            
            if (strpos($output, 'Unknown host') !== false || 
                strpos($output, 'cannot resolve') !== false) {
                $errorMessage = "Cannot resolve hostname";
            } elseif (strpos($output, 'Destination Host Unreachable') !== false) {
                $errorMessage = "Destination host unreachable";
            } elseif (strpos($output, 'Request timeout') !== false || 
                      strpos($output, 'Request timed out') !== false) {
                $errorMessage = "Request timed out";
            }
        }
    } else {
        $errorMessage = "Ping command failed";
    }
    
    return [
        'status' => $status,
        'response_time' => $avgPingTime,
        'ping_time' => $avgPingTime,
        'packet_loss' => $packetLoss,
        'raw_output' => $config['debug'] ?? false ? $output : null,
        'error' => $errorMessage,
        'timestamp' => time()
    ];
}

function performPortCheck($target, $port, $config = []) {
    $startTime = microtime(true);
    
    $host = str_replace(['http://', 'https://', '/'], '', $target);
    $timeout = $config['port_timeout'] ?? 5;
    
    $errno = null;
    $errstr = null;
    
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $responseTime = round((microtime(true) - $startTime) * 1000);
    
    if ($fp) {
        fclose($fp);
        return [
            'status' => 'up',
            'response_time' => $responseTime,
            'port' => $port,
            'error' => null,
            'timestamp' => time()
        ];
    } else {
        return [
            'status' => 'down',
            'response_time' => null,
            'port' => $port,
            'error' => $errstr ?: "Port $port is closed or unreachable",
            'error_code' => $errno,
            'timestamp' => time()
        ];
    }
}

function performHTTPCheck($monitor) {
    static $curlHandle = null;
    
    $startTime = microtime(true);
    
    $parsed = parse_url($monitor['target']);
    if (!isset($parsed['scheme'])) {
        $url = 'http://' . $monitor['target'];
    } else {
        $url = $monitor['target'];
    }
    
    // Reuse cURL handle for better performance
    if ($curlHandle === null) {
        $curlHandle = curl_init();
    }
    
    curl_setopt($curlHandle, CURLOPT_URL, $url);
    curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curlHandle, CURLOPT_TIMEOUT, $monitor['config']['timeout'] ?? 10);
    curl_setopt($curlHandle, CURLOPT_MAXREDIRS, $monitor['config']['max_redirects'] ?? 5);
    curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, $monitor['config']['verify_ssl'] ?? false);
    curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, ($monitor['config']['verify_ssl'] ?? false) ? 2 : 0);
    curl_setopt($curlHandle, CURLOPT_HEADER, true);
    
    $method = strtoupper($monitor['config']['check_method'] ?? 'GET');
    if ($method === 'HEAD') {
        curl_setopt($curlHandle, CURLOPT_NOBODY, true);
    } else {
        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curlHandle, CURLOPT_NOBODY, false);
    }
    
    $headers = ['User-Agent: Uptime-Monitor/2.2'];
    if (!empty($monitor['config']['custom_headers'])) {
        foreach ($monitor['config']['custom_headers'] as $key => $value) {
            $headers[] = "$key: $value";
        }
    }
    curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($curlHandle);
    $httpCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
    $error = curl_error($curlHandle);
    $totalTime = curl_getinfo($curlHandle, CURLINFO_TOTAL_TIME);
    
    $responseTime = round($totalTime * 1000);
    
    $sslInfo = null;
    if (($monitor['config']['check_ssl'] ?? true) && strpos($url, 'https://') === 0) {
        $sslInfo = checkSSLCertificate($url);
    }
    
    $status = 'down';
    $errorMessage = null;
    
    if ($error) {
        $errorMessage = $error;
    } else {
        $expectedCodes = $monitor['config']['expected_status_codes'] ?? 
                        [200, 201, 202, 203, 204, 301, 302, 303, 304, 307, 308];
        
        if (in_array($httpCode, $expectedCodes)) {
            $status = 'up';
            
            if (($monitor['config']['keyword_check'] ?? false) && $method !== 'HEAD') {
                $contentValid = validateContent($response, $monitor['config']);
                if (!$contentValid['valid']) {
                    $status = 'down';
                    $errorMessage = $contentValid['error'];
                }
            }
        } else {
            $errorMessage = "Unexpected HTTP status: $httpCode";
        }
    }
    
    return [
        'status' => $status,
        'response_time' => $status === 'up' ? $responseTime : null,
        'http_code' => $httpCode ?: null,
        'error' => $errorMessage,
        'timestamp' => time(),
        'ssl_info' => $sslInfo
    ];
}

// ============================================================================
// MONITORING FUNCTIONS - OPTIMIZED
// ============================================================================

function calculateUptime($checks, $hours = 2160) {
    if (empty($checks)) {
        return 100;
    }
    
    $cutoffTime = time() - ($hours * 3600);
    $relevantChecks = array_filter($checks, function($check) use ($cutoffTime) {
        return $check['timestamp'] >= $cutoffTime;
    });
    
    if (empty($relevantChecks)) {
        $relevantChecks = $checks;
    }
    
    $upCount = 0;
    foreach ($relevantChecks as $check) {
        if ($check['status'] === 'up') {
            $upCount++;
        }
    }
    
    return round(($upCount / count($relevantChecks)) * 100, 2);
}

function getMonitorWithStatus($monitor, $includeFullChart = false) {
    $checks = loadChecks($monitor['id']);
    $lastCheck = !empty($checks) ? end($checks) : null;
    
    $monitor['status'] = $lastCheck ? $lastCheck['status'] : 'unknown';
    $monitor['last_check'] = $lastCheck ? date('c', $lastCheck['timestamp']) : null;
    $monitor['response_time'] = $lastCheck && isset($lastCheck['response_time']) ? 
                                 $lastCheck['response_time'] : null;
    $monitor['uptime'] = calculateUptime($checks);
    $monitor['check_count'] = count($checks);
    
    $chartData = generateChartData($checks, 24);
    $monitor['sparkline'] = $chartData['sparkline'];
    
    if ($includeFullChart) {
        $monitor['chart_data'] = [
            'labels' => $chartData['labels'],
            'data' => $chartData['data'],
            'check_count' => $chartData['check_count']
        ];
        
        $monitor['chart_data_7d'] = generateChartData($checks, 168);
        $monitor['chart_data_30d'] = generateChartData($checks, 720);
    }
    
    if (isset($lastCheck['ssl_info']) && $lastCheck['ssl_info']) {
        $monitor['ssl_info'] = $lastCheck['ssl_info'];
    }
    
    if (!isset($monitor['config'])) {
        $monitor['config'] = [];
    }
    
    return $monitor;
}

function performMonitorCheck($monitor) {
    $checkType = $monitor['config']['check_type'] ?? 'http';
    
    switch ($checkType) {
        case 'ping':
        case 'icmp':
            return performPingCheck($monitor['target'], $monitor['config']);
            
        case 'port':
        case 'tcp':
            $port = $monitor['config']['port'] ?? 80;
            return performPortCheck($monitor['target'], $port, $monitor['config']);
            
        case 'http':
        case 'https':
        default:
            return performHTTPCheck($monitor);
    }
}

function validateContent($content, $config) {
    if (!empty($config['expected_keywords'])) {
        foreach ($config['expected_keywords'] as $keyword) {
            if (stripos($content, $keyword) === false) {
                return [
                    'valid' => false,
                    'error' => "Expected keyword not found: $keyword"
                ];
            }
        }
    }
    
    if (!empty($config['unexpected_keywords'])) {
        foreach ($config['unexpected_keywords'] as $keyword) {
            if (stripos($content, $keyword) !== false) {
                return [
                    'valid' => false,
                    'error' => "Unexpected keyword found: $keyword"
                ];
            }
        }
    }
    
    return ['valid' => true];
}

// ============================================================================
// INCIDENT MANAGEMENT
// ============================================================================

function addIncident($monitor, $status, $error = null) {
    $incidents = json_decode(file_get_contents(INCIDENTS_FILE), true) ?: [];
    
    $existingIndex = null;
    foreach ($incidents as $index => $incident) {
        if ($incident['monitor_id'] === $monitor['id'] && $incident['status'] === 'open') {
            $existingIndex = $index;
            break;
        }
    }
    
    if ($status === 'down' && $existingIndex === null) {
        $incident = [
            'id' => uniqid(),
            'monitor_id' => $monitor['id'],
            'title' => $monitor['name'] . ' Outage',
            'description' => 'Service is experiencing downtime' . ($error ? ': ' . $error : ''),
            'status' => 'open',
            'time' => time(),
            'resolved_time' => null
        ];
        array_unshift($incidents, $incident);
    } 
    elseif ($status === 'up' && $existingIndex !== null) {
        $incidents[$existingIndex]['status'] = 'resolved';
        $incidents[$existingIndex]['resolved_time'] = time();
    }
    
    $incidents = array_slice($incidents, 0, 50);
    file_put_contents(INCIDENTS_FILE, json_encode($incidents, JSON_PRETTY_PRINT));
}

// ============================================================================
// ALERT FUNCTIONS - OPTIMIZED
// ============================================================================

function sendMonitorAlerts($monitor, $status, $previousStatus, $error = null) {
    global $config;
    
    if (!($monitor['config']['alerts_enabled'] ?? true)) {
        return;
    }
    
    if (isMaintenanceMode()) {
        return;
    }
    
    if ($status === $previousStatus) {
        return;
    }
    
    if (!checkMonitorCooldown($monitor['id'], $monitor['config']['alert_cooldown'] ?? 30)) {
        return;
    }
    
    if (!empty($config['slack_bot']['bot_token'])) {
        sendSlackBotAlert($monitor, $status, $previousStatus, $error);
    }
    
    if (($config['email']['enabled'] ?? false) && !empty($config['email']['recipients'])) {
        sendEmailAlert($monitor, $status, $previousStatus, $error);
    }
    
    if (($config['textbee']['enabled'] ?? false)) {
        sendTextBeeSMS($monitor, $status, $previousStatus, $error);
    }
}

function checkMonitorCooldown($monitorId, $cooldownMinutes) {
    global $dataCache;
    
    $cacheKey = $monitorId;
    if (isset($dataCache['cooldown_cache'][$cacheKey])) {
        $lastAlert = $dataCache['cooldown_cache'][$cacheKey];
    } else {
        $cooldownFile = CHECKS_DIR . 'cooldown_' . $monitorId . '.txt';
        $lastAlert = file_exists($cooldownFile) ? (int)file_get_contents($cooldownFile) : 0;
        $dataCache['cooldown_cache'][$cacheKey] = $lastAlert;
    }
    
    $cooldownSeconds = $cooldownMinutes * 60;
    if (time() - $lastAlert < $cooldownSeconds) {
        return false;
    }
    
    $now = time();
    $cooldownFile = CHECKS_DIR . 'cooldown_' . $monitorId . '.txt';
    file_put_contents($cooldownFile, $now);
    $dataCache['cooldown_cache'][$cacheKey] = $now;
    
    return true;
}

function logAlert($type, $monitor, $status, $success, $details = '') {
    $log = date('Y-m-d H:i:s') . " | $type | $monitor | $status | " . 
           ($success ? 'SUCCESS' : 'FAILED') . 
           ($details ? " | $details" : '') . "\n";
    file_put_contents(ALERTS_LOG, $log, FILE_APPEND);
}

function sendSlackBotAlert($monitor, $status, $previousStatus, $error = null) {
    global $config;
    
    if (empty($config['slack_bot']['bot_token']) || empty($config['slack_bot']['channel'])) {
        return;
    }
    
    $emoji = $status === 'up' ? ':white_check_mark:' : ':rotating_light:';
    
    $blocks = [
        [
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => $emoji . ' Monitor ' . ($status === 'up' ? 'Recovered' : 'Alert'),
                'emoji' => true
            ]
        ],
        [
            'type' => 'section',
            'fields' => [
                [
                    'type' => 'mrkdwn',
                    'text' => "*Monitor:*\n" . $monitor['name']
                ],
                [
                    'type' => 'mrkdwn',
                    'text' => "*Status:*\n" . ucfirst($status) . " (was " . $previousStatus . ")"
                ],
                [
                    'type' => 'mrkdwn',
                    'text' => "*URL:*\n<" . $monitor['target'] . "|" . $monitor['target'] . ">"
                ],
                [
                    'type' => 'mrkdwn',
                    'text' => "*Uptime:*\n" . $monitor['uptime'] . "%"
                ]
            ]
        ]
    ];
    
    if ($error) {
        $blocks[] = [
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => "*Error:*\n```" . $error . "```"
            ]
        ];
    }
    
    $payload = [
        'channel' => $config['slack_bot']['channel'],
        'blocks' => $blocks
    ];
    
    $ch = curl_init('https://slack.com/api/chat.postMessage');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['slack_bot']['bot_token']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    logAlert('SlackBot', $monitor['name'], $status, $response !== false);
}

function sendEmailAlert($monitor, $status, $previousStatus, $error = null) {
    global $config;
    
    if (!($config['email']['enabled'] ?? false) || empty($config['email']['recipients'])) {
        return;
    }
    
    $subject = 'Uptime Monitor: ' . $monitor['name'] . ' is ' . $status;
    
    $message = "Monitor Status Change\n\n";
    $message .= "Monitor: " . $monitor['name'] . "\n";
    $message .= "URL: " . $monitor['target'] . "\n";
    $message .= "Status: " . ucfirst($status) . " (was " . $previousStatus . ")\n";
    $message .= "Time: " . date('Y-m-d H:i:s') . "\n";
    $message .= "Uptime: " . $monitor['uptime'] . "%\n";
    
    if ($error) {
        $message .= "Error: " . $error . "\n";
    }
    
    $headers = 'From: ' . ($config['email']['from_name'] ?? 'Uptime Monitor') . 
               ' <' . ($config['email']['from_email'] ?? 'noreply@' . $_SERVER['HTTP_HOST']) . '>';
    
    $recipients = $config['email']['recipients'];
    $success = mail($recipients, $subject, $message, $headers);
    
    logAlert('Email', $monitor['name'], $status, $success);
}

function sendTextBeeSMS($monitor, $status, $previousStatus, $error = null) {
    global $config;
    
    if (!($config['textbee']['enabled'] ?? false) || 
        empty($config['textbee']['api_key']) || 
        empty($config['textbee']['device_id'])) {
        return false;
    }
    
    if (($config['textbee']['high_priority_only'] ?? false) && 
        ($monitor['config']['priority'] ?? 'normal') !== 'high') {
        return false;
    }
    
    $recipients = [];
    
    if (!empty($monitor['config']['custom_sms_recipients'])) {
        $customRecipients = $monitor['config']['custom_sms_recipients'];
        if (is_string($customRecipients)) {
            $recipients = array_map('trim', explode(',', $customRecipients));
        } else {
            $recipients = $customRecipients;
        }
    }
    
    if (empty($recipients) && !empty($config['textbee']['recipients'])) {
        $recipients = array_map('trim', explode(',', $config['textbee']['recipients']));
    }
    
    if (empty($recipients)) {
        logAlert('TextBee', $monitor['name'], $status, false, 'No recipients configured');
        return false;
    }
    
    $emoji = $status === 'up' ? '✅' : '🚨';
    $message = $emoji . ' ' . $monitor['name'] . ' is ' . strtoupper($status);
    
    $message .= ' (was ' . $previousStatus . ')';
    
    if ($config['textbee']['include_url'] ?? true) {
        $message .= "\nURL: " . $monitor['target'];
    }
    
    $message .= "\nUptime: " . $monitor['uptime'] . '%';
    
    if ($error && $status === 'down') {
        $truncatedError = substr($error, 0, 50);
        if (strlen($error) > 50) {
            $truncatedError .= '...';
        }
        $message .= "\nError: " . $truncatedError;
    }
    
    if ($status === 'down' && !empty($monitor['config']['custom_down_message'])) {
        $message .= "\n" . $monitor['config']['custom_down_message'];
    } elseif ($status === 'up' && !empty($monitor['config']['custom_recovery_message'])) {
        $message .= "\n" . $monitor['config']['custom_recovery_message'];
    }
    
    $url = "https://api.textbee.dev/api/v1/gateway/devices/{$config['textbee']['device_id']}/send-sms";
    
    $payload = [
        'recipients' => $recipients,
        'message' => $message
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $config['textbee']['api_key']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $success = $httpCode === 200 || $httpCode === 201;
    $details = $success ? 'Sent to ' . count($recipients) . ' recipients' : 
             'HTTP ' . $httpCode . ' - ' . $curlError;
    
    logAlert('TextBee', $monitor['name'], $status, $success, $details);
    
    return $success;
}

// ============================================================================
// API ROUTE HANDLERS - ALL ORIGINAL ENDPOINTS
// ============================================================================

$method = $_SERVER['REQUEST_METHOD'];

// Support method override for servers that don't handle DELETE/PUT properly
if ($method === 'POST' && !empty($_GET['_method'])) {
    $override = strtoupper($_GET['_method']);
    if (in_array($override, ['DELETE', 'PUT'])) {
        $method = $override;
    }
}

$path = isset($_GET['path']) ? $_GET['path'] : '';

/**
 * GET /monitors - Get all monitors with optional chart data
 */
if ($method === 'GET' && $path === 'monitors') {
    $includeChart = isset($_GET['include_chart']) && $_GET['include_chart'] === 'true';
    
    $monitors = loadMonitors();
    $result = [];
    
    foreach ($monitors as $monitor) {
        $monitorWithStatus = getMonitorWithStatus($monitor, $includeChart);
        $result[] = $monitorWithStatus;
    }
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

/**
 * GET /monitors/{id} - Get single monitor with full configuration
 */
if ($method === 'GET' && preg_match('/monitors\/([^\/]+)$/', $path, $matches)) {
    $monitorId = $matches[1];
    $includeChart = isset($_GET['include_chart']) && $_GET['include_chart'] === 'true';
    $monitors = loadMonitors();
    
    foreach ($monitors as $monitor) {
        if ($monitor['id'] === $monitorId) {
            echo json_encode(getMonitorWithStatus($monitor, $includeChart));
            exit;
        }
    }
    
    http_response_code(404);
    echo json_encode(['error' => 'Monitor not found']);
    exit;
}

/**
 * POST /monitors - Add new monitor with configuration
 */
if ($method === 'POST' && $path === 'monitors') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['target'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Target is required']);
        exit;
    }
    
    $monitor = [
        'id' => uniqid(),
        'target' => $input['target'],
        'interval' => isset($input['interval']) ? intval($input['interval']) : 300,
        'name' => isset($input['name']) ? $input['name'] : $input['target'],
        'created_at' => time(),
        'updated_at' => time(),
        'config' => [
            'check_type' => $input['config']['check_type'] ?? 'http',
            'port' => $input['config']['port'] ?? null,
            'ping_timeout' => $input['config']['ping_timeout'] ?? 3,
            'ping_packets' => $input['config']['ping_packets'] ?? 3,
            'max_packet_loss' => $input['config']['max_packet_loss'] ?? 50,
            'port_timeout' => $input['config']['port_timeout'] ?? 5,
            'check_ssl' => $input['config']['check_ssl'] ?? true,
            'ssl_alert_days' => $input['config']['ssl_alert_days'] ?? 30,
            'alerts_enabled' => $input['config']['alerts_enabled'] ?? true,
            'alert_on_down' => $input['config']['alert_on_down'] ?? true,
            'alert_on_recovery' => $input['config']['alert_on_recovery'] ?? true,
            'alert_on_ssl_expiry' => $input['config']['alert_on_ssl_expiry'] ?? true,
            'alert_cooldown' => $input['config']['alert_cooldown'] ?? 30,
            'custom_slack_channel' => $input['config']['custom_slack_channel'] ?? '',
            'custom_email_recipients' => $input['config']['custom_email_recipients'] ?? '',
            'custom_sms_recipients' => $input['config']['custom_sms_recipients'] ?? '',
            'custom_webhook_url' => $input['config']['custom_webhook_url'] ?? '',
            'timeout' => $input['config']['timeout'] ?? 10,
            'max_redirects' => $input['config']['max_redirects'] ?? 5,
            'verify_ssl' => $input['config']['verify_ssl'] ?? false,
            'check_method' => $input['config']['check_method'] ?? 'GET',
            'expected_status_codes' => $input['config']['expected_status_codes'] ?? 
                                       [200, 201, 202, 203, 204, 301, 302, 303, 304, 307, 308],
            'custom_headers' => $input['config']['custom_headers'] ?? [],
            'keyword_check' => $input['config']['keyword_check'] ?? false,
            'expected_keywords' => $input['config']['expected_keywords'] ?? [],
            'unexpected_keywords' => $input['config']['unexpected_keywords'] ?? [],
            'priority' => $input['config']['priority'] ?? 'normal',
            'custom_down_message' => $input['config']['custom_down_message'] ?? '',
            'custom_recovery_message' => $input['config']['custom_recovery_message'] ?? ''
        ]
    ];
    
    $monitors = loadMonitors();
    $monitors[] = $monitor;
    saveMonitors($monitors);
    
    $check = performMonitorCheck($monitor);
    saveChecks($monitor['id'], [$check]);
    
    echo json_encode(getMonitorWithStatus($monitor));
    exit;
}

/**
 * PUT /monitors/{id} - Update existing monitor
 */
if ($method === 'PUT' && preg_match('/monitors\/([^\/]+)$/', $path, $matches)) {
    $monitorId = $matches[1];
    $input = json_decode(file_get_contents('php://input'), true);
    
    $monitors = loadMonitors();
    $found = false;
    
   foreach ($monitors as $key => $monitor) {
    if ($monitor['id'] === $monitorId) {
        $updatedMonitor = array_merge($monitor, [
            'target' => $input['target'] ?? $monitor['target'],
            'name' => $input['name'] ?? $monitor['name'],
            'interval' => isset($input['interval']) ? intval($input['interval']) : $monitor['interval'],
            'updated_at' => time()
        ]);
        
        if (isset($input['config'])) {
            $updatedMonitor['config'] = array_merge(
                $monitor['config'] ?? [],
                $input['config']
            );
        }
        
        $monitors[$key] = $updatedMonitor;
        $found = true;
        
        saveMonitors($monitors);
        echo json_encode(getMonitorWithStatus($updatedMonitor));
        break;
    }
}

if (!$found) {
    http_response_code(404);
    echo json_encode(['error' => 'Monitor not found']);
}
exit;
}

/**
 * DELETE /monitors/{id} - Delete monitor
 */
if ($method === 'DELETE' && preg_match('/monitors\/([^\/]+)$/', $path, $matches)) {
    $monitorId = $matches[1];
    $monitors = loadMonitors();
    
    $found = false;
    foreach ($monitors as $key => $monitor) {
        if ($monitor['id'] === $monitorId) {
            unset($monitors[$key]);
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        http_response_code(404);
        echo json_encode(['error' => 'Monitor not found']);
        exit;
    }
    
    saveMonitors(array_values($monitors));
    
    $checksFile = CHECKS_DIR . $monitorId . '.json';
    if (file_exists($checksFile)) {
        unlink($checksFile);
    }
    
    $cooldownFile = CHECKS_DIR . 'cooldown_' . $monitorId . '.txt';
    if (file_exists($cooldownFile)) {
        unlink($cooldownFile);
    }
    
    http_response_code(204);
    exit;
}

/**
 * POST /monitors/{id}/check - Trigger immediate check
 */
if ($method === 'POST' && preg_match('/monitors\/([^\/]+)\/check/', $path, $matches)) {
    $monitorId = $matches[1];
    $monitors = loadMonitors();
    
    $found = null;
    foreach ($monitors as $monitor) {
        if ($monitor['id'] === $monitorId) {
            $found = $monitor;
            break;
        }
    }
    
    if (!$found) {
        http_response_code(404);
        echo json_encode(['error' => 'Monitor not found']);
        exit;
    }
    
    $checks = loadChecks($monitorId);
    $previousStatus = !empty($checks) ? end($checks)['status'] : 'unknown';
    
    $check = performMonitorCheck($found);
    $checks[] = $check;
    saveChecks($monitorId, $checks);
    
    $updatedMonitor = getMonitorWithStatus($found);
    
    addIncident($updatedMonitor, $check['status'], $check['error'] ?? null);
    sendMonitorAlerts($updatedMonitor, $check['status'], $previousStatus, $check['error'] ?? null);
    
    echo json_encode($updatedMonitor);
    exit;
}

/**
 * POST /monitors/{id}/test-alert - Test monitor-specific alerts
 */
if ($method === 'POST' && preg_match('/monitors\/([^\/]+)\/test-alert/', $path, $matches)) {
    $monitorId = $matches[1];
    $monitors = loadMonitors();
    
    $found = null;
    foreach ($monitors as $monitor) {
        if ($monitor['id'] === $monitorId) {
            $found = $monitor;
            break;
        }
    }
    
    if (!$found) {
        http_response_code(404);
        echo json_encode(['error' => 'Monitor not found']);
        exit;
    }
    
    $testMonitor = getMonitorWithStatus($found);
    $testMonitor['response_time'] = 123;
    $testMonitor['uptime'] = 99.5;
    
    $cooldownFile = CHECKS_DIR . 'cooldown_' . $monitorId . '.txt';
    if (file_exists($cooldownFile)) {
        unlink($cooldownFile);
    }
    
    sendMonitorAlerts($testMonitor, 'down', 'up', 'Test alert - This is a test message');
    
    echo json_encode(['success' => true, 'message' => 'Test alert sent']);
    exit;
}

/**
 * GET /stats - Get statistics
 */
if ($method === 'GET' && $path === 'stats') {
    $monitors = loadMonitors();
    $total = count($monitors);
    $up = 0;
    $down = 0;
    $totalUptime = 0;
    $sslWarnings = 0;
    
    foreach ($monitors as $monitor) {
        $monitor = getMonitorWithStatus($monitor);
        if ($monitor['status'] === 'up') {
            $up++;
        } elseif ($monitor['status'] === 'down') {
            $down++;
        }
        $totalUptime += $monitor['uptime'];
        
        if (isset($monitor['ssl_info']) && $monitor['ssl_info']['is_expiring_soon']) {
            $sslWarnings++;
        }
    }
    
    $avgUptime = $total > 0 ? round($totalUptime / $total, 2) : 0;
    
    echo json_encode([
        'total' => $total,
        'up' => $up,
        'down' => $down,
        'avg_uptime' => $avgUptime,
        'ssl_warnings' => $sslWarnings
    ]);
    exit;
}

/**
 * POST /check-all - Check all monitors (called by cron)
 */
if ($method === 'POST' && $path === 'check-all') {
    if (isMaintenanceMode()) {
        echo json_encode([
            'checked' => 0,
            'maintenance' => true,
            'message' => 'Monitoring paused due to maintenance mode'
        ]);
        exit;
    }
    
    $monitors = loadMonitors();
    $currentTime = time();
    $checked = 0;
    $errors = [];
    
    foreach ($monitors as $monitor) {
        try {
            $checks = loadChecks($monitor['id']);
            $lastCheck = !empty($checks) ? end($checks) : null;
            $lastCheckTime = $lastCheck ? $lastCheck['timestamp'] : 0;
            $previousStatus = $lastCheck ? $lastCheck['status'] : 'unknown';
            
            if ($currentTime - $lastCheckTime >= $monitor['interval']) {
                $check = performMonitorCheck($monitor);
                
                if (!isset($check['timestamp'])) {
                    $check['timestamp'] = $currentTime;
                }
                if (!isset($check['status'])) {
                    $check['status'] = 'unknown';
                }
                
                $checks[] = $check;
                saveChecks($monitor['id'], $checks);
                
                $updatedMonitor = getMonitorWithStatus($monitor, false);
                
                if ($check['status'] !== $previousStatus) {
                    addIncident($updatedMonitor, $check['status'], $check['error'] ?? null);
                }
                
                sendMonitorAlerts($updatedMonitor, $check['status'], $previousStatus, $check['error'] ?? null);
                
                $checked++;
            }
        } catch (Exception $e) {
            $errors[] = "Error checking {$monitor['name']}: " . $e->getMessage();
        }
    }
    
    $response = [
        'checked' => $checked,
        'total' => count($monitors),
        'timestamp' => date('c'),
        'execution_time' => round(microtime(true) - $startTime, 2)
    ];
    
    if (!empty($errors)) {
        $response['errors'] = $errors;
    }
    
    echo json_encode($response);
    exit;
}

/**
 * POST /quick-check - Quick check if a device/IP is online
 */
if ($method === 'POST' && $path === 'quick-check') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['target'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Target IP or hostname required']);
        exit;
    }
    
    $checkType = $input['type'] ?? 'ping';
    $result = [];
    
    switch ($checkType) {
        case 'ping':
            $result = performPingCheck($input['target'], $input['config'] ?? []);
            break;
            
        case 'port':
            $port = $input['port'] ?? 80;
            $result = performPortCheck($input['target'], $port, $input['config'] ?? []);
            break;
            
        case 'http':
            $monitor = [
                'target' => $input['target'],
                'config' => $input['config'] ?? []
            ];
            $result = performHTTPCheck($monitor);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid check type. Use: ping, port, or http']);
            exit;
    }
    
    echo json_encode($result);
    exit;
}

/**
 * POST /network-scan - Scan a range of IPs or list of devices
 */
if ($method === 'POST' && $path === 'network-scan') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['targets']) || !is_array($input['targets'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Targets array required']);
        exit;
    }
    
    $results = [];
    $checkType = $input['type'] ?? 'ping';
    
    foreach ($input['targets'] as $target) {
        $targetInfo = is_array($target) ? $target : ['host' => $target];
        $host = $targetInfo['host'] ?? $target;
        $name = $targetInfo['name'] ?? $host;
        
        switch ($checkType) {
            case 'ping':
                $check = performPingCheck($host, $input['config'] ?? []);
                break;
                
            case 'port':
                $port = $targetInfo['port'] ?? $input['default_port'] ?? 80;
                $check = performPortCheck($host, $port, $input['config'] ?? []);
                break;
                
            default:
                $check = ['status' => 'error', 'error' => 'Invalid check type'];
        }
        
        $results[] = [
            'name' => $name,
            'host' => $host,
            'status' => $check['status'],
            'response_time' => $check['response_time'] ?? null,
            'error' => $check['error'] ?? null
        ];
    }
    
    $up = count(array_filter($results, function($r) { return $r['status'] === 'up'; }));
    $down = count($results) - $up;
    
    echo json_encode([
        'summary' => [
            'total' => count($results),
            'up' => $up,
            'down' => $down
        ],
        'results' => $results,
        'timestamp' => date('c')
    ]);
    exit;
}

/**
 * GET /config - Get non-sensitive config status
 */
if ($method === 'GET' && $path === 'config') {
    $status = [
        'alerts_enabled' => $config['general']['alerts_enabled'] ?? true,
        'slack_bot_configured' => !empty($config['slack_bot']['bot_token']),
        'slack_webhook_configured' => !empty($config['slack']['webhook_url']),
        'webhook_configured' => !empty($config['custom_webhook']['url']),
        'email_configured' => ($config['email']['enabled'] ?? false) && !empty($config['email']['recipients']),
        'textbee_sms_configured' => ($config['textbee']['enabled'] ?? false) && 
                                  !empty($config['textbee']['api_key']) && 
                                  !empty($config['textbee']['device_id']),
        'security_enabled' => !empty($config['security']['api_key']),
        'maintenance_mode' => isMaintenanceMode()
    ];
    echo json_encode($status);
    exit;
}

/**
 * PUT /config - Save configuration
 */
if ($method === 'PUT' && $path === 'config') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        exit;
    }
    
    $iniContent = '';
    foreach ($input as $section => $values) {
        $iniContent .= "[$section]\n";
        foreach ($values as $key => $value) {
            if (is_string($value) && (strpos($value, ' ') !== false || strpos($value, '#') !== false)) {
                $value = '"' . str_replace('"', '\"', $value) . '"';
            }
            $iniContent .= "$key = $value\n";
        }
        $iniContent .= "\n";
    }
    
    $success = file_put_contents(CONFIG_FILE, $iniContent);
    
    if ($success === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to write configuration file']);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => 'Configuration saved successfully']);
    exit;
}

/**
 * GET /maintenance - Get maintenance mode status
 */
if ($method === 'GET' && $path === 'maintenance') {
    $maintenance = json_decode(file_get_contents(MAINTENANCE_FILE), true);
    echo json_encode($maintenance);
    exit;
}

/**
 * POST /maintenance - Set maintenance mode
 */
if ($method === 'POST' && $path === 'maintenance') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $maintenance = [
        'enabled' => $input['enabled'] ?? false,
        'message' => $input['message'] ?? '',
        'start_time' => $input['start_time'] ?? null,
        'end_time' => $input['end_time'] ?? null
    ];
    
    file_put_contents(MAINTENANCE_FILE, json_encode($maintenance));
    echo json_encode(['success' => true, 'maintenance' => $maintenance]);
    exit;
}

/**
 * GET /incidents - Get recent incidents
 */
if ($method === 'GET' && $path === 'incidents') {
    $incidents = json_decode(file_get_contents(INCIDENTS_FILE), true) ?: [];
    echo json_encode($incidents);
    exit;
}

/**
 * GET /logs - Get alert logs
 */
if ($method === 'GET' && $path === 'logs') {
    if (!file_exists(ALERTS_LOG)) {
        echo json_encode(['logs' => '', 'size' => 0]);
        exit;
    }
    
    $logs = file_get_contents(ALERTS_LOG);
    $size = filesize(ALERTS_LOG);
    
    echo json_encode([
        'logs' => $logs,
        'size' => $size,
        'lines' => substr_count($logs, "\n")
    ]);
    exit;
}

/**
 * DELETE /logs - Clear alert logs
 */
if ($method === 'DELETE' && $path === 'logs') {
    if (file_exists(ALERTS_LOG)) {
        file_put_contents(ALERTS_LOG, '');
    }
    
    echo json_encode(['success' => true, 'message' => 'Alert logs cleared']);
    exit;
}

/**
 * DELETE /monitors/{id}/checks - Clear all checks for a monitor
 */
if ($method === 'DELETE' && preg_match('/monitors\/([^\/]+)\/checks$/', $path, $matches)) {
    $monitorId = $matches[1];
    $checksFile = CHECKS_DIR . $monitorId . '.json';
    
    if (file_exists($checksFile)) {
        unlink($checksFile);
    }
    
    echo json_encode(['success' => true, 'message' => 'All checks cleared for monitor']);
    exit;
}

/**
 * DELETE /monitors/{id}/checks/old - Clear old checks (>90 days)
 */
if ($method === 'DELETE' && preg_match('/monitors\/([^\/]+)\/checks\/old$/', $path, $matches)) {
    $monitorId = $matches[1];
    $checks = loadChecks($monitorId);
    
    $cutoffTime = time() - (90 * 24 * 3600);
    $recentChecks = array_filter($checks, function($check) use ($cutoffTime) {
        return $check['timestamp'] >= $cutoffTime;
    });
    
    $removed = count($checks) - count($recentChecks);
    saveChecks($monitorId, array_values($recentChecks));
    
    echo json_encode([
        'success' => true, 
        'message' => "Removed $removed old check records",
        'remaining' => count($recentChecks)
    ]);
    exit;
}

/**
 * POST /cleanup - Optimize storage and clean up old data
 */
if ($method === 'POST' && $path === 'cleanup') {
    $results = [];
    $totalRemoved = 0;
    
    $monitors = loadMonitors();
    foreach ($monitors as $monitor) {
        $checks = loadChecks($monitor['id']);
        $originalCount = count($checks);
        
        if ($originalCount > MAX_CHECKS_PER_MONITOR) {
            $checks = array_slice($checks, -MAX_CHECKS_PER_MONITOR);
            saveChecks($monitor['id'], $checks);
            $removed = $originalCount - count($checks);
            $totalRemoved += $removed;
            
            if ($removed > 0) {
                $results[] = "Monitor '{$monitor['name']}': Removed $removed old checks";
            }
        }
    }
    
    $checkFiles = glob(CHECKS_DIR . '*.json');
    $emptyFiles = 0;
    foreach ($checkFiles as $file) {
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        if (empty($data)) {
            unlink($file);
            $emptyFiles++;
        }
    }
    
    $cooldownFiles = glob(CHECKS_DIR . 'cooldown_*.txt');
    $oldCooldowns = 0;
    $oneDayAgo = time() - 86400;
    foreach ($cooldownFiles as $file) {
        if (filemtime($file) < $oneDayAgo) {
            unlink($file);
            $oldCooldowns++;
        }
    }
    
    if ($emptyFiles > 0) {
        $results[] = "Removed $emptyFiles empty check files";
    }
    if ($oldCooldowns > 0) {
        $results[] = "Removed $oldCooldowns old cooldown files";
    }
    
    echo json_encode([
        'success' => true,
        'total_checks_removed' => $totalRemoved,
        'empty_files_removed' => $emptyFiles,
        'old_cooldowns_removed' => $oldCooldowns,
        'details' => $results,
        'message' => 'Storage optimization completed'
    ]);
    exit;
}

/**
 * GET /storage-stats - Get detailed storage statistics
 */
if ($method === 'GET' && $path === 'storage-stats') {
    $monitors = loadMonitors();
    $stats = [
        'monitors_count' => count($monitors),
        'total_checks' => 0,
        'storage_size_bytes' => 0,
        'oldest_check' => null,
        'newest_check' => null,
        'monitor_details' => []
    ];
    
    $stats['storage_size_bytes'] += filesize(DATA_FILE);
    if (file_exists(ALERTS_LOG)) {
        $stats['storage_size_bytes'] += filesize(ALERTS_LOG);
    }
    if (file_exists(MAINTENANCE_FILE)) {
        $stats['storage_size_bytes'] += filesize(MAINTENANCE_FILE);
    }
    if (file_exists(INCIDENTS_FILE)) {
        $stats['storage_size_bytes'] += filesize(INCIDENTS_FILE);
    }
    
    foreach ($monitors as $monitor) {
        $checks = loadChecks($monitor['id']);
        $checksCount = count($checks);
        $stats['total_checks'] += $checksCount;
        
        $checksFile = CHECKS_DIR . $monitor['id'] . '.json';
        $fileSize = file_exists($checksFile) ? filesize($checksFile) : 0;
        $stats['storage_size_bytes'] += $fileSize;
        
        if (!empty($checks)) {
            $firstCheck = $checks[0]['timestamp'];
            $lastCheck = end($checks)['timestamp'];
            
            if ($stats['oldest_check'] === null || $firstCheck < $stats['oldest_check']) {
                $stats['oldest_check'] = $firstCheck;
            }
            if ($stats['newest_check'] === null || $lastCheck > $stats['newest_check']) {
                $stats['newest_check'] = $lastCheck;
            }
        }
        
        $stats['monitor_details'][] = [
            'id' => $monitor['id'],
            'name' => $monitor['name'],
            'checks_count' => $checksCount,
            'file_size_bytes' => $fileSize,
            'oldest_check' => !empty($checks) ? $checks[0]['timestamp'] : null,
            'newest_check' => !empty($checks) ? end($checks)['timestamp'] : null
        ];
    }
    
    if ($stats['oldest_check']) {
        $stats['oldest_check_formatted'] = date('Y-m-d H:i:s', $stats['oldest_check']);
    }
    if ($stats['newest_check']) {
        $stats['newest_check_formatted'] = date('Y-m-d H:i:s', $stats['newest_check']);
    }
    
    $stats['storage_size_kb'] = round($stats['storage_size_bytes'] / 1024, 2);
    $stats['storage_size_mb'] = round($stats['storage_size_bytes'] / 1024 / 1024, 2);
    
    echo json_encode($stats);
    exit;
}

/**
 * POST /reset-data - Reset all data (dangerous operation)
 */
if ($method === 'POST' && $path === 'reset-data') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['confirmation']) || $input['confirmation'] !== 'DELETE ALL DATA') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid confirmation']);
        exit;
    }
    
    try {
        file_put_contents(DATA_FILE, json_encode([]));
        
        $checkFiles = glob(CHECKS_DIR . '*.json');
        foreach ($checkFiles as $file) {
            unlink($file);
        }
        
        $cooldownFiles = glob(CHECKS_DIR . 'cooldown_*.txt');
        foreach ($cooldownFiles as $file) {
            unlink($file);
        }
        
        if (file_exists(ALERTS_LOG)) {
            file_put_contents(ALERTS_LOG, '');
        }
        
        file_put_contents(MAINTENANCE_FILE, json_encode([
            'enabled' => false,
            'message' => '',
            'start_time' => null,
            'end_time' => null
        ]));
        
        file_put_contents(INCIDENTS_FILE, json_encode([]));
        
        echo json_encode([
            'success' => true,
            'message' => 'All data has been reset',
            'timestamp' => date('c')
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to reset data: ' . $e->getMessage()]);
    }
    exit;
}

/**
 * GET /debug - Debug endpoint for troubleshooting
 */
if ($method === 'GET' && $path === 'debug') {
    $monitors = loadMonitors();
    $debugInfo = [];
    
    foreach ($monitors as $monitor) {
        $checks = loadChecks($monitor['id']);
        $lastCheck = !empty($checks) ? end($checks) : null;
        
        $debugInfo[] = [
            'name' => $monitor['name'],
            'type' => $monitor['config']['check_type'] ?? 'http',
            'last_check' => $lastCheck ? date('Y-m-d H:i:s', $lastCheck['timestamp']) : 'Never',
            'checks_count' => count($checks),
            'interval' => $monitor['interval'] . ' seconds',
            'should_check' => $lastCheck ? 
                (time() - $lastCheck['timestamp'] >= $monitor['interval'] ? 'YES' : 'NO') : 
                'YES',
            'time_until_next' => $lastCheck ? 
                max(0, $monitor['interval'] - (time() - $lastCheck['timestamp'])) . ' seconds' : 
                'Now'
        ];
    }
    
    echo json_encode([
        'api_version' => '2.2.0',
        'current_time' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get(),
        'performance' => [
            'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'memory_usage' => formatBytes(memory_get_usage() - $startMemory),
            'memory_peak' => formatBytes(memory_get_peak_usage()),
            'cache_entries' => count($dataCache['checks'])
        ],
        'monitors' => $debugInfo,
        'data_dir_writable' => is_writable(dirname(DATA_FILE)),
        'checks_dir_writable' => is_writable(CHECKS_DIR),
        'maintenance_mode' => isMaintenanceMode(),
        'php_version' => PHP_VERSION,
        'max_execution_time' => ini_get('max_execution_time'),
        'memory_limit' => ini_get('memory_limit'),
        'ping_available' => function_exists('shell_exec')
    ], JSON_PRETTY_PRINT);
    exit;
}

/**
 * GET /debug/checks - Debug endpoint for monitor checks
 */
if ($method === 'GET' && $path === 'debug/checks') {
    $monitorId = $_GET['monitor_id'] ?? null;
    
    if (!$monitorId) {
        $monitors = loadMonitors();
        $summary = [];
        
        foreach ($monitors as $monitor) {
            $checks = loadChecks($monitor['id']);
            $recent = array_slice($checks, -5);
            
            $summary[$monitor['name']] = [
                'total_checks' => count($checks),
                'recent_checks' => array_map(function($check) {
                    return [
                        'time' => date('Y-m-d H:i:s', $check['timestamp']),
                        'status' => $check['status'],
                        'response_time' => $check['response_time'] ?? null
                    ];
                }, $recent)
            ];
        }
        
        echo json_encode($summary, JSON_PRETTY_PRINT);
    } else {
        $checks = loadChecks($monitorId);
        $recent = array_slice($checks, -10);
        
        echo json_encode([
            'monitor_id' => $monitorId,
            'total_checks' => count($checks),
            'recent_checks' => array_map(function($check) {
                return [
                    'time' => date('Y-m-d H:i:s', $check['timestamp']),
                    'status' => $check['status'],
                    'response_time' => $check['response_time'] ?? null,
                    'http_code' => $check['http_code'] ?? null,
                    'error' => $check['error'] ?? null,
                    'ssl_days' => isset($check['ssl_info']) ? 
                                  $check['ssl_info']['days_remaining'] ?? null : null
                ];
            }, $recent)
        ], JSON_PRETTY_PRINT);
    }
    exit;
}

/**
 * GET /export - Export all monitors configuration
 */
if ($method === 'GET' && $path === 'export') {
    $monitors = loadMonitors();
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="monitors_export_' . date('Y-m-d') . '.json"');
    
    echo json_encode($monitors, JSON_PRETTY_PRINT);
    exit;
}

/**
 * POST /test-alerts - Test all configured alert channels
 */
if ($method === 'POST' && $path === 'test-alerts') {
    $results = [];
    
    $testMonitor = [
        'id' => 'test',
        'name' => 'Test Monitor',
        'target' => 'https://example.com',
        'uptime' => 99.5,
        'response_time' => 123,
        'config' => [
            'priority' => 'normal'
        ]
    ];
    
    if (!empty($config['slack_bot']['bot_token'])) {
        sendSlackBotAlert($testMonitor, 'down', 'up', 'This is a test alert');
        $results['slack_bot'] = 'sent';
    } else {
        $results['slack_bot'] = 'not configured';
    }
    
    if (($config['email']['enabled'] ?? false) && !empty($config['email']['recipients'])) {
        sendEmailAlert($testMonitor, 'down', 'up', 'This is a test alert');
        $results['email'] = 'sent';
    } else {
        $results['email'] = 'not configured';
    }
    
    if (($config['textbee']['enabled'] ?? false)) {
        $smsResult = sendTextBeeSMS($testMonitor, 'down', 'up', 'This is a test alert');
        $results['textbee_sms'] = $smsResult ? 'sent' : 'failed';
    } else {
        $results['textbee_sms'] = 'not configured';
    }
    
    echo json_encode([
        'success' => true,
        'results' => $results,
        'message' => 'Test alerts sent to all configured channels'
    ]);
    exit;
}

/**
 * POST /test-sms - Test SMS functionality
 */
if ($method === 'POST' && $path === 'test-sms') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!($config['textbee']['enabled'] ?? false) || 
        empty($config['textbee']['api_key']) || 
        empty($config['textbee']['device_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'TextBee SMS is not configured']);
        exit;
    }
    
    $recipients = [];
    if (!empty($input['recipient'])) {
        $recipients = [$input['recipient']];
    } elseif (!empty($config['textbee']['recipients'])) {
        $recipients = array_map('trim', explode(',', $config['textbee']['recipients']));
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'No SMS recipients configured']);
        exit;
    }
    
    $message = "🔔 Uptime Monitor Test SMS\n";
    $message .= "Time: " . date('Y-m-d H:i:s') . "\n";
    $message .= "This is a test message to verify SMS alerts are working correctly.";
    
    if (!empty($input['custom_message'])) {
        $message .= "\n" . $input['custom_message'];
    }
    
    $url = "https://api.textbee.dev/api/v1/gateway/devices/{$config['textbee']['device_id']}/send-sms";
    
    $payload = [
        'recipients' => $recipients,
        'message' => $message
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $config['textbee']['api_key']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200 || $httpCode === 201) {
        echo json_encode([
            'success' => true,
            'message' => 'Test SMS sent successfully',
            'recipients' => $recipients,
            'response' => json_decode($response, true)
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to send test SMS',
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'response' => $response
        ]);
    }
    exit;
}

/**
 * GET /health - Health check endpoint
 */
if ($method === 'GET' && $path === 'health') {
    $monitors = loadMonitors();
    $checksOk = true;
    $errors = [];
    
    if (!is_writable(dirname(DATA_FILE))) {
        $checksOk = false;
        $errors[] = 'Data directory not writable';
    }
    
    if (!is_writable(CHECKS_DIR)) {
        $checksOk = false;
        $errors[] = 'Checks directory not writable';
    }
    
    $recentCheck = false;
    foreach ($monitors as $monitor) {
        $checks = loadChecks($monitor['id']);
        if (!empty($checks)) {
            $lastCheck = end($checks);
            if (time() - $lastCheck['timestamp'] < 3600) {
                $recentCheck = true;
                break;
            }
        }
    }
    
    if (count($monitors) > 0 && !$recentCheck) {
        $errors[] = 'No recent checks - cron may not be running';
    }
    
    $status = $checksOk && (count($monitors) === 0 || $recentCheck) ? 'healthy' : 'unhealthy';
    
    echo json_encode([
        'status' => $status,
        'monitors_count' => count($monitors),
        'errors' => $errors,
        'timestamp' => date('c'),
        'performance' => [
            'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'memory_usage' => formatBytes(memory_get_usage() - $startMemory)
        ]
    ]);
    exit;
}

/**
 * GET /version - Get API version information
 */
if ($method === 'GET' && $path === 'version') {
    echo json_encode([
        'version' => '2.2.0',
        'name' => 'Enhanced Uptime Monitor API - Optimized',
        'php_version' => PHP_VERSION,
        'performance' => [
            'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'memory_usage' => formatBytes(memory_get_usage() - $startMemory),
            'peak_memory' => formatBytes(memory_get_peak_usage()),
            'optimizations_enabled' => true
        ],
        'features' => [
            'per_monitor_config' => true,
            'ssl_monitoring' => true,
            'keyword_validation' => true,
            'custom_alerts' => true,
            'maintenance_mode' => true,
            'incident_tracking' => true,
            'chart_data' => true,
            'data_management' => true,
            'ping_monitoring' => true,
            'port_monitoring' => true,
            'sms_alerts' => true,
            'network_scanning' => true,
            'caching_enabled' => true,
            'memory_optimization' => true,
            'connection_reuse' => true
        ],
        'limits' => [
            'max_checks_per_monitor' => MAX_CHECKS_PER_MONITOR,
            'max_sparkline_points' => MAX_SPARKLINE_POINTS
        ],
        'supported_check_types' => ['http', 'https', 'ping', 'port']
    ]);
    exit;
}

// ============================================================================
// CLEANUP AND ERROR HANDLING
// ============================================================================

// Clean up static cURL handle if exists
if (isset($curlHandle)) {
    curl_close($curlHandle);
}

// Clear cache periodically to prevent memory leaks
if (count($dataCache['checks']) > 100) {
    $dataCache['checks'] = array_slice($dataCache['checks'], -50, null, true);
}

// ============================================================================
// DEFAULT ERROR RESPONSE
// ============================================================================

http_response_code(404);
echo json_encode([
    'error' => 'Not Found',
    'message' => 'The requested endpoint does not exist',
    'path' => $path,
    'method' => $method,
    'performance' => [
        'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
        'memory_usage' => formatBytes(memory_get_usage() - $startMemory)
    ],
    'available_endpoints' => [
        'GET /monitors',
        'POST /monitors', 
        'GET /monitors/{id}',
        'PUT /monitors/{id}',
        'DELETE /monitors/{id}',
        'POST /monitors/{id}/check',
        'POST /monitors/{id}/test-alert',
        'GET /stats',
        'POST /check-all',
        'POST /quick-check',
        'POST /network-scan',
        'GET /config',
        'PUT /config',
        'GET /maintenance',
        'POST /maintenance',
        'GET /incidents',
        'GET /logs',
        'DELETE /logs',
        'DELETE /monitors/{id}/checks',
        'DELETE /monitors/{id}/checks/old',
        'POST /cleanup',
        'GET /storage-stats',
        'POST /reset-data',
        'GET /debug',
        'GET /debug/checks',
        'GET /export',
        'POST /test-alerts',
        'POST /test-sms',
        'GET /health',
        'GET /version'
    ]
]);

?>