<?php
/**
 * Retired bootstrap endpoint.
 *
 * Production identity and Level A access are managed through Nexus/HR. This
 * file remains as a non-routable tombstone so an old bookmark cannot recreate
 * a local administrator or expose bootstrap credentials.
 */
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
exit('Not found');
