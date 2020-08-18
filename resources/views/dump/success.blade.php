@extends('layouts.app')

@section('content')
    <div class="justify-content-center d-flex">
        {{ $message }}
    </div>
    <div class="justify-content-center d-flex">
        Размер файла {{ $file_size }}
    </div>
    <div class="justify-content-center d-flex">
        <a class="btn btn-primary" href="{{ $download_url }}">Скачать</a>
    </div>
@endsection
