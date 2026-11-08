<?php

namespace TechLegend\LaravelNotifyAfrica\Exceptions;

/**
 * The requested resource does not exist for this API key's customer.
 *
 * SMS status lookups return HTTP 403 "Message not found or not authorized" for
 * malformed, unknown and other-customer message IDs alike; WABA template
 * lookups return 404. Both map here.
 */
class NotifyAfricaNotFoundException extends NotifyAfricaRequestException {}
