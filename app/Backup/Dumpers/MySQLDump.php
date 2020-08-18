<?php


namespace App\Backup\Dumpers;

use App\Backup\TableState;
use Illuminate\Support\Facades\DB;
use App\Backup\DumpFile;
use App\Backup\DumpState;
use phpDocumentor\Reflection\Types\Self_;

final class MySQLDump
{
    /**
     * Минимальное кол-во строк, которое будет записано в файл
     */
    public const MIN_ROWS_TO_WRITE = 500;

    /**
     * Кол-во строк получаемых за раз
     */
    private const LIMIT = 1000;

    /**
     * @var DumpFile
     */
    private $dumpFile;

    /**
     * @var DumpState
     */
    private $dumpState;

    /**
     * @var string
     */
    private $dbName;

    /**
     * @var string
     */
    private $delimiter = ';';

    /**
     * MySQL constructor.
     */
    public function __construct()
    {
        $this->dumpState = DumpState::getDumpState();
        $this->dumpFile = new DumpFile($this->dumpState->getFileName());

        // фиксируем время, чтобы вычислить время завершения скрипта
        $this->dumpState->initStartTime()->save();

        $this->dbName = $this->getDBName();
    }

    /**
     * Создание дампа
     * @return bool true - успешно завершено, false - в процессе
     * @throws \Exception
     */
    public function dump(): bool
    {
        try {

            // STEP 0: начало дампа
            if ($this->dumpState->getStep() === $this->dumpState::START_STEP) {
                $this->lockTables($this->getTables());

                $this->dumpState->setFileName($this->dumpFile->makeFile());
                $this->dumpFile->append($this->commonStatements());
                $this->dumpState->nextStep()->save();
            }

            if ($this->dumpState->getStep() === $this->dumpState::INIT_TABLE_STEP) {

                $tables = $this->getTables();
                foreach ($tables as $table) {

                    if (!$this->dumpState->timeIsEnough()) {
                        return false;
                    }

                    $tableName = $table['table'];

                    if (!$this->dumpState->tableExist($tableName)) {
                        $total = 0;
                        if ($table['type'] === TableState::BASE_TYPE) {
                            $total = DB::table($tableName)->count();
                        }

                        $this->dumpState
                            ->addTable(new TableState($tableName, $total, $table['type']))
                            ->save();
                    }
                }

                $this->dumpState->nextStep()->save();
            }


            // STEP 1: начало дампа удаление, создание таблиц
            if ($this->dumpState->getStep() === $this->dumpState::CREATE_TABLE_STEP) {

                foreach ($this->dumpState->getTables() as &$tableState) {

                    if (!$this->dumpState->timeIsEnough()) {
                        return false;
                    }

                    if ($tableState->isComplete()) {
                        continue;
                    }

                    $this->dumpFile->append($this->dropTableStatement($tableState));
                    $this->dumpFile->append($this->createTableStatement($tableState));
                    $tableState->setComplete(true);
                }

                $this->dumpState->nextStep()->save();
            }


            // STEP 3: обработка строк
            if ($this->dumpState->getStep() === $this->dumpState::INSERT_STEP) {

                foreach ($this->dumpState->getTables() as &$tableState) {

                    $this->dumpState->setCurrentTable($tableState)->save();

                    if (!$this->dumpState->timeIsEnough()) {
                        return false;
                    }

                    if ($tableState->isComplete()) {
                        continue;
                    }

                    $strInsert = '';

                    if ($tableState->getLastId() === 0) {
                        $strInsert .= $this->insertStatement($tableState);
                    }

                    while (!$tableState->isComplete() && $tableState->getTotalRows()) {

                        $rows = $this->getRows(
                            $tableState->getTableName(),
                            $tableState->getLastId()
                        );

                        $chunkSize = 0;
                        $rowIndex = $tableState->getLastId();

                        foreach ($rows as $row) {

                            $chunkSize++;
                            $rowIndex++;

                            $isLast = $rowIndex >= $tableState->getTotalRows();

                            $row = (array)$row;
                            $strInsert .= $this->valueStatement($row, $isLast);

                            // время жизни скрипта
                            if (!$this->dumpState->timeIsEnough()) {
                                $this->dumpFile->append($strInsert);
                                $tableState->setLastId($rowIndex);
                                return false;
                            }

                            // записываем в дамп при достижении достаточного кол-ва строк
                            if ($chunkSize === self::MIN_ROWS_TO_WRITE) {
                                $this->dumpFile->append($strInsert);
                                $tableState->setLastId($rowIndex);

                                $strInsert = '';
                                $chunkSize = 0;
                            }

                            // выборка закончилась
                            if ($isLast) {
                                $this->dumpFile->append($strInsert);
                                $tableState->setLastId($rowIndex);
                                $tableState->setComplete(true);
                            }
                        }
                    }
                }

                $this->dumpState->nextStep()->save();
            }

            // STEP 4: обработка процедур, тригеров
            if ($this->dumpState->getStep() === $this->dumpState::FUNCTION_STEP) {

                if (!$this->dumpState->timeIsEnough()) {
                    return false;
                }

                $this->dumpFile->append($this->dropTriggersStatement());
                $this->dumpFile->append($this->dropProceduresStatement());
                $this->dumpFile->append($this->setDelimiter('$$'));
                $this->dumpFile->append($this->createProceduresStatement());
                $this->dumpFile->append($this->createTriggersStatement());
                $this->dumpFile->append($this->setDelimiter(';'));

                $this->dumpState->nextStep()->save();
            }

            // STEP 5: закрытие дампа
            if ($this->dumpState->getStep() === $this->dumpState::FINISH_STEP) {

                if (!$this->dumpState->timeIsEnough()) {
                    return false;
                }

                $this->dumpFile->append($this->restoreSystemVariables());

                $this->dumpState::clear();
                $this->unlockTables();

                return true;
            }

        } catch (\Exception $e) {

            $this->dumpState::clear();
            $this->unlockTables();

            throw $e;
        }
    }

    /**
     * @return DumpFile
     */
    public function getDumpFile(): DumpFile
    {
        return $this->dumpFile;
    }

    /**
     * @return DumpState
     */
    public function getDumpState(): DumpState
    {
        return $this->dumpState;
    }


    /**
     * Название и тип всех таблиц
     */
    private function getTables(): array
    {
        $items = DB::select("SHOW FULL TABLES");

        if (empty($items)) {
            return [];
        }

        $dbName = $this->dbName;
        $tables = [];

        foreach ($items as $item) {
            $tables[] = [
                'table' => $item->{'Tables_in_' . $dbName},
                'type' =>  $item->Table_type === 'BASE TABLE' ? 1 : 2
            ];
        }

        return $tables;
    }

    /**
     * Название БД
     */
    private function getDBName(): string
    {
        return DB::select("SELECT DATABASE() AS DB")[0]->DB;
    }

    /**
     * Блокировка таблиц на запись
     * @param array $tables
     */
    private function lockTables(array $tables): void
    {
        $tmp = [];
        foreach ($tables as $table) {
            $tmp[] = sprintf('`%s` READ', $table['table']);
        }

        $this->statementQuery(sprintf('LOCK TABLES %s', implode(', ', $tmp)));
    }

    /**
     * Снятие блокировки таблиц
     */
    private function unlockTables(): void
    {
        $this->statementQuery('UNLOCK TABLES');
    }

    private function commonStatements(): string
    {
        // отключаем проверку внешних ключей
        $str = "SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;" . PHP_EOL;

        // кодировка
        $str .= "SET NAMES 'utf8';" . PHP_EOL;

        // БД по умолчанию
        $str .= sprintf("USE %s;%s", $this->dbName, PHP_EOL);

        return $str;
    }

    private function restoreSystemVariables(): string
    {
        $str = '-- Restore system variables --' . PHP_EOL;
        $str .= 'SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;' . PHP_EOL;
        return $str;
    }

    /**
     * Описание Create Table
     * @param $table
     * @return string
     */
    private function createTableStatement(TableState $table): string
    {
        $item = DB::select(sprintf("SHOW CREATE TABLE `%s`", $table->getTableName()))[0];

        if ($table->getType() === $table::VIEW_TYPE) {
            $str = sprintf('%s;', preg_replace("/(?<=CREATE\s).+(?=\sVIEW)/i", '', $item->{'Create View'}));
        } else {
            $str =  sprintf('%s;', $item->{'Create Table'});
        }

        return $str . PHP_EOL;
    }

    /**
     * @param TableState $tableState
     * @return string
     */
    private function indexesStatement(TableState $tableState): string
    {
        // получаем внешние ключи таблицы
        $fKeys = DB::select(
            sprintf("
                SELECT
                TABLE_NAME,
                COLUMN_NAME,
                CONSTRAINT_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
                FROM information_schema.key_column_usage
                WHERE referenced_table_name is not null and TABLE_NAME = '%s'", $tableState->getTableName())
        );

        $fkNames = [];
        $str = '';
        foreach ($fKeys as $fKey) {
            $str .= sprintf('ALTER TABLE `%s` ADD CONSTRAINT %s FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`);' . PHP_EOL,
                $fKey->TABLE_NAME,
                $fKey->CONSTRAINT_NAME,
                $fKey->COLUMN_NAME,
                $fKey->REFERENCED_TABLE_NAME,
                $fKey->REFERENCED_COLUMN_NAME
            );

            $fkNames[] = $fKey->CONSTRAINT_NAME;
        }

        // получаем индексы
        $indexes = DB::select(sprintf("SHOW INDEXES IN `%s`;", $tableState->getTableName()));
        $groupIndexes = collect($indexes)->sortBy('Seq_in_index')->groupBy('Key_name');

        $groupIndexes->each(function ($sub, $key) use ($fkNames, &$str, $tableState) {

            // игнорируем первичные или внешние ключи
            // т.к. индексы для них будут созданы БД автоматически
            if ($key === 'PRIMARY' || in_array($key, $fkNames, true)) {
                return;
            }

            $unique = $sub->first()->Non_unique ? '' : 'UNIQUE';
            $columns = $sub->pluck('Column_name')->toArray();

            $str .= sprintf('ALTER TABLE `%s` ADD %s INDEX %s (%s);' . PHP_EOL,
                $tableState->getTableName(),
                $unique,
                $key,
                implode(', ', $columns)
            );
        });

        return $str;
    }

    /**
     * Хранимые процедуры
     * @return string
     */
    private function createProceduresStatement(): string
    {
        $str = '-- Procedures --' . PHP_EOL;

        $items = DB::select(sprintf("SHOW PROCEDURE STATUS WHERE Db = '%s'", $this->getDBName()));
        foreach ($items as $item) {
            $desc = DB::select(sprintf('SHOW CREATE PROCEDURE %s', $item->Name))[0];
            $str .= sprintf('%s%s', $desc->{'Create Procedure'}, $this->delimiter) . PHP_EOL;
        }

        return preg_replace("/DEFINER.+\s/Ui", '', $str);
    }

    private function dropProceduresStatement(): string
    {
        $items = DB::select(sprintf("SHOW PROCEDURE STATUS WHERE Db = '%s'", $this->getDBName()));

        $str = '';
        if (!empty($items)) {
            $str .= '-- Drop procedures --' . PHP_EOL;
        }

        foreach ($items as $item) {
            $str .= sprintf('DROP PROCEDURE IF EXISTS %s;', $item->Name) . PHP_EOL;
        }

        return $str;
    }

    /**
     * Тригеры
     * @return string
     */
    private function createTriggersStatement(): string
    {
        $str = '-- Triggers --' . PHP_EOL;

        $items = DB::select('SHOW TRIGGERS');
        foreach ($items as $item) {
            $desc = DB::select(sprintf('SHOW CREATE TRIGGER %s', $item->Trigger))[0];
            $str .= sprintf('%s%s', $desc->{'SQL Original Statement'}, $this->delimiter) . PHP_EOL;
        }

        return preg_replace("/DEFINER.+\s/Ui", '', $str);
    }

    private function dropTriggersStatement(): string
    {
        $items = DB::select('SHOW TRIGGERS');

        $str = '';
        if (!empty($items)) {
            $str .= '-- Drop triggers --' . PHP_EOL;
        }

        foreach ($items as $item) {
            $str .= sprintf('DROP TRIGGER IF EXISTS %s;', $item->Trigger) . PHP_EOL;
        }

        return $str;
    }

    /**
     * @param TableState $tableState
     * @return string
     */
    private function dropTableStatement(TableState $tableState): string
    {
        $str = 'DROP TABLE IF EXISTS';

        if ($tableState->getType() === TableState::VIEW_TYPE) {
            $str = 'DROP VIEW IF EXISTS';
        }

        return sprintf('%s `%s`;', $str, $tableState->getTableName()) . PHP_EOL;
    }

    /**
     * Выборка строк по таблице
     * @param $table
     * @param $last_id
     * @return array
     */
    private function getRows($table, $last_id): array
    {
        return DB::table($table)
            ->offset($last_id)
            ->limit(self::LIMIT)
            ->get()
            ->toArray();
    }

    private function insertStatement(TableState $tableState): string
    {
        return sprintf('INSERT INTO `%s` VALUES ', $tableState->getTableName()) . PHP_EOL;
    }

    /**
     * @param array $row
     * @param bool $isLast
     * @return string
     */
    private function valueStatement(array $row, bool $isLast = false): string
    {
        $values = [];

        // переводим значения в нужный вид
        foreach ($row as $value) {
            if (is_null($value)) {
                $value = 'NULL';
            } elseif (is_string($value)) {
                $value = DB::connection()->getPdo()->quote($value);
            }
            $values[] = $value;
        }

        return sprintf('(%s)%s',
                implode(", ", $values), $isLast ? ';' : ','
            )  . PHP_EOL;
    }

    /**
     * @param string $delimiter
     * @return string
     */
    private function setDelimiter($delimiter): string
    {
        $this->delimiter = $delimiter;
        return 'DELIMITER ' . $delimiter;
    }

    /**
     * Laravel имеет баг, при котором не все запросы SQL могут выполнится штатными средствами.
     * Как вариант работать через PDO.
     * @param string $sql
     */
    private function statementQuery(string $sql): void
    {
        DB::connection()->getpdo()->exec($sql);
    }
}
