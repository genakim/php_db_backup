<?php


namespace App\Backup;


final class DumpState
{
    /**
     * Название ключа в сессии
     */
    public const SESSION_KEY = 'dump_state';


    public const RESERVE_TIME_IN_SEC = 4;

    /**
     * Дамп не был начат
     */
    public const START_STEP = 0;

    /**
     * Обработка таблиц
     */
    public const INIT_TABLE_STEP = 1;

    /**
     * Этап создания таблиц
     */
    public const CREATE_TABLE_STEP = 2;

    /**
     * Этап записи данных
     */
    public const INSERT_STEP = 3;

    /**
     * Этап создания процедур, триггеров
     */
    public const FUNCTION_STEP = 4;

    /**
     * Последний этап
     */
    public const FINISH_STEP = 5;

    public const STEP_DESCRIPTION = [
        0 => 'Подготовка к дампу',
        1 => 'Подготовка таблиц',
        2 => 'Экспорт описания таблиц',
        3 => 'Экспорт данных',
        4 => 'Экспорт процедур и тригерров',
        5 => 'Завершение дампа'
    ];

    /**
     * @var int
     */
    private $step = 0;

    /**
     * @var string
     */
    private $fileName = '';

    /**
     * @var TableState[]
     */
    private $tables = [];

    /**
     * @var TableState
     */
    private $currentTable;

    /**
     * @var int
     */
    private $startTime = 0;

    /**
     * @var int
     */
    private $timeout = 0;

    /**
     * @return array
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * @param string $table
     * @return mixed
     */
    public function getTable(string $table)
    {
        return $this->tables[$table];
    }

    public function initStartTime(): DumpState
    {
        $this->startTime = time();
        $this->timeout = ini_get('max_execution_time') - self::RESERVE_TIME_IN_SEC;
        return $this;
    }

    /**
     * Есть ли достаточно времени до завершения скрипта
     * @return bool
     */
    public function timeIsEnough(): bool
    {
        return !(time() - $this->startTime > $this->timeout);
    }

    /**
     * @return string
     */
    public function getFileName(): string
    {
        return $this->fileName;
    }

    /**
     * @param string $fileName
     */
    public function setFileName(string $fileName): void
    {
        $this->fileName = $fileName;
    }

    public function addTable(TableState $table): DumpState
    {
        $this->tables[$table->getTableName()] = $table;
        return $this;
    }

    public function tableExist(string $tableName): bool
    {
        return array_key_exists($tableName, $this->tables);
    }

    /**
     * @return int
     */
    public function getTotalTables(): int
    {
        return count($this->tables);
    }

    /**
     * @return int
     */
    public function getStep(): int
    {
        return $this->step;
    }

    public function nextStep(): DumpState
    {
        foreach ($this->tables as $table) {
            $table->setComplete(false);
        }
        $this->currentTable = null;
        $this->step++;
        return $this;
    }

    public function save()
    {
        session()->put(self::SESSION_KEY, $this);
        session()->save();
    }

    public static function clear()
    {
        session()->forget(self::SESSION_KEY);
        session()->save();
    }

    public static function getDumpState()
    {
        return session(self::SESSION_KEY) ?? new self();
    }

    /**
     * @return TableState|null
     */
    public function getCurrentTable(): ?TableState
    {
        return $this->currentTable;
    }

    /**
     * @param TableState $currentTable
     */
    public function setCurrentTable(TableState $currentTable): DumpState
    {
        $this->currentTable = $currentTable;
        return $this;
    }
}
