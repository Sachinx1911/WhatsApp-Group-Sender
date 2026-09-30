<?php

namespace App\Support;

use RuntimeException;

/** The image is readable but too big (in pixels) to process within PHP's memory. The message is safe to show the admin. */
class ImageTooLargeException extends RuntimeException {}
