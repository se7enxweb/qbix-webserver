<?php
/**
 * @module Q
 */
/**
 * Keeps certificate events in the bounded history the control panel's SSL
 * tab shows (Q_WebServer_Certificate_Admin::record). The per-provider
 * "trying" and "skipped" steps are left to the console reporter: the history
 * is for what happened, not how each attempt was made.
 *
 * @class Q_WebServer_Certificate_History
 */
class Q_WebServer_Certificate_History implements Q_WebServer_Certificate_Observer
{
	function update($event, array $info)
	{
		if ($event === 'trying' or $event === 'skipped') return;
		Q_WebServer_Certificate_Admin::record($event, $info);
	}
}
