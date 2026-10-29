<div class="page feedback-page">
    <style>
        .feedback-page .when { overflow-wrap: anywhere; max-width: 100%; }
        @media (max-width: 390px) {
            .feedback-page .when { overflow-wrap: anywhere; max-width: 100%; }
        }
    </style>
    <header class="page-head">
        <div>
            <h1>Feedback</h1>
            @if ($count === 0 && ! $filtered)
                <p class="lede">No ratings yet.</p>
            @elseif ($count === 0)
                <p class="lede">No ratings match these filters.</p>
            @else
                <p class="lede">{{ $average }} average from {{ $count }} {{ $count === 1 ? 'rating' : 'ratings' }}.</p>
            @endif
            @if ($waiting > 0)
                <p class="muted">{{ $waiting }} feedback {{ $waiting === 1 ? 'request is' : 'requests are' }} waiting to send.</p>
            @endif
        </div>
    </header>

    @if ($flash)
        <p class="flash ok" role="status">{{ $flash }}</p>
    @endif

    <form class="filters" wire:submit.prevent>
        <label class="field compact">
            <span>Stars</span>
            <select wire:model.live="stars">
                <option value="">Any</option>
                @foreach ([5, 4, 3, 2, 1] as $star)
                    <option value="{{ $star }}">{{ $star }} {{ $star === 1 ? 'star' : 'stars' }}</option>
                @endforeach
            </select>
        </label>
        <label class="field compact">
            <span>Service</span>
            <select wire:model.live="service">
                <option value="">Any service</option>
                @foreach ($services as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="field compact">
            <span>Comment</span>
            <select wire:model.live="commented">
                <option value="">Any</option>
                <option value="yes">With a comment</option>
                <option value="no">Stars only</option>
            </select>
        </label>
    </form>

    @if ($ratings->isEmpty())
        <p class="empty">No ratings match these filters.</p>
    @else
        <div class="stack">
            @foreach ($ratings as $rating)
                <article class="panel" data-feedback="{{ $rating->id }}">
                    <div class="panel-head">
                        <h2>
                            <a href="/staff/bookings/{{ $rating->booking_id }}" wire:navigate>{{ $this->customerName($rating) }}</a>
                        </h2>
                        <p class="stars" aria-label="{{ $rating->rating }} out of 5">{{ $this->starsLabel($rating) }}</p>
                    </div>
                    <p class="muted"><span>{{ $this->serviceName($rating) }}</span> · <span class="when" data-when>{{ $this->whenLabel($rating) }}</span></p>
                    @if ($rating->comment)
                        <p>{{ $rating->comment }}</p>
                    @endif
                </article>
            @endforeach
        </div>
        @include('partials.pager', ['paginator' => $ratings])
    @endif

    @if ($canUpdate)
        <section class="panel top-gap">
            <h2>Feedback email</h2>
            <p class="muted">One email after a booking is returned or completed. The default wait is 2 hours.</p>
            <form wire:submit="saveSettings" class="stack">
                <label class="check">
                    <input type="checkbox" wire:model="feedbackEnabled">
                    <span>Send feedback email</span>
                </label>
                <label class="field compact">
                    <span>Send after (minutes)</span>
                    <input type="number" min="0" max="10080" wire:model="delayMinutes">
                </label>
                @error('delayMinutes')<p class="error">{{ $message }}</p>@enderror
                <label class="field">
                    <span>Public review link</span>
                    <input type="url" wire:model="reviewUrl" placeholder="https://g.page/your-shop/review" maxlength="2048">
                </label>
                @error('reviewUrl')<p class="error">{{ $message }}</p>@enderror
                <p class="muted">Shown only after a 4 or 5 star rating. Leave it blank to hide it.</p>
                <button type="submit" class="button primary">Save feedback settings</button>
            </form>
        </section>
    @endif
</div>
