/* Runs as shell UID: app-owner VPN exclusion cannot bypass the kernel TUN. */
#include <arpa/inet.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <unistd.h>

int main(int argc, char **argv) {
    if (argc != 4) return 2;
    int udp = strcmp(argv[1], "udp") == 0;
    if (!udp && strcmp(argv[1], "tcp")) return 2;
    int fd = socket(AF_INET, udp ? SOCK_DGRAM : SOCK_STREAM, 0);
    struct timeval timeout = { 8, 0 };
    setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &timeout, sizeof(timeout));
    setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &timeout, sizeof(timeout));
    struct sockaddr_in peer = { .sin_family = AF_INET, .sin_port = htons(atoi(argv[2])) };
    inet_pton(AF_INET, "10.0.2.2", &peer.sin_addr);
    if (connect(fd, (struct sockaddr *)&peer, sizeof(peer))) { perror("connect"); return 3; }
    char sent[80], received[80] = {0};
    int size = snprintf(sent, sizeof(sent), "TUN_%s_%s\n", udp ? "UDP" : "TCP", argv[3]);
    if (size <= 0 || size >= (int)sizeof(sent)) return 2;
    if (send(fd, sent, size, 0) != size) { perror("send"); return 4; }
    int total = 0;
    while (total < size) {
        int n = recv(fd, received + total, sizeof(received) - 1 - total, 0);
        if (n <= 0) { perror("recv"); return 5; }
        total += n;
        if (udp) break;
    }
    close(fd);
    if (total != size || memcmp(sent, received, size)) return 6;
    fputs(received, stdout);
    return 0;
}
