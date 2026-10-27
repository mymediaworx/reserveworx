<div class="page handbook-page">
    <style>
        @media (max-width: 390px) {
            .handbook-page { min-width: 0; max-width: 100%; }
            .handbook-page .page-head,
            .handbook-page .handbook-list,
            .handbook-page .handbook-topic,
            .handbook-page .handbook-topic * { min-width: 0; overflow-wrap: anywhere; }
        }
    </style>
    <header class="page-head">
        <div>
            <p class="kicker">Master admin</p>
            <h1>Admin handbook</h1>
            <p class="lede">How a platform administrator runs ReserveWorx. Opening this page does not change a workspace, a price, a payment, a tax, a fee, or a refund.</p>
        </div>
    </header>

    @foreach ($sections as $section)
        <section class="panel">
            <div class="panel-head"><h2>{{ $section['heading'] }}</h2></div>
            <ul class="handbook-list">
                @foreach ($section['topics'] as $topic)
                    <li class="handbook-topic">
                        <a href="{{ $topic['href'] }}" wire:navigate>{{ $topic['title'] }}</a>
                        <p>{{ $topic['body'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
