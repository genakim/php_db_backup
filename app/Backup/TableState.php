<?php


namespace App\Backup;


final class TableState
{
    public const BASE_TYPE = 1;
    public const VIEW_TYPE = 2;

    /**
     * @var int
     */
    private $type;

    /**
     * @var string
     */
    private $tableName;

    /**
     * @var null|int
     */
    private $last_id = 0;

    /**
     * @var int
     */
    private $totalRows;

    /**
     * @var bool
     */
    private $complete = false;

    /**
     * TableState constructor.
     * @param string $tableName
     * @param int $totalRows
     * @param int $type
     */
    public function __construct(string $tableName, int $totalRows, int $type)
    {
        $this->tableName = $tableName;
        $this->totalRows = $totalRows;
        $this->type = $type;
    }

    /**
     * @return string
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Номер последней сохраненной строки
     * @return mixed
     */
    public function getLastId()
    {
        return $this->last_id;
    }

    /**
     * @param int $last_id
     */
    public function setLastId(int $last_id): void
    {
        $this->last_id = $last_id;
    }

    /**
     * Кол-во обрабатываемых таблиц
     * @return int
     */
    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    /**
     * @param int $totalRows
     */
    public function setTotalRows(int $totalRows): void
    {
        $this->totalRows = $totalRows;
    }

    /**
     * Дамп данных по таблице завершен
     * @return bool
     */
    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * @param bool $complete
     */
    public function setComplete(bool $complete): void
    {
        $this->complete = $complete;
    }

    /**
     * @return int
     */
    public function getType(): int
    {
        return $this->type;
    }

    /**
     * @param int $type
     */
    public function setType(int $type): void
    {
        $this->type = $type;
    }
}
