<?php

try {
    // файл лога
    $logFile = "rename.log";
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../php/common/rename.php';
    // записываем в лог
    Log::write($logFile);
} catch (Exception $e) {
    Log::append($e->getMessage());
    Log::write($logFile);
}
