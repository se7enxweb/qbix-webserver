<?php

/**
 * Handler for the metrics-privacy test's fallback route (Q.webserver.fallback
 * = {handler: "app/main"}). It exists only to make a fork-per-request worker
 * fail on purpose, so the 502/504 reaper records a metric:
 *
 *   ?boom=throw  -> throws, so the child exits non-zero and is recorded as 502
 *   ?boom=sleep  -> sleeps past requestTimeout, so it is killed and recorded 504
 */
function app_main_get($params)
{
	$mode = isset($_GET['boom']) ? $_GET['boom'] : '';
	if ($mode === 'sleep') {
		sleep(30);
		echo 'slept';
		return;
	}
	throw new RuntimeException('deliberate failure for a 502 metric');
}
