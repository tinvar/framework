<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/**
 * Something that can travel in a job's payload: a job, a mailable sent from
 * a worker, an event handed to a queued listener.
 *
 * It travels as its class and its constructor's arguments, read back from
 * its properties of the same names -- so every constructor parameter must
 * be a property, as a promoted one is -- and each argument must be a value
 * the payload carries (see Payload). It is rebuilt with `new`, never
 * unserialize(): a payload is data, and the classes it may name are only
 * those that say they can be named here.
 */
interface Queueable {}
