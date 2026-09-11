namespace LeikwanBridge.Mac.Services;

internal static class MacSecretStoreNativeRunner
{
    public static async Task<T> RunAsync<T>(
        Func<T> operation,
        CancellationToken ct,
        TimeSpan timeout,
        Action? cleanupOnCompletion = null,
        Action<T>? cleanupIfAbandoned = null)
    {
        Func<T> wrapped = cleanupOnCompletion is null
            ? operation
            : () =>
            {
                try
                {
                    return operation();
                }
                finally
                {
                    cleanupOnCompletion();
                }
            };
        Task<T> work;
        try
        {
            work = Task.Run(wrapped, CancellationToken.None);
        }
        catch
        {
            cleanupOnCompletion?.Invoke();
            throw;
        }

        var abandoned = 0;
        var cleanupClaimed = 0;
        void CleanupAbandoned(T value)
        {
            if (Interlocked.Exchange(ref cleanupClaimed, 1) == 0)
                cleanupIfAbandoned?.Invoke(value);
        }
        if (cleanupIfAbandoned is not null)
            _ = ObserveAbandonedAsync(work, () => Volatile.Read(ref abandoned) == 1, CleanupAbandoned);

        try
        {
            return await work.WaitAsync(timeout, ct).ConfigureAwait(false);
        }
        catch
        {
            if (cleanupIfAbandoned is not null)
            {
                Volatile.Write(ref abandoned, 1);
                if (work.IsCompletedSuccessfully)
                    CleanupAbandoned(await work.ConfigureAwait(false));
            }
            throw;
        }
    }

    private static async Task ObserveAbandonedAsync<T>(
        Task<T> work,
        Func<bool> isAbandoned,
        Action<T> cleanup)
    {
        try
        {
            var value = await work.ConfigureAwait(false);
            if (isAbandoned())
                cleanup(value);
        }
        catch
        {
        }
    }
}
