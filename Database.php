<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;
    public $skip;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $pattern = '/\?([#]|a|[df]?)/';

        // Находим все совпадения в шаблоне
        preg_match_all($pattern, $query, $matches);

        // Получаем массив с найденными спецификаторами
        $specifiers = $matches[1];

        // Функция для экранирования значений
        $escapeValue = fn($value) => is_string($value) ? "'" . $this->mysqli->real_escape_string($value) . "'" : $value;

        $handleArray = function($value) use ($escapeValue) {
            if (!is_array($value)) {
                throw new Exception("Array expected for ?a parameter.");
            }

            // Проверяем, является ли массив ассоциативным
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);

            if ($isAssoc) {
                // Ассоциативный массив: каждая пара ключ-значение преобразуется в идентификатор и его значение

                $formattedPairs = array_map(function($key, $val) use ($escapeValue) {
                  if ($val === null) {
                        return "`$key` = NULL";
                    }
                    return '`' . $this->mysqli->real_escape_string($key) . '` = ' . $escapeValue($val);
                }, array_keys($value), $value);
                return implode(', ', $formattedPairs);
            } else {
                // Простой числовой массив: значения просто перечисляются через запятую
                $formattedValues = array_map($escapeValue, $value);
                return implode(', ', $formattedValues);
            }
        };

        // Функция для форматирования значений в зависимости от спецификатора
        $formatValue = function($value, $specifier) use ($escapeValue, $handleArray) {
            switch ($specifier) {
                case 'd':
                    return (int)$value;
                case 'f':
                    return (float)$value;
                case 'a':
                    return $handleArray($value);
                case '#':
                    if (is_array($value)) {
                        $formattedIds = array_map(fn($id) => '`' . $this->mysqli->real_escape_string($id) . '`', $value);
                        return implode(', ', $formattedIds);
                    } elseif(is_string($value)) {
                        return '`' . $this->mysqli->real_escape_string($value) . '`';
                    } else {
                        throw new Exception("Identifier expected for ?# parameter.");
                    }
                default:
                    return $escapeValue($value);
            }
        };

        // Формируем SQL-запрос, обрабатывая каждый спецификатор
        $sql = preg_replace_callback($pattern, function() use (&$args, &$specifiers, $formatValue) {
            // Получаем следующий спецификатор из массива $specifiers
            $specifier = array_shift($specifiers);
            $value = array_shift($args);

            // Если значение равно null, заменяем на NULL
            if ($value === null) {
                return 'NULL';
            }

            // Если значение равно специальному значению, возвращаем пустую строку
            if ($value instanceof \stdClass) {
                $this->skip = true;
                return '';
            } else {
                $this->skip = false;
            }

            return $formatValue($value, $specifier);
        }, $query);


        if ($this->skip) {
            $sql = preg_replace('/{[^{}]*[^{}]*}/', '', $sql);
        } else {
            // Удаляем только фигурные скобки
            $sql = str_replace(['{', '}'], '', $sql);
        }

        if ($sql === null) {
            throw new Exception("Error in SQL template.");
        }

        return $sql;
    }

    public function skip()
    {
        return new \stdClass(); // Возвращаем объект stdClass как специальное значение для пропуска
    }
}
