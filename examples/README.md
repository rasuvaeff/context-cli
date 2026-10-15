# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `worker.php` | Graceful stop on SIGTERM/SIGINT; without a signal it ends after a 2 s budget; `--stuck` never checks the context, so the second Ctrl+C terminates it | ext-pcntl (no server; executed by `WorkerProcessTest`) |

```bash
make image
docker run --rm -it -v "$PWD":/app -w /app rasuvaeff/composer-pcntl:local php examples/worker.php
# press Ctrl+C within 2 s → "stopped gracefully: Received SIGINT"
docker run --rm -it -v "$PWD":/app -w /app rasuvaeff/composer-pcntl:local php examples/worker.php --stuck
# first Ctrl+C is ignored by the stuck loop, the second terminates the process
```
