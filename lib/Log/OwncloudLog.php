<?php
/**
 * @author Piotr Mrowczynski <piotr@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Diagnostics\Log;

/**
 * logging utilities
 *
 * Log is saved at data/diagnostics.log (on default)
 */

use OCP\IConfig;

class OwncloudLog {
	const EVENT_TYPE = 'EVENT';
	const QUERY_TYPE = 'QUERY';
	const SUMMARY_TYPE = 'SUMMARY';

	/**
	 * @param string
	 */
	private $logFile;

	/**
	 * array(
	 * 	array(
	 * 		'entry' => {"type":"QUERY","reqId":"...},
	 * 		'timestamp' => 1504537527.3142
	 * 	),
	 *  ....
	 * )
	 *
	 * @param mixed[][]
	 */
	private $logsQueue = [];

	/**
	 * @param \OCP\IConfig $config
	 */
	private $config;
	
	/**
	 * @param \OCP\IConfig $config
	 */
	public function __construct(IConfig $config) {
		$this->config = $config;
		$this->logFile = $this->config->getSystemValue("datadirectory", \OC::$SERVERROOT.'/data').'/diagnostic.log';
	}
	
	/**
	 * Write a message in the log queue.
	 *
	 * Log message will be written on the disk only
	 * after commiting for performance reseaons
	 *
	 * Queued logs will be ordered by timestamp or
	 * appended at the end in case ($timestamp=false)
	 *
	 * @param string $type
	 * @param string[] $diagnostics
	 * @param float|false $timestamp -
	 */
	public function write($type, $diagnostics, $timestamp = false) {
		$request = \OC::$server->getRequest();
		$reqId = $request->getId();
		
		if ($type === self::SUMMARY_TYPE) {
			// Log full info in case of SUMMARY_TYPE
			$format = $this->config->getSystemValue('logdateformat', 'c');
			$logTimeZone = $this->config->getSystemValue( "logtimezone", 'UTC' );
			try {
				$timezone = new \DateTimeZone($logTimeZone);
			} catch (\Exception $e) {
				$timezone = new \DateTimeZone('UTC');
			}
			$time = \DateTime::createFromFormat("U.u", number_format(microtime(true), 4, ".", ""));
			if ($time === false) {
				$time = new \DateTime(null, $timezone);
			} else {
				// apply timezone if $time is created from UNIX timestamp
				$time->setTimezone($timezone);
			}
			$remoteAddr = $request->getRemoteAddress();
			// remove username/passwords from URLs before writing the to the log file
			$time = $time->format($format);
			$url = ($request->getRequestUri() !== '') ? $request->getRequestUri() : '--';
			$method = is_string($request->getMethod()) ? $request->getMethod() : '--';
			if(\OC::$server->getConfig()->getSystemValue('installed', false)) {
				$user = (\OC_User::getUser()) ? \OC_User::getUser() : '--';
			} else {
				$user = '--';
			}

			$entry = compact(
				'type',
				'reqId',
				'time',
				'remoteAddr',
				'user',
				'method',
				'url',
				'diagnostics'
			);
		} else {
			// Log only reqId and its type if QUERY_TYPE or EVENT_TYPE
			$entry = compact(
				'type',
				'reqId',
				'diagnostics'
			);
		}

		$entry = json_encode($entry);
		$this->logsQueue[] = array('entry' => $entry, 'timestamp' => $timestamp);
	}

	private function sortLogsQueue() {
		usort($this->logsQueue, function($a, $b) {
			if ($a['timestamp'] === false) {
				return -1;
			} else if ($b['timestamp'] === false) {
				return 1;
			} else if ($b['timestamp'] === $a['timestamp']) {
				return 0;
			}
			return ($b['timestamp'] > $a['timestamp']) ? 1 : -1;
		});
	}

	/**
	 * Remove entries from the queue and write to disk (logfile)
	 */
	public function commit() {
		$this->sortLogsQueue();
		$handle = @fopen($this->logFile, 'a');
		@chmod($this->logFile, 0640);
		if ($handle) {
			$entry = array_pop($this->logsQueue);
			while($entry != null) {
				fwrite($handle, $entry['entry']."\n");
				$entry = array_pop($this->logsQueue);
			}
			fclose($handle);
		} else {
			// Fall back to error_log
			error_log("Diagnostic logger could not open file ".$this->logFile);
		}
	}

	/**
	 * clean the log
	 */
	public function clean() {
		$handle = @fopen($this->logFile, 'w');
		@chmod($this->logFile, 0640);
		if ($handle) {
			fclose($handle);
		}
	}

	/**
	 * @return string
	 */
	public function getLogFilePath() {
		return $this->logFile;
	}
}