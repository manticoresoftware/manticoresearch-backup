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
use OpenMetrics\Exposition\Text\Collections\CounterCollection;
use OpenMetrics\Exposition\Text\Metrics\Counter;
use OpenMetrics\Exposition\Text\Types\Label;
use OpenMetrics\Exposition\Text\Types\MetricName;

/**
 * For more information of the exporter check the url
 * https://github.com/manticoresoftware/openmetrics
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
	const PROTO = 'https';
	const HOST = 'telemetry.manticoresearch.com';
	const PORT = 443;

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
	 * @param array<string,string> $labels
	 * 	Optional labels if we need to attach it to every metric we register
	 * @return void
	 */
	public function __construct(array $labels = []) {
		if (!$labels) {
			return;
		}

		$this->addLabelList($labels);
	}

	/**
	 * Add single label to the current instance that will be used for all metrics
	 *
	 * @param string $name
	 * @param string $value
	 * @return $this
	 */
	public function addLabel(string $name, string $value): static {
		$this->labels[] = Label::fromNameAndValue($name, $value);
		return $this;
	}

	/**
	 * Add list of labels for single call
	 *
	 * @param array<string,string> $labels
	 * @return $this
	 */
	public function addLabelList(array $labels): static {
		foreach ($labels as $name => $value) {
			$this->addLabel($name, $value);
		}
		return $this;
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
				static::PROTO . '://' . static::HOST . ':' . static::PORT . static::API_PATH,
				false,
				$context
			);
		} catch (ErrorException) {
			return false;
		}

		return $result === '';
	}
}
