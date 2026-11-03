#ifndef LEIKWAN_RUNTIME_H
#define LEIKWAN_RUNTIME_H

#include <stddef.h>
#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

/* Runtime kinds accepted by lk_runtime_start. */
#define LK_RUNTIME_INGRESS "ingress"
#define LK_RUNTIME_BOND "bond"

/* Values returned by lk_runtime_state. */
#define LK_STATE_UNKNOWN 0
#define LK_STATE_RUNNING 1
#define LK_STATE_EXITED 2
#define LK_STATE_FAILED 3

/* Log callback: level follows the log crate (1 error .. 5 trace). The message
 * pointer is only valid for the duration of the call. */
typedef void (*lk_log_fn)(int32_t level, const char *message, void *context);

/* Installs the process-wide log sink. Only the first call takes effect;
 * returns 0 on success, -1 if a sink was already installed. */
int32_t lk_runtime_set_log(lk_log_fn callback, void *context, int32_t max_level);

/* Starts a runtime with the same argument vector the Android client passes to
 * the corresponding binary, minus the program name (argv[0] is the subcommand,
 * e.g. "client"). Returns a positive handle, or -1 with a NUL-terminated
 * message written to error (truncated to error_len). The call waits briefly so
 * an immediate bind failure is reported here rather than asynchronously. */
int64_t lk_runtime_start(const char *kind,
                         const char *const *argv,
                         size_t argc,
                         char *error,
                         size_t error_len);

/* Stops a runtime and releases its handle. Returns 0, or -1 for an unknown
 * handle. Blocks until the runtime's sockets are closed (bounded). */
int32_t lk_runtime_stop(int64_t handle);

/* Returns one of the LK_STATE_* values. */
int32_t lk_runtime_state(int64_t handle);

/* Copies the runtime's failure message (empty while running); returns the
 * full message length. */
size_t lk_runtime_last_error(int64_t handle, char *buffer, size_t buffer_len);

/* Static NUL-terminated version string of this library. */
const char *lk_runtime_version(void);

#ifdef __cplusplus
}
#endif

#endif /* LEIKWAN_RUNTIME_H */
