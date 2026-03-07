<?php
/**
 * Lightweight health check endpoint.
 * Returns 200 without loading WordPress — minimal overhead for container healthchecks.
 */
http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';
