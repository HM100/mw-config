<?php

use MediaWiki\Logger\LoggerFactory;
use Monolog\Handler\WhatFailureGroupHandler;

// Monolog logging configuration

$wmgMonologProcessors = [
	'wiki' => [
		'class' => \MediaWiki\Logger\Monolog\WikiProcessor::class,
	],
	'psr' => [
		'class' => \Monolog\Processor\PsrLogMessageProcessor::class,
	],
	'web' => [
		'class' => \Monolog\Processor\WebProcessor::class,
	],
	'mhconfig' => [
		'factory' => static function () {
			return static function ( array $record ) {
				global $wgLBFactoryConf, $wgDBname;
				$record['extra']['shard'] = $wgLBFactoryConf['sectionsByDB'][$wgDBname] ?? 'c1';

				return $record;
			};
		}
	],
];

$wmgMonologHandlers = [
	'blackhole' => [
		'class' => \Monolog\Handler\NullHandler::class,
	],
];

foreach ( [ 'debug', 'info', 'warning', 'error' ] as $logLevel ) {
	$wmgMonologHandlers[ "graylog-$logLevel" ] = [
		'class'     => \MediaWiki\Logger\Monolog\SyslogHandler::class,
		'formatter' => 'logstash',
		'args'      => [
			'mediawiki', // tag
			'127.0.0.1', // host
			10514,       // port
			LOG_USER,    // facility
			$logLevel,   // log level threshold
		],
	];
}

$wmgMonologHandlers['what-debug'] = [
	'class'     => WhatFailureGroupHandler::class,
	'formatter' => 'logstash',
	'args' => [
		static function () {
			$provider = LoggerFactory::getProvider();
			return array_map( [ $provider, 'getHandler' ], [ 'graylog-debug' ] );
		}
	],
];

// Post construction calls to make for new Logger instances
$wmgMonologLoggerCalls = [
	'setTimezone' => [ new DateTimeZone( 'UTC' ) ],
	// https://phabricator.wikimedia.org/T116550 - Requires Monolog > 1.17.2
	'useMicrosecondTimestamps' => [ false ],
];

$wmgMonologConfig = [
	'loggers' => [
		// Template for all undefined log channels
		'@default' => [
			'handlers' => [ 'what-debug' ],
			'processors' => array_keys( $wmgMonologProcessors ),
			'calls' => $wmgMonologLoggerCalls,
		],
	],
	'processors' => $wmgMonologProcessors,
	'handlers' => $wmgMonologHandlers,
	'formatters' => [
		'logstash' => [
			'class' => \MediaWiki\Logger\Monolog\LogstashFormatter::class,
			'args' => [ 'mediawiki', php_uname( 'n' ), '', '', 1 ],
		],
	],
];

// Add logging channels defined in $wmgMonologChannels
foreach ( $wmgMonologChannels as $channel => $opts ) {
	if ( $opts === false ) {
		// Log channel disabled on this wiki
		$wmgMonologConfig['loggers'][$channel] = [
			'handlers' => [ 'blackhole' ],
			'calls' => $wmgMonologLoggerCalls,
		];
		continue;
	}

	$opts = is_array( $opts ) ? $opts : [ 'graylog' => $opts ];
	$opts = array_merge(
		[
			'graylog' => 'debug',
			'buffer' => false,
			'sample' => false,
		],
		$opts
	);

	$handlers = [];

	// Configure Logstash handler
	if ( $opts['graylog'] ) {
		$level = $opts['graylog'];
		$graylogHandler = "graylog-{$level}";
		if ( isset( $wmgMonologHandlers[ $graylogHandler ] ) ) {
			$handlers[] = $graylogHandler;
		}
	}

	if ( $opts['sample'] ) {
		$sample = $opts['sample'];
		foreach ( $handlers as $idx => $handlerName ) {
			$sampledHandler = "{$handlerName}-sampled-{$sample}";
			if ( !isset( $wmgMonologConfig['handlers'][$sampledHandler] ) ) {
				// Register a handler that will sample the event stream and
				// pass events on to $handlerName for storage
				$wmgMonologConfig['handlers'][$sampledHandler] = [
					'class' => \Monolog\Handler\SamplingHandler::class,
					'args' => [
						static function () use ( $handlerName ) {
							return LoggerFactory::getProvider()->getHandler(
								$handlerName
							);
						},
						$sample,
					],
				];
			}
			$handlers[$idx] = $sampledHandler;
		}
	}

	if ( $opts['buffer'] ) {
		foreach ( $handlers as $idx => $handlerName ) {
			$bufferedHandler = "{$handlerName}-buffered";
			if ( !isset( $wmgMonologConfig['handlers'][$bufferedHandler] ) ) {
				// Register a handler that will buffer the event stream and
				// pass events to the nested handler after closing the request
				$wmgMonologConfig['handlers'][$bufferedHandler] = [
					'class' => \MediaWiki\Logger\Monolog\BufferHandler::class,
					'args' => [
						static function () use ( $handlerName ) {
							return LoggerFactory::getProvider()->getHandler(
								$handlerName
							);
						},
					],
				];
			}
			$handlers[$idx] = $bufferedHandler;
		}
	}

	if ( $handlers ) {
		// wrap the collection of handlers in a WhatFailureGroupHandler
		// to swallow any exceptions that might leak out otherwise
		$failureGroupHandler = 'failuregroup|' . implode( '|', $handlers );
		if ( !isset( $wmgMonologConfig['handlers'][$failureGroupHandler] ) ) {
			$wmgMonologConfig['handlers'][$failureGroupHandler] = [
				'class' => WhatFailureGroupHandler::class,
				'args' => [
					static function () use ( $handlers ) {
						$provider = LoggerFactory::getProvider();
						return array_map(
							[ $provider, 'getHandler' ],
							$handlers
						);
					}
				],
			];
		}

		$wmgMonologConfig['loggers'][$channel] = [
			'handlers' => [ $failureGroupHandler ],
			'processors' => array_keys( $wmgMonologProcessors ),
			'calls' => $wmgMonologLoggerCalls,
		];

	} else {
		// No handlers configured, so use the blackhole route
		$wmgMonologConfig['loggers'][$channel] = [
			'handlers' => [ 'blackhole' ],
			'calls' => $wmgMonologLoggerCalls,
		];
	}
}

$wgMWLoggerDefaultSpi = [
	'class' => \MediaWiki\Logger\MonologSpi::class,
	'args' => [ $wmgMonologConfig ],
];

if ( $wmgLogToDisk ) {
	$wmgLogDir = "/var/log/mediawiki";

	$wgDBerrorLog = "$wmgLogDir/debuglogs/database.log";

	$wgDebugLogGroups = [
		'404' => "$wmgLogDir/debuglogs/404.log",
		'api' => "$wmgLogDir/debuglogs/api.log",
		'captcha' => "$wmgLogDir/debuglogs/captcha.log",
		'CentralAuth' => "$wmgLogDir/debuglogs/CentralAuth.log",
		'CreateWiki' => "$wmgLogDir/debuglogs/CreateWiki.log",
		'Echo' => "$wmgLogDir/debuglogs/Echo.log",
		'error' => "$wmgLogDir/debuglogs/php-error.log",
		'exception' => "$wmgLogDir/debuglogs/exception.log",
		'exec' => "$wmgLogDir/debuglogs/exec.log",
		'ldap' => "$wmgLogDir/debuglogs/ldap.log",
		'Math' => "$wmgLogDir/debuglogs/Math.log",
		'MatomoAnalytics' => "$wmgLogDir/debuglogs/MatomoAnalytics.log",
		'ManageWiki' => "$wmgLogDir/debuglogs/ManageWiki.log",
		'memcached' => [
			'destination' => "$wmgLogDir/debuglogs/memcached.log",
			'level' => \Psr\Log\LogLevel::ERROR,
		],
		'OAuth' => "$wmgLogDir/debuglogs/OAuth.log",
		'redis' => [
			'destination' => "$wmgLogDir/debuglogs/redis.log",
			'level' => \Psr\Log\LogLevel::WARNING,
		],
		'spf-tmp' => "$wmgLogDir/debuglogs/spf-tmp.log",
		'thumbnail' => "$wmgLogDir/debuglogs/thumbnail.log",
		'VisualEditor' => "$wmgLogDir/debuglogs/VisualEditor.log",
	];
}

if ( $wgCommandLineMode ) {
	error_reporting( -1 );
	ini_set( 'display_startup_errors', 1 );
	ini_set( 'display_errors', 1 );

	$wgShowExceptionDetails = true;

	$wgShowSQLErrors = true;
	$wgDebugDumpSql = true;
	$wgShowDBErrorBacktrace = true;
}
