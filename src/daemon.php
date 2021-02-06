<?php

namespace inblank\daemon;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use RuntimeException;
use Throwable;

/**
 * Класс многопоточного демона
 *
 * Создан на базе:
 * https://medium.com/@indi.go/multiprocess-daemons-in-php-88a2f58a40df
 */
class daemon
{
    /**
     * Код успешного завершения работы
     */
    public const EXIT_OK = 0;

    /**
     * Код завершения работы с ошибкой
     */
    public const EXIT_ERROR = -1;

    /**
     * Имя демона
     * @var string
     */
    protected string $name;
    /**
     * Имя pid файла демона.
     * По умолчанию /var/run/phpdaemon/{name}.pid
     * @var string
     */
    protected string $pidFile;
    /**
     * Имя log файла демона.
     * По умолчанию /var/log/phpdaemon/{name}.log
     * @var string|null
     */
    protected string $logFile;

    /**
     * Список pid дочерних процессов
     * @var array
     */
    protected array $processes = [];

    /**
     * Логгер
     * @var Logger
     */
    protected Logger $logger;

    /**
     * Список обработчиков, которые запускает каждый дочерний процесс.
     * Запуск происходит в цикле в порядке добавления обработчиков
     * Если не задан ни один обработчик, демон не запускается.
     * Обработчики должны быть заданы как callable.
     * При вызове:
     *      первым аргументом будет передан объект логгера
     *      вторым аргументом будет передана функция определения, что процесс получил сигнал останова
     * Исключения отлавливаются и записываются в лог как ошибка
     * @var array
     */
    protected array $runners = [];

    /**
     * Признак остановки работы демона со всеми дочерними процессами
     * @var bool
     */
    protected bool $stop = false;

    /**
     * Конструктор
     * @param string $name имя демона
     * @param ?string $pidFile имя pid файла. По умолчанию /var/run/phpdaemon/{name}.pid
     * @param ?string $logFile имя лог файла. По умолчанию /var/log/phpdaemon/{name}.log
     */
    public function __construct(string $name, string $pidFile = null, string $logFile = null)
    {
        if (empty($name)) {
            $this->exit(self::EXIT_ERROR, 'Not set daemon name');
        }

        $this->name = $name;

        // проверяем и подготавливаем необходимые файлы
        $this->pidFile = !empty($pidFile) ? $pidFile : "/var/run/phpdaemon/$this->name.pid";
        $this->logFile = !empty($logFile) ? $logFile : "/var/log/phpdaemon/$this->name.log";
        foreach ([$this->pidFile, $this->logFile] as $file) {
            $path = dirname($file);
            if (!file_exists($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
                throw new RuntimeException("Directory `$path` was not created");
            }
            if (!is_dir($path) || !is_writable($path)) {
                throw new RuntimeException("Directory `$path` not writable");
            }
        }

        // проверяем запущен ли демон
        if ($this->isActive()) {
            $this->exit(self::EXIT_OK, 'Daemon already running');
        }

        // инициализируем логгер
        $this->logger = new Logger($this->name);
        $stream = new StreamHandler($this->logFile);
        $formatter = new LineFormatter(
            "[%datetime%]\t%channel%.%level_name%\t%message%\t%context%\t%extra%\n",
            'Y-m-d H:i:s'
        );
        $stream->setFormatter($formatter);
        $this->logger->pushHandler($stream);
        $this->logger->pushProcessor(function ($record) {
            if (empty($record['context']['pid'])) {
                // pid берем по текущему процессу
                $pid = posix_getpid();
            } else {
                // pid берем из контекста, так как передан
                $pid = $record['context']['pid'];
                unset($record['context']['pid']);
            }
            $record['extra']['pid'] = $pid;
            return $record;
        });
    }

    /**
     * Запуск демона. Запускается основной процесс, который запускает нужное количество дочерних процессов
     * @param int $processes количество запускаемых дочерних процессов
     */
    public function run(int $processes = 1): void
    {
        if (empty($this->runners)) {
            // не можем работать без установленных обработчиков
            $this->exit(self::EXIT_ERROR, 'Not set runners for child process');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            // не получилось создать основной процесс
            $this->exit(self::EXIT_ERROR, 'Unable start main process');
        }
        if ($pid) {
            // завершаем родительский процесс, чтобы "отвязать" от терминала
            exit("Daemon main process pid $pid" . PHP_EOL);
        }

        // делаем основной процесс главным, это необходимо для создания дочерних процессов
        if (posix_setsid() === -1) {
            $this->exit(self::EXIT_ERROR, 'Unable set sid for main process');
        }

        $this->logger->info("Start main process");

        // задаем имя основного процесса отображаемое по ps
        cli_set_process_title("phpdaemon.m.$this->name");
        // устанавливаем обработчик сигналов
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, "signalHandler"]);
        // создаем pid файл
        file_put_contents($this->pidFile, getmypid());

        // основной цикл главного процесса
        while (!$this->isStopped()) {
            try {
                if (!$this->isStopped() && count($this->processes) < $processes) {
                    // если не задана остановка, основной процесс будет создавать/восстанавливать дочерние процессы
                    $pid = pcntl_fork();
                    if ($pid === -1) {
                        // не получилось создать дочерний процесс
                        $this->logger->error("Unable start child process");
                    } elseif ($pid) {
                        // Дочерний процесс создан, сохраняем pid в список дочерних процессов
                        $this->processes[$pid] = true;
                        $this->logger->info("Start child process", ["pid" => $pid]);
                    } else {
                        // дочерний процесс создан
                        // задаем его имя для отображения по ps
                        cli_set_process_title("phpdaemon.c.$this->name");
                        //---------------------------------------------------------------------
                        // Рабочий цикл дочернего процесса
                        while (!$this->isStopped()) {
                            // обходим обработчики
                            foreach ($this->runners as $runnerName => $runner) {
                                try {
                                    $runner($this->logger, [$this, "isStopped"]);
                                } catch (Throwable $e) {
                                    $this->logger->error(
                                        "Runner `$runnerName` error",
                                        [
                                            'error' => [
                                                'file' => $e->getFile(),
                                                'line' => $e->getLine(),
                                                'message' => $e->getMessage(),
                                            ]
                                        ]
                                    );
                                }
                            }
                        }
                        //---------------------------------------------------------------------
                        // завершение работы дочернего процесса
                        $this->logger->info("Stop child process");
                        exit(self::EXIT_OK);
                    }
                }
                // отслеживаем состояние дочерних процессов
                while ($signaledPid = pcntl_waitpid(-1, $status, WNOHANG)) {
                    if ($signaledPid === -1) {
                        // дочерних процессов не осталось, будем завершать работу
                        $this->processes = [];
                        break;
                    }
                    unset($this->processes[$signaledPid]);
                }
            } catch (Throwable $e) {
                $this->logger->error("{$e->getFile()}:{$e->getLine()} {$e->getMessage()}");
                // TODO вызов события при ошибке
            }
        }
        // посылаем команду завершения оставшимся дочерним процессам
        foreach ($this->processes as $pid => $val) {
            posix_kill($pid, SIGTERM);
        }
        // удаляем pid файл
        if (is_file($this->pidFile)) {
            unlink($this->pidFile);
        }
        $this->logger->info("Stop main process");
    }

    /**
     * Проверка, запущен ли демон
     * @return bool возвращает true если демон запущен
     */
    protected function isActive(): bool
    {
        if (is_file($this->pidFile)) {
            $pid = file_get_contents($this->pidFile);
            if (posix_kill($pid, 0)) {
                // есть файл и процесс. демон запущен
                return true;
            }
            // pid файл есть, но процесса нет
            if (!unlink($this->pidFile)) {
                $this->exit(self::EXIT_ERROR, 'Unable delete pid file ' . $this->pidFile);
            }
        }
        // демон не запущен
        return false;
    }

    /**
     * Завершение работы
     * @param int $code код завершения. self::EXIT_OK - корректное завершение, иначе код ошибки
     * @param string $message выводимое сообщение. Если пусто, ничего не выводится
     */
    protected function exit(int $code = self::EXIT_OK, string $message = ''): void
    {
        if (!empty($message)) {
            echo $message . PHP_EOL;
            if (!empty($this->logger)) {
                $method = $code === self::EXIT_OK ? 'info' : 'error';
                $this->logger->$method($message);
            }
        }
        exit($code);
    }

    /**
     * Отслеживание сигналов процессам
     * @param int $signal полученный сигнал
     */
    public function signalHandler(int $signal): void
    {
        // TODO события прикрепляемые пользователем на сигналы
        if ($signal === SIGTERM) {
            $this->stop = true;
        }
    }

    /**
     * Установка обработчиков
     * @param array $runners список обработчиков. Ключ - имя обработчика, значение - вызываемая функция/метод.
     *  Совпадающие имена обработчиков будут перезаписаны
     *  Если значение не callable, то такой обработчик не будет добавлен
     *  Сигнатура функции обработчика: function ($logger, $isStopped)
     * @return self
     */
    public function addRunners(array $runners): self
    {
        foreach ($runners as $name => $call) {
            if (is_callable($call)) {
                $this->runners[$name] = $call;
            }
        }
        return $this;
    }

    /**
     * Проверка, что процесс получил сигнал остановки
     * @return bool
     */
    public function isStopped(): bool
    {
        return $this->stop;
    }
}