<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Backup\Dumpers\MySQLDump;
use App\Backup\DumpState;

class BackupController extends Controller
{

    public function index()
    {
        session()->forget(DumpState::SESSION_KEY);
        session()->save();

        return view('index', [
            'message' => 'Начать создание дампа БД?',
            'dump_route' => route('dump')
        ]);
    }

    public function dump(MySQLDump $dumper)
    {
        try {
            $status = $dumper->dump();

            if ($status === true) {
                return view('dump.success', [
                    'message' => 'Дамп БД успешно создан!',
                    'download_url' => $dumper->getDumpFile()->fileUrl(),
                    'file_size' => sprintf('%.2f кб', ($dumper->getDumpFile()->getSize() / 1024))
                ]);
            }

            // дамп в процессе
            if ($status === false) {

                $currentTable = $dumper->getDumpState()->getCurrentTable();

                return view('dump.index', [
                    'message' => 'Идет процесс дампа',
                    'step' => $dumper->getDumpState()::STEP_DESCRIPTION[$dumper->getDumpState()->getStep()],
                    'table' => $currentTable ? $currentTable->getTableName() : '',
                    'last_row' => $currentTable ? $currentTable->getLastId() : '',
                    'total_rows' => $currentTable ? $currentTable->getTotalRows() : '',
                    'refresh' => 0
                ]);
            }

        } catch (\Exception $e) {
            return view('dump.failure', [
                'message' => 'Произошла ошибка. Дамп не создан',
                'error' => $e->getMessage()
            ]);
        }
    }
}
