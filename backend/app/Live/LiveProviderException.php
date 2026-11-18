<?php

namespace App\Live;

use RuntimeException;

/** Provider not configured or the remote call failed; rendered as 422/502 by controllers. */
class LiveProviderException extends RuntimeException {}
