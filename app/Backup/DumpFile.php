<?php


namespace App\Backup;

use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

final class DumpFile
{
    /**
     * @var string
     */
    private $fileName;

    /**
     * @var Filesystem
     */
    private $fileSystem;

    /**
     * DumpFile constructor.
     * @param $fileName
     */
    public function __construct($fileName = '')
    {
        $this->fileName = $fileName;
        $this->fileSystem = new Filesystem;
    }

    /**
     * Запись данных в файл
     * @param string $content
     * @return void
     */
    public function append(string $content): void
    {
        $fileName = $this->fileName;

        if (!is_file($this->getPath($fileName))) {
            $fileName = $this->makeFile();
        }

        if ($fileName) {
            $this->fileSystem->append($this->getPath($fileName), $content . PHP_EOL);
        }
    }

    /**
     * Создание файла
     * @return bool|string
     */
    public function makeFile()
    {
        $this->fileSystem->deleteDirectory($this->getPath());
        $this->fileSystem->makeDirectory($this->getPath());

        $fileName = $this->createName();
        $filePath = $this->getPath($fileName);

        if ($this->fileSystem->append($filePath, '-- Dump Start' . PHP_EOL)) {
            return $this->fileName = $fileName;
        }

        return '';
    }

    /**
     * URL для скачивания файла
     * @return string
     */
    public function fileUrl(): string
    {
        return Storage::url('dump/' . $this->fileName);
    }

    public function getSize(): string
    {
        return $this->fileSystem->size($this->getPath($this->fileName));
    }

    /**
     * Возвращает путь до директории или файла дампа
     * @param string $fileName
     * @return string
     */
    private function getPath($fileName = ''): string
    {
        return storage_path('app/public/dump/' . $fileName);
    }

    /**
     * Генерация имени файла
     * @return string
     */
    private function createName(): string
    {
        $now = Carbon::now();

        return sprintf(
            'backup-%s-%s.sql',
            $now->format('Ymd'),
            $now->format('Hi')
        );
    }
}
