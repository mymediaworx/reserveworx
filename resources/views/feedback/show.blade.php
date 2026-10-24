@extends('widget.layout')

@section('title', 'Rate your visit')

@section('content')
    <style>
        textarea { font: inherit; font-size: 1rem; color: var(--ink); padding: .6rem .75rem; border: 1px solid var(--line-strong); border-radius: 10px; background: #fff; width: 100%; min-height: 7rem; }
        .thanks { background: #fff; border: 1px solid var(--line); border-radius: 16px; box-shadow: var(--shadow); padding: 1.25rem; display: grid; gap: .75rem; }
        .stars { font-size: 1.4rem; letter-spacing: .08em; margin: 0; }
        .quiet { color: var(--muted); font-size: .9rem; }
    </style>

    <h1>How was {{ $serviceName }}?</h1>

    @if ($feedback->rated_at)
        <div class="thanks">
            <p class="stars" aria-label="{{ $feedback->rating }} out of 5">{{ str_repeat('★', (int) $feedback->rating) }}{{ str_repeat('☆', max(0, 5 - (int) $feedback->rating)) }}</p>
            <p>Thanks for rating your visit.</p>
            @if ($feedback->comment)
                <p>{{ $feedback->comment }}</p>
            @endif
            @if ($reviewUrl)
                <p><a href="{{ $reviewUrl }}" rel="noopener noreferrer" target="_blank">Share a public review</a></p>
            @endif
        </div>
    @else
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif
        <form method="POST" action="{{ request()->fullUrl() }}">
            @csrf
            <fieldset>
                <legend>Rating</legend>
                @for ($star = 1; $star <= 5; $star++)
                    <label>
                        <input type="radio" name="rating" value="{{ $star }}" @checked((int) old('rating') === $star) required>
                        {{ $star }} {{ $star === 1 ? 'star' : 'stars' }}
                    </label>
                @endfor
            </fieldset>
            <label>
                Comment
                <textarea name="comment" maxlength="2000" rows="4">{{ old('comment') }}</textarea>
            </label>
            <button type="submit">Send rating</button>
        </form>
    @endif

    <p class="quiet">
        @if ($optedOut)
            You will not get more feedback emails.
        @else
            <a href="{{ $optOutUrl }}">Don't ask me for feedback again</a>
        @endif
    </p>
@endsection
