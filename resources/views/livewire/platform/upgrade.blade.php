<div class="page upgrade-page">
    <style>
        @media (max-width: 390px) {
            .upgrade-page { min-width: 0; max-width: 100%; }
            .upgrade-page .page-head,
            .upgrade-page .upgrade-steps,
            .upgrade-page .upgrade-step,
            .upgrade-page .upgrade-step * { min-width: 0; overflow-wrap: anywhere; }
        }
    </style>
    <header class="page-head">
        <div>
            <p class="kicker">Master admin</p>
            <h1>Upgrade</h1>
            <p class="lede">{{ $intro }}</p>
        </div>
    </header>

    <section class="panel">
        <div class="panel-head"><h2>Procedure</h2></div>
        <ol class="upgrade-steps">
            @foreach ($steps as $step)
                <li class="upgrade-step">
                    <h3>{{ $step['title'] }}</h3>
                    <p>{{ $step['body'] }}</p>
                </li>
            @endforeach
        </ol>
    </section>
</div>
