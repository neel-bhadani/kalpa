<?php

/*
| Feature tests run inside a Laravel test case. Add RefreshDatabase here
| once there are tables to reset between tests.
*/

pest()->extend(Tests\TestCase::class)->in('Feature');
