@extends('layouts.app')

@section('content')
    <div class="justify-content-center d-flex">
        {{ $message }}: {{ $step }}<br>
        Текущая таблица: {{ $table }}<br>
        Всего строк в таблице: {{ $total_rows}}<br>
        Обработано строк: {{ $last_row }}<br>
    </div>
@endsection
