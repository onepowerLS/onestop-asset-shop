<?php
/** Retired diagnostic endpoint; use the non-sensitive health endpoint. */
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
exit('Not found');
