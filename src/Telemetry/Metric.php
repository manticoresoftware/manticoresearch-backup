<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Telemetry;

use ErrorException;
use Exception;
use OpenMetricsPhp\Exposition\Text\Collections\CounterCollection;
use OpenMetricsPhp\Exposition\Text\Metrics\Counter;
use OpenMetricsPhp\Exposition\Text\Types\Label;
use OpenMetricsPhp\Exposition\Text\Types\MetricName;

/**
 * For more information of the exporter check the url
 * https://github.com/openmetrics-php/exposition-text
 *
 * Usage:
 * <code>
 * 	$metric = new Metric('127.0.0.1', labels: ['version' => '1.0', 'columnar' => '5.2.3']);
 * 	$metric->add('metric1', 1);
 * 	$metric->add('metric1', 13);
 * 	$metric->add('metric1', 156);
 * 	$metric->add('metric2', 1000);
 * 	$metric->add('metric2', 1000);
 * 	if ($metric->send()) {
 * 		echo 'OK';
 * 	}
 * </code>
 */
final class Metric {
	// The writing path for prometheus metrics
	const API_PATH = '/api/v1/import/prometheus';

	// Request timeout in seconds
	const REQUEST_TIMEOUT = 1;

	/** @var array<string,CounterCollection> */
	protected array $metrics = [];

	/** @var array<int,Label> */
	protected array $labels = [];

	/**
	 * Initialize Metric with host and port to Prometheus
	 *
	 * @param string $host
	 * @param int $port
	 * 	Default is 8428
	 * @param array<string,string> $labels
	 * 	Optional labels if we need to attach it to every metric we register
	 * @return void
	 */
	public function __construct(protected string $host, protected int $port = 8428, array $labels = []) {
		if (!$labels) {
			return;
		}

		foreach ($labels as $name => $value) {
			$this->labels[] = Label::fromNameAndValue($name, $value);
		}
	}

	/**
	 * Register a metric that will be send on calling send method
	 *
	 * @param string $name
	 * 	The name of the metric
	 * @param int|float $value
	 * 	Number value of the metric
	 * @return static
	 */
	public function add(string $name, int|float $value): static {
		$counter = Counter::fromValue($value);
		if ($this->labels) {
			$counter->withLabels(...$this->labels);
		}

		if (isset($this->metrics[$name])) {
			$this->metrics[$name]->add($counter);
			return $this;
		}

		// We recieved this metric for the first time
		$this->metrics[$name] = CounterCollection::fromCounters(
			MetricName::fromString($name),
			$counter
		);

		return $this;
	}

	/**
	 * Send registered batch of metrics to the server
	 *
	 * @return bool
	 */
	public function send(): bool {
		$groups = [];
		/** @var CounterCollection $collection */
		foreach ($this->metrics as $collection) {
			$groups[] = $collection->getMetricsString();
		}

		$body = implode(PHP_EOL, $groups);

		return $this->process($body);
	}

	/**
	 * Helper function to make request and send data to server
	 *
	 * @param string $body
	 * @return bool
	 * @throws Exception
	 */
	protected function process(string $body): bool {
		$content = gzencode($body, 6);
		if ($content === false) {
			throw new Exception('Failed to gzip data to send');
		}

		$opts = [
			'http' => [
				'method'  => 'POST',
				'header'  => "Content-Encoding: gzip\n"
					. "Content-Type: application/x-www-form-urlencoded\n"
		  . 'Content-Length: '. strlen($content),
				'content' => $content,
				'ignore_errors' => false,
				'timeout' => static::REQUEST_TIMEOUT,
			],
		];

		$context = stream_context_create($opts);
		try {
			$result = file_get_contents(
				'http://' . $this->host . ':' . $this->port . static::API_PATH,
				false,
				$context
			);
		} catch (ErrorException) {
			return false;
		}

		return $result === '';
	}
}
