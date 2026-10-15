# rasuvaeff/context-cli

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/context-cli/v)](https://packagist.org/packages/rasuvaeff/context-cli)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/context-cli/downloads)](https://packagist.org/packages/rasuvaeff/context-cli)
[![Build](https://github.com/rasuvaeff/context-cli/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/context-cli/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/context-cli/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/context-cli/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/context-cli/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/context-cli/php)](https://packagist.org/packages/rasuvaeff/context-cli)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Корректная остановка PHP CLI-воркеров: `SIGTERM`/`SIGINT` отменяют `Context`
из [`rasuvaeff/context`](https://github.com/rasuvaeff/context), поэтому воркер
очереди или долгая команда доделывает текущий шаг и выходит чисто — при
деплое, при остановке пода в Kubernetes, по Ctrl+C.

> Пользуетесь AI-ассистентом? В [llms.txt](llms.txt) — компактный справочник по API, который можно передать модели.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills)
> дополнительно получают agent-скилл этого пакета в `.agents/skills/`
> автоматически при установке.

## Требования

- PHP 8.3+
- `ext-pcntl`, `ext-posix` (Linux/macOS; на Windows их нет)
- `rasuvaeff/context` ^0.3

## Установка

```bash
composer require rasuvaeff/context-cli
```

## Ограничения

- **Первый сигнал останавливает работу на следующей проверке, а не посреди
  вызова.** Обработчик только запрашивает отмену
  (`ContextController::requestCancel()`); код увидит её на следующем вызове
  `assertActive()`, `sleep()`, `iterate()` или `is*()`. Блокирующие вызовы,
  которые сигнал прерывает, — `usleep()`, `sleep()`, `stream_select()`,
  `Context::sleep()` — возвращаются раньше; curl с `CURLOPT_NOSIGNAL` и
  большинство драйверов БД сначала доработают — в пределах своих таймаутов.
- **Один процесс — одна таблица обработчиков.** Два активных scope не могут
  владеть одним сигналом (`LogicException`); пока scope активен, прежний
  обработчик его сигналов не вызывается.
- **Windows не поддерживается** — там нет `ext-pcntl`.

## Использование

### Воркер под scope

```php
use Rasuvaeff\Context\Cli\SignalScope;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;

try {
    SignalScope::run(Context::background(), static function (Context $context) use ($queue): void {
        while (true) {
            $context->assertActive();               // остановка между сообщениями
            $message = $queue->reserve(timeout: 5);
            if ($message === null) {
                continue;
            }

            [$job, $jobController] = $context->withTimeout($message->ttr());
            try {
                handle($message, $job);
                $queue->ack($message);
            } finally {
                $jobController->cancel();
            }
        }
    });
} catch (ContextCancelledException $stopped) {
    $logger->info('worker stopped', ['reason' => $stopped->getPrevious()?->getMessage()]); // "Received SIGTERM"
}
```

`run()` ставит обработчики, передаёт контекст scope в callback и
восстанавливает прежние обработчики после — даже если callback бросил.

### Scope вручную

```php
$scope = SignalScope::listen(Context::background(), signals: [\SIGTERM, \SIGINT, \SIGHUP]);
try {
    work($scope->context());
} finally {
    $scope->restore();
}

$scope->signal();   // первый полученный сигнал или null
```

### Поведение

| Событие | Что происходит |
|---|---|
| Первый сигнал из набора | `requestCancel(new SignalReceived($signal))`. Больше в обработчике ничего не исполняется; следующая проверка завершает контекст, вызывает его `onTerminate()`-слушателей и бросает `ContextCancelledException` с `SignalReceived` в `previous` |
| Второй сигнал из набора | Scope восстанавливает прежние обработчики и передаёт им этот сигнал: PHP-обработчик вызывается, поведение по умолчанию завершает процесс (двойной Ctrl+C останавливает зависший воркер), игнорируемый сигнал остаётся игнорируемым |
| `restore()` | Возвращаются прежние обработчики и прежний режим `pcntl_async_signals()`, затем контекст scope отменяется (как `stop()` у `NotifyContext` в Go), чтобы он не остался навсегда привязан к долгоживущему родителю; уже полученная причина-сигнал сохраняется; идемпотентен |
| Родитель отменён/истёк | Контекст scope завершается вместе с ним (это `$parent->withCancel()`) |
| `SIGKILL`, `SIGSTOP`, дубликаты, пустой набор | `InvalidArgumentException` до установки чего-либо |
| Сигнал уже занят другим активным scope | `LogicException` |

Пока scope активен, он включает `pcntl_async_signals(true)`, поэтому сигналы
доставляются между опкодами PHP без ручного `pcntl_signal_dispatch()`.

### Kubernetes / systemd

Оба шлют `SIGTERM`, ждут grace period (`terminationGracePeriodSeconds` /
`TimeoutStopSec`), затем `SIGKILL`. Воркер останавливается на следующей
проверке, поэтому самый длинный шаг между двумя проверками — TTR одного
сообщения — обязан укладываться в этот период.

### Публичный API

| Класс | Описание |
|---|---|
| `SignalScope` | `listen(Context $parent, list<int> $signals = [SIGTERM, SIGINT])`, `run(Context $parent, callable(Context): T $work, list<int> $signals)`, `context()`, `signal()`, `restore()` |
| `SignalReceived` | Причина отмены (`RuntimeException`): `signal(): int`, `name(int): string` (`"SIGTERM"`, `"signal 14"`) |

## Безопасность

Пакет ставит обработчики сигналов на весь процесс и восстанавливает их; код
приложения внутри обработчика никогда не исполняется. Второй сигнал
намеренно возвращает прежнее поведение, чтобы оператор всегда мог
остановить процесс.

## Примеры

Исполняемые скрипты — в [examples/](examples/).

| Скрипт | Что показывает | Нужен сервер? |
|---|---|---|
| `worker.php` | Корректная остановка по SIGTERM/SIGINT; `--stuck` показывает, как второй Ctrl+C завершает воркер, который никогда не проверяет контекст | ext-pcntl, сервер не нужен |

## Разработка

PHP/Composer на хосте нет — всё в Docker. В `composer:2` нет `ext-pcntl`,
поэтому один раз соберите локальный образ:

```bash
make image                 # rasuvaeff/composer-pcntl:local = composer:2 + pcntl
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

## Лицензия

[BSD-3-Clause](LICENSE.md)
