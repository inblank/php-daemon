# Многопоточный демон

## Пример запуска

```php
<?php
use inblank\daemon\daemon;

include_once './vendor/autoload.php';

// 1. Инициализируем
$daemon = new daemon('loader');

// 2. Добавляем обработчик
$daemon->addRunners([
    'log' => static function ($logger) {
        $logger->info(random_int(1, 100));
        sleep(1);
    }
]);

// 3. Запускаем в 2 потока
$daemon->run(2);
```