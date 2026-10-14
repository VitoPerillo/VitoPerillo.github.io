#define _GNU_SOURCE
#include <errno.h>
#include <grp.h>
#include <linux/audit.h>
#include <linux/filter.h>
#include <linux/seccomp.h>
#include <stddef.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/prctl.h>
#include <sys/resource.h>
#include <sys/syscall.h>
#include <sys/types.h>
#include <unistd.h>

static void die(const char *message) {
    perror(message);
    _exit(125);
}

static void set_limit(int resource, rlim_t value) {
    struct rlimit limit = {value, value};
    if (setrlimit(resource, &limit) != 0) {
        die("setrlimit");
    }
}

#define DENY_SYSCALL(nr) \
    BPF_JUMP(BPF_JMP | BPF_JEQ | BPF_K, (nr), 0, 1), \
    BPF_STMT(BPF_RET | BPF_K, SECCOMP_RET_ERRNO | (EPERM & SECCOMP_RET_DATA))

#if defined(__x86_64__)
#define VDE_AUDIT_ARCH AUDIT_ARCH_X86_64
#elif defined(__aarch64__)
#define VDE_AUDIT_ARCH AUDIT_ARCH_AARCH64
#else
#error "Unsupported architecture for VDE seccomp sandbox"
#endif

static void install_seccomp(void) {
    struct sock_filter filter[] = {
        BPF_STMT(BPF_LD | BPF_W | BPF_ABS, offsetof(struct seccomp_data, arch)),
        BPF_JUMP(BPF_JMP | BPF_JEQ | BPF_K, VDE_AUDIT_ARCH, 1, 0),
        BPF_STMT(BPF_RET | BPF_K, SECCOMP_RET_KILL_PROCESS),
        BPF_STMT(BPF_LD | BPF_W | BPF_ABS, offsetof(struct seccomp_data, nr)),
#ifdef __NR_socket
        DENY_SYSCALL(__NR_socket),
#endif
#ifdef __NR_socketpair
        DENY_SYSCALL(__NR_socketpair),
#endif
#ifdef __NR_connect
        DENY_SYSCALL(__NR_connect),
#endif
#ifdef __NR_accept
        DENY_SYSCALL(__NR_accept),
#endif
#ifdef __NR_accept4
        DENY_SYSCALL(__NR_accept4),
#endif
#ifdef __NR_bind
        DENY_SYSCALL(__NR_bind),
#endif
#ifdef __NR_listen
        DENY_SYSCALL(__NR_listen),
#endif
#ifdef __NR_sendto
        DENY_SYSCALL(__NR_sendto),
#endif
#ifdef __NR_sendmsg
        DENY_SYSCALL(__NR_sendmsg),
#endif
#ifdef __NR_sendmmsg
        DENY_SYSCALL(__NR_sendmmsg),
#endif
#ifdef __NR_recvfrom
        DENY_SYSCALL(__NR_recvfrom),
#endif
#ifdef __NR_recvmsg
        DENY_SYSCALL(__NR_recvmsg),
#endif
#ifdef __NR_recvmmsg
        DENY_SYSCALL(__NR_recvmmsg),
#endif
#ifdef __NR_mount
        DENY_SYSCALL(__NR_mount),
#endif
#ifdef __NR_umount2
        DENY_SYSCALL(__NR_umount2),
#endif
#ifdef __NR_pivot_root
        DENY_SYSCALL(__NR_pivot_root),
#endif
#ifdef __NR_ptrace
        DENY_SYSCALL(__NR_ptrace),
#endif
#ifdef __NR_bpf
        DENY_SYSCALL(__NR_bpf),
#endif
#ifdef __NR_unshare
        DENY_SYSCALL(__NR_unshare),
#endif
#ifdef __NR_setns
        DENY_SYSCALL(__NR_setns),
#endif
#ifdef __NR_kexec_load
        DENY_SYSCALL(__NR_kexec_load),
#endif
#ifdef __NR_finit_module
        DENY_SYSCALL(__NR_finit_module),
#endif
#ifdef __NR_init_module
        DENY_SYSCALL(__NR_init_module),
#endif
#ifdef __NR_delete_module
        DENY_SYSCALL(__NR_delete_module),
#endif
        BPF_STMT(BPF_RET | BPF_K, SECCOMP_RET_ALLOW),
    };
    struct sock_fprog program = {
        .len = (unsigned short)(sizeof(filter) / sizeof(filter[0])),
        .filter = filter,
    };
    if (prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) != 0) {
        die("no_new_privs");
    }
    if (prctl(PR_SET_SECCOMP, SECCOMP_MODE_FILTER, &program) != 0) {
        die("seccomp");
    }
}

static void close_extra_fds(void) {
#ifdef SYS_close_range
    if (syscall(SYS_close_range, 3U, ~0U, 0U) == 0) {
        return;
    }
#endif
    long max_fd = sysconf(_SC_OPEN_MAX);
    if (max_fd < 0 || max_fd > 65536) {
        max_fd = 65536;
    }
    for (int fd = 3; fd < max_fd; ++fd) {
        close(fd);
    }
}

int main(int argc, char **argv) {
    if (argc < 8 || strcmp(argv[6], "--") != 0) {
        fprintf(stderr, "usage: %s ROOTFS CWD CPU_SECONDS MEMORY_MB OUTPUT_BYTES -- COMMAND [ARGS...]\n", argv[0]);
        return 64;
    }
    if (strcmp(argv[7], "/bin/probe") != 0 &&
        strcmp(argv[7], "/opt/vde/bin/vde-docling-worker") != 0 &&
        strcmp(argv[7], "/opt/vde/bin/vde-docx-worker") != 0 &&
        strcmp(argv[7], "/opt/vde/bin/vde-xlsx-worker") != 0) {
        fprintf(stderr, "sandbox executable is not allowlisted\n");
        return 65;
    }
    const char *rootfs = argv[1];
    const char *cwd = argv[2];
    unsigned long cpu_seconds = strtoul(argv[3], NULL, 10);
    unsigned long memory_mb = strtoul(argv[4], NULL, 10);
    unsigned long output_bytes = strtoul(argv[5], NULL, 10);
    if (cpu_seconds == 0 || memory_mb == 0 || output_bytes == 0) {
        return 64;
    }

    set_limit(RLIMIT_CPU, (rlim_t)cpu_seconds);
    set_limit(RLIMIT_AS, (rlim_t)memory_mb * 1024UL * 1024UL);
    set_limit(RLIMIT_FSIZE, (rlim_t)output_bytes);
    set_limit(RLIMIT_NOFILE, 64);
    set_limit(RLIMIT_NPROC, 32);

    close_extra_fds();
    if (chroot(rootfs) != 0) {
        die("chroot");
    }
    if (chdir(cwd) != 0) {
        die("chdir");
    }
    setenv("HOME", cwd, 1);
    setenv("TMPDIR", cwd, 1);
    setenv("PYTHONDONTWRITEBYTECODE", "1", 1);

    if (setgroups(0, NULL) != 0) {
        die("setgroups");
    }
    if (setgid(65534) != 0) {
        die("setgid");
    }
    if (setuid(65534) != 0) {
        die("setuid");
    }
    install_seccomp();

    execv(argv[7], &argv[7]);
    die("execv");
}
