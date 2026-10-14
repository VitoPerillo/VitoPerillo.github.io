#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <sys/resource.h>
#include <sys/socket.h>
#include <unistd.h>

static const char *ok_blocked(int condition) { return condition ? "PASS" : "FAIL"; }

int main(void) {
    int fd = open("inside.txt", O_CREAT | O_WRONLY | O_TRUNC, 0600);
    printf("workdir_write=%s\n", ok_blocked(fd >= 0));
    if (fd >= 0) { write(fd, "ok", 2); close(fd); }

    errno = 0;
    fd = open("/outside.txt", O_CREAT | O_WRONLY | O_TRUNC, 0600);
    printf("root_write_blocked=%s errno=%d\n", ok_blocked(fd < 0 && errno == EACCES), errno);
    if (fd >= 0) close(fd);

    errno = 0;
    fd = open("../../outside2.txt", O_CREAT | O_WRONLY | O_TRUNC, 0600);
    printf("traversal_write_blocked=%s errno=%d\n", ok_blocked(fd < 0), errno);
    if (fd >= 0) close(fd);

    errno = 0;
    int sy = symlink("/outside3.txt", "escape-link");
    int syfd = open("escape-link", O_CREAT | O_WRONLY | O_TRUNC, 0600);
    printf("symlink_escape_blocked=%s errno=%d\n", ok_blocked(sy == 0 && syfd < 0), errno);
    if (syfd >= 0) close(syfd);

    errno = 0;
    int sock = socket(AF_INET, SOCK_STREAM, 0);
    printf("network_blocked=%s errno=%d\n", ok_blocked(sock < 0 && errno == EPERM), errno);
    if (sock >= 0) close(sock);

    errno = 0;
    int shell = access("/bin/sh", X_OK);
    printf("shell_absent=%s errno=%d\n", ok_blocked(shell != 0), errno);

    printf("euid_nobody=%s uid=%u\n", ok_blocked(geteuid() == 65534), (unsigned)geteuid());

    struct rlimit cpu, as, fsize, nproc;
    getrlimit(RLIMIT_CPU, &cpu); getrlimit(RLIMIT_AS, &as); getrlimit(RLIMIT_FSIZE, &fsize); getrlimit(RLIMIT_NPROC, &nproc);
    printf("cpu_limit=%llu\n", (unsigned long long)cpu.rlim_cur);
    printf("memory_limit=%llu\n", (unsigned long long)as.rlim_cur);
    printf("output_limit=%llu\n", (unsigned long long)fsize.rlim_cur);
    printf("process_limit=%llu\n", (unsigned long long)nproc.rlim_cur);
    return 0;
}
