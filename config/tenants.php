<?php

/*
|--------------------------------------------------------------------------
| Customer workspaces applied on every deploy
|--------------------------------------------------------------------------
|
| Each entry is created by `reserveworx:tenant:sync` after a deploy goes live.
| The command is idempotent: an existing workspace, location, owner or domain
| is left as it is. It never sets prices, tax, fees or refund terms. The owner
| gets one set-password link the first time they are added to the workspace.
| See docs/ONBOARDING.md.
|
*/

return [
    [
        'slug' => 'yakshack',
        'name' => 'Yak Shack',
        'owner_email' => 'michael@brandworx.ai',
        'timezone' => 'America/New_York',
    ],
];
