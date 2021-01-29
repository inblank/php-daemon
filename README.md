# Многопоточный демон

Пример запуска:

```php
<?php
include_once '../vendor/autoload.php';

// Инициализируем
$daemon = new \inblank\daemon\daemon('test');

// Добавляем обработчик
$daemon->addRunners([
    'log' => static function ($logger, $isStopped) {
        $logger->info(random_int(1, 100));
        sleep(1);
    }
]);

// Запускаем в 2 потока
$daemon->run(2);
```