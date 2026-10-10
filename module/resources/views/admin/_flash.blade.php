@if (session('hytale_flash'))
    <div class="alert alert-{{ session('hytale_flash')['type'] }}">{{ session('hytale_flash')['message'] }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif
