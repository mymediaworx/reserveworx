<?php

namespace App\Services\Team;

use RuntimeException;

/**
 * The signed link is intact, but the invitation was used, revoked, or replaced.
 */
final class InviteClosed extends RuntimeException {}
