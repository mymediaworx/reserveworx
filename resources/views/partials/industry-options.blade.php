@foreach (\App\Enums\Industry::cases() as $option)
    <option value="{{ $option->value }}" @selected(($selected ?? null) === $option->value)>{{ $option->label() }}</option>
@endforeach
