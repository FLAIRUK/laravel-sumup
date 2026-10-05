<?php

namespace FLAIRUK\SumUp\Exceptions;

/**
 * The OAuth callback's state did not match the one stored in the session.
 */
class InvalidStateException extends OAuthException {}
